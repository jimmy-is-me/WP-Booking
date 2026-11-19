<?php

namespace Wumetax\Booking;

use WP_Error;

defined( 'ABSPATH' ) || exit;

function admin_url_for( array $args = array() ): string {
	return add_query_arg( array( 'page' => 'wp-booking' ) + $args, admin_url( 'admin.php' ) );
}

function register_admin(): void {
	$screen = add_menu_page( 'WP Booking', '預約管理', 'manage_options', 'wp-booking', __NAMESPACE__ . '\\render_admin', 'dashicons-calendar-alt' );
	if ( $screen ) {
		add_action( 'load-' . $screen, static function (): void {
			wp_enqueue_style( 'wp-booking-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
		} );
	}
}

function read_field( array $source, string $key ): string {
	return is_string( $source[ $key ] ?? null ) ? wp_unslash( $source[ $key ] ) : '';
}

function render_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tabs = array( 'bookings' => '預約一覽', 'services' => '服務項目', 'settings' => '排程設定' );
	$tab = read_field( $_GET, 'tab' );
	$tab = isset( $tabs[ $tab ] ) ? $tab : 'bookings';
	?>
	<div class="wrap wpb-admin">
		<h1>WP Booking 預約管理</h1>
		<nav class="nav-tab-wrapper" aria-label="預約管理選單">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"
					<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>
					href="<?php echo esc_url( admin_url_for( array( 'tab' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( '1' === read_field( $_GET, 'wpb_saved' ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>已儲存。</p></div>
		<?php endif; ?>
		<?php if ( SCHEMA_VERSION !== (int) get_option( SCHEMA_OPTION ) ) : ?>
			<div class="notice notice-error"><p>無法建立預約資料表，請檢查 WordPress 資料庫權限。</p></div>
		<?php endif; ?>
		<?php
		if ( 'services' === $tab ) {
			render_services();
		} elseif ( 'settings' === $tab ) {
			render_settings();
		} else {
			render_bookings();
		}
		?>
	</div>
	<?php
}

function admin_form_fields( string $operation, string $nonce ): void {
	?>
	<input name="action" type="hidden" value="wpb_save">
	<input name="operation" type="hidden" value="<?php echo esc_attr( $operation ); ?>">
	<?php wp_nonce_field( $nonce ); ?>
	<?php
}

function handle_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '您沒有管理預約的權限。', '', array( 'response' => 403 ) );
	}
	$operation = read_field( $_POST, 'operation' );
	$tab = $operation;
	switch ( $operation ) {
		case 'services':
			$id = read_field( $_POST, 'service_id' );
			check_admin_referer( 'wpb-service-' . $id );
			$result = save_service( $id, $_POST );
			break;
		case 'settings':
			check_admin_referer( 'wpb-settings' );
			$result = save_settings( $_POST );
			break;
		case 'status':
			$tab = 'bookings';
			$id = (int) read_field( $_POST, 'booking_id' );
			check_admin_referer( 'wpb-booking-' . $id );
			$result = change_status( $id, read_field( $_POST, 'status' ) );
			break;
		default:
			wp_die( '無法辨識管理操作。', '', array( 'response' => 400 ) );
	}
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), '無法儲存', array( 'response' => 400, 'back_link' => true ) );
	}

	$target = 'status' === $operation && wp_get_referer() ? wp_get_referer() : admin_url_for( array( 'tab' => $tab ) );
	wp_safe_redirect( add_query_arg( 'wpb_saved', '1', $target ), 303 );
	exit;
}

