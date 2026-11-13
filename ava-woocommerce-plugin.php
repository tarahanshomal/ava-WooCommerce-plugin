<?php
/**
 * Plugin Name: Ava SMS for WooCommerce
 * Plugin URI: https://github.com/tarahanshomal/ava-WooCommerce-plugin
 * Description: Send WooCommerce order SMS through Ava, with editable templates, background processing and delivery reports.
 * Version: 1.2.3
 * Author: طراحان شمال
 * Author URI: https://profiles.wordpress.org/tarahanshomal/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ava-sms-for-woocommerce
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 11.2.1
 */

defined( 'ABSPATH' ) || exit;

define( 'AVA_WOO_VERSION', '1.2.3' );
define( 'AVA_WOO_FILE', __FILE__ );
define( 'AVA_WOO_PATH', plugin_dir_path( __FILE__ ) );

foreach ( array( 'phone', 'template', 'settings', 'api', 'queue', 'notifications', 'admin' ) as $ava_woo_class ) {
    require_once AVA_WOO_PATH . 'includes/class-' . $ava_woo_class . '.php';
}
unset( $ava_woo_class );

register_activation_hook( __FILE__, array( 'AvaWoo\\Queue', 'install' ) );
register_deactivation_hook( __FILE__, array( 'AvaWoo\\Queue', 'deactivate' ) );

add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AVA_WOO_FILE, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', AVA_WOO_FILE, true );
    }
} );

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) || version_compare( WC_VERSION, '8.2', '<' ) ) {
        add_action( 'admin_notices', static function () {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'پیامک آوا به ووکامرس نسخهٔ ۸٫۲ یا جدیدتر نیاز دارد.', 'ava-sms-for-woocommerce' ) . '</p></div>';
            }
        } );
        return;
    }
    \AvaWoo\Queue::init();
    \AvaWoo\Notifications::init();
    if ( is_admin() ) {
        \AvaWoo\Admin::init();
    }
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), static function ( $links ) {
    array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=ava-woo-sms' ) ) . '">' . esc_html__( 'تنظیمات', 'ava-sms-for-woocommerce' ) . '</a>' );
    return $links;
} );
