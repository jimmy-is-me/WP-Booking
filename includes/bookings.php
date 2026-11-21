<?php

namespace Wumetax\Booking;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const SCHEMA_OPTION = 'wumetax_wpb_schema';
const SCHEMA_VERSION = 3;

function booking_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'wumetax_bookings';
}

function maybe_install_schema(): void {
	if ( SCHEMA_VERSION !== (int) get_option( SCHEMA_OPTION ) ) {
		install_schema();
	}
}

function install_schema(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = booking_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		reference varchar(24) NOT NULL,
		request_id char(32) NOT NULL,
		payload_hash char(64) NOT NULL,
		service_id char(32) NOT NULL,
		service_name varchar(120) NOT NULL,
		price int(10) unsigned DEFAULT NULL,
		starts_at bigint(20) unsigned NOT NULL,
		ends_at bigint(20) unsigned NOT NULL,
		timezone varchar(64) NOT NULL,
		status varchar(16) NOT NULL,
		customer_name varchar(100) NOT NULL,
		customer_email varchar(254) NOT NULL,
		customer_phone varchar(40) NOT NULL DEFAULT '',
		note text NOT NULL,
		admin_note text,
		buffer_before smallint(5) unsigned NOT NULL DEFAULT 0,
		buffer_after smallint(5) unsigned NOT NULL DEFAULT 0,
		hold_until bigint(20) unsigned NOT NULL DEFAULT 0,
		remind_day bigint(20) unsigned NOT NULL DEFAULT 0,
		remind_soon bigint(20) unsigned NOT NULL DEFAULT 0,
		revision int(10) unsigned NOT NULL DEFAULT 0,
		history longtext,
		created_at bigint(20) unsigned NOT NULL,
		updated_at bigint(20) unsigned NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY reference (reference),
		UNIQUE KEY request_id (request_id),
		KEY calendar (starts_at,status,ends_at),
		KEY status_start (status,starts_at),
		KEY pending_hold (status,hold_until),
		KEY reminder_day (status,remind_day),
		KEY reminder_soon (status,remind_soon),
		KEY customer_email (customer_email(191))
	) {$charset};" );

	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
	if ( ! array_diff( array( 'price', 'admin_note', 'buffer_before', 'buffer_after', 'hold_until', 'remind_day', 'remind_soon', 'revision', 'history' ), $columns ) && install_notice_table( $charset ) ) {
		update_option( SCHEMA_OPTION, SCHEMA_VERSION, true );
	}
}

function storage_error(): WP_Error {
	return new WP_Error( 'wpb_storage', '目前無法處理預約，請稍後再試。', array( 'status' => 503 ) );
}

/** MySQL and MariaDB advisory locks serialize writes to the shared calendar. */
function with_calendar_lock( callable $callback ) {
	global $wpdb;

	$lock = 'wpb:' . substr( hash( 'sha256', DB_NAME . ':' . booking_table() ), 0, 56 );
	if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
		return storage_error();
	}

	try {
		return $callback( $lock );
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

function overlaps( int $start, int $end, int $exclude = 0 ) {
	global $wpdb;

	$table = booking_table();
	$buffer = MAX_BUFFER_MINUTES * MINUTE_IN_SECONDS;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT starts_at - buffer_before * 60 AS starts_at, ends_at + buffer_after * 60 AS ends_at FROM {$table}
		WHERE starts_at >= %d AND starts_at < %d AND ends_at + buffer_after * 60 > %d
		AND starts_at - buffer_before * 60 < %d AND id <> %d
		AND (status IN ('confirmed', 'completed', 'no_show') OR (status = 'pending' AND (hold_until = 0 OR hold_until > %d)))
		ORDER BY 1 ASC",
		$start - 8 * HOUR_IN_SECONDS - $buffer,
		$end + $buffer,
		$start,
		$end,
		$exclude,
		time()
	), ARRAY_A );

	return $wpdb->last_error ? storage_error() : $rows;
}

function unavailable(): WP_Error {
	return new WP_Error( 'wpb_unavailable', '這個時段目前無法預約，請重新選擇。', array( 'status' => 409 ) );
}

function find_booking( int $id ): ?array {
	global $wpdb;
	$table = booking_table();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
}

function pending_expired( array $booking, int $now ): bool {
	return 'pending' === $booking['status'] && ! empty( $booking['hold_until'] ) && (int) $booking['hold_until'] <= $now;
}