function save_service( string $id, array $posted ) {
	$name = sanitize_text_field( read_field( $posted, 'name' ) );
	$description = sanitize_textarea_field( read_field( $posted, 'description' ) );
	$duration = read_field( $posted, 'duration' );
	if ( '' === $name || text_size( $name ) > 120 || text_size( $description ) > 500
		|| ! ctype_digit( $duration ) || (int) $duration < STEP_MINUTES || (int) $duration > 480
		|| (int) $duration % STEP_MINUTES ) {
		return new WP_Error( 'wpb_service', '請確認服務名稱、簡介及所需時間。所需時間為 15 至 480 分鐘，須為 15 的倍數。' );
	}

	return with_calendar_lock( static function () use ( $id, $name, $description, $duration, $posted ) {
		$original = services();
		$items = $original;
		if ( '' !== $id && ! isset( $items[ $id ] ) ) {
			return new WP_Error( 'wpb_service_missing', '找不到此服務。' );
		}
		if ( '' === $id && count( $items ) >= 100 ) {
			return new WP_Error( 'wpb_service_limit', '目前最多可建立 100 項服務。' );
		}
		$id = $id ?: bin2hex( random_bytes( 16 ) );
		$items[ $id ] = array(
			'name'        => $name,
			'description' => $description,
			'duration'    => (int) $duration,
			'enabled'     => '1' === read_field( $posted, 'enabled' ),
		);
		return $items === $original || update_option( SERVICES_OPTION, $items, false ) ? true : storage_error();
	} );
}

function render_services(): void {
	?>
	<p>新增服務後，將 <code>[wp_booking]</code> 貼入頁面的短代碼區塊，即可顯示預約表單。</p>
	<p>所有服務共用一份行事曆。停用服務會從前台隱藏，既有預約仍可管理。</p>
	<div class="wpb-admin-cards">
		<?php render_service_form( '', array( 'name' => '', 'description' => '', 'duration' => 60, 'enabled' => true ) ); ?>
		<?php foreach ( services() as $id => $service ) : ?>
			<?php render_service_form( (string) $id, $service ); ?>
		<?php endforeach; ?>
	</div>
	<?php
}

function render_service_form( string $id, array $service ): void {
	?>
	<form class="wpb-admin-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<h2><?php echo esc_html( '' === $id ? '新增服務' : $service['name'] ); ?></h2>
		<?php admin_form_fields( 'services', 'wpb-service-' . $id ); ?>
		<input type="hidden" name="service_id" value="<?php echo esc_attr( $id ); ?>">
		<label>服務名稱<input type="text" name="name" maxlength="120" required value="<?php echo esc_attr( $service['name'] ); ?>"></label>
		<label>服務簡介（選填）<textarea name="description" rows="3" maxlength="500"><?php echo esc_textarea( $service['description'] ); ?></textarea></label>
		<label>所需時間（分鐘）<input type="number" name="duration" required min="15" max="480" step="15" value="<?php echo esc_attr( $service['duration'] ); ?>"></label>
		<label><input type="checkbox" name="enabled" value="1" <?php checked( $service['enabled'] ); ?>>開放預約</label>
		<?php submit_button( '' === $id ? '新增服務' : '儲存服務', 'primary', 'submit', false ); ?>
	</form>
	<?php
}

function save_settings( array $posted ) {
	$week = array();
	$posted_week = is_array( $posted['hours'] ?? null ) ? $posted['hours'] : array();
	for ( $weekday = 0; $weekday < 7; $weekday++ ) {
		if ( ! is_string( $posted_week[ $weekday ] ?? null ) || strlen( $posted_week[ $weekday ] ) > 300 ) {
			return new WP_Error( 'wpb_week', '請確認每週營業時間的欄位。' );
		}
		$hours = parse_hours( wp_unslash( $posted_week[ $weekday ] ) );
		if ( is_wp_error( $hours ) ) {
			return $hours;
		}
		$week[] = $hours;
	}

	$raw_closed = read_field( $posted, 'closed' );
	$closed = preg_split( '/[\s,，]+/u', trim( $raw_closed ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $closed ) || count( $closed ) > 366 ) {
		return new WP_Error( 'wpb_closed', '最多可設定 366 筆休假日。' );
	}
	foreach ( $closed as $date ) {
		if ( ! calendar_date( $date, wp_timezone() ) ) {
			return new WP_Error( 'wpb_closed', '休假日須為有效的 YYYY-MM-DD 日期。' );
		}
	}
	$closed = array_values( array_unique( $closed ) );
	sort( $closed );
	$lead = read_field( $posted, 'lead_minutes' );
	$horizon = read_field( $posted, 'horizon_days' );
	$email = trim( read_field( $posted, 'notify_email' ) );
	if ( ! ctype_digit( $lead ) || (int) $lead > 10080 || ! ctype_digit( $horizon ) || (int) $horizon < 1 || (int) $horizon > 365 ) {
		return new WP_Error( 'wpb_bounds', '最短提前時間須介於 0 至 10080 分鐘，可預約天數須介於 1 至 365 天。' );
	}
	if ( '' !== $email && ( strlen( $email ) > 254 || ! is_email( $email ) ) ) {
		return new WP_Error( 'wpb_notify_email', '請輸入有效的通知信箱。' );
	}
	$config = array(
		'week'         => $week,
		'closed'       => $closed,
		'lead_minutes' => (int) $lead,
		'horizon_days' => (int) $horizon,
		'auto_confirm' => '1' === read_field( $posted, 'auto_confirm' ),
		'notify_email' => $email,
	);

	return with_calendar_lock( static function () use ( $config ) {
		return $config === settings() || update_option( SETTINGS_OPTION, $config, false ) ? true : storage_error();
	} );
}

