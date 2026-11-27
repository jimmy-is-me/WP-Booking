<?php

namespace Wumetax\Booking;

use DateTimeImmutable;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

function management_key( array $booking ): string {
	$proof = implode( '|', array( $booking['reference'], $booking['customer_email'], $booking['revision'], $booking['starts_at'] ) );
	return $booking['reference'] . '.' . hash_hmac( 'sha256', $proof, wp_salt( 'auth' ) );
}

function management_link( array $booking ): string {
	return add_query_arg( 'wpb_manage', '1', home_url( '/' ) ) . '#' . management_key( $booking );
}

function valid_management_key( array $booking, string $key ): bool {
	return '' !== $booking['customer_email'] && (int) $booking['starts_at'] > time()
		&& hash_equals( management_key( $booking ), $key );
}

function management_denied(): WP_Error {
	return new WP_Error( 'wpb_manage_denied', '這個預約連結已失效，或這筆預約目前無法變更。請使用最新通知中的連結或聯絡店家。', array( 'status' => 403 ) );
}

function can_manage_action( array $booking, array $config, string $action, int $now ): bool {
	return empty( $booking['checked_in_at'] ) && ! empty( $config[ 'self_' . $action ] )
		&& in_array( $booking['status'], array( 'pending', 'confirmed' ), true )
		&& ! pending_expired( $booking, $now ) && (int) $booking['starts_at'] > $now
		&& (int) $booking['starts_at'] - $now >= $config[ $action . '_cutoff' ] * MINUTE_IN_SECONDS;
}

function may_reschedule( array $booking ): bool {
	$now = time();
	return empty( $booking['checked_in_at'] ) && in_array( $booking['status'], array( 'pending', 'confirmed' ), true )
		&& (int) $booking['starts_at'] > $now && ! pending_expired( $booking, $now );
}

function rescheduling_rules( array $booking ): array {
	$service = services()[ $booking['service_id'] ] ?? array();
	$service['buffer_before'] = $booking['buffer_before'];
	$service['buffer_after'] = $booking['buffer_after'];
	return service_schedule( settings(), $service ) + array( 'service_id' => $booking['service_id'] );
}

function reschedule_booking( int $id, int $start, int $revision, string $credential = '' ) {
	$result = mutate_booking( $id, static function ( array $booking ) use ( $start, $revision, $credential ) {
		$config = rescheduling_rules( $booking );
		$timezone = wp_timezone();
		$now = new DateTimeImmutable( 'now', $timezone );
		if ( '' !== $credential && ( ! valid_management_key( $booking, $credential )
			|| ! can_manage_action( $booking, $config, 'move', $now->getTimestamp() ) ) ) {
			return management_denied();
		}
		if ( ! may_reschedule( $booking ) || (int) $booking['revision'] !== $revision ) {
			return new WP_Error( 'wpb_manage_stale', '預約已變更或無法改期，請重新整理。', array( 'status' => 409 ) );
		}
		if ( $start === (int) $booking['starts_at'] ) {
			return new WP_Error( 'wpb_same_slot', '請選擇與原預約不同的時段。', array( 'status' => 400 ) );
		}
		$duration = intdiv( (int) $booking['ends_at'] - (int) $booking['starts_at'], MINUTE_IN_SECONDS );
		$day = calendar_date( $now->setTimestamp( $start )->format( 'Y-m-d' ), $timezone );
		if ( ! $day || ! isset( scheduled_slots( $day, $duration, $config, $now )[ $start ] ) ) {
			return unavailable();
		}
		$end = $start + $duration * MINUTE_IN_SECONDS;
		$busy = overlaps( $start - $config['buffer_before'] * 60, $end + $config['buffer_after'] * 60, (int) $booking['id'] );
		if ( is_wp_error( $busy ) ) {
			return $busy;
		}
		if ( remaining_capacity( $start - $config['buffer_before'] * 60, $end + $config['buffer_after'] * 60, $busy, $config, services() ) < required_capacity( $config, (int) $booking['party_size'] ) ) {
			return unavailable();
		}
		return array(
			'event'      => '' !== $credential ? 'customer_moved' : 'admin_moved',
			'starts_at'  => $start,
			'ends_at'    => $end,
			'timezone'   => $timezone->getName(),
			'hold_until' => ! empty( $booking['hold_until'] ) ? min( $start, (int) $booking['hold_until'] ) : 0,
		) + reminder_times( $booking['status'], $start, $now->getTimestamp(), $config );
	} );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	send_notice( $result['booking'], '' !== $credential ? booking_admin_email() : '', 'moved', $result['before'] );
	return $result['booking'];
}