function book( array $input, string $rate_key = '' ) {
	$result = with_calendar_lock( static function ( string $lock ) use ( $input, $rate_key ) {
		global $wpdb;
		$table = booking_table();
		$hash = hash( 'sha256', wp_json_encode( $input ) );
		$previous = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE request_id = %s",
			$input['request_id']
		), ARRAY_A );
		if ( $wpdb->last_error ) {
			return storage_error();
		}
		if ( $previous ) {
			if ( ! hash_equals( $previous['payload_hash'], $hash ) ) {
				return new WP_Error( 'wpb_request_mismatch', '預約資料已變更，請重新選擇時段後送出。', array( 'status' => 409 ) );
			}
			return array( 'booking' => $previous, 'created' => false );
		}

		$config = settings();
		$service = services()[ $input['service_id'] ] ?? null;
		if ( ! $service || ! $service['enabled'] ) {
			return unavailable();
		}

		$timezone = wp_timezone();
		$now = new DateTimeImmutable( 'now', $timezone );
		$start = $input['starts_at'];
		$day = calendar_date( $now->setTimestamp( $start )->format( 'Y-m-d' ), $timezone );
		$duration = (int) $service['duration'];
		$price = isset( $service['price'] ) ? (int) $service['price'] : null;
		if ( isset( $input['expected_duration'] ) && ( $duration !== $input['expected_duration'] || $price !== $input['expected_price'] ) ) {
			return new WP_Error( 'wpb_service_changed', '服務費用或時間已變更，請重新選擇服務。', array( 'status' => 409 ) );
		}
		$config = service_schedule( $config, $service );
		if ( ! $day || ! isset( scheduled_slots( $day, $duration, $config, $now )[ $start ] ) ) {
			return unavailable();
		}

		if ( '' !== $rate_key ) {
			$attempts = (int) get_transient( $rate_key );
			if ( $attempts >= 8 ) {
				return new WP_Error( 'wpb_rate_limit', '送出次數過多，請於 15 分鐘後再試。', array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
		}

		$end = $start + $duration * MINUTE_IN_SECONDS;
		$overlap = overlaps( $start - $config['buffer_before'] * 60, $end + $config['buffer_after'] * 60 );
		if ( is_wp_error( $overlap ) ) {
			return $overlap;
		}
		if ( $overlap ) {
			return unavailable();
		}

		$created = $now->getTimestamp();
		$status = $config['auto_confirm'] ? 'confirmed' : 'pending';
		$reminders = reminder_times( $status, $start, $created, $config );
		$booking = array(
			'reference'      => 'WPB-' . strtoupper( bin2hex( random_bytes( 8 ) ) ),
			'request_id'     => $input['request_id'],
			'payload_hash'   => $hash,
			'service_id'     => $input['service_id'],
			'service_name'   => $service['name'],
			'price'          => $price,
			'starts_at'      => $start,
			'ends_at'        => $end,
			'timezone'       => $timezone->getName(),
			'status'         => $status,
			'customer_name'  => $input['customer_name'],
			'customer_email' => $input['customer_email'],
			'customer_phone' => $input['customer_phone'],
			'note'           => $input['note'],
			'buffer_before'  => $config['buffer_before'],
			'buffer_after'   => $config['buffer_after'],
			'hold_until'     => 'pending' === $status && $config['hold_minutes'] ? min( $start, $created + $config['hold_minutes'] * 60 ) : 0,
			'remind_day'     => $reminders['remind_day'],
			'remind_soon'    => $reminders['remind_soon'],
			'created_at'     => $created,
			'updated_at'     => $created,
		);

		// wpdb can reconnect and retry a statement. The new connection must not write without the lock.
		$values = array_replace( $booking, array( 'price' => $booking['price'] ?? -1 ) );
		$written = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table}
			(reference, request_id, payload_hash, service_id, service_name, price, starts_at, ends_at, timezone,
			status, customer_name, customer_email, customer_phone, note, buffer_before, buffer_after, hold_until,
			remind_day, remind_soon, created_at, updated_at)
			SELECT %s, %s, %s, %s, %s, NULLIF(%d, -1), %d, %d, %s, %s, %s, %s, %s, %s, %d, %d, %d, %d, %d, %d, %d
			WHERE IS_USED_LOCK(%s) = CONNECTION_ID()",
			array_merge( array_values( $values ), array( $lock ) )
		) );
		if ( 1 !== $written ) {
			return storage_error();
		}
		$booking['id'] = (int) $wpdb->insert_id;
		$booking['revision'] = 0;

		return array(
			'booking' => $booking,
			'created' => true,
			'notify'  => $config['notify_email'] ?: get_option( 'admin_email' ),
		);
	} );

	if ( ! is_wp_error( $result ) && $result['created'] ) {
		send_notice( $result['booking'], $result['notify'] );
	}
	return $result;
}

