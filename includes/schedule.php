<?php

namespace Wumetax\Booking;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const STEP_MINUTES = 15;
const MAX_BUFFER_MINUTES = 120;
const SETTINGS_OPTION = 'wumetax_wpb_settings';
const SERVICES_OPTION = 'wumetax_wpb_services';

function settings(): array {
	$workday = array( array( 540, 720 ), array( 780, 1080 ) );
	$defaults = array(
		'week'          => array( array(), $workday, $workday, $workday, $workday, $workday, array() ),
		'closed'        => array(),
		'blocked_hours' => array(),
		'lead_minutes'  => 120,
		'horizon_days'  => 30,
		'auto_confirm'  => false,
		'notify_email'  => '',
		'date_hours'    => array(),
		'cancel_cutoff' => 120,
		'move_cutoff'   => 120,
		'self_cancel'   => true,
		'self_move'     => true,
		'hold_minutes'  => 0,
		'remind_day'    => false,
		'remind_soon'   => false,
	);

	$saved = get_option( SETTINGS_OPTION, array() );
	return array_replace( $defaults, is_array( $saved ) ? $saved : array() );
}

function services(): array {
	$saved = get_option( SERVICES_OPTION, array() );
	return is_array( $saved ) ? $saved : array();
}

function calendar_date( string $date, DateTimeZone $timezone ): ?DateTimeImmutable {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ) {
		return null;
	}
	$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
	return $day && $day->format( 'Y-m-d' ) === $date ? $day : null;
}

function clock_minutes( string $time ): ?int {
	if ( '24:00' === $time ) {
		return 1440;
	}
	if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/D', $time, $parts ) ) {
		return null;
	}
	return (int) $parts[1] * 60 + (int) $parts[2];
}

function clock_label( int $minutes ): string {
	return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
}

/** Accept time ranges separated by commas or lines, in local wall-clock time. */
function parse_hours( string $value ) {
	$lines = preg_split( '/[,，\r\n]+/u', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
	if ( false === $lines ) {
		return new WP_Error( 'invalid_hours', '營業時間包含無法讀取的字元。' );
	}
	$ranges = array();
	foreach ( $lines as $line ) {
		$bounds = preg_split( '/\s*[-–]\s*/u', trim( $line ) );
		if ( 2 !== count( $bounds ) ) {
			return new WP_Error( 'invalid_hours', '請用 HH:MM-HH:MM 輸入營業時段，多段以逗號分隔。' );
		}
		$start = clock_minutes( $bounds[0] );
		$end = clock_minutes( $bounds[1] );
		if ( null === $start || null === $end || $start >= $end || $start % STEP_MINUTES || $end % STEP_MINUTES ) {
			return new WP_Error( 'invalid_hours', '每段營業時間需使用 15 分鐘刻度，結束時間須晚於開始。' );
		}
		$ranges[] = array( $start, $end );
	}
	sort( $ranges );
	$merged = array();
	foreach ( $ranges as $range ) {
		$last = count( $merged ) - 1;
		if ( $last >= 0 && $range[0] <= $merged[ $last ][1] ) {
			$merged[ $last ][1] = max( $range[1], $merged[ $last ][1] );
		} else {
			$merged[] = $range;
		}
	}
	return $merged;
}

function calendar_limits( array $config, DateTimeImmutable $now ): array {
	return array(
		'min' => $now->format( 'Y-m-d' ),
		'max' => $now->modify( '+' . (int) $config['horizon_days'] . ' days' )->format( 'Y-m-d' ),
	);
}

function service_schedule( array $config, array $service ): array {
	$config['capacity'] = (int) ( $service['capacity'] ?? 1 );
	$config['capacity_unit'] = $service['capacity_unit'] ?? 'bookings';
	$config['calendar_mode'] = $service['calendar_mode'] ?? 'shared';
	$config['lead_minutes'] = $service['lead_minutes'] ?? $config['lead_minutes'];
	$config['horizon_days'] = $service['horizon_days'] ?? $config['horizon_days'];
	$config['buffer_before'] = (int) ( $service['buffer_before'] ?? 0 );
	$config['buffer_after'] = (int) ( $service['buffer_after'] ?? 0 );
	return $config;
}

function opening_hours( DateTimeImmutable $day, array $config ): array {
	$date = $day->format( 'Y-m-d' );
	if ( in_array( $date, $config['closed'], true ) ) {
		return array();
	}
	$hours = $config['date_hours'][ $date ] ?? $config['week'][ (int) $day->format( 'w' ) ] ?? array();
	foreach ( $config['blocked_hours'][ $date ] ?? array() as list( $blocked_start, $blocked_end ) ) {
		$remaining = array();
		foreach ( $hours as list( $start, $end ) ) {
			if ( $blocked_end <= $start || $blocked_start >= $end ) {
				$remaining[] = array( $start, $end );
				continue;
			}
			if ( $start < $blocked_start ) {
				$remaining[] = array( $start, $blocked_start );
			}
			if ( $blocked_end < $end ) {
				$remaining[] = array( $blocked_end, $end );
			}
		}
		$hours = $remaining;
	}
	return $hours;
}

/** Return start timestamps and labels, each covering a complete service. */
function scheduled_slots( DateTimeImmutable $day, int $duration, array $config, DateTimeImmutable $now ): array {
	$date = $day->format( 'Y-m-d' );
	$limits = calendar_limits( $config, $now );
	$hours = opening_hours( $day, $config );
	if ( $date < $limits['min'] || $date > $limits['max'] || ! $hours ) {
		return array();
	}

	$start = $day->getTimestamp();
	$day_end = $day->modify( 'tomorrow' )->getTimestamp();
	$earliest = max( $now->getTimestamp() + 1, $now->getTimestamp() + (int) $config['lead_minutes'] * 60 );
	$before = $config['buffer_before'] ?? 0;
	$after = $config['buffer_after'] ?? 0;
	$slots = array();
	$labels = array();

	for ( $timestamp = $start + $before * 60; $timestamp + ( $duration + $after ) * 60 <= $day_end; $timestamp += STEP_MINUTES * 60 ) {
		if ( $timestamp < $earliest ) {
			continue;
		}
		$local = $day->setTimestamp( $timestamp );
		$first = $day->setTimestamp( $timestamp - $before * 60 );
		$last = $day->setTimestamp( $timestamp + ( $duration + $after ) * 60 - 1 );
		// Avoid an appointment spanning a clock change. Repeated starts get an offset label.
		if ( $local->getOffset() !== $first->getOffset() || $local->getOffset() !== $last->getOffset() ) {
			continue;
		}
		$minute = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
		foreach ( $hours as list( $opens, $closes ) ) {
			if ( $minute - $before >= $opens && $minute + $duration + $after <= $closes ) {
				$label = $local->format( 'H:i' );
				$slots[ $timestamp ] = $label;
				$labels[ $label ] = ( $labels[ $label ] ?? 0 ) + 1;
				break;
			}
		}
	}

	foreach ( $slots as $timestamp => $label ) {
		if ( $labels[ $label ] > 1 ) {
			$slots[ $timestamp ] .= ' (UTC' . $day->setTimestamp( $timestamp )->format( 'P' ) . ')';
		}
	}
	return $slots;
}

function status_labels(): array {
	return array(
		'pending'   => '待確認',
		'confirmed' => '已確認',
		'completed' => '已完成',
		'cancelled' => '已取消',
		'expired'   => '待確認逾時',
		'no_show'   => '未到場',
	);
}
