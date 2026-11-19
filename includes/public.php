<?php

namespace Wumetax\Booking;

use DateTimeImmutable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

const API = 'wp-booking/v1';

function register_routes(): void {
	register_rest_route( API, '/calendar', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => __NAMESPACE__ . '\\calendar_response',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( API, '/slots', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => __NAMESPACE__ . '\\slots_response',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( API, '/bookings', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => __NAMESPACE__ . '\\booking_response',
		'permission_callback' => __NAMESPACE__ . '\\permit_public_booking',
	) );
}

function prevent_rest_cache( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
	if ( 0 === strpos( $request->get_route(), '/' . API . '/' ) ) {
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
	}
	return $response;
}

function calendar_response(): array {
	$timezone = wp_timezone();
	$now = new DateTimeImmutable( 'now', $timezone );
	$available = array();
	foreach ( services() as $id => $service ) {
		if ( $service['enabled'] ) {
			$available[] = array(
				'id'          => $id,
				'name'        => $service['name'],
				'description' => $service['description'],
				'duration'    => (int) $service['duration'],
			);
		}
	}
	return array(
		'services' => $available,
		'dates'    => calendar_limits( settings(), $now ),
		'timezone' => $timezone->getName(),
	);
}

function slots_response( WP_REST_Request $request ) {
	$id = $request->get_query_params()['service'] ?? null;
	$date = $request->get_query_params()['date'] ?? null;
	$timezone = wp_timezone();
	$day = is_string( $date ) ? calendar_date( $date, $timezone ) : null;
	$service = is_string( $id ) ? services()[ $id ] ?? null : null;
	if ( ! $day || ! $service || ! $service['enabled'] ) {
		return new WP_Error( 'wpb_selection', '請選擇有效的服務與日期。', array( 'status' => 400 ) );
	}

	$now = new DateTimeImmutable( 'now', $timezone );
	$duration = (int) $service['duration'] * MINUTE_IN_SECONDS;
	$starts = scheduled_slots( $day, (int) $service['duration'], settings(), $now );
	if ( ! $starts ) {
		return array( 'slots' => array() );
	}

	$busy = overlaps( array_key_first( $starts ), array_key_last( $starts ) + $duration );
	if ( is_wp_error( $busy ) ) {
		return $busy;
	}

	$slots = array();
	foreach ( $starts as $timestamp => $label ) {
		foreach ( $busy as $booking ) {
			if ( $timestamp < (int) $booking['ends_at'] && $timestamp + $duration > (int) $booking['starts_at'] ) {
				continue 2;
			}
		}
		$slots[] = array( 'start' => $timestamp, 'label' => $label );
	}
	return array( 'slots' => $slots );
}

function permit_public_booking( WP_REST_Request $request ) {
	$origin = $request->get_header( 'origin' );
	$home = wp_parse_url( home_url( '/' ) );
	$source = $origin ? wp_parse_url( $origin ) : array();
	$valid_origin = ! $origin || ( isset( $source['scheme'], $source['host'], $home['scheme'], $home['host'] )
		&& $source['scheme'] === $home['scheme'] && 0 === strcasecmp( $source['host'], $home['host'] )
		&& ( $source['port'] ?? ( 'https' === $source['scheme'] ? 443 : 80 ) )
			=== ( $home['port'] ?? ( 'https' === $home['scheme'] ? 443 : 80 ) ) );
	if ( '1' !== $request->get_header( 'x-wpb-request' ) || ! $valid_origin ) {
		return new WP_Error( 'wpb_origin', '請從網站的預約表單送出。', array( 'status' => 403 ) );
	}
	if ( ! $request->is_json_content_type() ) {
		return new WP_Error( 'wpb_content_type', '請使用 JSON 送出預約資料。', array( 'status' => 415 ) );
	}
	if ( strlen( $request->get_body() ) > 12000 ) {
		return new WP_Error( 'wpb_body_size', '預約資料長度超過限制。', array( 'status' => 413 ) );
	}
	return true;
}

function text_size( string $value ): int {
	return preg_match_all( '/./us', $value ) ?: 0;
}

function validate_booking( array $payload ) {
	foreach ( array( 'request_id', 'service_id', 'customer_name', 'customer_email', 'customer_phone', 'note', 'website' ) as $key ) {
		if ( ! is_string( $payload[ $key ] ?? null ) ) {
			return new WP_Error( 'wpb_input', '預約資料格式有誤，請重新確認。', array( 'status' => 400 ) );
		}
	}

	$input = array(
		'request_id'     => $payload['request_id'],
		'service_id'     => $payload['service_id'],
		'starts_at'      => $payload['starts_at'] ?? null,
		'customer_name'  => sanitize_text_field( $payload['customer_name'] ),
		'customer_email' => trim( $payload['customer_email'] ),
		'customer_phone' => sanitize_text_field( $payload['customer_phone'] ),
		'note'           => sanitize_textarea_field( $payload['note'] ),
	);
	if ( '' !== $payload['website'] || ! preg_match( '/^[a-f0-9]{32}$/D', $input['request_id'] )
		|| ! preg_match( '/^[a-f0-9]{32}$/D', $input['service_id'] )
		|| ! is_int( $input['starts_at'] ) || $input['starts_at'] <= 0 ) {
		return new WP_Error( 'wpb_input', '預約資料格式有誤，請重新確認。', array( 'status' => 400 ) );
	}
	if ( '' === $input['customer_name'] || text_size( $input['customer_name'] ) > 100
		|| strlen( $input['customer_email'] ) > 254 || ! is_email( $input['customer_email'] )
		|| text_size( $input['customer_phone'] ) > 40 || text_size( $input['note'] ) > 500 ) {
		return new WP_Error( 'wpb_contact', '請填寫姓名與有效的電子郵件，並確認電話與備註的長度。', array( 'status' => 400 ) );
	}
	return $input;
}

function booking_response( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$input = validate_booking( is_array( $payload ) ? $payload : array() );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	// A host can provide a verified client IP when using a trusted reverse proxy.
	$ip = apply_filters( 'wpb_client_ip', $ip, $request );
	$rate_key = 'wpb_rate_' . substr( wp_hash( (string) $ip, 'nonce' ), 0, 32 );
	$result = book( $input, $rate_key );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$booking = $result['booking'];
	return new WP_REST_Response( array(
		'reference' => $booking['reference'],
		'service'   => $booking['service_name'],
		'status'    => status_labels()[ $booking['status'] ],
		'time'      => booking_time( $booking ),
	), $result['created'] ? 201 : 200 );
}

function register_form(): void {
	wp_register_style( 'wp-booking', plugins_url( 'assets/booking.css', FILE ), array(), VERSION );
	wp_register_script( 'wp-booking', plugins_url( 'assets/booking.js', FILE ), array(), VERSION, true );
	add_shortcode( 'wp_booking', __NAMESPACE__ . '\\render_form' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_shortcode_style' );
}

function enqueue_shortcode_style(): void {
	$post = get_queried_object();
	if ( $post instanceof \WP_Post && has_shortcode( $post->post_content, 'wp_booking' ) ) {
		wp_enqueue_style( 'wp-booking' );
	}
}

function render_form(): string {
	wp_enqueue_style( 'wp-booking' );
	wp_enqueue_script( 'wp-booking' );
	$id = wp_unique_id( 'wpb-' );
	$privacy = get_privacy_policy_url();

	ob_start();
	?>
	<section class="wpb" aria-labelledby="<?php echo esc_attr( $id . '-title' ); ?>"
		data-wpb-calendar="<?php echo esc_url( rest_url( API . '/calendar' ) ); ?>"
		data-wpb-slots="<?php echo esc_url( rest_url( API . '/slots' ) ); ?>"
		data-wpb-bookings="<?php echo esc_url( rest_url( API . '/bookings' ) ); ?>">
		<header class="wpb-header">
			<h2 id="<?php echo esc_attr( $id . '-title' ); ?>">線上預約</h2>
			<p>選擇服務與時段，留下聯絡方式即可送出。</p>
		</header>
		<noscript><p class="wpb-message">請啟用 JavaScript 以使用預約表單。</p></noscript>
		<p class="wpb-message" data-wpb="calendar-message" role="status" hidden></p>
		<button class="wpb-button wpb-secondary wpb-retry" type="button" data-wpb="retry" hidden>重新載入</button>
		<form class="wpb-form" data-wpb="form" hidden>
			<div class="wpb-panel">
				<h3>服務與時段</h3>
				<label class="wpb-field">
					<span>服務</span>
					<select name="service" required></select>
				</label>
				<p class="wpb-help" data-wpb="description" hidden></p>
				<label class="wpb-field">
					<span>預約日期</span>
					<input type="date" name="date" required>
				</label>
				<p class="wpb-help" data-wpb="timezone"></p>
				<fieldset class="wpb-slots-field">
					<legend>可預約時段</legend>
					<p class="wpb-help" data-wpb="slot-message" role="status"></p>
					<div class="wpb-slots" data-wpb="slots"></div>
				</fieldset>
			</div>
			<div class="wpb-panel">
				<h3>聯絡資料</h3>
				<label class="wpb-field">
					<span>姓名</span>
					<input name="customer_name" autocomplete="name" maxlength="100" required>
				</label>
				<label class="wpb-field">
					<span>電子郵件</span>
					<input type="email" name="customer_email" autocomplete="email" maxlength="254" required>
				</label>
				<label class="wpb-field">
					<span>電話 <small>選填</small></span>
					<input type="tel" name="customer_phone" autocomplete="tel" maxlength="40">
				</label>
				<label class="wpb-field">
					<span>備註 <small>選填</small></span>
					<textarea name="note" rows="3" maxlength="500"></textarea>
				</label>
				<div hidden><input name="website" tabindex="-1" autocomplete="off" aria-label="請留空"></div>
				<p class="wpb-help">聯絡資料用於處理預約與寄送通知。
					<?php if ( $privacy ) : ?>
						<a href="<?php echo esc_url( $privacy ); ?>">隱私權政策</a>
					<?php endif; ?>
				</p>
				<div class="wpb-footer">
					<button class="wpb-button" type="submit" data-wpb="submit" disabled>送出預約</button>
					<p class="wpb-error" data-wpb="submit-message" role="alert" hidden></p>
				</div>
			</div>
		</form>
		<div class="wpb-result" data-wpb="result" tabindex="-1" hidden>
			<h3>預約已送出</h3>
			<dl>
				<div><dt>預約編號</dt><dd data-wpb="reference"></dd></div>
				<div><dt>預約服務</dt><dd data-wpb="service"></dd></div>
				<div><dt>日期與時段</dt><dd data-wpb="time"></dd></div>
				<div><dt>預約狀態</dt><dd data-wpb="status"></dd></div>
			</dl>
			<button class="wpb-button wpb-secondary" type="button" data-wpb="another">新增另一筆預約</button>
		</div>
	</section>
	<?php
	return ob_get_clean();
}
