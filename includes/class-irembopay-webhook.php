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

		if ( ! $invoice_number || ! $payment_status ) {
			return new WP_REST_Response( [ 'error' => 'Missing required fields' ], 400 );
		}

		$orders = wc_get_orders( [ 'limit' => 1, 'meta_key' => '_irembopay_invoice_number', 'meta_value' => $invoice_number ] );
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
}

new IremboPay_Webhook();
