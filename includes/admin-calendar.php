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
		"SELECT id, reference, service_name, starts_at, ends_at, timezone, status, customer_name, hold_until
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
	if ( 'week' === read_field( $_GET, 'view' ) ) {
		render_booking_week( $selected, $today );
		render_quick_booking( $selected->format( 'Y-m-d' ) );
		return;
	}
	render_calendar_views( $selected, 'month' );
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
		"SELECT id, reference, service_name, starts_at, ends_at, timezone, status, customer_name, hold_until
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
	render_availability_form( $selected );
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
					<a class="wpb-admin-agenda-title" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings', 'booking' => $row['id'] ) ) ); ?>"><?php echo esc_html( $row['service_name'] ); ?></a>
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

function render_calendar_views( DateTimeImmutable $day, string $view ): void {
	?>
	<nav class="wpb-admin-views" aria-label="排程檢視">
		<?php foreach ( array( 'month' => '月曆', 'week' => '週排程' ) as $key => $label ) : ?>
			<a <?php if ( $key === $view ) : ?>aria-current="page"<?php endif; ?>
				href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'view' => $key, 'day' => $day->format( 'Y-m-d' ) ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php
}

function render_booking_week( DateTimeImmutable $selected, DateTimeImmutable $today ): void {
	global $wpdb;
	$start = $selected->modify( '-' . ( (int) $selected->format( 'N' ) - 1 ) . ' days' );
	$end = $start->modify( '+7 days' );
	$table = booking_table();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, reference, service_name, starts_at, ends_at, timezone, status, customer_name, hold_until
		FROM {$table} WHERE starts_at >= %d AND starts_at < %d AND status NOT IN ('cancelled','expired')
		AND (status <> 'pending' OR hold_until = 0 OR hold_until > %d) ORDER BY starts_at, id",
		$start->getTimestamp(), $end->getTimestamp(), time()
	), ARRAY_A );
	$by_date = array();
	foreach ( $rows ?? array() as $row ) {
		$by_date[ $start->setTimestamp( (int) $row['starts_at'] )->format( 'Y-m-d' ) ][] = $row;
	}
	$config = settings();
	render_calendar_views( $selected, 'week' );
	?>
	<section class="wpb-admin-card">
		<div class="wpb-admin-section-head">
			<h2><?php echo esc_html( $start->format( 'Y/m/d' ) . ' – ' . $end->modify( '-1 day' )->format( 'Y/m/d' ) ); ?></h2>
			<div class="wpb-admin-month-links">
				<?php foreach ( array( '上一週' => $selected->modify( '-7 days' ), '本週' => $today, '下一週' => $selected->modify( '+7 days' ) ) as $label => $day ) : ?>
					<a class="wpb-admin-btn" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'view' => 'week', 'day' => $day->format( 'Y-m-d' ) ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<button class="wpb-admin-btn wpb-admin-primary" type="button" data-wpb-dialog="wpb-create-booking">＋ 新增預約</button>
			</div>
		</div>
		<?php if ( null === $rows ) : ?><p role="alert">目前無法讀取預約。</p><?php endif; ?>
		<p class="wpb-scroll-hint" data-wpb-scroll-hint hidden>可向右滑動查看其餘日期 <span aria-hidden="true">→</span></p>
		<div class="wpb-admin-overflow" data-wpb-scroll tabindex="0" role="region" aria-label="七天排程">
			<div class="wpb-week-board">
				<?php for ( $day = $start; $day < $end; $day = $day->modify( 'tomorrow' ) ) : ?>
					<?php $date = $day->format( 'Y-m-d' ); ?>
					<section class="wpb-week-day" <?php if ( $day == $today ) : ?>aria-current="date"<?php endif; ?>>
						<h3><a href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'day' => $date ) ) ); ?>"><?php echo esc_html( wp_date( 'D m/d', $day->getTimestamp() ) ); ?></a></h3>
						<p><?php echo esc_html( format_opening_hours( opening_hours( $day, $config ) ) ); ?></p>
						<?php render_agenda( $by_date[ $date ] ?? array() ); ?>
					</section>
				<?php endfor; ?>
			</div>
		</div>
	</section>
	<?php
	render_availability_form( $selected );
}