function register_management_routes(): void {
	register_rest_route( API, '/manage', array(
		'methods'             => 'POST',
		'callback'            => __NAMESPACE__ . '\\guest_management_response',
		'permission_callback' => __NAMESPACE__ . '\\permit_public_booking',
	) );
	register_rest_route( API, '/admin-manage', array(
		'methods'             => 'POST',
		'callback'            => __NAMESPACE__ . '\\admin_management_response',
		'permission_callback' => static function (): bool {
			return can_manage_bookings();
		},
	) );
}

function guest_management_response( WP_REST_Request $request ) {
	global $wpdb;
	$payload = $request->get_json_params();
	$key = $payload['key'] ?? null;
	if ( ! is_string( $key ) || ! preg_match( '/^(WPB-[A-F0-9]{16})\.[a-f0-9]{64}$/D', $key, $parts ) ) {
		return management_denied();
	}
	$table = booking_table();
	$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", $parts[1] ), ARRAY_A );
	if ( ! $booking || ! valid_management_key( $booking, $key ) ) {
		return management_denied();
	}
	return management_response( $booking, $payload, $key );
}

function admin_management_response( WP_REST_Request $request ) {
	$allowed = permit_public_booking( $request );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	$payload = $request->get_json_params();
	$id = $payload['id'] ?? null;
	$booking = is_int( $id ) ? find_booking( $id ) : null;
	return $booking ? management_response( $booking, $payload ) : management_denied();
}

function management_response( array $booking, array $payload, string $key = '' ) {
	$action = $payload['action'] ?? null;
	$now = time();
	$config = rescheduling_rules( $booking );
	$move = '' === $key ? may_reschedule( $booking ) : can_manage_action( $booking, $config, 'move', $now );
	$cancel = '' !== $key && can_manage_action( $booking, $config, 'cancel', $now );
	$local_now = new DateTimeImmutable( 'now', wp_timezone() );
	$limits = calendar_limits( $config, $local_now );
	$duration = intdiv( (int) $booking['ends_at'] - (int) $booking['starts_at'], MINUTE_IN_SECONDS );
	if ( 'details' === $action ) {
		return array(
			'reference' => $booking['reference'],
			'service'   => $booking['service_name'],
			'time'      => booking_time( $booking ),
			'status'    => status_labels()[ pending_expired( $booking, $now ) ? 'expired' : $booking['status'] ],
			'revision'  => (int) $booking['revision'],
			'move'      => $move,
			'cancel'    => $cancel,
			'dates'     => $limits,
			'calendar'  => 'confirmed' === $booking['status'] ? array(
				'start' => (int) $booking['starts_at'],
				'end' => (int) $booking['ends_at'],
				'attendance' => booking_attendance( $booking ),
			) : null,
		);
	}
	if ( 'month' === $action && $move ) {
		$month = $payload['month'] ?? null;
		$first = is_string( $month ) ? calendar_date( $month . '-01', wp_timezone() ) : null;
		if ( ! $first || $month < substr( $limits['min'], 0, 7 ) || $month > substr( $limits['max'], 0, 7 ) ) {
			return new WP_Error( 'wpb_date', '請選擇可預約範圍內的月份。', array( 'status' => 400 ) );
		}
		return month_availability( $first, $duration, $config, $local_now, (int) $booking['id'], (int) $booking['starts_at'], (int) $booking['party_size'] );
	}
	if ( 'slots' === $action && $move ) {
		$date = $payload['date'] ?? null;
		$day = is_string( $date ) ? calendar_date( $date, wp_timezone() ) : null;
		if ( ! $day ) {
			return new WP_Error( 'wpb_date', '請選擇有效日期。', array( 'status' => 400 ) );
		}
		$available = slots_for_day( $day, $duration, $config, (int) $booking['id'], (int) $booking['party_size'] );
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		$available['slots'] = array_values( array_filter( $available['slots'], static function ( array $slot ) use ( $booking ): bool {
			return $slot['start'] !== (int) $booking['starts_at'];
		} ) );
		return $available;
	}
	if ( 'move' === $action && $move ) {
		$start = $payload['starts_at'] ?? null;
		$revision = $payload['revision'] ?? null;
		if ( ! is_int( $start ) || $start <= 0 || ! is_int( $revision ) ) {
			return new WP_Error( 'wpb_move_data', '請重新選擇日期與時段。', array( 'status' => 400 ) );
		}
		$result = reschedule_booking( (int) $booking['id'], $start, $revision, $key );
		return is_wp_error( $result ) ? $result : array( 'message' => '改期完成。', 'time' => booking_time( $result ) );
	}
	if ( 'cancel' === $action && $cancel ) {
		$result = change_status( (int) $booking['id'], 'cancelled', $key );
		return is_wp_error( $result ) ? $result : array( 'message' => '預約已取消。' );
	}
	return management_denied();
}