function allowed_statuses( array $booking ): array {
	if ( pending_expired( $booking, time() ) ) {
		return array();
	}
	if ( 'pending' === $booking['status'] ) {
		return array( 'confirmed', 'cancelled' );
	}
	if ( 'confirmed' === $booking['status'] ) {
		return time() >= (int) $booking['ends_at'] ? array( 'completed', 'no_show', 'cancelled' ) : array( 'cancelled' );
	}
	return array();
}

/** Validate and update one booking while other booking writes are serialized. */
function mutate_booking( int $id, callable $calculate ) {
	return with_calendar_lock( static function ( string $lock ) use ( $id, $calculate ) {
		global $wpdb;
		$table = booking_table();
		$booking = find_booking( $id );
		if ( $wpdb->last_error ) {
			return storage_error();
		}
		if ( ! $booking ) {
			return new WP_Error( 'wpb_status', '找不到這筆預約。', array( 'status' => 404 ) );
		}
		$changes = $calculate( $booking );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$event = $changes['event'];
		unset( $changes['event'] );
		$history = booking_history( $booking );
		$history[] = array( 'kind' => $event, 'at' => time(), 'previous_start' => (int) $booking['starts_at'], 'previous_end' => (int) $booking['ends_at'], 'timezone' => $booking['timezone'] );
		$changes['history'] = wp_json_encode( array_slice( $history, -50 ) );
		$changes['updated_at'] = time();
		$changes['revision'] = (int) $booking['revision'] + 1;
		$assignments = array();
		foreach ( $changes as $field => $value ) {
			$assignments[] = $field . ' = ' . ( is_int( $value ) ? '%d' : '%s' );
		}
		$written = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET " . implode( ', ', $assignments ) . '
			WHERE id = %d AND revision = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()',
			array_merge( array_values( $changes ), array( $id, (int) $booking['revision'], $lock ) )
		) );
		if ( 1 !== $written ) {
			return storage_error();
		}
		return array( 'before' => $booking, 'booking' => array_replace( $booking, $changes ) );
	} );
}

function booking_history( array $booking ): array {
	$history = json_decode( $booking['history'] ?? '', true );
	return is_array( $history ) ? $history : array();
}

function change_status( int $id, string $next, string $credential = '' ) {
	$result = mutate_booking( $id, static function ( array $booking ) use ( $next, $credential ) {
		$now = time();
		$active = allowed_statuses( $booking );
		$config = settings();
		if ( '' !== $credential && ( 'cancelled' !== $next || ! valid_management_key( $booking, $credential )
			|| ! can_manage_action( $booking, $config, 'cancel', $now ) ) ) {
			return management_denied();
		}
		if ( 'expired' === $next ? ! pending_expired( $booking, $now ) : ! in_array( $next, $active, true ) ) {
			return new WP_Error( 'wpb_status', '此預約狀態無法變更，請重新整理後確認。', array( 'status' => 409 ) );
		}
		return array( 'event' => $next, 'status' => $next, 'hold_until' => 0 )
			+ reminder_times( $next, (int) $booking['starts_at'], $now, $config );
	} );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( in_array( $next, array( 'confirmed', 'cancelled', 'expired' ), true ) ) {
		send_notice( $result['booking'], 'cancelled' === $next && '' !== $credential ? booking_admin_email() : '', $next );
	}
	return $result['booking'];
}

function booking_time( array $booking ): string {
	$timezone = new DateTimeZone( $booking['timezone'] );
	return wp_date( 'Y/m/d H:i', (int) $booking['starts_at'], $timezone ) . '–'
		. wp_date( 'H:i', (int) $booking['ends_at'], $timezone )
		. ' (UTC' . wp_date( 'P', (int) $booking['starts_at'], $timezone ) . ')';
}

function booking_price( $price ): string {
	return null === $price ? '未設定' : 'NT$ ' . number_format_i18n( (int) $price );
}

