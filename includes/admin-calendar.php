<?php

namespace Wumetax\Booking;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

function render_admin_overview(): void {
	global $wpdb;

	$now = new DateTimeImmutable( 'now', wp_timezone() );
	$today = $now->setTime( 0, 0 );
	$month = $today->modify( 'first day of this month' );
	$table = booking_table();
	$daily = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE starts_at >= %d AND starts_at < %d AND status NOT IN ('cancelled','expired')
		AND (status <> 'pending' OR hold_until = 0 OR hold_until > %d)",
		$today->getTimestamp(), $today->modify( 'tomorrow' )->getTimestamp(), $now->getTimestamp()
	) );
	$review = $wpdb->get_row( $wpdb->prepare(
		"SELECT SUM(starts_at >= %d) AS upcoming, SUM(starts_at < %d) AS overdue
		 FROM {$table} WHERE status = 'pending' AND (hold_until = 0 OR hold_until > %d)",
		$now->getTimestamp(), $now->getTimestamp(), $now->getTimestamp()
	), ARRAY_A );
	$amounts = $wpdb->get_results( $wpdb->prepare(
		"SELECT status, SUM(price) AS amount FROM {$table} WHERE starts_at >= %d AND starts_at < %d
		 AND status IN ('pending','confirmed','completed') AND (status <> 'pending' OR hold_until = 0 OR hold_until > %d) GROUP BY status",
		$month->getTimestamp(), $month->modify( 'first day of next month' )->getTimestamp(), $now->getTimestamp()
	), OBJECT_K );
	$mail = $wpdb->get_col( unresolved_mail_query() );
	$upcoming = $wpdb->get_results( $wpdb->prepare(
		"SELECT reference, service_name, starts_at, ends_at, timezone, status, customer_name, hold_until
		FROM {$table} WHERE starts_at >= %d AND (status = 'confirmed' OR (status = 'pending' AND (hold_until = 0 OR hold_until > %d)))
		ORDER BY starts_at, id LIMIT 5",
		$now->getTimestamp(), $now->getTimestamp()
	), ARRAY_A );
	?>
	<div class="wpb-admin-metrics">
		<div class="wpb-admin-card wpb-admin-metric">
			<span class="wpb-admin-icon"><?php echo wpb_icon( 'calendar' ); ?></span>
			<h2>今日預約數</h2><p><?php echo esc_html( number_format_i18n( (int) $daily ) ); ?></p>
			<a href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'day' => $today->format( 'Y-m-d' ) ) ) ); ?>">查看今日排程 →</a>
		</div>
		<?php foreach ( array( 'upcoming' => '即將到期待確認', 'overdue' => '已過時待處理' ) as $queue => $label ) : ?>
			<div class="wpb-admin-card wpb-admin-metric">
				<h2><?php echo esc_html( $label ); ?></h2><p><?php echo esc_html( number_format_i18n( (int) ( $review[ $queue ] ?? 0 ) ) ); ?></p>
				<a href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings', 'status' => 'pending', 'queue' => $queue ) ) ); ?>">前往處理 →</a>
			</div>
		<?php endforeach; ?>
		<div class="wpb-admin-card wpb-admin-metric">
			<h2>寄送失敗的預約</h2><p><?php echo esc_html( number_format_i18n( count( $mail ) ) ); ?></p>
			<a href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings', 'queue' => 'failed_mail' ) ) ); ?>">查看寄送紀錄 →</a>
		</div>
	</div>
	<section class="wpb-admin-card">
		<h2>本月服務金額</h2>
		<dl>
			<?php foreach ( array( 'pending' => '待確認金額', 'confirmed' => '已確認服務金額', 'completed' => '已完成服務金額' ) as $status => $label ) : ?>
				<dt><?php echo esc_html( $label ); ?></dt>
				<dd><?php echo esc_html( isset( $amounts[ $status ]->amount ) ? booking_price( $amounts[ $status ]->amount ) : '—' ); ?></dd>
			<?php endforeach; ?>
		</dl>
	</section>
	<section class="wpb-admin-card">
		<div class="wpb-admin-section-head">
			<h2>即將到來的預約</h2>
			<a class="wpb-admin-btn" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings' ) ) ); ?>">查看全部</a>
		</div>
		<?php render_agenda( $upcoming ); ?>
	</section>
	<?php
}

