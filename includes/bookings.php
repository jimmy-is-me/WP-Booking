<?php

namespace Wumetax\Booking;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const SCHEMA_OPTION = 'wumetax_wpb_schema';
const SCHEMA_VERSION = 1;

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
		starts_at bigint(20) unsigned NOT NULL,
		ends_at bigint(20) unsigned NOT NULL,
		timezone varchar(64) NOT NULL,
		status varchar(16) NOT NULL,
		customer_name varchar(100) NOT NULL,
		customer_email varchar(254) NOT NULL,
		customer_phone varchar(40) NOT NULL DEFAULT '',
		note text NOT NULL,
		created_at bigint(20) unsigned NOT NULL,
		updated_at bigint(20) unsigned NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY reference (reference),
		UNIQUE KEY request_id (request_id),
		KEY calendar (starts_at,status,ends_at),
		KEY customer_email (customer_email(191))
	) {$charset};" );

	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
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

function overlaps( int $start, int $end ) {
	global $wpdb;

	$table = booking_table();
	// Service durations are capped at eight hours. Use a bounded index range.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT starts_at, ends_at FROM {$table}
		WHERE starts_at >= %d AND starts_at < %d AND ends_at > %d AND status IN ('pending', 'confirmed', 'completed')",
		$start - 8 * HOUR_IN_SECONDS,
		$end,
		$start
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

function book( array $input, string $rate_key ) {
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
		if ( ! $day || ! isset( scheduled_slots( $day, $duration, $config, $now )[ $start ] ) ) {
			return unavailable();
		}

		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 8 ) {
			return new WP_Error( 'wpb_rate_limit', '送出次數過多，請於 15 分鐘後再試。', array( 'status' => 429 ) );
		}
		set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );

		$end = $start + $duration * MINUTE_IN_SECONDS;
		$overlap = overlaps( $start, $end );
		if ( is_wp_error( $overlap ) ) {
			return $overlap;
		}
		if ( $overlap ) {
			return unavailable();
		}

		$booking = array(
			'reference'      => 'WPB-' . strtoupper( bin2hex( random_bytes( 8 ) ) ),
			'request_id'     => $input['request_id'],
			'payload_hash'   => $hash,
			'service_id'     => $input['service_id'],
			'service_name'   => $service['name'],
			'starts_at'      => $start,
			'ends_at'        => $end,
			'timezone'       => $timezone->getName(),
			'status'         => $config['auto_confirm'] ? 'confirmed' : 'pending',
			'customer_name'  => $input['customer_name'],
			'customer_email' => $input['customer_email'],
			'customer_phone' => $input['customer_phone'],
			'note'           => $input['note'],
			'created_at'     => $now->getTimestamp(),
			'updated_at'     => $now->getTimestamp(),
		);

		// wpdb can reconnect and retry a statement. The new connection must not write without the lock.
		$written = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table}
			(reference, request_id, payload_hash, service_id, service_name, starts_at, ends_at, timezone,
			status, customer_name, customer_email, customer_phone, note, created_at, updated_at)
			SELECT %s, %s, %s, %s, %s, %d, %d, %s, %s, %s, %s, %s, %s, %d, %d
			WHERE IS_USED_LOCK(%s) = CONNECTION_ID()",
			array_merge( array_values( $booking ), array( $lock ) )
		) );
		if ( 1 !== $written ) {
			return storage_error();
		}

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
	if ( 'pending' === $booking['status'] ) {
		return array( 'confirmed', 'cancelled' );
	}
	if ( 'confirmed' === $booking['status'] ) {
		return time() >= (int) $booking['ends_at'] ? array( 'completed', 'cancelled' ) : array( 'cancelled' );
	}
	return array();
}

function change_status( int $id, string $next ) {
	$result = with_calendar_lock( static function ( string $lock ) use ( $id, $next ) {
		global $wpdb;
		$table = booking_table();
		$booking = find_booking( $id );
		if ( $wpdb->last_error ) {
			return storage_error();
		}
		if ( ! $booking || ! in_array( $next, allowed_statuses( $booking ), true ) ) {
			return new WP_Error( 'wpb_status', '此預約狀態無法變更，請重新整理後確認。' );
		}

		$written = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = %s, updated_at = %d
			WHERE id = %d AND status = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()",
			$next,
			time(),
			$id,
			$booking['status'],
			$lock
		) );
		if ( 1 !== $written ) {
			return storage_error();
		}
		$booking['status'] = $next;
		return $booking;
	} );

	if ( ! is_wp_error( $result ) && 'completed' !== $next ) {
		send_notice( $result );
	}
	return $result;
}

function booking_time( array $booking ): string {
	$timezone = new DateTimeZone( $booking['timezone'] );
	return wp_date( 'Y/m/d H:i', (int) $booking['starts_at'], $timezone ) . '–'
		. wp_date( 'H:i', (int) $booking['ends_at'], $timezone )
		. ' (UTC' . wp_date( 'P', (int) $booking['starts_at'], $timezone ) . ')';
}

function send_notice( array $booking, string $admin_email = '' ): void {
	$status = status_labels()[ $booking['status'] ];
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$summary = "預約編號：{$booking['reference']}\n服務：{$booking['service_name']}\n時段：" . booking_time( $booking ) . "\n狀態：{$status}";

	if ( is_email( $booking['customer_email'] ) ) {
		$message = "您好，{$booking['customer_name']}：\n\n{$summary}";
		if ( 'pending' === $booking['status'] ) {
			$message .= "\n\n此預約已送出，請等候確認通知。";
		}
		wp_mail( $booking['customer_email'], "[{$site}] 預約{$status} {$booking['reference']}", $message );
	}
	if ( is_email( $admin_email ) ) {
		$admin_link = add_query_arg( array( 'page' => 'wp-booking', 'q' => $booking['reference'] ), admin_url( 'admin.php' ) );
		wp_mail( $admin_email, "[{$site}] 新預約 {$booking['reference']}", $summary . "\n\n管理預約：{$admin_link}" );
	}
}
