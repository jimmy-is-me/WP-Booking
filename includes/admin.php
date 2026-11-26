<?php

namespace Wumetax\Booking;

use WP_Error;

defined( 'ABSPATH' ) || exit;

function admin_url_for( array $args = array() ): string {
	$encoded = array_map( static function ( $value ): string {
		return rawurlencode( (string) $value );
	}, $args );
	return add_query_arg( array( 'page' => 'wp-booking' ) + $encoded, admin_url( 'admin.php' ) );
}

function can_manage_bookings(): bool {
	return current_user_can( 'manage_options' ) || current_user_can( 'wpb_manage_bookings' );
}

function register_booking_role(): void {
	if ( ! get_role( 'wpb_booking_staff' ) ) {
		add_role( 'wpb_booking_staff', '預約人員', array( 'read' => true, 'wpb_manage_bookings' => true ) );
	}
}

function booking_plugin_actions( array $links ): array {
	if ( current_user_can( 'manage_options' ) ) {
		$links = array( 'wpb_settings' => '<a href="' . esc_url( admin_url_for( array( 'tab' => 'settings' ) ) ) . '">設定</a>' ) + $links;
	}
	return $links;
}

function booking_plugin_details_link( array $links, string $file ): array {
	if ( plugin_basename( FILE ) !== $file ) {
		return $links;
	}
	if ( current_user_can( 'install_plugins' ) ) {
		$url = add_query_arg( array( 'tab' => 'plugin-information', 'plugin' => 'wp-booking', 'TB_iframe' => 'true', 'width' => 720, 'height' => 600 ), self_admin_url( 'plugin-install.php' ) );
		$links[] = '<a class="thickbox open-plugin-details-modal" href="' . esc_url( $url ) . '" aria-label="檢視 WP Booking 詳細資料">檢視詳細資料</a>';
	} else {
		$links[] = '<a href="' . esc_url( RELEASE_ROOT . '/tag/v' . VERSION ) . '">檢視詳細資料</a>';
	}
	return $links;
}

