<?php

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

function add_action( ...$args ): void {}
function add_filter( ...$args ): void {}
function plugin_basename( string $path ): string { return $path; }
function get_site_transient( string $key ) { return false; }
function set_site_transient( ...$args ): void {}
function wp_safe_remote_get( string $url, array $args ): array {
	$GLOBALS['requests']++;
	return array(
		'tag_name' => 'v9.0.0',
		'assets' => array( array(
			'name' => 'wp-booking.zip',
			'state' => 'uploaded',
			'browser_download_url' => $GLOBALS['package'],
		) ),
	);
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( array $response ): int { return 200; }
function wp_remote_retrieve_body( array $response ): string { return json_encode( $response ); }
class WP_Error { public function __construct( ...$args ) {} }

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require __DIR__ . '/../includes/licensing.php';

define( 'Wumetax\Booking\FILE', 'wp-booking/wp-booking.php' );
define( 'Wumetax\Booking\VERSION', '1.4.2' );
$file = Wumetax\Booking\FILE;
$package = Wumetax\Booking\RELEASE_ROOT . '/download/v9.0.0/wp-booking.zip';
$requests = 0;

expect( ! Wumetax\Booking\booking_license_is_active(), 'Missing Toolbox must deny bookings.' );
expect( false === Wumetax\Booking\booking_update_offer( false, array(), $file ), 'Missing Toolbox must deny updates.' );
expect( 0 === $requests, 'An unlicensed site must not request release data.' );

if ( ! function_exists( 'wutm_license_is_valid' ) ) {
	function wutm_license_is_valid(): bool { return $GLOBALS['valid']; }
	function wutm_license_can_update(): bool { return $GLOBALS['can_update']; }
}

$valid = false;
$can_update = true;
expect( ! Wumetax\Booking\booking_updates_allowed(), 'Invalid Toolbox license must deny updates.' );
$valid = true;
$can_update = false;
expect( Wumetax\Booking\booking_license_is_active(), 'An active Toolbox license must allow bookings.' );
expect( ! Wumetax\Booking\booking_updates_allowed(), 'Update suspension must be respected.' );
$can_update = true;
$offer = Wumetax\Booking\booking_update_offer( false, array(), $file );
expect( $offer['package'] === $package && '9.0.0' === $offer['version'], 'Approved releases must be offered.' );
expect( false === Wumetax\Booking\booking_update_download( false, $package, null, array( 'plugin' => $file ) ), 'The approved package must pass the WordPress downloader.' );
$can_update = false;
expect( is_wp_error( Wumetax\Booking\booking_update_download( false, $package, null ) ), 'A stale offer must not bypass update suspension.' );
expect( true === Wumetax\Booking\booking_update_download( true, 'https://example.com/another.zip', null ), 'Other plugins must be unaffected.' );
echo "Shared license tests passed.\n";
