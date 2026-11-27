<?php

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/bookings.php';

use function Wumetax\Booking\remaining_capacity;
use function Wumetax\Booking\required_capacity;

function expect_capacity( int $expected, int $value ): void {
	if ( $expected !== $value ) {
		throw new RuntimeException( "Expected {$expected}, got {$value}" );
	}
}

$config = array( 'service_id' => 'a', 'capacity' => 4, 'capacity_unit' => 'people', 'calendar_mode' => 'shared' );
$first = array( 'starts_at' => 10, 'ends_at' => 20, 'service_id' => 'a', 'party_size' => 2 );
$second = array( 'starts_at' => 20, 'ends_at' => 30, 'service_id' => 'a', 'party_size' => 3 );
$parallel = array( 'starts_at' => 15, 'ends_at' => 25, 'service_id' => 'b', 'party_size' => 1 );

expect_capacity( 1, remaining_capacity( 10, 30, array( $first, $second ), $config, array() ) );
expect_capacity( 4, remaining_capacity( 30, 40, array( $first, $second ), $config, array() ) );
expect_capacity( 0, remaining_capacity( 10, 30, array( $parallel ), $config, array() ) );
expect_capacity( 4, remaining_capacity( 10, 30, array( $parallel ), $config, array( 'b' => array( 'calendar_mode' => 'independent' ) ) ) );
$config['calendar_mode'] = 'independent';
expect_capacity( 2, remaining_capacity( 10, 20, array( $first, $parallel ), $config, array() ) );
expect_capacity( 3, required_capacity( $config, 3 ) );
$config['capacity_unit'] = 'bookings';
expect_capacity( 3, remaining_capacity( 10, 30, array( $first, $second ), $config, array() ) );
expect_capacity( 1, required_capacity( $config, 3 ) );
echo "Capacity checks passed.\n";
