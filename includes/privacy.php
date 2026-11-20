<?php

namespace Wumetax\Booking;

defined( 'ABSPATH' ) || exit;

const PRIVACY_BATCH = 50;

function register_privacy_policy(): void {
	wp_add_privacy_policy_content( 'WP Booking', '<p>使用預約表單時，本網站會收集您的姓名、電子郵件，以及您自願提供的電話與備註，用於處理預約及寄送相關通知。預約紀錄由網站管理員管理。系統會用連線 IP 的雜湊值短暫限制重複送出。</p><p>您可向網站管理員申請匯出或清除個人資料。清除資料後，系統仍會保留匿名的預約時段及狀態供排程管理。</p>' );
}

function register_exporter( array $exporters ): array {
	$exporters['wp-booking'] = array(
		'exporter_friendly_name' => 'WP Booking 預約紀錄',
		'callback'              => __NAMESPACE__ . '\\export_personal_data',
	);
	return $exporters;
}

function register_eraser( array $erasers ): array {
	$erasers['wp-booking'] = array(
		'eraser_friendly_name' => 'WP Booking 預約紀錄',
		'callback'             => __NAMESPACE__ . '\\erase_personal_data',
	);
	return $erasers;
}

function personal_rows( string $email, int $offset = 0 ): array {
	global $wpdb;
	$table = booking_table();
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$table} WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
		$email,
		PRIVACY_BATCH,
		$offset
	), ARRAY_A );
}

function export_personal_data( string $email, int $page = 1 ): array {
	$rows = personal_rows( $email, ( max( 1, $page ) - 1 ) * PRIVACY_BATCH );
	$data = array();
	foreach ( $rows as $row ) {
		$data[] = array(
			'group_id'    => 'wp-booking',
			'group_label' => '預約紀錄',
			'item_id'     => 'wp-booking-' . $row['id'],
			'data'        => array(
				array( 'name' => '預約編號', 'value' => $row['reference'] ),
				array( 'name' => '預約服務', 'value' => $row['service_name'] ),
				array( 'name' => '預約時段', 'value' => booking_time( $row ) ),
				array( 'name' => '預約狀態', 'value' => status_labels()[ $row['status'] ] ),
				array( 'name' => '服務費用', 'value' => booking_price( $row['price'] ?? null ) ),
				array( 'name' => '姓名', 'value' => $row['customer_name'] ),
				array( 'name' => '電子郵件', 'value' => $row['customer_email'] ),
				array( 'name' => '電話', 'value' => $row['customer_phone'] ),
				array( 'name' => '備註', 'value' => $row['note'] ),
				array( 'name' => '管理備註', 'value' => $row['admin_note'] ?? '' ),
			),
		);
	}
	return array( 'data' => $data, 'done' => count( $rows ) < PRIVACY_BATCH );
}

function erase_personal_data( string $email ): array {
	global $wpdb;

	$rows = personal_rows( $email );
	$result = array(
		'items_removed'  => false,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => count( $rows ) < PRIVACY_BATCH,
	);
	if ( $wpdb->last_error ) {
		$result['items_retained'] = true;
	}
	foreach ( $rows as $row ) {
		$updated = $wpdb->update( booking_table(), array(
			'customer_name'  => '',
			'customer_email' => '',
			'customer_phone' => '',
			'note'           => '',
			'admin_note'     => null,
			'payload_hash'   => '',
			'updated_at'     => time(),
		), array( 'id' => (int) $row['id'], 'customer_email' => $email ) );
		$result['items_removed'] = $result['items_removed'] || 1 === $updated;
		$result['items_retained'] = $result['items_retained'] || false === $updated;
	}
	if ( $result['items_retained'] ) {
		$result['messages'][] = '有預約個資未能清除，請稍後重試。';
		$result['done'] = true;
	}
	return $result;
}
