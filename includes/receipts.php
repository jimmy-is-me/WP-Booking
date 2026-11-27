<?php

namespace Wumetax\Booking;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

function receipt_token( array $booking ): string {
	$reference = $booking['reference'];
	return $reference . '.' . hash_hmac( 'sha256', 'wpb-receipt|' . $reference . '|' . $booking['customer_email'], wp_salt( 'auth' ) );
}

function receipt_link( array $booking ): string {
	return empty( $booking['customer_email'] ) ? '' : add_query_arg( 'wpb_receipt', '1', home_url( '/' ) ) . '#' . receipt_token( $booking );
}

function booking_by_reference( string $reference ): ?array {
	global $wpdb;
	$table = booking_table();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", $reference ), ARRAY_A );
}

function booking_from_receipt( string $token ): ?array {
	if ( ! preg_match( '/^(WPB-[A-F0-9]{16})\.[a-f0-9]{64}$/D', $token, $parts ) ) {
		return null;
	}
	$booking = booking_by_reference( $parts[1] );
	return $booking && ! empty( $booking['customer_email'] ) && hash_equals( receipt_token( $booking ), $token ) ? $booking : null;
}

function can_check_in( array $booking ): bool {
	$timezone = wp_timezone();
	$day = wp_date( 'Y-m-d', (int) $booking['starts_at'], $timezone );
	return 'confirmed' === $booking['status'] && ! empty( $booking['customer_email'] )
		&& $day === wp_date( 'Y-m-d', time(), $timezone )
		&& empty( $booking['checked_in_at'] );
}

function check_in_booking( int $id ) {
	$result = mutate_booking( $id, static function ( array $booking ) {
		if ( ! can_check_in( $booking ) ) {
			return new WP_Error( 'wpb_checkin', '此預約已報到，或尚未符合報到條件。僅限預約當日的已確認預約。', array( 'status' => 409 ) );
		}
		return array( 'event' => 'checked_in', 'checked_in_at' => time() );
	} );
	return is_wp_error( $result ) ? $result : $result['booking'];
}

