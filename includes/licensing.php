<?php

namespace Wumetax\Booking;

defined( 'ABSPATH' ) || exit;

const LICENSE_OPTION = 'wpb_license';
const LICENSE_CRON = 'wpb_license_refresh';
const RELEASE_ROOT = 'https://github.com/jimmy-is-me/WP-Booking/releases';

function license_state(): array {
	$state = (array) get_option( LICENSE_OPTION, array() );
	return ( $state['site'] ?? '' ) === home_url( '/' ) ? $state : array();
}

function booking_license_is_active(): bool {
	$state = license_state();
	return 'active' === ( $state['status'] ?? '' ) && (int) ( $state['until'] ?? 0 ) > time();
}

function license_request( string $action, string $credential ) {
	$base = defined( 'WPB_LICENSE_API' ) ? rtrim( WPB_LICENSE_API, '/' ) : 'https://wpcd.wumetax.com/wp-json/wp-booking-license/v1';
	if ( 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) {
		return new \WP_Error( 'wpb_license_url', '授權伺服器須使用 HTTPS。' );
	}
	$response = wp_safe_remote_post( $base . '/' . $action, array(
		'timeout' => 8,
		'redirection' => 0,
		'body' => array(
			'site' => home_url( '/' ),
			'credential' => $credential,
		),
	) );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $code || ! is_array( $data ) || ! in_array( $data['status'] ?? '', array( 'active', 'inactive', 'bound', 'invalid' ), true ) ) {
		return new \WP_Error( 'wpb_license_unavailable', '授權伺服器目前無法驗證。' );
	}
	return $data;
}

function sync_booking_license( string $key = '' ): string {
	$previous = license_state();
	$credential = '' !== $key ? $key : ( $previous['token'] ?? '' );
	if ( '' === $credential ) {
		return 'missing';
	}
	$data = license_request( '' !== $key ? 'activate' : 'validate', $credential );
	if ( is_wp_error( $data ) ) {
		if ( '' === $key ) {
			$current = license_state();
			if ( $current === $previous ) {
				$current['target'] = '';
				update_option( LICENSE_OPTION, $current, false );
			}
		}
		return 'unavailable';
	}
	$status = $data['status'];
	if ( '' !== $key && 'active' !== $status ) {
		return $status;
	}
	if ( '' === $key && license_state() !== $previous ) {
		return 'changed';
	}
	$token = '' === $key ? $credential : ( $data['token'] ?? '' );
	if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{16}\.[a-f0-9]{64}$/D', $token ) ) {
		return 'unavailable';
	}
	$target = $data['version'] ?? '';
	update_option( LICENSE_OPTION, array(
		'site' => home_url( '/' ),
		'token' => $token,
		'status' => $status,
		'until' => 'active' === $status ? time() + 2 * DAY_IN_SECONDS : 0,
		'target' => 'active' === $status && is_string( $target ) && preg_match( '/^\d+\.\d+\.\d+$/D', $target ) ? $target : '',
	), false );
	return $status;
}