function booking_plugin_information( $result, string $action, $args ) {
	if ( 'plugin_information' !== $action || 'wp-booking' !== ( $args->slug ?? '' ) || ! current_user_can( 'install_plugins' ) ) {
		return $result;
	}
	return (object) array(
		'name' => 'WP Booking',
		'slug' => 'wp-booking',
		'version' => VERSION,
		'author' => '<a href="https://wumetax.com/">WUMETAX 末特數位科技</a>',
		'requires' => '6.5',
		'requires_php' => '7.4',
		'homepage' => RELEASE_ROOT,
		'download_link' => '',
		'sections' => array(
			'description' => '<p>繁體中文 WordPress 預約外掛，提供服務時段、月曆、通知與訂單管理。</p><p><code>[wp_booking]</code> 接受預約。<code>[wp_booking_availability]</code> 顯示可預約時段。</p>',
			'installation' => '<p>在同站啟用並授權 WU Toolbox Modular，再到「預約管理 → 服務項目管理」建立服務，並從「排程與系統設定」設定營業時間。</p>',
			'changelog' => '<p>v1.4.4 新增外掛列表快捷入口、預約詳情頁、快速確認，以及融入網站佈景的前台介面。</p><p><a href="' . esc_url( RELEASE_ROOT ) . '">版本紀錄</a></p>',
		),
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( FILE ), __NAMESPACE__ . '\booking_plugin_actions' );
add_filter( 'plugin_row_meta', __NAMESPACE__ . '\booking_plugin_details_link', 10, 2 );
add_filter( 'plugins_api', __NAMESPACE__ . '\booking_plugin_information', 10, 3 );

function register_admin(): void {
	$screen = add_menu_page( 'WP Booking', '預約管理', current_user_can( 'manage_options' ) ? 'manage_options' : 'wpb_manage_bookings', 'wp-booking', __NAMESPACE__ . '\\render_admin', 'dashicons-calendar-alt' );
	if ( $screen ) {
		add_action( 'load-' . $screen, static function (): void {
			wp_enqueue_style( 'wp-booking-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
			wp_enqueue_style( 'wp-booking' );
			wp_enqueue_script( 'wp-booking-admin', plugins_url( 'assets/admin.js', FILE ), array(), VERSION, true );
		} );
	}
}

function read_field( array $source, string $key ): string {
	return is_string( $source[ $key ] ?? null ) ? wp_unslash( $source[ $key ] ) : '';
}

function render_admin(): void {
	if ( ! can_manage_bookings() ) {
		return;
	}

	$tabs = array(
		'overview' => '總覽儀表板',
		'bookings' => '預約訂單列表',
		'calendar' => '行事曆排程',
		'services' => '服務項目管理',
		'settings' => '排程與系統設定',
		'license' => '授權與更新',
	);
	if ( ! current_user_can( 'manage_options' ) ) {
		unset( $tabs['services'], $tabs['settings'], $tabs['license'] );
	}
	$tab = read_field( $_GET, 'tab' );
	$has_filter = array_intersect( array( 'q', 'status', 'from', 'to', 'paged' ), array_keys( $_GET ) );
	$tab = isset( $tabs[ $tab ] ) ? $tab : ( $has_filter ? 'bookings' : 'overview' );
	$detail_id = 'bookings' === $tab ? absint( read_field( $_GET, 'booking' ) ) : 0;
	?>
	<div class="wrap wpb-admin">
		<div class="wpb-window-bar" aria-hidden="true"><span class="wpb-window-lights"><i></i><i></i><i></i></span><span class="wpb-window-tab">WP Booking</span></div>
		<header class="wpb-admin-header">
			<div>
				<span class="wpb-admin-brand"><span class="wpb-admin-logo"><?php echo wpb_icon( 'calendar' ); ?></span> WP Booking</span>
				<h1><?php echo esc_html( $detail_id ? '預約詳細資料' : $tabs[ $tab ] ); ?></h1>
			</div>
			<?php if ( in_array( $tab, array( 'overview', 'bookings' ), true ) ) : ?>
				<button class="wpb-admin-btn wpb-admin-primary" type="button" data-wpb-dialog="wpb-create-booking">＋ 新增預約</button>
			<?php endif; ?>
		</header>
		<nav class="wpb-admin-tabs" aria-label="預約管理選單">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a
					<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>
					href="<?php echo esc_url( admin_url_for( array( 'tab' => $key ) ) ); ?>"><?php echo wpb_icon( $key ); ?><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( '1' === read_field( $_GET, 'wpb_saved' ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>已儲存。</p></div>
		<?php endif; ?>
		<?php if ( 'mail' === read_field( $_GET, 'wpb_saved' ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>WordPress 寄信系統已接受通知。</p></div>
		<?php endif; ?>
		<?php if ( ! booking_license_is_active() && current_user_can( 'manage_options' ) && 'license' !== $tab ) : ?>
			<div class="notice notice-warning"><p>本站的 WU Toolbox Modular 授權尚未生效，新預約已暫停。<a href="<?php echo esc_url( admin_url_for( array( 'tab' => 'license' ) ) ); ?>">前往授權與更新</a></p></div>
		<?php endif; ?>
		<?php if ( SCHEMA_VERSION !== (int) get_option( SCHEMA_OPTION ) ) : ?>
			<div class="notice notice-error"><p>無法建立預約資料表，請檢查 WordPress 資料庫權限。</p></div>
		<?php endif; ?>
		<?php
		if ( 'license' === $tab ) {
			render_booking_license();
		} elseif ( 'services' === $tab ) {
			render_services();
		} elseif ( 'settings' === $tab ) {
			render_booking_health();
			render_settings();
		} elseif ( 'calendar' === $tab ) {
			render_admin_calendar();
		} elseif ( 'bookings' === $tab ) {
			if ( $detail_id ) {
				render_booking_page( $detail_id );
			} else {
				render_bookings();
			}
		} else {
			render_admin_overview();
		}
		if ( in_array( $tab, array( 'overview', 'bookings' ), true ) ) {
			render_quick_booking();
		}
		?>
		<?php powered_by(); ?>
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
	if ( ! can_manage_bookings() ) {
		wp_die( '您沒有管理預約的權限。', '', array( 'response' => 403 ) );
	}
	$operation = read_field( $_POST, 'operation' );
	if ( in_array( $operation, array( 'services', 'settings', 'test_mail', 'availability' ), true ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( '您沒有調整系統設定的權限。', '', array( 'response' => 403 ) );
	}
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
		case 'test_mail':
			check_admin_referer( 'wpb-test-mail' );
			$tab = 'settings';
			$user = wp_get_current_user();
			$result = wp_mail( $user->user_email, 'WP Booking 測試通知', '這是您在預約管理後台要求寄出的測試信。' )
				? true : new WP_Error( 'wpb_mail', 'WordPress 未接受測試信，請檢查網站 SMTP 設定。' );
			break;
		case 'resend':
			$tab = 'bookings';
			$id = (int) read_field( $_POST, 'booking_id' );
			check_admin_referer( 'wpb-resend-' . $id );
			$result = resend_booking_notice( $id );
			break;
		case 'availability':
			check_admin_referer( 'wpb-availability' );
			$tab = 'calendar';
			$result = save_availability( $_POST );
			break;
		case 'status':
			$tab = 'bookings';
			$id = (int) read_field( $_POST, 'booking_id' );
			check_admin_referer( 'wpb-booking-' . $id );
			$result = change_status( $id, read_field( $_POST, 'status' ) );
			break;
		case 'note':
			$tab = 'bookings';
			$id = (int) read_field( $_POST, 'booking_id' );
			check_admin_referer( 'wpb-note-' . $id );
			$result = save_admin_note( $id, read_field( $_POST, 'admin_note' ) );
			break;
		default:
			wp_die( '無法辨識管理操作。', '', array( 'response' => 400 ) );
	}
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), '無法儲存', array( 'response' => 400, 'back_link' => true ) );
	}

	$target = in_array( $operation, array( 'status', 'note', 'resend', 'availability' ), true ) && wp_get_referer() ? wp_get_referer() : admin_url_for( array( 'tab' => $tab ) );
	wp_safe_redirect( add_query_arg( 'wpb_saved', in_array( $operation, array( 'test_mail', 'resend' ), true ) ? 'mail' : '1', $target ), 303 );
	exit;
}

function save_admin_note( int $id, string $note ) {
	global $wpdb;
	$note = sanitize_textarea_field( $note );
	if ( text_size( $note ) > 500 ) {
		return new WP_Error( 'wpb_note', '管理備註最多 500 字。' );
	}
	$booking = find_booking( $id );
	if ( ! $booking || '' === $booking['customer_email'] ) {
		return new WP_Error( 'wpb_note_booking', '這筆預約目前無法加註。' );
	}
	$updated = $wpdb->update( booking_table(), array( 'admin_note' => $note ), array( 'id' => $id, 'customer_email' => $booking['customer_email'] ) );
	return false === $updated ? storage_error() : true;
}

function save_service( string $id, array $posted ) {
	$name = sanitize_text_field( read_field( $posted, 'name' ) );
	$description = sanitize_textarea_field( read_field( $posted, 'description' ) );
	$duration = read_field( $posted, 'duration' );
	$price = read_field( $posted, 'price' );
	if ( '' === $name || text_size( $name ) > 120 || text_size( $description ) > 500
		|| ! ctype_digit( $duration ) || (int) $duration < STEP_MINUTES || (int) $duration > 480
		|| (int) $duration % STEP_MINUTES ) {
		return new WP_Error( 'wpb_service', '請確認服務名稱、簡介及所需時間。所需時間為 15 至 480 分鐘，須為 15 的倍數。' );
	}
	if ( '' !== $price && ( ! ctype_digit( $price ) || (int) $price > 9999999 ) ) {
		return new WP_Error( 'wpb_service_price', '服務費用須為 0 至 9,999,999 元的整數。' );
	}
	if ( text_size( read_field( $posted, 'attendance' ) ) > 500 ) {
		return new WP_Error( 'wpb_attendance', '出席資訊最多 500 字。' );
	}
	$rules = service_rules_fields( $posted );
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}

	return with_calendar_lock( static function () use ( $id, $name, $description, $duration, $price, $posted, $rules ) {
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
			'price'       => '' === $price ? null : (int) $price,
			'attendance'  => sanitize_textarea_field( read_field( $posted, 'attendance' ) ),
			'enabled'     => '1' === read_field( $posted, 'enabled' ),
		) + $rules;
		return $items === $original || update_option( SERVICES_OPTION, $items, false ) ? true : storage_error();
	} );
}

function service_rules_fields( array $posted ) {
	$values = array();
	foreach ( array( 'buffer_before' => 0, 'buffer_after' => 0, 'lead_minutes' => null, 'horizon_days' => null ) as $key => $default ) {
		$value = read_field( $posted, $key );
		if ( '' === $value ) {
			$values[ $key ] = $default;
			continue;
		}
		$number = (int) $value;
		$is_buffer = null !== $default;
		$max = $is_buffer ? MAX_BUFFER_MINUTES : ( 'horizon_days' === $key ? 365 : 10080 );
		$min = 'horizon_days' === $key ? 1 : 0;
		if ( ! ctype_digit( $value ) || $number < $min || $number > $max || ( $is_buffer && $number % STEP_MINUTES ) ) {
			return new WP_Error( 'wpb_service_rules', '請確認服務排程規則。前後緩衝最多 120 分鐘，以 15 分鐘為一格。' );
		}
		$values[ $key ] = $number;
	}
	return $values;
}

function render_services(): void {
	$items = services();
	?>
	<div class="wpb-admin-section-head">
		<div><h2>服務項目管理</h2><p>所有服務共用一份行事曆。</p></div>
		<button class="wpb-admin-btn wpb-admin-primary" type="button" data-wpb-dialog="wpb-new-service">＋ 新增服務</button>
	</div>
	<div class="wpb-admin-cards">
		<?php if ( ! $items ) : ?><div class="wpb-admin-card wpb-admin-empty">目前還沒有服務，請先新增一項服務。</div><?php endif; ?>
		<?php foreach ( $items as $id => $service ) : ?>
			<?php render_service_form( (string) $id, $service ); ?>
		<?php endforeach; ?>
	</div>
	<dialog class="wpb-admin-dialog" id="wpb-new-service" aria-label="新增服務">
		<button class="wpb-admin-close" type="button" data-wpb-dismiss aria-label="關閉">×</button>
		<?php render_service_form( '', array( 'name' => '', 'description' => '', 'duration' => 60, 'enabled' => true ) ); ?>
	</dialog>
	<?php
}

function render_service_form( string $id, array $service ): void {
	?>
	<form class="wpb-admin-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<div class="wpb-admin-card-heading">
			<span class="wpb-admin-icon"><?php echo wpb_icon( 'services' ); ?></span>
			<h2><?php echo esc_html( '' === $id ? '新增服務' : $service['name'] ); ?></h2>
			<?php if ( '' !== $id ) : ?><span class="wpb-admin-status <?php echo $service['enabled'] ? 'wpb-admin-completed' : ''; ?>"><?php echo $service['enabled'] ? '開放中' : '已停用'; ?></span><?php endif; ?>
		</div>
		<?php admin_form_fields( 'services', 'wpb-service-' . $id ); ?>
		<input type="hidden" name="service_id" value="<?php echo esc_attr( $id ); ?>">
		<label>服務名稱<input type="text" name="name" maxlength="120" required value="<?php echo esc_attr( $service['name'] ); ?>"></label>
		<label>服務簡介（選填）<textarea name="description" rows="3" maxlength="500"><?php echo esc_textarea( $service['description'] ); ?></textarea></label>
		<label>服務地點或線上會議資訊（選填，確認後提供）<textarea name="attendance" rows="2" maxlength="500"><?php echo esc_textarea( $service['attendance'] ?? '' ); ?></textarea></label>
		<label>所需時間（分鐘）<input type="number" name="duration" required min="15" max="480" step="15" value="<?php echo esc_attr( $service['duration'] ); ?>"></label>
		<label>服務費用（NT$，選填）<input type="number" name="price" min="0" max="9999999" step="1" value="<?php echo esc_attr( $service['price'] ?? '' ); ?>"></label>
		<details class="wpb-admin-service-rules">
			<summary>個別排程規則</summary>
			<label>服務前緩衝（分鐘）<input type="number" name="buffer_before" min="0" max="120" step="15" value="<?php echo esc_attr( $service['buffer_before'] ?? 0 ); ?>"></label>
			<label>服務後緩衝（分鐘）<input type="number" name="buffer_after" min="0" max="120" step="15" value="<?php echo esc_attr( $service['buffer_after'] ?? 0 ); ?>"></label>
			<label>至少提前預約（分鐘）<input type="number" name="lead_minutes" min="0" max="10080" placeholder="沿用全站設定" value="<?php echo esc_attr( $service['lead_minutes'] ?? '' ); ?>"></label>
			<label>開放預約天數<input type="number" name="horizon_days" min="1" max="365" placeholder="沿用全站設定" value="<?php echo esc_attr( $service['horizon_days'] ?? '' ); ?>"></label>
		</details>
		<label><input type="checkbox" name="enabled" value="1" <?php checked( $service['enabled'] ); ?>>開放預約</label>
		<button class="wpb-admin-btn wpb-admin-primary" type="submit"><?php echo '' === $id ? '新增服務' : '儲存服務'; ?></button>
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
	$overrides = parse_date_hours( read_field( $posted, 'date_hours' ) );
	if ( is_wp_error( $overrides ) ) {
		return $overrides;
	}
	$previous = settings();
	$minutes = array();
	foreach ( array( 'cancel_cutoff', 'move_cutoff', 'hold_minutes' ) as $key ) {
		$value = array_key_exists( $key, $posted ) ? read_field( $posted, $key ) : (string) $previous[ $key ];
		if ( ! ctype_digit( $value ) || (int) $value > 10080 ) {
			return new WP_Error( 'wpb_manage_time', '取消、改期與待確認期限都須介於 0 至 10080 分鐘。' );
		}
		$minutes[ $key ] = (int) $value;
	}
	$config = array(
		'week'         => $week,
		'closed'       => $closed,
		'lead_minutes' => (int) $lead,
		'horizon_days' => (int) $horizon,
		'auto_confirm' => '1' === read_field( $posted, 'auto_confirm' ),
		'notify_email' => $email,
		'date_hours'   => $overrides,
		'self_cancel'  => '1' === read_field( $posted, 'self_cancel' ),
		'self_move'    => '1' === read_field( $posted, 'self_move' ),
		'remind_day'   => '1' === read_field( $posted, 'remind_day' ),
		'remind_soon'  => '1' === read_field( $posted, 'remind_soon' ),
	) + $minutes;

	return with_calendar_lock( static function () use ( $config ) {
		$current = settings();
		$updated = array_replace( $current, $config );
		return $updated === $current || update_option( SETTINGS_OPTION, $updated, false ) ? true : storage_error();
	} );
}

function parse_date_hours( string $input ) {
	$lines = preg_split( '/\R/u', trim( $input ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $lines ) || count( $lines ) > 90 || strlen( $input ) > 18000 ) {
		return new WP_Error( 'wpb_date_hours', '最多可新增 90 筆特殊日期營業時間。' );
	}
	$timezone = wp_timezone();
	$overrides = array();
	foreach ( $lines as $line ) {
		$parts = preg_split( '/\s+/u', trim( $line ), 2 );
		if ( 2 !== count( $parts ) || ! calendar_date( $parts[0], $timezone ) || isset( $overrides[ $parts[0] ] ) ) {
			return new WP_Error( 'wpb_date_hours', '每個特殊日期填一行，格式為 YYYY-MM-DD HH:MM-HH:MM。' );
		}
		$hours = parse_hours( $parts[1] );
		if ( is_wp_error( $hours ) || ! $hours ) {
			return new WP_Error( 'wpb_date_hours', '特殊日期的營業時間無效，請確認起訖時間與 15 分鐘刻度。' );
		}
		$overrides[ $parts[0] ] = $hours;
	}
	ksort( $overrides );
	return $overrides;
}

function render_settings(): void {
	$config = settings();
	$weekdays = array( '星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六' );
	$special_hours = array();
	foreach ( $config['date_hours'] as $date => $ranges ) {
		$labels = array_map( static function ( array $range ): string {
			return clock_label( $range[0] ) . '-' . clock_label( $range[1] );
		}, $ranges );
		$special_hours[] = $date . ' ' . implode( ', ', $labels );
	}
	?>
	<div class="wpb-admin-section-head"><div><h2>排程與系統設定</h2>
		<p>排程使用網站時區 <?php echo esc_html( wp_timezone_string() ); ?>。</p></div></div>
	<form class="wpb-admin-settings" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<?php admin_form_fields( 'settings', 'wpb-settings' ); ?>
		<div class="wpb-admin-cards">
			<section class="wpb-admin-card">
				<h2>每週營業時間</h2>
				<p>每段使用 15 分鐘刻度，可設定多段時段。休息日留空。</p>
				<?php foreach ( $weekdays as $number => $label ) : ?>
					<?php
					$hours = array_map( static function ( array $range ): string {
						return clock_label( $range[0] ) . '-' . clock_label( $range[1] );
					}, $config['week'][ $number ] );
					?>
					<div class="wpb-admin-hours">
						<label><?php echo esc_html( $label ); ?>
							<input type="text" name="hours[<?php echo esc_attr( $number ); ?>]" maxlength="300" placeholder="09:00-12:00, 13:00-18:00" value="<?php echo esc_attr( implode( ', ', $hours ) ); ?>">
						</label>
						<button class="wpb-admin-btn" data-wpb-hours type="button" aria-label="<?php echo esc_attr( $label . '營業' ); ?>" aria-pressed="<?php echo $hours ? 'true' : 'false'; ?>"><?php echo $hours ? '營業' : '店休'; ?></button>
					</div>
				<?php endforeach; ?>
			</section>
			<section class="wpb-admin-card">
				<h2>特殊公休日</h2>
				<div class="wpb-admin-closure-tools"><input type="date" data-wpb-closure-date aria-label="公休日期"><button type="button" class="wpb-admin-btn" data-wpb-closure-add>加入公休</button></div>
				<ul class="wpb-admin-closures" data-wpb-closures aria-label="已設定的公休日"></ul>
				<label>休假日期（每行一筆，格式 YYYY-MM-DD）
					<textarea name="closed" data-wpb-closure-text rows="3" maxlength="4096"><?php echo esc_textarea( implode( "\n", $config['closed'] ) ); ?></textarea>
				</label>
				<label>特殊日期營業時間（每行一筆）
					<textarea name="date_hours" rows="4" maxlength="18000" placeholder="2027-01-05 09:00-12:00, 13:00-17:00"><?php echo esc_textarea( implode( "\n", $special_hours ) ); ?></textarea>
				</label>
				<p>指定日期的時段會取代該星期的預設時段，同日有公休時以公休為準。</p>
			</section>
			<section class="wpb-admin-card">
				<h2>預約規則</h2>
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
				<label>待確認保留（分鐘，0 為不限制）<input type="number" name="hold_minutes" min="0" max="10080" required value="<?php echo esc_attr( $config['hold_minutes'] ); ?>"></label>
				<p>新建的待確認預約逾時會釋出時段。</p>
			</section>
			<section class="wpb-admin-card">
				<h2>客戶自行管理</h2>
				<label><input type="checkbox" name="self_cancel" value="1" <?php checked( $config['self_cancel'] ); ?>>允許客戶自行取消</label>
				<label>取消截止（預約前分鐘）<input type="number" name="cancel_cutoff" min="0" max="10080" required value="<?php echo esc_attr( $config['cancel_cutoff'] ); ?>"></label>
				<label><input type="checkbox" name="self_move" value="1" <?php checked( $config['self_move'] ); ?>>允許客戶自行改期</label>
				<label>改期截止（預約前分鐘）<input type="number" name="move_cutoff" min="0" max="10080" required value="<?php echo esc_attr( $config['move_cutoff'] ); ?>"></label>
			</section>
			<section class="wpb-admin-card">
				<h2>預約提醒</h2>
				<label><input type="checkbox" name="remind_day" value="1" <?php checked( $config['remind_day'] ); ?>>開始前 24 小時寄信</label>
				<label><input type="checkbox" name="remind_soon" value="1" <?php checked( $config['remind_soon'] ); ?>>開始前 2 小時寄信</label>
				<p>設定套用至之後確認或改期的預約。可在訂單詳情查看排程與寄送紀錄。</p>
			</section>
		</div>
		<p><button type="submit" class="wpb-admin-btn wpb-admin-primary">儲存排程設定</button></p>
	</form>
	<section class="wpb-admin-shortcode">
		<div><h2>把預約表單加到頁面</h2><p>在 Gutenberg 的「短代碼」區塊貼上這段短代碼。</p></div>
		<div><code>[wp_booking]</code> <button class="wpb-admin-btn" data-wpb-copy type="button">複製短代碼</button></div>
	</section>
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
	$queue = read_field( $_GET, 'queue' );
	$service = read_field( $_GET, 'service' );
	$sort = read_field( $_GET, 'sort' );
	$services = services();
	if ( isset( $services[ $service ] ) ) {
		$where[] = 'service_id = %s';
		$args[] = $service;
		$filters['service'] = $service;
	}

	$now = time();
	if ( in_array( $queue, array( 'upcoming', 'overdue' ), true ) ) {
		$where[] = 'upcoming' === $queue ? 'starts_at >= %d' : 'starts_at < %d';
		$args[] = $now;
		$filters['queue'] = $queue;
	}
	if ( 'failed_mail' === $queue ) {
		$where[] = 'id IN (' . unresolved_mail_query() . ')';
		$filters['queue'] = $queue;
	}
	if ( 'expired' === $status ) {
		$where[] = "(status = 'expired' OR (status = 'pending' AND hold_until > 0 AND hold_until <= %d))";
		$args[] = $now;
		$filters['status'] = $status;
	} elseif ( isset( $states[ $status ] ) ) {
		$where[] = 'status = %s';
		$args[] = $status;
		$filters['status'] = $status;
		if ( 'pending' === $status ) {
			$where[] = '(hold_until = 0 OR hold_until > %d)';
			$args[] = $now;
		}
	}
	if ( '' !== $query ) {
		$where[] = '(reference LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
		$search = '%' . $wpdb->esc_like( $query ) . '%';
		array_push( $args, $search, $search, $search, $search );
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
	$order = "CASE WHEN starts_at >= %d THEN 0 ELSE 1 END, CASE WHEN starts_at >= %d THEN starts_at END ASC, starts_at DESC, id DESC";
	$order_args = array( $now, $now );
	$sort_orders = array( 'earliest' => 'starts_at ASC, id ASC', 'latest' => 'starts_at DESC, id DESC', 'created' => 'id DESC' );
	if ( isset( $sort_orders[ $sort ] ) ) {
		$order = $sort_orders[ $sort ];
		$order_args = array();
		$filters['sort'] = $sort;
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * {$sql} ORDER BY {$order} LIMIT %d OFFSET %d",
		array_merge( $args, $order_args, array( $per_page, ( $page - 1 ) * $per_page ) )
	), ARRAY_A );
	?>
	<div class="wpb-admin-section-head"><h2>預約訂單列表</h2></div>
	<div class="wpb-admin-card wpb-admin-list-card">
	<nav class="wpb-admin-facets" aria-label="依預約狀態查看">
		<?php foreach ( array( '' => '全部' ) + $states as $value => $name ) : ?>
			<?php $facet = array_filter( array_replace( $filters, array( 'status' => $value ) ), 'strlen' ); ?>
			<a class="wpb-admin-btn" <?php echo $status === $value ? 'aria-current="page"' : ''; ?>
				href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings' ) + $facet ) ); ?>"><?php echo esc_html( $name ); ?></a>
		<?php endforeach; ?>
	</nav>
	<form class="wpb-admin-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="wp-booking">
		<input type="hidden" name="tab" value="bookings">
		<?php if ( isset( $filters['queue'] ) ) : ?><input type="hidden" name="queue" value="<?php echo esc_attr( $queue ); ?>"><?php endif; ?>
		<label>搜尋預約<input type="search" name="q" maxlength="100" placeholder="編號、姓名、電話或電子郵件" value="<?php echo esc_attr( $query ); ?>"></label>
		<label>狀態<select name="status">
			<option value="">全部狀態</option>
			<?php foreach ( $states as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select></label>
		<label>服務<select name="service">
			<option value="">全部服務</option>
			<?php foreach ( $services as $id => $item ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $service, $id ); ?>><?php echo esc_html( $item['name'] ); ?></option>
			<?php endforeach; ?>
		</select></label>
		<label>排序<select name="sort">
			<?php foreach ( array( '' => '即將到來優先', 'earliest' => '預約時間由早到晚', 'latest' => '預約時間由晚到早', 'created' => '最近建立優先' ) as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sort, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select></label>
		<label>起始日期<input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ?? '' ); ?>"></label>
		<label>結束日期<input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ?? '' ); ?>"></label>
		<button class="wpb-admin-btn" type="submit">篩選</button>
		<?php if ( $filters ) : ?>
			<a href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings' ) ) ); ?>">清除篩選</a>
		<?php endif; ?>
	</form>
	<p class="wpb-scroll-hint" data-wpb-scroll-hint hidden>可向右滑動查看其餘欄位 <span aria-hidden="true">→</span></p>
	<div class="wpb-admin-overflow" data-wpb-scroll tabindex="0" role="region" aria-label="預約訂單表格">
		<table class="widefat striped">
			<thead><tr><th>預約客戶</th><th>服務項目</th><th>狀態</th><th>服務費用</th><th>預約時段</th><th>操作</th></tr></thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php echo esc_html( $wpdb->last_error ? '無法讀取預約，請稍後再試。' : '目前沒有符合條件的預約。' ); ?></td></tr>
				<?php endif; ?>
		<?php foreach ( $rows as $row ) : ?>
					<?php $state = pending_expired( $row, $now ) ? 'expired' : $row['status']; ?>
					<tr>
						<td><strong><?php echo esc_html( $row['customer_name'] ?: '匿名預約' ); ?></strong><small><?php echo esc_html( $row['reference'] ); ?></small></td>
						<td><?php echo esc_html( $row['service_name'] ); ?></td>
						<td><span class="wpb-admin-status wpb-admin-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $states[ $state ] ?? $state ); ?></span></td>
						<td><?php echo esc_html( booking_price( $row['price'] ?? null ) ); ?></td>
						<td><?php echo esc_html( booking_time( $row ) ); ?></td>
						<td>
							<button class="wpb-admin-btn" type="button" data-wpb-dialog="wpb-detail-<?php echo esc_attr( $row['id'] ); ?>" aria-label="<?php echo esc_attr( '查看預約 ' . $row['reference'] ); ?>">詳細資料</button>
							<?php render_quick_confirm( $row ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="wpb-admin-pages">
		<span><?php echo esc_html( sprintf( '共 %d 筆預約，第 %d／%d 頁', $total, $page, $max_page ) ); ?></span>
		<?php if ( $page > 1 ) : ?>
			<a class="wpb-admin-btn" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings', 'paged' => $page - 1 ) + $filters ) ); ?>">上一頁</a>
		<?php endif; ?>
		<?php if ( $page < $max_page ) : ?>
			<a class="wpb-admin-btn" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings', 'paged' => $page + 1 ) + $filters ) ); ?>">下一頁</a>
		<?php endif; ?>
	</div>
	</div>
	<?php $notices = booking_notices( array_column( $rows, 'id' ) ); ?>
	<?php foreach ( $rows as $row ) : ?>
		<?php render_booking_detail( $row, $states, $notices[ $row['id'] ] ?? array() ); ?>
	<?php endforeach; ?>
	<?php
}

function render_booking_page( int $id ): void {
	$booking = find_booking( $id );
	?>
	<div class="wpb-admin-section-head">
		<a class="wpb-admin-btn" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings' ) ) ); ?>">返回預約列表</a>
		<?php if ( $booking ) : ?>
			<a class="wpb-admin-btn" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'calendar', 'day' => wp_date( 'Y-m-d', (int) $booking['starts_at'] ) ) ) ); ?>">查看當日行事曆</a>
		<?php endif; ?>
	</div>
	<?php
	if ( ! $booking ) {
		echo '<p class="wpb-admin-card" role="status">找不到這筆預約。</p>';
		return;
	}
	$notices = booking_notices( array( $id ) );
	render_booking_detail( $booking, status_labels(), $notices[ $id ] ?? array(), true );
}

