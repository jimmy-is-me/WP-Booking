<?php

namespace Wumetax\Booking;

defined( 'ABSPATH' ) || exit;

const RELEASE_ROOT = 'https://github.com/jimmy-is-me/WP-Booking/releases';
const RELEASE_API = 'https://api.github.com/repos/jimmy-is-me/WP-Booking/releases/latest';
const RELEASE_CACHE = 'wpb_latest_release';

function booking_license_is_active(): bool {
	return function_exists( 'wutm_license_is_valid' ) && wutm_license_is_valid();
}

function booking_updates_allowed(): bool {
	return booking_license_is_active() && function_exists( 'wutm_license_can_update' ) && wutm_license_can_update();
}

function latest_booking_release(): array {
	$cached = get_site_transient( RELEASE_CACHE );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$response = wp_safe_remote_get( RELEASE_API, array(
		'timeout' => 8,
		'redirection' => 0,
		'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WP-Booking/' . VERSION ),
	) );
	$release = array();
	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && empty( $data['draft'] ) && empty( $data['prerelease'] )
			&& is_string( $data['tag_name'] ?? null ) && preg_match( '/^v(\d+\.\d+\.\d+)$/D', $data['tag_name'], $match ) ) {
			$tag = $data['tag_name'];
			$package = RELEASE_ROOT . '/download/' . $tag . '/wp-booking.zip';
			foreach ( $data['assets'] ?? array() as $asset ) {
				if ( 'wp-booking.zip' === ( $asset['name'] ?? '' ) && $package === ( $asset['browser_download_url'] ?? '' )
					&& 'uploaded' === ( $asset['state'] ?? '' ) ) {
					$release = array( 'version' => $match[1], 'url' => RELEASE_ROOT . '/tag/' . $tag, 'package' => $package );
					break;
				}
			}
		}
	}
	set_site_transient( RELEASE_CACHE, $release, $release ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
	return $release;
}

function booking_update_offer( $update, array $plugin_data, string $plugin_file ) {
	if ( plugin_basename( FILE ) !== $plugin_file ) {
		return $update;
	}
	if ( ! booking_updates_allowed() ) {
		return false;
	}
	$release = latest_booking_release();
	if ( ! $release || ! version_compare( $release['version'], VERSION, '>' ) ) {
		return false;
	}
	return $release + array( 'slug' => 'wp-booking', 'requires' => '6.5', 'requires_php' => '7.4' );
}

function booking_update_download( $reply, string $package, $upgrader, array $context = array() ) {
	if ( ( $context['plugin'] ?? '' ) !== plugin_basename( FILE )
		&& ! preg_match( '~^' . preg_quote( RELEASE_ROOT, '~' ) . '/download/v\d+\.\d+\.\d+/wp-booking\.zip$~D', $package ) ) {
		return $reply;
	}
	if ( ! booking_updates_allowed() || $package !== ( latest_booking_release()['package'] ?? '' ) ) {
		return new \WP_Error( 'wpb_update_license', '請確認 WU Toolbox Modular 授權並重新檢查 WP Booking 更新。' );
	}
	return $reply;
}

function handle_booking_license(): void {
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( '權限不足。', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wpb_check_update' );
	delete_site_transient( RELEASE_CACHE );
	delete_site_transient( 'update_plugins' );
	wp_safe_redirect( self_admin_url( 'update-core.php?force-check=1' ), 303 );
	exit;
}

function render_booking_license(): void {
	?>
	<section class="wpb-admin-card">
		<h2>WU Toolbox Modular 共用授權</h2>
		<p><?php
		if ( ! function_exists( 'wutm_license_is_valid' ) ) {
			echo '請先在本站安裝並啟用 WU Toolbox Modular，並在工具箱完成授權。';
		} elseif ( ! booking_license_is_active() ) {
			echo '請至 WU Toolbox Modular 啟用或重新驗證本站授權。';
		} else {
			echo '已沿用本站的 WU Toolbox Modular 授權，可接受新預約。';
		}
		?></p>
		<p>WP Booking 版本 <?php echo esc_html( VERSION ); ?> · 更新<?php echo booking_updates_allowed() ? '可使用' : '暫停'; ?></p>
		<?php if ( current_user_can( 'update_plugins' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpb_check_update' ); ?>
				<input type="hidden" name="action" value="wpb_check_update">
				<button class="wpb-admin-btn wpb-admin-primary" type="submit">檢查 WordPress 更新</button>
			</form>
		<?php endif; ?>
	</section>
	<?php
}

// Clear the obsolete 1.4.1 connection and scheduled validation on upgrade.
add_action( 'init', static function (): void {
	if ( get_option( 'wpb_shared_license_migrated' ) ) {
		return;
	}
	delete_option( 'wpb_license' );
	wp_clear_scheduled_hook( 'wpb_license_refresh' );
	delete_site_transient( 'update_plugins' );
	update_option( 'wpb_shared_license_migrated', '1', false );
} );
add_action( 'admin_post_wpb_check_update', __NAMESPACE__ . '\handle_booking_license' );
add_filter( 'update_plugins_wumetax.com', __NAMESPACE__ . '\booking_update_offer', 10, 3 );
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\booking_update_download', 10, 4 );
