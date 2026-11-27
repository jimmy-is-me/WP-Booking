<?php

namespace Wumetax\Booking;

use DateTimeImmutable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

const API = 'wp-booking/v1';

function register_routes(): void {
	register_management_routes();
	register_rest_route( API, '/calendar', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => __NAMESPACE__ . '\\calendar_response',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( API, '/slots', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => __NAMESPACE__ . '\\slots_response',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( API, '/month', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => __NAMESPACE__ . '\\month_response',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( API, '/bookings', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => __NAMESPACE__ . '\\booking_response',
		'permission_callback' => __NAMESPACE__ . '\\permit_public_booking',
	) );
	register_rest_route( API, '/admin-bookings', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => __NAMESPACE__ . '\\admin_booking_response',
		'permission_callback' => static function (): bool {
			return can_manage_bookings();
		},
	) );
}

function prevent_rest_cache( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
	if ( 0 === strpos( $request->get_route(), '/' . API . '/' ) ) {
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
	}
	return $response;
}

function calendar_response(): array {
	$timezone = wp_timezone();
	$now = new DateTimeImmutable( 'now', $timezone );
	$available = array();
	$config = settings();
	foreach ( services() as $id => $service ) {
		if ( $service['enabled'] ) {
			$available[] = array(
				'id'           => $id,
				'name'         => $service['name'],
				'description'  => $service['description'],
				'duration'     => (int) $service['duration'],
				'price'        => isset( $service['price'] ) ? booking_price( $service['price'] ) : null,
				'capacity_unit' => $service['capacity_unit'] ?? 'bookings',
				'price_amount' => isset( $service['price'] ) ? (int) $service['price'] : null,
				'dates'        => calendar_limits( service_schedule( $config, $service ), $now ),
			);
		}
	}
	return array(
		'services' => $available,
		'dates'    => calendar_limits( $config, $now ),
		'timezone' => $timezone->getName(),
	);
}

function slots_response( WP_REST_Request $request ) {
	$id = $request->get_query_params()['service'] ?? null;
	$date = $request->get_query_params()['date'] ?? null;
	$timezone = wp_timezone();
	$day = is_string( $date ) ? calendar_date( $date, $timezone ) : null;
	$service = is_string( $id ) ? services()[ $id ] ?? null : null;
	if ( ! $day || ! $service || ! $service['enabled'] ) {
		return new WP_Error( 'wpb_selection', '請選擇有效的服務與日期。', array( 'status' => 400 ) );
	}

	return slots_for_day( $day, (int) $service['duration'], service_schedule( settings(), $service ) + array( 'service_id' => $id ) );
}

function slots_for_day( DateTimeImmutable $day, int $duration, array $config, int $exclude = 0, int $party_size = 1 ) {
	$now = new DateTimeImmutable( 'now', $day->getTimezone() );
	$starts = scheduled_slots( $day, $duration, $config, $now );
	if ( ! $starts ) {
		return array( 'slots' => array() );
	}

	$before = $config['buffer_before'] * MINUTE_IN_SECONDS;
	$after = ( $duration + $config['buffer_after'] ) * MINUTE_IN_SECONDS;
	$busy = overlaps( array_key_first( $starts ) - $before, array_key_last( $starts ) + $after, $exclude );
	if ( is_wp_error( $busy ) ) {
		return $busy;
	}

	$slots = array();
	$services = services();
	foreach ( $starts as $timestamp => $label ) {
		$remaining = remaining_capacity( $timestamp - $before, $timestamp + $after, $busy, $config, $services );
		if ( $remaining >= required_capacity( $config, $party_size ) ) {
			$slots[] = array( 'start' => $timestamp, 'label' => $label, 'remaining' => $remaining );
		}
	}
	return array( 'slots' => $slots );
}

function month_response( WP_REST_Request $request ) {
	$params = $request->get_query_params();
	$id = $params['service'] ?? null;
	$month = $params['month'] ?? null;
	$timezone = wp_timezone();
	$first = is_string( $month ) ? calendar_date( $month . '-01', $timezone ) : null;
	$service = is_string( $id ) ? services()[ $id ] ?? null : null;
	$config = service_schedule( settings(), $service ?? array() ) + array( 'service_id' => $id );
	$now = new DateTimeImmutable( 'now', $timezone );
	$limits = calendar_limits( $config, $now );
	if ( ! $first || ! $service || ! $service['enabled']
		|| $month < substr( $limits['min'], 0, 7 ) || $month > substr( $limits['max'], 0, 7 ) ) {
		return new WP_Error( 'wpb_selection', '請選擇預約範圍內的月份與有效的服務。', array( 'status' => 400 ) );
	}

	return month_availability( $first, (int) $service['duration'], $config, $now );
}

function month_availability( DateTimeImmutable $first, int $duration, array $config, DateTimeImmutable $now, int $exclude = 0, int $original = 0, int $party_size = 1 ) {
	$limits = calendar_limits( $config, $now );
	$days = array();
	$starts = array();
	$last = $first->modify( 'first day of next month' );
	for ( $day = $first; $day < $last; $day = $day->modify( 'tomorrow' ) ) {
		$date = $day->format( 'Y-m-d' );
		if ( $date < $limits['min'] || $date > $limits['max'] ) {
			$days[ $date ] = 'outside';
			continue;
		}
		if ( ! opening_hours( $day, $config ) ) {
			$days[ $date ] = 'closed';
			continue;
		}
		$times = scheduled_slots( $day, $duration, $config, $now );
		$days[ $date ] = $times ? 'full' : 'outside';
		foreach ( $times as $timestamp => $label ) {
			if ( $timestamp !== $original ) {
				$starts[ $timestamp ] = $date;
			}
		}
	}
	if ( $starts ) {
		$before = $config['buffer_before'] * MINUTE_IN_SECONDS;
		$after = ( $duration + $config['buffer_after'] ) * MINUTE_IN_SECONDS;
		$busy = overlaps( array_key_first( $starts ) - $before, array_key_last( $starts ) + $after, $exclude );
		if ( is_wp_error( $busy ) ) {
			return $busy;
		}
		$services = services();
		foreach ( $starts as $timestamp => $date ) {
			if ( 'available' !== $days[ $date ] && remaining_capacity( $timestamp - $before, $timestamp + $after, $busy, $config, $services ) >= required_capacity( $config, $party_size ) ) {
				$days[ $date ] = 'available';
			}
		}
	}
	return array( 'days' => $days );
}

function permit_public_booking( WP_REST_Request $request ) {
	$origin = $request->get_header( 'origin' );
	$home = wp_parse_url( home_url( '/' ) );
	$source = $origin ? wp_parse_url( $origin ) : array();
	$valid_origin = ! $origin || ( isset( $source['scheme'], $source['host'], $home['scheme'], $home['host'] )
		&& $source['scheme'] === $home['scheme'] && 0 === strcasecmp( $source['host'], $home['host'] )
		&& ( $source['port'] ?? ( 'https' === $source['scheme'] ? 443 : 80 ) )
			=== ( $home['port'] ?? ( 'https' === $home['scheme'] ? 443 : 80 ) ) );
	if ( '1' !== $request->get_header( 'x-wpb-request' ) || ! $valid_origin ) {
		return new WP_Error( 'wpb_origin', '請從網站的預約表單送出。', array( 'status' => 403 ) );
	}
	if ( ! $request->is_json_content_type() ) {
		return new WP_Error( 'wpb_content_type', '請使用 JSON 送出預約資料。', array( 'status' => 415 ) );
	}
	if ( strlen( $request->get_body() ) > 12000 ) {
		return new WP_Error( 'wpb_body_size', '預約資料長度超過限制。', array( 'status' => 413 ) );
	}
	return true;
}

function text_size( string $value ): int {
	return preg_match_all( '/./us', $value ) ?: 0;
}

function validate_booking( array $payload ) {
	foreach ( array( 'request_id', 'service_id', 'customer_name', 'customer_email', 'customer_phone', 'note', 'website' ) as $key ) {
		if ( ! is_string( $payload[ $key ] ?? null ) ) {
			return new WP_Error( 'wpb_input', '預約資料格式有誤，請重新確認。', array( 'status' => 400 ) );
		}
	}

	$input = array(
		'request_id'     => $payload['request_id'],
		'service_id'     => $payload['service_id'],
		'starts_at'      => $payload['starts_at'] ?? null,
		'customer_name'  => sanitize_text_field( $payload['customer_name'] ),
		'customer_email' => trim( $payload['customer_email'] ),
		'customer_phone' => sanitize_text_field( $payload['customer_phone'] ),
		'note'           => sanitize_textarea_field( $payload['note'] ),
	);
	if ( '' !== $payload['website'] || ! preg_match( '/^[a-f0-9]{32}$/D', $input['request_id'] )
		|| ! preg_match( '/^[a-f0-9]{32}$/D', $input['service_id'] )
		|| ! is_int( $input['starts_at'] ) || $input['starts_at'] <= 0 ) {
		return new WP_Error( 'wpb_input', '預約資料格式有誤，請重新確認。', array( 'status' => 400 ) );
	}
	if ( '' === $input['customer_name'] || text_size( $input['customer_name'] ) > 100
		|| strlen( $input['customer_email'] ) > 254 || ! is_email( $input['customer_email'] )
		|| text_size( $input['customer_phone'] ) > 40 || text_size( $input['note'] ) > 500 ) {
		return new WP_Error( 'wpb_contact', '請填寫姓名與有效的電子郵件，並確認電話與備註的長度。', array( 'status' => 400 ) );
	}
	if ( array_key_exists( 'expected_duration', $payload ) || array_key_exists( 'expected_price', $payload ) ) {
		$expected_price = $payload['expected_price'] ?? null;
		if ( ! is_int( $payload['expected_duration'] ?? null ) || ( null !== $expected_price && ! is_int( $expected_price ) ) ) {
			return new WP_Error( 'wpb_input', '服務資料格式有誤，請重新選擇服務。', array( 'status' => 400 ) );
		}
		$input['expected_duration'] = $payload['expected_duration'];
		$input['expected_price'] = $expected_price;
	}
	$party = $payload['party_size'] ?? 1;
	if ( ! is_int( $party ) || $party < 1 || $party > 100 ) {
		return new WP_Error( 'wpb_party', '請輸入有效的預約人數。', array( 'status' => 400 ) );
	}
	if ( $party > 1 ) {
		$input['party_size'] = $party;
	}
	return $input;
}

function booking_response( WP_REST_Request $request ) {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	// A host can provide a verified client IP when using a trusted reverse proxy.
	$ip = apply_filters( 'wpb_client_ip', $ip, $request );
	$rate_key = 'wpb_rate_' . substr( wp_hash( (string) $ip, 'nonce' ), 0, 32 );
	return create_booking_response( $request, $rate_key );
}

function admin_booking_response( WP_REST_Request $request ) {
	$permitted = permit_public_booking( $request );
	if ( is_wp_error( $permitted ) ) {
		return $permitted;
	}
	return create_booking_response( $request );
}

function create_booking_response( WP_REST_Request $request, string $rate_key = '' ) {
	if ( ! booking_license_is_active() ) {
		return new WP_Error( 'wpb_license_required', '此網站目前暫停接受新預約，請聯絡店家。', array( 'status' => 403 ) );
	}
	$payload = $request->get_json_params();
	$input = validate_booking( is_array( $payload ) ? $payload : array() );
	if ( is_wp_error( $input ) ) {
		return $input;
	}
	$result = book( $input, $rate_key );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$booking = $result['booking'];
	$status = pending_expired( $booking, time() ) ? 'expired' : $booking['status'];
	return new WP_REST_Response( array(
		'reference' => $booking['reference'],
		'receipt' => receipt_link( $booking ),
		'party_size' => (int) ( $booking['party_size'] ?? 1 ),
		'service'   => $booking['service_name'],
		'status'    => status_labels()[ $status ],
		'time'      => booking_time( $booking ),
		'price'     => isset( $booking['price'] ) ? booking_price( $booking['price'] ) : null,
	), $result['created'] ? 201 : 200 );
}

function register_form(): void {
	wp_register_style( 'wp-booking', plugins_url( 'assets/booking.css', FILE ), array(), VERSION );
	wp_register_script( 'wp-booking', plugins_url( 'assets/booking.js', FILE ), array(), VERSION, true );
	add_shortcode( 'wp_booking', __NAMESPACE__ . '\\render_form' );
	add_shortcode( 'wp_booking_availability', __NAMESPACE__ . '\\render_availability' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_shortcode_style' );
}

function enqueue_shortcode_style(): void {
	$post = get_queried_object();
	if ( $post instanceof \WP_Post && ( has_shortcode( $post->post_content, 'wp_booking' ) || has_shortcode( $post->post_content, 'wp_booking_availability' ) ) ) {
		wp_enqueue_style( 'wp-booking' );
	}
}

function render_form(): string {
	return booking_wizard();
}

function render_availability(): string {
	return booking_wizard( false, '', true );
}

function booking_wizard( bool $admin = false, string $selected_date = '', bool $availability = false ): string {
	$accepting = $admin || $availability || booking_license_is_active();
	wp_enqueue_style( 'wp-booking' );
	wp_enqueue_script( 'wp-booking' );
	$id = wp_unique_id( 'wpb-' );
	$privacy = get_privacy_policy_url();

	ob_start();
	?>
	<section class="wpb<?php echo $admin ? ' wpb-compact' : ' wpb-embedded'; ?>" aria-labelledby="<?php echo esc_attr( $id . '-title' ); ?>"
		<?php if ( $accepting ) : ?>data-wpb-calendar="<?php echo esc_url( rest_url( API . '/calendar' ) ); ?>"<?php endif; ?>
		data-wpb-month="<?php echo esc_url( rest_url( API . '/month' ) ); ?>"
		data-wpb-slots="<?php echo esc_url( rest_url( API . '/slots' ) ); ?>"
		<?php if ( ! $availability ) : ?>data-wpb-bookings="<?php echo esc_url( rest_url( API . ( $admin ? '/admin-bookings' : '/bookings' ) ) ); ?>"<?php endif; ?>
		<?php if ( $admin ) : ?>
			data-wpb-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-wpb-date="<?php echo esc_attr( $selected_date ); ?>"
		<?php endif; ?>>
<?php if ( $admin ) : ?>
		<div class="wpb-window-bar" aria-hidden="true"><span class="wpb-window-lights"><i></i><i></i><i></i></span><span class="wpb-window-tab">WP Booking</span></div>
		<?php endif; ?>
		<header class="wpb-header">
			<?php if ( $admin ) : ?>
				<h2 id="<?php echo esc_attr( $id . '-title' ); ?>">新增預約</h2>
			<?php else : ?>
				<h2 id="<?php echo esc_attr( $id . '-title' ); ?>"><?php echo $availability ? '可預約時段' : '線上預約'; ?></h2>
			<?php endif; ?>
		</header>
		<?php if ( ! $admin && ! $availability ) : ?>
			<details class="wpb-lookup">
				<summary>我的預約</summary>
				<?php render_receipt_panel(); ?>
			</details>
		<?php endif; ?>
		<?php if ( $accepting ) : ?>
		<noscript><p class="wpb-message">請啟用 JavaScript 以使用預約表單。</p></noscript>
		<p class="wpb-message" data-wpb="calendar-message" role="status" hidden></p>
		<button class="wpb-button wpb-secondary" type="button" data-wpb="retry" hidden>重新載入</button>
		<div class="wpb-wizard" data-wpb="wizard" hidden>
			<ol class="wpb-stepper<?php echo $availability ? ' wpb-stepper-short' : ''; ?>" aria-label="預約進度">
				<?php foreach ( ( $availability ? array( '選擇服務', '日期時段' ) : array( '選擇服務', '日期時段', '聯絡資料', '預約完成' ) ) as $index => $label ) : ?>
					<li data-wpb-step="<?php echo esc_attr( $index + 1 ); ?>"><span><?php echo esc_html( $index + 1 ); ?></span><?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ol>
			<p class="wpb-feedback" data-wpb="flow-message" role="status" hidden></p>
			<div class="wpb-stage" data-wpb-panel="1">
				<h3 tabindex="-1">選擇預約服務</h3>
				<div class="wpb-services" data-wpb="services" role="group" aria-label="選擇服務"></div>
				<div class="wpb-actions wpb-actions-end"><button class="wpb-button" type="button" data-wpb="date-next" disabled>下一步：選擇日期 <span aria-hidden="true">→</span></button></div>
			</div>
			<div class="wpb-stage" data-wpb-panel="2" hidden>
				<h3 tabindex="-1">選擇日期與時段</h3>
				<div class="wpb-columns">
					<div class="wpb-calendar">
						<div class="wpb-month">
							<h4 data-wpb="month-label"></h4>
							<button class="wpb-icon-button" type="button" data-wpb="previous" aria-label="上個月">‹</button>
							<button class="wpb-icon-button" type="button" data-wpb="next" aria-label="下個月">›</button>
						</div>
						<div class="wpb-week" aria-hidden="true"><?php foreach ( array( '日', '一', '二', '三', '四', '五', '六' ) as $day ) : ?><span><?php echo esc_html( $day ); ?></span><?php endforeach; ?></div>
						<div class="wpb-days" data-wpb="days" role="group" aria-label="可預約日期"></div>
						<p class="wpb-help" data-wpb="month-message" role="status" hidden></p>
						<button class="wpb-button wpb-secondary" data-wpb="retry-month" type="button" hidden>重新查詢月曆</button>
						<ul class="wpb-legend"><li class="wpb-available">可預約</li><li class="wpb-closed">公休</li><li class="wpb-full">額滿</li></ul>
					</div>
					<div class="wpb-time-panel">
						<h4 data-wpb="date-label">可預約時段</h4>
						<p class="wpb-help" data-wpb="timezone"></p>
						<p class="wpb-help" data-wpb="slot-message" role="status">請先選擇日期。</p>
						<div class="wpb-slots" data-wpb="slots" role="group" aria-label="可預約時段"></div>
					</div>
				</div>
				<div class="wpb-actions">
					<button class="wpb-button wpb-secondary" data-wpb="back-service" type="button"><span aria-hidden="true">←</span> 上一步</button>
					<?php if ( ! $availability ) : ?><button class="wpb-button" data-wpb="contact-next" type="button" disabled>下一步：填寫資料 <span aria-hidden="true">→</span></button><?php endif; ?>
				</div>
			</div>
			<?php if ( ! $availability ) : ?>
			<div class="wpb-stage" data-wpb-panel="3" hidden>
				<h3 tabindex="-1">填寫聯絡資料</h3>
				<form class="wpb-form" data-wpb="form">
					<div class="wpb-columns wpb-contact">
						<div class="wpb-contact-fields">
							<label class="wpb-field"><span>姓名</span><input name="customer_name" autocomplete="<?php echo $admin ? 'off' : 'name'; ?>" maxlength="100" required></label>
							<label class="wpb-field"><span>電子郵件</span><input type="email" name="customer_email" autocomplete="<?php echo $admin ? 'off' : 'email'; ?>" maxlength="254" required></label>
							<label class="wpb-field" data-wpb="party-field" hidden><span>預約人數</span><input name="party_size" type="number" value="1" min="1" max="100" step="1"></label>
							<label class="wpb-field"><span>電話 <small>選填</small></span><input type="tel" name="customer_phone" autocomplete="<?php echo $admin ? 'off' : 'tel'; ?>" maxlength="40"></label>
							<label class="wpb-field"><span>備註 <small>選填</small></span><textarea name="note" rows="3" maxlength="500"></textarea></label>
							<div hidden><input name="website" tabindex="-1" autocomplete="off" aria-label="請留空"></div>
							<p class="wpb-help">聯絡資料用於處理預約與寄送通知。<?php if ( $privacy ) : ?> <a href="<?php echo esc_url( $privacy ); ?>">隱私權政策</a><?php endif; ?></p>
						</div>
						<div class="wpb-summary">
							<h4>預約摘要</h4>
							<dl>
								<div><dt>服務</dt><dd data-wpb="summary-service"></dd></div>
								<div><dt>日期與時段</dt><dd data-wpb="summary-time"></dd></div>
								<div><dt>服務時間</dt><dd data-wpb="summary-duration"></dd></div>
								<div data-wpb="summary-price-row" hidden><dt>服務費用</dt><dd data-wpb="summary-price"></dd></div>
							</dl>
						</div>
					</div>
					<p class="wpb-feedback" data-wpb="submit-message" role="alert" hidden></p>
					<div class="wpb-actions">
						<button class="wpb-button wpb-secondary" data-wpb="back-date" type="button"><span aria-hidden="true">←</span> 上一步</button>
						<button class="wpb-button" type="submit" data-wpb="submit">送出預約</button>
					</div>
				</form>
			</div>
			<div class="wpb-stage wpb-result" data-wpb-panel="4" data-wpb="result" hidden>
				<span class="wpb-confirm-icon" aria-hidden="true">✓</span>
				<h3 tabindex="-1">預約已送出</h3>
				<p data-wpb="result-message"></p>
				<dl class="wpb-summary">
					<div><dt>預約編號</dt><dd data-wpb="reference"></dd></div>
					<div><dt>預約服務</dt><dd data-wpb="service"></dd></div>
					<div><dt>日期與時段</dt><dd data-wpb="time"></dd></div>
					<div><dt>預約狀態</dt><dd data-wpb="status"></dd></div>
					<div><dt>人數</dt><dd data-wpb="result-party"></dd></div>
					<div data-wpb="result-price-row" hidden><dt>服務費用</dt><dd data-wpb="result-price"></dd></div>
				</dl>
				<?php render_booking_qr(); ?>
				<?php if ( $admin ) : ?>
					<a class="wpb-button" href="<?php echo esc_url( admin_url_for( array( 'tab' => 'bookings' ) ) ); ?>">返回預約訂單列表</a>
				<?php else : ?>
					<button class="wpb-button wpb-secondary" type="button" data-wpb="another">新增另一筆預約</button>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php else : ?>
			<p class="wpb-message" role="status">目前暫停接受新預約，請聯絡店家。</p>
		<?php endif; ?>
		<?php if ( ! $admin ) : ?>
			<?php powered_by(); ?>
		<?php endif; ?>
	</section>
	<?php
	return ob_get_clean();
}

function powered_by(): void {
	echo '<footer class="wpb-credit">Powered by <a href="https://wumetax.com/" rel="noopener">wumetax</a></footer>';
}

function wpb_icon( string $name ): string {
	$paths = array(
		'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3h.01"/>',
		'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'amount' => '<rect x="2" y="5" width="20" height="14" rx="3"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01m12 0h.01"/>',
		'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4m8-4v4M3 10h18m-13 5h2m4 0h2"/>',
		'overview' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
		'bookings' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8m-8 4h8m-8 4h5"/>',
		'services' => '<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/>',
		'settings' => '<path d="M4 6h5m4 0h7M4 12h9m4 0h3M4 18h3m4 0h9"/><circle cx="11" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="9" cy="18" r="2"/>',
	);
	return isset( $paths[ $name ] ) ? '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $name ] . '</svg>' : '';
}