function render_admin_calendar(): void {
	global $wpdb;

	$timezone = wp_timezone();
	$today = new DateTimeImmutable( 'today', $timezone );
	$selected = calendar_date( read_field( $_GET, 'day' ), $timezone );
	$requested_month = calendar_date( read_field( $_GET, 'month' ) . '-01', $timezone );
	$month = ( $requested_month ?? $selected ?? $today )->modify( 'first day of this month' );
	$end = $month->modify( 'first day of next month' );
	if ( ! $selected || $selected < $month || $selected >= $end ) {
		$selected = $today >= $month && $today < $end ? $today : $month;
	}
	$key = $month->format( 'Y-m' );
	$selected_key = $selected->format( 'Y-m-d' );
	$config = settings();
	$table = booking_table();
	$starts = $wpdb->get_col( $wpdb->prepare(
		"SELECT starts_at FROM {$table} WHERE starts_at >= %d AND starts_at < %d AND status NOT IN ('cancelled','expired')
		AND (status <> 'pending' OR hold_until = 0 OR hold_until > %d)",
		$month->getTimestamp(), $end->getTimestamp(), time()
	) );
	$counts = array();
	foreach ( $starts as $start ) {
		$date = $month->setTimestamp( (int) $start )->format( 'Y-m-d' );
		$counts[ $date ] = ( $counts[ $date ] ?? 0 ) + 1;
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT reference, service_name, starts_at, ends_at, timezone, status, customer_name, hold_until
		FROM {$table} WHERE starts_at >= %d AND starts_at < %d ORDER BY starts_at, id",
		$selected->getTimestamp(), $selected->modify( 'tomorrow' )->getTimestamp()
	), ARRAY_A );
	?>
	<div class="wpb-admin-calendar-columns">
		<section class="wpb-admin-card">
			<div class="wpb-admin-section-head wpb-admin-month">
				<h2><?php echo esc_html( $month->format( 'Y 年 n 月' ) ); ?>預約行事曆</h2>
				<div class="wpb-admin-month-links">
					<a class="wpb-admin-btn" aria-label="上個月" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'month' => $month->modify( '-1 month' )->format( 'Y-m' ) ) ) ); ?>">‹</a>
					<a class="wpb-admin-btn" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'day' => $today->format( 'Y-m-d' ) ) ) ); ?>">今天</a>
					<a class="wpb-admin-btn" aria-label="下個月" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'month' => $end->format( 'Y-m' ) ) ) ); ?>">›</a>
				</div>
			</div>
			<div class="wpb-admin-days">
				<?php foreach ( array( '日', '一', '二', '三', '四', '五', '六' ) as $label ) : ?>
					<strong class="wpb-admin-weekday"><?php echo esc_html( $label ); ?></strong>
				<?php endforeach; ?>
				<?php for ( $blank = 0; $blank < (int) $month->format( 'w' ); $blank++ ) : ?><span></span><?php endfor; ?>
				<?php for ( $day = $month; $day < $end; $day = $day->modify( 'tomorrow' ) ) : ?>
					<?php
					$date = $day->format( 'Y-m-d' );
					$count = $counts[ $date ] ?? 0;
					$closed = ! opening_hours( $day, $config );
					?>
					<a class="wpb-admin-day<?php echo $closed ? ' wpb-admin-day-closed' : ''; ?>"
						<?php echo $date === $selected_key ? 'aria-current="date"' : ''; ?>
						aria-label="<?php echo esc_attr( $date . ( $closed ? ' 公休' : '' ) . '，' . $count . ' 筆預約' ); ?>"
						href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'month' => $key, 'day' => $date ) ) ); ?>">
						<strong><?php echo esc_html( $day->format( 'j' ) ); ?></strong>
						<?php if ( $count ) : ?><span><?php echo esc_html( $count ); ?> 筆</span><?php elseif ( $closed ) : ?><span>公休</span><?php endif; ?>
					</a>
				<?php endfor; ?>
			</div>
		</section>
		<section class="wpb-admin-card">
			<div class="wpb-admin-section-head">
				<div><h2><?php echo esc_html( $selected->format( 'm/d' ) ); ?> 當日預約</h2><p><?php echo esc_html( sprintf( '共 %d 筆', count( $rows ) ) ); ?></p></div>
				<button class="wpb-admin-btn wpb-admin-primary" type="button" data-wpb-dialog="wpb-create-booking">＋ 新增預約</button>
			</div>
			<?php render_agenda( $rows ); ?>
		</section>
	</div>
	<?php
	render_quick_booking( $selected_key );
}

function render_agenda( array $rows ): void {
	$states = status_labels();
	if ( ! $rows ) {
		echo '<p class="wpb-admin-empty">目前沒有預約。</p>';
		return;
	}
	?>
	<ul class="wpb-admin-agenda">
		<?php foreach ( $rows as $row ) : ?>
			<?php $state = pending_expired( $row, time() ) ? 'expired' : $row['status']; ?>
			<li>
				<div>
					<a class="wpb-admin-agenda-title" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings', 'q' => $row['reference'] ) ) ); ?>"><?php echo esc_html( $row['service_name'] ); ?></a>
					<span><?php echo esc_html( $row['customer_name'] ); ?></span>
					<small><?php echo esc_html( booking_time( $row ) ); ?></small>
				</div>
				<span class="wpb-admin-status wpb-admin-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $states[ $state ] ?? $state ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

function render_quick_booking( string $date = '' ): void {
	?>
	<dialog class="wpb-admin-dialog wpb-admin-booking-dialog" id="wpb-create-booking" aria-label="新增預約">
		<button class="wpb-admin-close" type="button" data-wpb-dismiss aria-label="關閉">×</button>
		<?php echo booking_wizard( true, $date ); ?>
	</dialog>
	<?php
}