function render_settings(): void {
	$config = settings();
	$weekdays = array( '星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六' );
	?>
	<p>排程使用 WordPress 的網站時區（<?php echo esc_html( wp_timezone_string() ); ?>）。</p>
	<form class="wpb-admin-settings" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<?php admin_form_fields( 'settings', 'wpb-settings' ); ?>
		<div class="wpb-admin-cards">
			<section class="wpb-admin-card">
				<h2>每週營業時間</h2>
				<p>每段使用 15 分鐘刻度，例 <code>09:00-12:00, 13:00-18:00</code>。休息日留空。</p>
				<?php foreach ( $weekdays as $number => $label ) : ?>
					<?php
					$hours = array_map( static function ( array $range ): string {
						return clock_label( $range[0] ) . '-' . clock_label( $range[1] );
					}, $config['week'][ $number ] );
					?>
					<label><?php echo esc_html( $label ); ?>
						<input type="text" name="hours[<?php echo esc_attr( $number ); ?>]" maxlength="300" value="<?php echo esc_attr( implode( ', ', $hours ) ); ?>">
					</label>
				<?php endforeach; ?>
			</section>
			<section class="wpb-admin-card">
				<h2>預約規則</h2>
				<label>休假日期（每行一筆，格式 YYYY-MM-DD）
					<textarea name="closed" rows="5" maxlength="4096"><?php echo esc_textarea( implode( "\n", $config['closed'] ) ); ?></textarea>
				</label>
				<label>至少提前預約（分鐘）
					<input type="number" name="lead_minutes" min="0" max="10080" required value="<?php echo esc_attr( $config['lead_minutes'] ); ?>">
				</label>
				<label>最遠可預約至今天起（天）
					<input type="number" name="horizon_days" min="1" max="365" required value="<?php echo esc_attr( $config['horizon_days'] ); ?>">
				</label>
				<label>管理員通知信箱（留空使用網站管理員信箱）
					<input type="email" name="notify_email" maxlength="254" value="<?php echo esc_attr( $config['notify_email'] ); ?>">
				</label>
				<label><input type="checkbox" name="auto_confirm" value="1" <?php checked( $config['auto_confirm'] ); ?>>送出後自動確認</label>
				<p>採人工確認時，待確認的預約會先保留時段。</p>
			</section>
		</div>
		<?php submit_button( '儲存排程設定' ); ?>
	</form>
	<?php
}

