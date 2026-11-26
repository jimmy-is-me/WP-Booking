<?php

namespace Wumetax\Booking;

defined( 'ABSPATH' ) || exit;

const CRON_HOOK = 'wumetax_wpb_process_bookings';

function notice_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'wumetax_booking_notices';
}

function install_notice_table( string $charset ): bool {
	global $wpdb;
	$table = notice_table();
	dbDelta( "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		booking_id bigint(20) unsigned NOT NULL,
		revision int(10) unsigned NOT NULL,
		kind varchar(20) NOT NULL,
		recipient varchar(10) NOT NULL,
		due_at bigint(20) unsigned NOT NULL DEFAULT 0,
		sent_at bigint(20) unsigned NOT NULL DEFAULT 0,
		state varchar(12) NOT NULL,
		detail varchar(100) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		UNIQUE KEY booking_event (booking_id,revision,kind,recipient)
	) {$charset};" );
	return in_array( 'state', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ), true );
}

function booking_admin_email(): string {
	return (string) ( settings()['notify_email'] ?: get_option( 'admin_email' ) );
}

function reminder_times( string $status, int $start, int $now, array $config ): array {
	$times = array( 'remind_day' => 0, 'remind_soon' => 0 );
	foreach ( array( 'remind_day' => 24, 'remind_soon' => 2 ) as $name => $hours ) {
		$due = $start - $hours * HOUR_IN_SECONDS;
		if ( 'confirmed' === $status && ! empty( $config[ $name ] ) && $due >= $now ) {
			$times[ $name ] = $due;
		}
	}
	return $times;
}

function booking_cron_intervals( array $schedules ): array {
	$schedules['wpb_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'WP Booking 每五分鐘' );
	return $schedules;
}

function ensure_booking_cron(): void {
	if ( ! wp_next_scheduled( CRON_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wpb_five_minutes', CRON_HOOK );
	}
}

function stop_booking_cron(): void {
	wp_clear_scheduled_hook( CRON_HOOK );
}

function notice_types(): array {
	return array(
		'resent'       => '最新預約通知',
		'created'      => '新預約',
		'confirmed'    => '預約確認',
		'cancelled'    => '預約取消',
		'expired'      => '待確認逾時',
		'moved'        => '預約改期',
		'remind_day'   => '24 小時提醒',
		'remind_soon'  => '2 小時提醒',
	);
}

function booking_attendance( array $booking ): string {
	return (string) ( services()[ $booking['service_id'] ]['attendance'] ?? '' );
}

function send_notice( array $booking, string $admin_email = '', string $kind = 'created', array $before = array(), int $due = 0 ): bool {
	$status = status_labels()[ $booking['status'] ];
	$type = notice_types()[ $kind ];
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$summary = "預約編號：{$booking['reference']}\n服務：{$booking['service_name']}\n時段：" . booking_time( $booking ) . "\n狀態：{$status}";
	if ( null !== ( $booking['price'] ?? null ) ) {
		$summary .= "\n服務費用：" . booking_price( $booking['price'] );
	}
	if ( $before ) {
		$summary .= "\n原時段：" . booking_time( $before );
	}
	if ( 'expired' === $kind ) {
		$summary .= "\n這筆預約未在期限內確認，時段已重新開放。";
	}

	$recorded = true;
	if ( is_email( $booking['customer_email'] ) ) {
		$message = "您好，{$booking['customer_name']}：\n\n{$summary}";
		if ( in_array( $kind, array( 'created', 'resent' ), true ) && 'pending' === $booking['status'] ) {
			$message .= "\n\n此預約已送出，請等候確認通知。";
			if ( ! empty( $booking['hold_until'] ) ) {
				$message .= "\n確認期限：" . wp_date( 'Y/m/d H:i', (int) $booking['hold_until'], new \DateTimeZone( $booking['timezone'] ) );
			}
		}
		if ( 'confirmed' === $booking['status'] ) {
			$attendance = booking_attendance( $booking );
			if ( '' !== $attendance ) {
				$message .= "\n\n出席資訊：" . $attendance;
			}
		}
		if ( in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) && (int) $booking['starts_at'] > time() ) {
			$message .= "\n\n管理預約與下載行事曆：" . management_link( $booking );
		}
		$recorded = deliver_notice( $booking, $kind, 'customer', $booking['customer_email'], "[{$site}] {$type} {$booking['reference']}", $message, $due );
	}
	if ( is_email( $admin_email ) ) {
		$link = admin_url_for( array( 'q' => $booking['reference'] ) );
		$recorded = deliver_notice( $booking, $kind, 'admin', $admin_email, "[{$site}] {$type} {$booking['reference']}", $summary . "\n\n管理預約：{$link}" ) && $recorded;
	}
	return $recorded;
}

function deliver_notice( array $booking, string $kind, string $recipient, string $email, string $subject, string $body, int $due = 0 ): bool {
	global $wpdb;
	$table = notice_table();
	$claimed = $wpdb->query( $wpdb->prepare(
		"INSERT IGNORE INTO {$table} (booking_id, revision, kind, recipient, due_at, sent_at, state) VALUES (%d, %d, %s, %s, %d, %d, 'sending')",
		$booking['id'], $booking['revision'], $kind, $recipient, $due, time()
	) );
	if ( 0 === $claimed && 'resent' === $kind ) {
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET state = 'sending', sent_at = %d, detail = ''
			 WHERE booking_id = %d AND revision = %d AND kind = %s AND recipient = %s AND sent_at <= %d",
			time(), $booking['id'], $booking['revision'], $kind, $recipient, time() - MINUTE_IN_SECONDS
		) );
	}
	if ( 1 !== $claimed ) {
		// A duplicate has already been claimed. Retry only if the log write itself failed.
		return 'resent' !== $kind && 0 === $claimed;
	}
	$row_key = array( 'booking_id' => $booking['id'], 'revision' => $booking['revision'], 'kind' => $kind, 'recipient' => $recipient );
	$latest = find_booking( (int) $booking['id'] );
	if ( ! $latest || (int) $latest['revision'] !== (int) $booking['revision']
		|| ( 'customer' === $recipient && $latest['customer_email'] !== $email )
		|| ( $due && (int) $latest['starts_at'] <= time() ) ) {
		$wpdb->update( $table, array( 'state' => 'skipped', 'detail' => '預約已變更或提醒已過期。', 'sent_at' => time() ), $row_key );
		return 'resent' !== $kind;
	}
	$error_code = '';
	$capture = static function ( \WP_Error $error ) use ( &$error_code ): void {
		$error_code = substr( sanitize_key( $error->get_error_code() ), 0, 60 );
	};
	add_action( 'wp_mail_failed', $capture );
	try {
		$accepted = wp_mail( $email, $subject, $body );
	} catch ( \Throwable $error ) {
		$accepted = false;
		$error_code = 'mail_exception';
	} finally {
		remove_action( 'wp_mail_failed', $capture );
	}
	$detail = $accepted ? '' : ( $error_code ?: 'wp_mail_failed' );
	$wpdb->update( $table, array( 'state' => $accepted ? 'accepted' : 'failed', 'detail' => $detail, 'sent_at' => time() ), $row_key );
	return 'resent' !== $kind || $accepted;
}

