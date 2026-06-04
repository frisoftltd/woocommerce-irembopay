<?php
/**
 * Plugin Name:       WooCommerce IremboPay Gateway
 * Plugin URI:        https://github.com/frisoftltd/woocommerce-irembopay
 * Description:       Accept payments via IremboPay with built-in subscriptions for Tutor LMS.
 * Version:           2.8.1
 * Author:            Fri Soft Ltd
 * Author URI:        https://frisoft.rw
 * License:           GPL-2.0-or-later
 * Text Domain:       wc-irembopay
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   8.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WC_IREMBOPAY_VERSION',     '2.8.1' );
define( 'WC_IREMBOPAY_PLUGIN_FILE', __FILE__ );
define( 'WC_IREMBOPAY_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WC_IREMBOPAY_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

final class WC_IremboPay {
    private static ?WC_IremboPay $instance = null;
    public static function instance(): self {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() { $this->includes(); $this->hooks(); }

    private function includes(): void {
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-logger.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-api.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-github-updater.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-webhook.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-wc-gateway-irembopay.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-subscription-db.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-subscription-manager.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-subscription-cron.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-subscription-product.php';
        require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/admin/class-irembopay-subscriptions-admin.php';
        if ( defined( 'TUTOR_VERSION' ) || class_exists( 'Tutor\Init' ) ) {
            require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-tutor-integration.php';
        }
    }

    private function hooks(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );
        add_action( 'template_redirect', [ $this, 'handle_payment_page' ] );
        add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );
        add_action( 'plugins_loaded', [ $this, 'maybe_boot_tutor_integration' ], 20 );
        add_action( 'plugins_loaded', function() {
            new IremboPay_Subscription_Cron();
            new IremboPay_Subscription_Product();
            new IremboPay_Subscriptions_Admin();
        }, 15 );
        add_action( 'admin_init', [ $this, 'boot_updater' ] );
        add_action( 'admin_init', [ $this, 'handle_check_update_request' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( WC_IREMBOPAY_PLUGIN_FILE ), [ $this, 'add_check_update_link' ] );
    }

    public function boot_updater(): void {
        if ( ! is_admin() ) { return; }
        $token = defined( 'WC_IREMBOPAY_GITHUB_TOKEN' ) ? WC_IREMBOPAY_GITHUB_TOKEN : '';
        new IremboPay_GitHub_Updater(
            WC_IREMBOPAY_PLUGIN_FILE,
            'frisoftltd',
            'woocommerce-irembopay',
            $token
        );
    }

    public function add_check_update_link( array $links ): array {
        $url = wp_nonce_url(
            add_query_arg( 'irembopay_check_update', '1', admin_url( 'plugins.php' ) ),
            'irembopay_check_update'
        );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . __( 'Check for Updates', 'wc-irembopay' ) . '</a>' );
        return $links;
    }

    public function handle_check_update_request(): void {
        if ( ! isset( $_GET['irembopay_check_update'] ) || $_GET['irembopay_check_update'] !== '1' ) { return; }
        check_admin_referer( 'irembopay_check_update' );
        if ( ! current_user_can( 'update_plugins' ) ) { wp_die( 'Insufficient permissions.' ); }
        delete_site_transient( 'update_plugins' );
        delete_site_transient( 'irembopay_github_latest_release' );
        wp_safe_redirect( admin_url( 'plugins.php' ) );
        exit;
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'wc-irembopay', false, dirname( plugin_basename( WC_IREMBOPAY_PLUGIN_FILE ) ) . '/languages' );
    }

    public function register_gateway( array $methods ): array {
        $methods[] = 'WC_Gateway_IremboPay';
        return $methods;
    }

    public function declare_hpos_compatibility(): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_IREMBOPAY_PLUGIN_FILE, true );
        }
    }

    public function maybe_boot_tutor_integration(): void {
        if ( ! class_exists( 'IremboPay_Tutor_Integration' ) ) { return; }
        $tutor = new IremboPay_Tutor_Integration();
        add_action( 'irembopay_subscription_cancelled', [ $tutor, 'revoke_course_access' ] );
        add_action( 'irembopay_subscription_expired',   [ $tutor, 'revoke_course_access' ] );
        add_action( 'irembopay_subscription_activated',      [ $tutor, 'restore_course_access' ], 10, 2 );
        add_action( 'irembopay_subscription_renewed',        [ $tutor, 'restore_course_access' ], 10, 2 );
        add_action( 'irembopay_subscription_owned', [ $tutor, 'restore_course_access' ], 10, 2 );
    }

    public function handle_payment_page(): void {
        if ( ! isset( $_GET['irembopay_payment'] ) || $_GET['irembopay_payment'] !== '1' ) { return; }
        $order_id       = absint( $_GET['order_id'] ?? 0 );
        $invoice_number = sanitize_text_field( $_GET['invoice_number'] ?? '' );
        $order_key      = sanitize_text_field( $_GET['key'] ?? '' );
        if ( ! $order_id || ! $invoice_number || ! $order_key ) { wp_die( 'Invalid payment request.' ); }
        $order = wc_get_order( $order_id );
        if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) { wp_die( 'Invalid order.' ); }
        $settings   = get_option( 'woocommerce_irembopay_settings', [] );
        $public_key = $settings['public_key'] ?? '';

        $redirect_url = $order->get_checkout_order_received_url();
        if ( class_exists( 'IremboPay_Tutor_Integration' ) ) {
            $tutor = new IremboPay_Tutor_Integration();
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                if ( $tutor->is_bundle_product( $product_id ) ) {
                    $course_ids = $tutor->get_bundle_course_ids( $product_id );
                    if ( ! empty( $course_ids ) ) {
                        $course_url = get_permalink( $course_ids[0] );
                        if ( $course_url ) {
                            $redirect_url = $course_url;
                            break;
                        }
                    }
                    break;
                }
                $course_id = $tutor->get_course_id_from_product( $product_id );
                if ( $course_id ) {
                    $course_url = get_permalink( $course_id );
                    if ( $course_url ) {
                        $redirect_url = $course_url;
                        break;
                    }
                }
            }
        }

        load_template( WC_IREMBOPAY_PLUGIN_DIR . 'templates/payment-page.php', true, compact( 'public_key', 'invoice_number', 'order', 'redirect_url' ) );
        exit;
    }
}

function wc_irembopay_init(): void {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>WooCommerce IremboPay requires WooCommerce to be active.</p></div>';
        });
        return;
    }
    WC_IremboPay::instance();
}
add_action( 'plugins_loaded', 'wc_irembopay_init', 11 );

register_activation_hook( WC_IREMBOPAY_PLUGIN_FILE, function() {
    require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-subscription-db.php';
    IremboPay_Subscription_DB::create_table();
    require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-subscription-cron.php';
    IremboPay_Subscription_Cron::schedule();
});

register_deactivation_hook( WC_IREMBOPAY_PLUGIN_FILE, function() {
    require_once WC_IREMBOPAY_PLUGIN_DIR . 'includes/class-irembopay-subscription-cron.php';
    IremboPay_Subscription_Cron::unschedule();
});