function render_quick_confirm( array $booking ): void {
	if ( ! in_array( 'confirmed', allowed_statuses( $booking ), true ) ) {
		return;
	}
	?>
	<form class="wpb-admin-quick-confirm" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<?php admin_form_fields( 'status', 'wpb-booking-' . $booking['id'] ); ?>
		<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>">
		<button class="wpb-admin-btn wpb-admin-primary" type="submit" name="status" value="confirmed"
			aria-label="<?php echo esc_attr( '確認預約 ' . $booking['reference'] ); ?>">確認預約</button>
	</form>
	<?php
}

function render_booking_detail( array $booking, array $states, array $notices, bool $standalone = false ): void {
	$state = pending_expired( $booking, time() ) ? 'expired' : $booking['status'];
	$lines = array(
		'預約時段' => booking_time( $booking ),
		'服務費用' => booking_price( $booking['price'] ?? null ),
		'客戶姓名' => $booking['customer_name'],
		'電子郵件' => $booking['customer_email'],
		'聯絡電話' => $booking['customer_phone'],
		'客戶備註' => $booking['note'],
	);
	if ( 'pending' === $state && ! empty( $booking['hold_until'] ) ) {
		$lines['確認期限'] = wp_date( 'Y/m/d H:i', (int) $booking['hold_until'] );
	}
	?>
	<?php if ( $standalone ) : ?>
	<section class="wpb-admin-card wpb-admin-detail">
	<?php else : ?>
	<dialog class="wpb-admin-dialog wpb-admin-drawer" id="wpb-detail-<?php echo esc_attr( $booking['id'] ); ?>" aria-label="預約訂單詳情">
		<button class="wpb-admin-close" type="button" data-wpb-dismiss aria-label="關閉">×</button>
	<?php endif; ?>
		<span class="wpb-admin-brand"><?php echo esc_html( $booking['reference'] ); ?></span>
		<h2><?php echo esc_html( $booking['service_name'] ); ?></h2>
		<span class="wpb-admin-status wpb-admin-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $states[ $state ] ); ?></span>
		<dl>
			<?php foreach ( $lines as $label => $value ) : ?>
				<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo nl2br( esc_html( '' === $value ? '—' : $value ) ); ?></dd></div>
			<?php endforeach; ?>
		</dl>
		<?php render_status_form( $booking, $states ); ?>
		<?php if ( may_reschedule( $booking ) ) : ?>
			<details class="wpb-admin-booking-section">
				<summary>直接改期</summary>
				<?php render_management_form( (int) $booking['id'] ); ?>
			</details>
		<?php endif; ?>
		<?php render_booking_activity( $booking, $notices ); ?>
		<?php if ( '' !== $booking['customer_email'] ) : ?>
			<form class="wpb-admin-note" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php admin_form_fields( 'note', 'wpb-note-' . $booking['id'] ); ?>
				<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>">
				<label>管理員內部備註<textarea name="admin_note" maxlength="500" rows="3"><?php echo esc_textarea( $booking['admin_note'] ?? '' ); ?></textarea></label>
				<button class="wpb-admin-btn" type="submit">儲存備註</button>
			</form>
		<?php endif; ?>
	<?php if ( $standalone ) : ?></section><?php else : ?></dialog><?php endif; ?>
	<?php
}