function run_booking_cron(): void {
	global $wpdb;
	if ( SCHEMA_VERSION !== (int) get_option( SCHEMA_OPTION ) ) {
		return;
	}
	$table = booking_table();
	$now = time();
	$expired = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE status = 'pending' AND hold_until > 0 AND hold_until <= %d ORDER BY hold_until LIMIT 50", $now
	) );
	foreach ( $expired as $id ) {
		change_status( (int) $id, 'expired' );
	}
	foreach ( array( 'remind_day', 'remind_soon' ) as $field ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, reference, revision, status, service_id, service_name, starts_at, ends_at, timezone, price, customer_name, customer_email, {$field}
			FROM {$table} WHERE status = 'confirmed' AND {$field} > 0 AND {$field} <= %d ORDER BY {$field} LIMIT 50", $now
		), ARRAY_A );
		foreach ( $rows as $booking ) {
			$due = (int) $booking[ $field ];
			if ( ! send_notice( $booking, '', $field, array(), $due ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET {$field} = 0 WHERE id = %d AND revision = %d AND {$field} = %d", $booking['id'], $booking['revision'], $due
			) );
		}
	}
	update_option( 'wpb_last_cron', time(), false );
}

function booking_notices( array $booking_ids ): array {
	global $wpdb;
	if ( ! $booking_ids ) {
		return array();
	}
	$table = notice_table();
	$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$table} WHERE booking_id IN ({$placeholders}) ORDER BY id DESC",
		$booking_ids
	), ARRAY_A );
	$grouped = array();
	foreach ( $rows as $row ) {
		if ( count( $grouped[ $row['booking_id'] ] ?? array() ) < 12 ) {
			$grouped[ $row['booking_id'] ][] = $row;
		}
	}
	return $grouped;
}

function resend_booking_notice( int $id ) {
	$booking = find_booking( $id );
	if ( ! $booking || ! is_email( $booking['customer_email'] ) ) {
		return new \WP_Error( 'wpb_no_email', '這筆預約沒有可用的收件地址。' );
	}
	if ( pending_expired( $booking, time() ) ) {
		$booking['status'] = 'expired';
	}
	return send_notice( $booking, '', 'resent' ) ? true
		: new \WP_Error( 'wpb_mail', '通知未寄出。請查看寄送紀錄，並間隔至少一分鐘再重試。' );
}

function unresolved_mail_query(): string {
	$notices = notice_table();
	$bookings = booking_table();
	return "SELECT DISTINCT n.booking_id FROM {$notices} n INNER JOIN {$bookings} b ON b.id = n.booking_id AND b.revision = n.revision
		WHERE n.state = 'failed' AND NOT EXISTS (
			SELECT 1 FROM {$notices} r WHERE r.booking_id = n.booking_id AND r.revision = n.revision
			AND r.recipient = n.recipient AND r.kind = 'resent' AND r.state = 'accepted' AND r.sent_at >= n.sent_at
		)";
}

function render_booking_health(): void {
	$last = (int) get_option( 'wpb_last_cron', 0 );
	$next = wp_next_scheduled( CRON_HOOK );
	?>
	<section class="wpb-admin-card">
		<h2>通知與排程檢測</h2>
		<p>上次完成排程：<?php echo esc_html( $last ? wp_date( 'Y/m/d H:i:s', $last ) : '尚無紀錄' ); ?></p>
		<p>下次預定執行：<?php echo esc_html( $next ? wp_date( 'Y/m/d H:i:s', $next ) : '未排程' ); ?></p>
		<?php if ( $next && $next < time() - 10 * MINUTE_IN_SECONDS ) : ?>
			<p>排程已落後超過十分鐘，請檢查網站 WP-Cron 或主機排程。</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php admin_form_fields( 'test_mail', 'wpb-test-mail' ); ?>
			<button class="wpb-admin-btn" type="submit">寄測試信到我的帳號信箱</button>
		</form>
	</section>
	<?php
}
