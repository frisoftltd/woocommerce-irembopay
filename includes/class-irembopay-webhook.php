<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Webhook {
	public function __construct() { add_action( 'rest_api_init', [ $this, 'register_route' ] ); }

	public function register_route(): void {
		register_rest_route( 'irembopay/v1', '/webhook', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$body           = $request->get_json_params();
		$invoice_number = $body['data']['invoiceNumber'] ?? null;
		$payment_status = $body['data']['paymentStatus']  ?? null;

		IremboPay_Logger::debug( 'Webhook received', [ 'body' => $body ] );

		$settings       = get_option( 'woocommerce_irembopay_settings', [] );
		$webhook_secret = $settings['webhook_secret'] ?? '';
		if ( ! empty( $webhook_secret ) ) {
			$signature = $request->get_header( 'x-irembopay-signature' ) ?? '';
			$payload   = $request->get_body();
			$expected  = hash_hmac( 'sha256', $payload, $webhook_secret );
			if ( ! hash_equals( $expected, $signature ) ) {
				IremboPay_Logger::error( 'Webhook signature verification failed.' );
				return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
			}
		}

		if ( ! $invoice_number || ! $payment_status ) {
			return new WP_REST_Response( [ 'error' => 'Missing required fields' ], 400 );
		}

		// Check if this invoice belongs to an installment (installments 2+)
		$installment = class_exists( 'IremboPay_Installment_DB' ) ? IremboPay_Installment_DB::get_by_invoice( $invoice_number ) : null;
		if ( $installment ) {
			if ( $payment_status === 'PAID' ) {
				IremboPay_Installment_Manager::complete_installment_payment( $installment );
				IremboPay_Logger::info( 'Installment invoice paid: ' . $invoice_number );
			} elseif ( $payment_status === 'FAILED' ) {
				IremboPay_Installment_DB::update( (int) $installment->id, [ 'status' => 'failed' ] );
			}
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		$orders = wc_get_orders( [
			'limit'      => 1,
			'meta_query' => [ [
				'key'   => '_irembopay_invoice_number',
				'value' => $invoice_number,
			] ],
		] );
		if ( empty( $orders ) ) {
			IremboPay_Logger::error( 'Order not found for invoice: ' . $invoice_number );
			return new WP_REST_Response( [ 'error' => 'Order not found' ], 404 );
		}

		$order = $orders[0];
		if ( $order->is_paid() && $payment_status === 'PAID' ) {
			return new WP_REST_Response( [ 'success' => true, 'note' => 'Already paid' ], 200 );
		}

		switch ( $payment_status ) {
			case 'PAID':
				$order->payment_complete( $invoice_number );
				$order->add_order_note( sprintf( __( 'IremboPay payment completed. Invoice: %s', 'wc-irembopay' ), $invoice_number ) );
				do_action( 'irembopay_payment_complete', $order );

				$sub_id = $order->get_meta( '_irembopay_subscription_id' );
				if ( $sub_id ) {
					$sub = IremboPay_Subscription_DB::get( (int) $sub_id );
					if ( $sub ) { IremboPay_Subscription_Manager::complete_renewal( $sub, $order ); }
				} elseif ( ! $order->get_meta( '_irembopay_renewal' ) ) {
					IremboPay_Subscription_Manager::create_from_order( $order );
					// After subscription is created, schedule installments 2..N if applicable
					if ( class_exists( 'IremboPay_Installment_Manager' ) && (int) $order->get_meta( '_irembopay_chosen_installments' ) > 1 ) {
						$new_sub = self::get_latest_subscription_for_order( $order );
						if ( $new_sub ) {
							IremboPay_Installment_Manager::create_installments_for_order( $order, (int) $new_sub->id );
						}
					}
				}
				IremboPay_Logger::info( 'Order #' . $order->get_id() . ' marked as paid.' );
				break;

			case 'FAILED':
				$order->update_status( 'failed', __( 'Payment failed via IremboPay.', 'wc-irembopay' ) );
				break;

			case 'CANCELLED':
				$order->update_status( 'cancelled', __( 'Payment cancelled via IremboPay.', 'wc-irembopay' ) );
				break;

			default:
				IremboPay_Logger::debug( 'Unhandled payment status: ' . $payment_status );
		}

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	private static function get_latest_subscription_for_order( WC_Order $order ): ?object {
		global $wpdb;
		$t = $wpdb->prefix . 'irembopay_subscriptions';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE parent_order_id = %d ORDER BY id DESC LIMIT 1",
			$order->get_id()
		) );
	}
}

new IremboPay_Webhook();