function render_bookings(): void {
	global $wpdb;

	$states = status_labels();
	$status = read_field( $_GET, 'status' );
	$query = sanitize_text_field( read_field( $_GET, 'q' ) );
	$query = text_size( $query ) <= 100 ? $query : '';
	$timezone = wp_timezone();
	$from = calendar_date( read_field( $_GET, 'from' ), $timezone );
	$to = calendar_date( read_field( $_GET, 'to' ), $timezone );
	$page = max( 1, (int) read_field( $_GET, 'paged' ) );
	$per_page = 25;
	$table = booking_table();
	$where = array( '1=1' );
	$args = array();
	$filters = array();

	if ( isset( $states[ $status ] ) ) {
		$where[] = 'status = %s';
		$args[] = $status;
		$filters['status'] = $status;
	}
	if ( '' !== $query ) {
		$where[] = '(reference LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s)';
		$search = '%' . $wpdb->esc_like( $query ) . '%';
		array_push( $args, $search, $search, $search );
		$filters['q'] = $query;
	}
	if ( $from ) {
		$where[] = 'starts_at >= %d';
		$args[] = $from->getTimestamp();
		$filters['from'] = $from->format( 'Y-m-d' );
	}
	if ( $to ) {
		$where[] = 'starts_at < %d';
		$args[] = $to->modify( 'tomorrow' )->getTimestamp();
		$filters['to'] = $to->format( 'Y-m-d' );
	}
	$sql = "FROM {$table} WHERE " . implode( ' AND ', $where );
	$count_sql = "SELECT COUNT(*) {$sql}";
	$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
	$max_page = max( 1, (int) ceil( $total / $per_page ) );
	$page = min( $page, $max_page );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * {$sql} ORDER BY starts_at DESC, id DESC LIMIT %d OFFSET %d",
		array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
	), ARRAY_A );
	?>
	<p>在頁面加入 <code>[wp_booking]</code> 開啟前台預約。</p>
	<form class="wpb-admin-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="wp-booking">
		<label>搜尋預約<input type="search" name="q" maxlength="100" placeholder="編號、姓名或電子郵件" value="<?php echo esc_attr( $query ); ?>"></label>
		<label>狀態<select name="status">
			<option value="">全部狀態</option>
			<?php foreach ( $states as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select></label>
		<label>起始日期<input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ?? '' ); ?>"></label>
		<label>結束日期<input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ?? '' ); ?>"></label>
		<?php submit_button( '篩選', 'secondary', '', false ); ?>
		<?php if ( $filters ) : ?>
			<a href="<?php echo esc_url( admin_url_for() ); ?>">清除篩選</a>
		<?php endif; ?>
	</form>
	<div class="wpb-admin-overflow">
		<table class="widefat striped">
			<thead><tr><th>預約編號</th><th>服務</th><th>預約時段</th><th>聯絡資料</th><th>狀態</th><th>操作</th></tr></thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php echo esc_html( $wpdb->last_error ? '無法讀取預約，請稍後再試。' : '目前沒有符合條件的預約。' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $row['reference'] ); ?></strong></td>
						<td><?php echo esc_html( $row['service_name'] ); ?></td>
						<td><?php echo esc_html( booking_time( $row ) ); ?></td>
						<td>
							<?php echo esc_html( $row['customer_name'] ); ?>
							<?php if ( $row['customer_email'] || $row['customer_phone'] || $row['note'] ) : ?>
								<details><summary>聯絡方式與備註</summary>
									<?php foreach ( array( 'customer_email', 'customer_phone', 'note' ) as $key ) : ?>
										<?php if ( '' !== $row[ $key ] ) : ?>
											<div><?php echo nl2br( esc_html( $row[ $key ] ) ); ?></div>
										<?php endif; ?>
									<?php endforeach; ?>
								</details>
							<?php endif; ?>
						</td>
						<td><span class="wpb-admin-status wpb-admin-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $states[ $row['status'] ] ?? $row['status'] ); ?></span></td>
						<td><?php render_status_form( $row, $states ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="wpb-admin-pages">
		<span><?php echo esc_html( sprintf( '共 %d 筆預約，第 %d／%d 頁', $total, $page, $max_page ) ); ?></span>
		<?php if ( $page > 1 ) : ?>
			<a class="button" href="<?php echo esc_url( admin_url_for( array( 'paged' => $page - 1 ) + $filters ) ); ?>">上一頁</a>
		<?php endif; ?>
		<?php if ( $page < $max_page ) : ?>
			<a class="button" href="<?php echo esc_url( admin_url_for( array( 'paged' => $page + 1 ) + $filters ) ); ?>">下一頁</a>
		<?php endif; ?>
	</div>
	<?php
}

function render_status_form( array $booking, array $labels ): void {
	$allowed = allowed_statuses( $booking );
	if ( ! $allowed ) {
		return;
	}
	?>
	<form class="wpb-admin-action" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<?php admin_form_fields( 'status', 'wpb-booking-' . $booking['id'] ); ?>
		<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>">
		<select name="status" aria-label="<?php echo esc_attr( '變更 ' . $booking['reference'] . ' 的狀態' ); ?>" required>
			<option value="">選擇狀態</option>
			<?php foreach ( $allowed as $status ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( '套用', 'secondary small', '', false ); ?>
	</form>
	<?php
}