function serve_management_page(): void {
	if ( '1' !== ( $_GET['wpb_manage'] ?? null ) ) {
		return;
	}
	status_header( 200 );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Referrer-Policy: no-referrer', true );
	header( 'Content-Type: text/html; charset=UTF-8', true );
	header( "Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; base-uri 'none'; frame-ancestors 'none'; form-action 'none'", true );
	?>
	<!doctype html>
	<html lang="zh-Hant">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>管理預約</title>
		<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/booking.css', FILE ) ) ); ?>">
	</head>
	<body>
		<main class="wpb wpb-embedded wpb-management-page">
			<header class="wpb-header"><h2>管理預約</h2></header>
			<?php render_management_form(); ?>
			<?php powered_by(); ?>
		</main>
		<script src="<?php echo esc_url( add_query_arg( 'ver', VERSION, plugins_url( 'assets/management.js', FILE ) ) ); ?>" defer></script>
	</body>
	</html>
	<?php
	exit;
}

function render_management_form( int $id = 0 ): void {
	if ( $id ) {
		wp_enqueue_script( 'wp-booking-manage', plugins_url( 'assets/management.js', FILE ), array(), VERSION, true );
	}
	?>
	<div class="wpb-manage" data-wpb-manage="<?php echo esc_url( rest_url( API . ( $id ? '/admin-manage' : '/manage' ) ) ); ?>"
		<?php if ( $id ) : ?>data-wpb-id="<?php echo esc_attr( $id ); ?>" data-wpb-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"<?php endif; ?>>
		<p data-wpb-manage-message role="status"><?php echo $id ? '' : '載入預約資料…'; ?></p>
		<?php if ( ! $id ) : ?><noscript>請啟用 JavaScript 以管理預約。</noscript><?php endif; ?>
		<div data-wpb-manage-summary hidden></div>
		<p data-wpb-attendance hidden></p>
		<button type="button" class="wpb-button wpb-secondary" data-wpb-ics hidden>下載行事曆（ICS）</button>
		<form data-wpb-move hidden>
			<p data-wpb-original></p>
			<div class="wpb-manage-month">
				<button type="button" class="wpb-button wpb-secondary" data-wpb-prev>上個月</button>
				<strong data-wpb-month-label aria-live="polite"></strong>
				<button type="button" class="wpb-button wpb-secondary" data-wpb-next>下個月</button>
			</div>
			<div class="wpb-manage-days wpb-manage-week" aria-hidden="true"><?php foreach ( array( '日', '一', '二', '三', '四', '五', '六' ) as $label ) : ?><span><?php echo esc_html( $label ); ?></span><?php endforeach; ?></div>
			<div class="wpb-manage-days" data-wpb-days aria-label="可改期日期"></div>
			<input type="hidden" name="new_date">
			<input type="hidden" name="new_slot">
			<strong>新時段</strong>
			<div class="wpb-manage-slots" data-wpb-manage-slots role="group" aria-label="新時段">請先選日期</div>
			<p data-wpb-new-time hidden></p>
			<button type="submit" class="<?php echo $id ? 'wpb-admin-btn wpb-admin-primary' : 'wpb-button'; ?>" disabled>確認改期</button>
		</form>
		<div data-wpb-cancel hidden>
			<button type="button" class="wpb-button wpb-secondary" data-wpb-cancel-toggle>取消預約</button>
			<div data-wpb-cancel-confirm hidden>
				<p>確定取消這筆預約並釋出時段？</p>
				<button type="button" class="wpb-button wpb-secondary" data-wpb-cancel-back>返回</button>
				<button type="button" class="wpb-button" data-wpb-cancel-submit>確定取消</button>
			</div>
		</div>
	</div>
	<?php
}
