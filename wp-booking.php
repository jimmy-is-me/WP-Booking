<?php
/**
 * Plugin Name: WP Booking
 * Description: 繁體中文線上預約、服務排程、預約管理與電子郵件通知。
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: WUMETAX 末特數位科技
 * Author URI: https://wumetax.com/
 * License: GPL-2.0-or-later
 * Text Domain: wp-booking
 */

namespace Wumetax\Booking;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/includes/schedule.php';
require_once __DIR__ . '/includes/bookings.php';
require_once __DIR__ . '/includes/public.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/admin-calendar.php';
require_once __DIR__ . '/includes/privacy.php';

register_activation_hook( FILE, __NAMESPACE__ . '\\install_schema' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_install_schema' );
add_action( 'init', __NAMESPACE__ . '\\register_form' );
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\\prevent_rest_cache', 10, 3 );
add_action( 'admin_menu', __NAMESPACE__ . '\\register_admin' );
add_action( 'admin_post_wpb_save', __NAMESPACE__ . '\\handle_admin' );
add_action( 'admin_init', __NAMESPACE__ . '\\register_privacy_policy' );
add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', __NAMESPACE__ . '\\register_eraser' );