function render_booking_activity( array $booking, array $notices ): void {
	$kinds = notice_types();
	$labels = array( 'accepted' => '寄信系統已接受', 'failed' => '寄送失敗', 'skipped' => '已略過', 'sending' => '寄送結果待確認' );
	$history = array_reverse( booking_history( $booking ) );
	$events = status_labels() + array( 'admin_moved' => '管理員改期', 'customer_moved' => '客戶自行改期' );
	?>
	<details class="wpb-admin-booking-section">
		<summary>提醒排程與寄送紀錄</summary>
		<ul>
			<?php foreach ( array( 'remind_day', 'remind_soon' ) as $field ) : ?>
				<?php if ( ! empty( $booking[ $field ] ) ) : ?>
					<li><?php echo esc_html( $kinds[ $field ] . ' · 排程 ' . wp_date( 'Y/m/d H:i', (int) $booking[ $field ] ) ); ?></li>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( $notices as $notice ) : ?>
				<li>
					<?php
					$time = (int) ( $notice['sent_at'] ?: $notice['due_at'] );
					$who = 'admin' === $notice['recipient'] ? '管理員' : '客戶';
					echo esc_html( ( $kinds[ $notice['kind'] ] ?? $notice['kind'] ) . ' · ' . $who . ' · ' . ( $labels[ $notice['state'] ] ?? $notice['state'] ) );
					echo $time ? esc_html( ' · ' . wp_date( 'Y/m/d H:i', $time ) ) : '';
					echo $notice['detail'] ? esc_html( ' · ' . $notice['detail'] ) : '';
					?>
				</li>
			<?php endforeach; ?>
			<?php if ( ! $notices && empty( $booking['remind_day'] ) && empty( $booking['remind_soon'] ) ) : ?><li>目前沒有排程或寄送紀錄。</li><?php endif; ?>
		</ul>
		<?php if ( in_array( 'failed', array_column( $notices, 'state' ), true ) ) : ?><p>寄信失敗時可查看網站 SMTP 外掛的記錄。</p><?php endif; ?>
		<?php if ( is_email( $booking['customer_email'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php admin_form_fields( 'resend', 'wpb-resend-' . $booking['id'] ); ?>
				<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>">
				<button class="wpb-admin-btn" type="submit">重寄最新預約通知</button>
			</form>
		<?php endif; ?>
	</details>
	<?php if ( $history ) : ?>
		<details class="wpb-admin-booking-section">
			<summary>預約變更紀錄</summary>
			<ul>
				<?php foreach ( $history as $entry ) : ?>
					<li>
						<?php
						echo esc_html( ( $events[ $entry['kind'] ] ?? $entry['kind'] ) . ' · ' . wp_date( 'Y/m/d H:i', $entry['at'] ) );
						if ( in_array( $entry['kind'], array( 'admin_moved', 'customer_moved' ), true ) ) {
							echo esc_html( ' · 原時段 ' . booking_time( array( 'starts_at' => $entry['previous_start'], 'ends_at' => $entry['previous_end'], 'timezone' => $entry['timezone'] ) ) );
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
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
		<button class="wpb-admin-btn wpb-admin-primary" type="submit">更新狀態</button>
	</form>
	<?php
}