function enable_license_cron(): void {
	if ( ! wp_next_scheduled( LICENSE_CRON ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', LICENSE_CRON );
	}
}

function disable_license_cron(): void {
	wp_clear_scheduled_hook( LICENSE_CRON );
}

function booking_update_url( string $version ): string {
	return RELEASE_ROOT . '/download/v' . $version . '/wp-booking.zip';
}

function booking_update_offer( $update, array $plugin_data, string $plugin_file ) {
	$version = license_state()['target'] ?? '';
	if ( plugin_basename( FILE ) !== $plugin_file ) {
		return $update;
	}
	if ( ! booking_license_is_active() || ! version_compare( $version, VERSION, '>' ) ) {
		return false;
	}
	return array(
		'slug' => 'wp-booking',
		'version' => $version,
		'url' => RELEASE_ROOT . '/tag/v' . $version,
		'package' => booking_update_url( $version ),
		'requires' => '6.5',
		'requires_php' => '7.4',
	);
}

function booking_update_download( $reply, string $package, $upgrader, array $context = array() ) {
	if ( ( $context['plugin'] ?? '' ) !== plugin_basename( FILE ) && ! preg_match( '~^' . preg_quote( RELEASE_ROOT, '~' ) . '/download/v\d+\.\d+\.\d+/wp-booking\.zip$~D', $package ) ) {
		return $reply;
	}
	if ( 'active' !== sync_booking_license() || ! booking_license_is_active() || $package !== booking_update_url( license_state()['target'] ?? '' ) ) {
		return new \WP_Error( 'wpb_update_license', '目前沒有取得此版本的 WP Booking 更新授權。' );
	}
	return $reply;
}

function handle_booking_license(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '權限不足。', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wpb_license' );
	$key = trim( read_field( $_POST, 'license_key' ) );
	$operation = read_field( $_POST, 'operation' );
	$result = 'invalid';
	if ( 'activate' === $operation && preg_match( '/^WPB-[a-f0-9]{16}-[a-f0-9]{48}$/D', $key ) ) {
		$result = sync_booking_license( $key );
	} elseif ( 'refresh' === $operation ) {
		$result = sync_booking_license();
	}
	delete_site_transient( 'update_plugins' );
	wp_safe_redirect( add_query_arg( 'license_result', $result, admin_url_for( array( 'tab' => 'license' ) ) ), 303 );
	exit;
}

function render_booking_license(): void {
	$state = license_state();
	$labels = array(
		'active' => '授權有效',
		'inactive' => '授權已停用',
		'bound' => '此授權碼已綁定其他網站',
		'invalid' => '授權碼或網站綁定無效',
		'missing' => '請先輸入授權碼',
		'unavailable' => '目前無法連線驗證授權',
		'changed' => '授權狀態已更新，請重新整理',
	);
	$result = read_field( $_GET, 'license_result' );
	?>
	<section class="wpb-admin-card">
		<h2>網站授權</h2>
		<p><?php echo esc_html( booking_license_is_active() ? '授權有效' : '新預約已暫停，請啟用或重新驗證授權。' ); ?></p>
		<p>目前版本 <?php echo esc_html( VERSION ); ?> · 授權提供版本 <?php echo esc_html( ( $state['target'] ?? '' ) ?: '尚未指定' ); ?></p>
		<?php if ( isset( $labels[ $result ] ) ) : ?><p role="status"><?php echo esc_html( $labels[ $result ] ); ?></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wpb_license' ); ?>
			<input type="hidden" name="action" value="wpb_license">
			<label>授權碼<input name="license_key" type="password" maxlength="69" autocomplete="off"></label>
			<p>授權站會接收本站網址及授權碼或網站權杖。<a href="https://wumetax.com/privacy-policy/">隱私權政策</a></p>
			<button class="wpb-admin-btn wpb-admin-primary" type="submit" name="operation" value="activate">啟用授權</button>
			<?php if ( $state ) : ?><button class="wpb-admin-btn" type="submit" name="operation" value="refresh">重新驗證並檢查更新</button><?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">WordPress 更新</a>
		</form>
	</section>
	<?php
}

add_action( 'init', __NAMESPACE__ . '\enable_license_cron' );
register_deactivation_hook( FILE, __NAMESPACE__ . '\disable_license_cron' );
add_action( LICENSE_CRON, static function (): void {
	$before = license_state();
	sync_booking_license();
	$after = license_state();
	if ( ( $before['status'] ?? '' ) !== ( $after['status'] ?? '' ) || ( $before['target'] ?? '' ) !== ( $after['target'] ?? '' ) ) {
		delete_site_transient( 'update_plugins' );
	}
} );
add_action( 'admin_post_wpb_license', __NAMESPACE__ . '\handle_booking_license' );
add_filter( 'update_plugins_wumetax.com', __NAMESPACE__ . '\booking_update_offer', 10, 3 );
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\booking_update_download', 10, 4 );
