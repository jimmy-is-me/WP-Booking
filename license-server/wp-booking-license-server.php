<?php
/**
 * Plugin Name: WP Booking License Server
 * Description: WUMETAX 網站授權與 WP Booking 版本發布管理。
 * Version: 1.4.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

namespace Wumetax\BookingLicenseServer;

defined( 'ABSPATH' ) || exit;

const OPTION = 'wpb_license_registry';
const RELEASE = 'https://api.github.com/repos/jimmy-is-me/WP-Booking/releases/tags/v';

function registry(): array {
	return (array) get_option( OPTION, array( 'version' => '', 'licenses' => array() ) );
}

function change_registry( callable $callback ) {
	global $wpdb;
	$lock = 'wpbl_' . md5( DB_NAME . ':' . $wpdb->options );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
		return new \WP_Error( 'wpbl_busy', '請稍後再試。', array( 'status' => 503 ) );
	}
	try {
		// Read the database after acquiring the lock, including with per-request option caching.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", OPTION ) );
		$before = null === $raw ? array( 'version' => '', 'licenses' => array() ) : maybe_unserialize( $raw );
		$after = $before;
		$result = $callback( $after );
		if ( $after !== $before ) {
			$changed = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 SELECT %s, %s, 'no' WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
				 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				OPTION, maybe_serialize( $after ), $lock
			) );
			if ( ! $changed ) {
				return new \WP_Error( 'wpbl_save', '無法儲存授權。', array( 'status' => 503 ) );
			}
			wp_cache_delete( OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return $result;
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

function site_identity( string $site ): string {
	$url = wp_parse_url( $site );
	if ( ! $url || ! in_array( $url['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $url['host'] )
		|| isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) {
		return '';
	}
	return strtolower( $url['host'] ) . ( isset( $url['port'] ) ? ':' . $url['port'] : '' ) . rtrim( $url['path'] ?? '', '/' );
}

function token_for( string $id, array $license ): string {
	return $id . '.' . hash_hmac( 'sha256', $id . '|' . $license['site'] . '|' . $license['generation'], wp_salt( 'auth' ) );
}

function verify_credential( \WP_REST_Request $request, bool $activate ) {
	$credential = $request->get_param( 'credential' );
	$site = $request->get_param( 'site' );
	$pattern = $activate ? '/^WPB-([a-f0-9]{16})-[a-f0-9]{48}$/D' : '/^([a-f0-9]{16})\.[a-f0-9]{64}$/D';
	$identity = is_string( $site ) && strlen( $site ) <= 512 ? site_identity( $site ) : '';
	if ( ! $identity || ! is_string( $credential ) || ! preg_match( $pattern, $credential, $match ) ) {
		return array( 'status' => 'invalid' );
	}
	$id = $match[1];
	if ( ! $activate ) {
		$data = registry();
		$record = $data['licenses'][ $id ] ?? null;
		if ( ! $record || $record['site'] !== $identity || ! hash_equals( token_for( $id, $record ), $credential ) ) {
			return array( 'status' => 'invalid' );
		}
		return $record['enabled'] ? array( 'status' => 'active', 'version' => $data['version'] ) : array( 'status' => 'inactive' );
	}
	return change_registry( static function ( array &$data ) use ( $id, $identity, $credential ) {
		$record = $data['licenses'][ $id ] ?? null;
		if ( ! $record || ! hash_equals( $record['key_hash'], hash( 'sha256', $credential ) ) ) {
			return array( 'status' => 'invalid' );
		}
		if ( ! $record['enabled'] ) {
			return array( 'status' => 'inactive' );
		}
		if ( $record['site'] && $record['site'] !== $identity ) {
			return array( 'status' => 'bound' );
		}
		$record['site'] = $identity;
		$data['licenses'][ $id ] = $record;
		return array( 'status' => 'active', 'version' => $data['version'], 'token' => token_for( $id, $record ) );
	} );
}

function register_routes(): void {
	foreach ( array( 'activate', 'validate' ) as $action ) {
		register_rest_route( 'wp-booking-license/v1', '/' . $action, array(
			'methods' => \WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'callback' => static function ( \WP_REST_Request $request ) use ( $action ) {
				$result = verify_credential( $request, 'activate' === $action );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$response = rest_ensure_response( $result );
				$response->header( 'Cache-Control', 'private, no-store' );
				return $response;
			},
		) );
	}
}

function release_exists( string $version ): bool {
	$response = wp_safe_remote_get( RELEASE . $version, array( 'timeout' => 8, 'redirection' => 0 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) && empty( $data['draft'] ) && empty( $data['prerelease'] )
		&& in_array( 'wp-booking.zip', array_column( $data['assets'] ?? array(), 'name' ), true );
}

function handle_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '權限不足。', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wpbl_manage' );
	$post = static function ( string $key ): string {
		return is_string( $_POST[ $key ] ?? null ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	};
	$action = $post( 'operation' );
	$id = $post( 'id' );
	$label = $post( 'label' );
	$version = $post( 'version' );
	if ( 'publish' === $action && '' !== $version && ( ! preg_match( '/^\d+\.\d+\.\d+$/D', $version ) || ! release_exists( $version ) ) ) {
		wp_die( '找不到此版本的正式 Release 或 wp-booking.zip。', '', array( 'back_link' => true ) );
	}
	$result = change_registry( static function ( array &$data ) use ( $action, $id, $label, $version ) {
		if ( 'issue' === $action && '' !== $label && strlen( $label ) <= 200 ) {
			$id = bin2hex( random_bytes( 8 ) );
			$key = 'WPB-' . $id . '-' . bin2hex( random_bytes( 24 ) );
			$data['licenses'][ $id ] = array(
				'key_hash' => hash( 'sha256', $key ),
				'label' => $label,
				'enabled' => true,
				'site' => '',
				'generation' => bin2hex( random_bytes( 16 ) ),
			);
			return $key;
		}
		if ( 'publish' === $action ) {
			$data['version'] = $version;
			return true;
		}
		if ( isset( $data['licenses'][ $id ] ) && in_array( $action, array( 'disable', 'enable', 'unbind' ), true ) ) {
			if ( 'unbind' === $action ) {
				$data['licenses'][ $id ]['site'] = '';
				$data['licenses'][ $id ]['generation'] = bin2hex( random_bytes( 16 ) );
			} else {
				$data['licenses'][ $id ]['enabled'] = 'enable' === $action;
			}
			return true;
		}
		return new \WP_Error( 'wpbl_action', '無法執行這項操作。' );
	} );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
	}
	if ( is_string( $result ) ) {
		nocache_headers();
		wp_die( '<p>請現在複製授權碼。管理端只保存雜湊。</p><p><code>' . esc_html( $result ) . '</code></p><p><a href="' . esc_url( admin_url( 'tools.php?page=wp-booking-license-server' ) ) . '">返回授權管理</a></p>', '網站授權碼已建立', array( 'response' => 200 ) );
	}
	wp_safe_redirect( admin_url( 'tools.php?page=wp-booking-license-server&saved=1' ), 303 );
	exit;
}

function form_fields(): void {
	wp_nonce_field( 'wpbl_manage' );
	echo '<input type="hidden" name="action" value="wpbl_manage">';
}

function render_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$data = registry();
	$url = admin_url( 'admin-post.php' );
	?>
	<div class="wrap">
		<h1>WP Booking 授權管理</h1>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>已儲存。</p></div><?php endif; ?>
		<h2>建立網站授權碼</h2>
		<form method="post" action="<?php echo esc_url( $url ); ?>">
			<?php form_fields(); ?>
			<label>客戶或網站備註 <input name="label" required maxlength="65"></label>
			<button class="button button-primary" name="operation" value="issue">建立授權碼</button>
		</form>
		<h2>提供後台更新</h2>
		<form method="post" action="<?php echo esc_url( $url ); ?>">
			<?php form_fields(); ?>
			<label>提供版本 <input name="version" placeholder="1.4.1" value="<?php echo esc_attr( $data['version'] ); ?>"></label>
			<button class="button button-primary" name="operation" value="publish">套用</button>
			<p>留空可停止提供更新。網站會在下次授權驗證時取得新的版本設定。</p>
		</form>
		<h2>網站授權</h2>
		<table class="widefat striped">
			<thead><tr><th>備註</th><th>識別碼</th><th>綁定網站</th><th>狀態</th><th>處理</th></tr></thead>
			<tbody>
			<?php foreach ( $data['licenses'] as $id => $record ) : ?>
				<tr>
					<td><?php echo esc_html( $record['label'] ); ?></td>
					<td><?php echo esc_html( $id ); ?></td>
					<td><?php echo esc_html( $record['site'] ?: '未綁定' ); ?></td>
					<td><?php echo $record['enabled'] ? '已啟用' : '已停用'; ?></td>
					<td><form method="post" action="<?php echo esc_url( $url ); ?>">
						<?php form_fields(); ?>
						<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
						<button class="button" name="operation" value="<?php echo $record['enabled'] ? 'disable' : 'enable'; ?>"><?php echo $record['enabled'] ? '停用' : '啟用'; ?></button>
						<?php if ( $record['site'] ) : ?><button class="button" name="operation" value="unbind">解除綁定</button><?php endif; ?>
					</form></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
add_action( 'admin_post_wpbl_manage', __NAMESPACE__ . '\handle_admin' );
add_action( 'admin_menu', static function (): void {
	add_management_page( 'WP Booking 授權管理', 'WP Booking 授權', 'manage_options', 'wp-booking-license-server', __NAMESPACE__ . '\render_admin' );
} );