function format_opening_hours( array $hours ): string {
	$labels = array();
	foreach ( $hours as list( $start, $end ) ) {
		$labels[] = clock_label( $start ) . '–' . clock_label( $end );
	}
	return $labels ? implode( '、', $labels ) : '停止開放';
}

function render_availability_form( DateTimeImmutable $day ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$date = $day->format( 'Y-m-d' );
	$config = settings();
	?>
	<section class="wpb-admin-card wpb-availability">
		<h2>管理可預約日期與時段</h2>
		<p>目前 <?php echo esc_html( $date . ' · ' . format_opening_hours( opening_hours( $day, $config ) ) ); ?>。變更只影響之後的預約與改期，已存在的預約照原時段保留。</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wpb-availability>
			<?php admin_form_fields( 'availability', 'wpb-availability' ); ?>
			<div class="wpb-admin-form-grid">
				<label>開始日期<input required type="date" name="from" value="<?php echo esc_attr( $date ); ?>"></label>
				<label>結束日期<input required type="date" name="to" value="<?php echo esc_attr( $date ); ?>"></label>
				<label>調整方式<select name="mode">
					<option value="close">整天停約</option>
					<option value="restore">解除整天停約</option>
					<option value="block">封鎖指定時段</option>
					<option value="clear">清除時段封鎖</option>
				</select></label>
				<label data-wpb-block-hours>封鎖時段<input name="hours" maxlength="300" placeholder="09:30-10:30, 14:00-15:00"></label>
			</div>
			<p>最多一次調整 31 天。解除整天停約會恢復該日原本的營業設定，個別時段封鎖可另行清除。</p>
			<button class="wpb-admin-btn wpb-admin-primary" type="submit">套用日期設定</button>
		</form>
	</section>
	<?php
}

function save_availability( array $posted ) {
	$timezone = wp_timezone();
	$from = calendar_date( read_field( $posted, 'from' ), $timezone );
	$to = calendar_date( read_field( $posted, 'to' ), $timezone );
	$mode = read_field( $posted, 'mode' );
	if ( ! $from || ! $to || $to < $from || $to >= $from->modify( '+31 days' )
		|| ! in_array( $mode, array( 'close', 'restore', 'block', 'clear' ), true ) ) {
		return new \WP_Error( 'wpb_availability', '請選擇有效的起訖日期與調整方式。一次最多調整 31 天。' );
	}
	$raw = read_field( $posted, 'hours' );
	$hours = 'block' === $mode && strlen( $raw ) <= 300 ? parse_hours( $raw ) : array();
	if ( 'block' === $mode && ( is_wp_error( $hours ) || ! $hours ) ) {
		return new \WP_Error( 'wpb_block_hours', '請輸入以 15 分鐘為單位的封鎖時段，例如 09:30-10:30。' );
	}
	return with_calendar_lock( static function () use ( $from, $to, $mode, $hours ) {
		$current = settings();
		$updated = $current;
		$closed = array_fill_keys( $current['closed'], true );
		for ( $day = $from; $day <= $to; $day = $day->modify( 'tomorrow' ) ) {
			$date = $day->format( 'Y-m-d' );
			switch ( $mode ) {
				case 'close':
					$closed[ $date ] = true;
					break;
				case 'restore':
					unset( $closed[ $date ] );
					break;
				case 'block':
					$updated['blocked_hours'][ $date ] = $hours;
					break;
				case 'clear':
					unset( $updated['blocked_hours'][ $date ] );
					break;
			}
		}
		if ( count( $closed ) > 366 || count( $updated['blocked_hours'] ) > 366 ) {
			return new \WP_Error( 'wpb_availability_limit', '整天停約與時段封鎖各最多保留 366 個日期。' );
		}
		$updated['closed'] = array_keys( $closed );
		sort( $updated['closed'] );
		ksort( $updated['blocked_hours'] );
		return $updated === $current || update_option( SETTINGS_OPTION, $updated, false ) ? true : storage_error();
	} );
}