function receipt_response( WP_REST_Request $request ) {
	$data = $request->get_json_params();
	$email = $data['email'] ?? null;
	$reference = $data['reference'] ?? null;
	$token = $data['token'] ?? null;
	$booking = null;
	$verified_email = false;
	if ( is_string( $token ) ) {
		$booking = booking_from_receipt( $token );
	} elseif ( is_string( $reference ) && is_string( $email ) ) {
		$ip = apply_filters( 'wpb_client_ip', $_SERVER['REMOTE_ADDR'] ?? '', $request );
		$key = 'wpb_lookup_' . substr( wp_hash( (string) $ip, 'nonce' ), 0, 32 );
		$attempts = (int) get_transient( $key );
		if ( $attempts >= 15 ) {
			return new WP_Error( 'wpb_lookup_rate', '查詢次數過多，請稍後再試。', array( 'status' => 429 ) );
		}
		set_transient( $key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
		$reference = strtoupper( trim( $reference ) );
		if ( preg_match( '/^WPB-[A-F0-9]{16}$/D', $reference ) && strlen( $email ) <= 254 && is_email( trim( $email ) ) ) {
			$booking = booking_by_reference( $reference );
			$verified_email = $booking && hash_equals( strtolower( $booking['customer_email'] ), strtolower( trim( $email ) ) );
			$booking = $verified_email ? $booking : null;
		}
	}
	if ( ! $booking ) {
		return new WP_Error( 'wpb_receipt', '找不到符合的預約，請檢查預約編號與電子郵件。', array( 'status' => 404 ) );
	}
	if ( ! empty( $data['check_in'] ) ) {
		if ( ! is_string( $token ) || ! can_manage_bookings() ) {
			return new WP_Error( 'wpb_checkin_forbidden', '請以預約管理人員身分登入再報到。', array( 'status' => 403 ) );
		}
		$booking = check_in_booking( (int) $booking['id'] );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
	}
	$status = pending_expired( $booking, time() ) ? 'expired' : $booking['status'];
	$active = in_array( $status, array( 'pending', 'confirmed' ), true ) && (int) $booking['starts_at'] > time() && empty( $booking['checked_in_at'] );
	return array(
		'reference' => $booking['reference'],
		'service' => $booking['service_name'],
		'time' => booking_time( $booking ),
		'status' => status_labels()[ $status ],
		'party_size' => (int) $booking['party_size'],
		'checked_in' => ! empty( $booking['checked_in_at'] ),
		'can_check_in' => is_string( $token ) && can_manage_bookings() && can_check_in( $booking ),
		'manage' => $verified_email && $active ? management_link( $booking ) : '',
		'receipt' => receipt_link( $booking ),
	);
}

function register_receipt_route(): void {
	register_rest_route( API, '/receipt', array(
		'methods' => 'POST',
		'callback' => __NAMESPACE__ . '\receipt_response',
		'permission_callback' => __NAMESPACE__ . '\permit_public_booking',
	) );
}

function register_receipt_assets(): void {
	wp_register_script( 'wpb-qrcode', plugins_url( 'assets/qrcode.min.js', FILE ), array(), VERSION, true );
	wp_register_script( 'wpb-receipts', plugins_url( 'assets/receipts.js', FILE ), array( 'wpb-qrcode' ), VERSION, true );
}

function render_receipt_panel( bool $scanner = false ): void {
	wp_enqueue_script( 'wpb-receipts' );
	?>
	<div data-wpb-receipt-panel="<?php echo $scanner ? 'scan' : 'lookup'; ?>" data-endpoint="<?php echo esc_url( rest_url( API . '/receipt' ) ); ?>"
		<?php if ( $scanner && can_manage_bookings() ) : ?>data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"<?php endif; ?>>
		<?php if ( ! $scanner ) : ?>
			<form class="wpb-lookup-form" method="post" hidden>
				<label class="wpb-field">預約編號<input name="reference" required maxlength="20" placeholder="WPB-…" autocomplete="off"></label>
				<label class="wpb-field">預約時填寫的電子郵件<input name="email" type="email" required maxlength="254" autocomplete="email"></label>
				<button type="submit" class="wpb-button">查詢預約</button>
			</form>
			<noscript>請啟用 JavaScript 查詢預約。</noscript>
		<?php endif; ?>
		<p data-wpb-receipt-message role="status"></p>
		<div data-wpb-receipt-result hidden>
			<p data-wpb-receipt-summary></p>
			<div class="wpb-receipt-links">
				<a data-wpb-receipt-open hidden>開啟預約憑證</a>
				<a data-wpb-receipt-manage hidden>改期或取消</a>
				<button class="wpb-button" type="button" data-wpb-scan-in hidden>確認報到</button>
			</div>
		</div>
	</div>
	<?php
}

function render_booking_qr( string $url = '' ): void {
	wp_enqueue_script( 'wpb-receipts' );
	?>
	<div class="wpb-qr" data-wpb-qr="<?php echo esc_url( $url ); ?>" <?php if ( ! $url ) : ?>hidden<?php endif; ?>>
		<canvas role="img" aria-label="預約憑證 QR Code" hidden></canvas>
		<a href="<?php echo esc_url( $url ); ?>">開啟預約憑證</a>
		<a download="WP-Booking-QR.png" class="wpb-qr-download" hidden>儲存 QR Code</a>
	</div>
	<?php
}

function serve_receipt_page(): void {
	if ( '1' !== ( $_GET['wpb_receipt'] ?? null ) ) {
		return;
	}
	nocache_headers();
	header( 'Content-Type: text/html; charset=UTF-8', true );
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Referrer-Policy: no-referrer', true );
	header( "Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'", true );
	?>
	<!doctype html>
	<html lang="zh-Hant">
	<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
		<title>預約憑證</title>
		<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/booking.css', FILE ) ) ); ?>">
	</head>
	<body>
		<main class="wpb wpb-embedded wpb-management-page">
			<header class="wpb-header"><h2>預約憑證</h2></header>
			<noscript>請啟用 JavaScript 以檢視預約憑證。</noscript>
			<?php render_receipt_panel( true ); ?>
			<?php powered_by(); ?>
		</main>
		<script src="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/receipts.js', FILE ) ) ); ?>" defer></script>
	</body>
	</html>
	<?php
	exit;
}

add_action( 'init', __NAMESPACE__ . '\register_receipt_assets' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_receipt_route' );
add_action( 'template_redirect', __NAMESPACE__ . '\serve_receipt_page', 0 );
