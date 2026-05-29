<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Gateway_IremboPay extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'irembopay';
		$this->has_fields         = false;
		$this->method_title       = __( 'IremboPay', 'wc-irembopay' );
		$this->method_description = __( 'Accept payments via the IremboPay inline checkout modal.', 'wc-irembopay' );
		$this->supports           = [ 'products' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->secret_key         = $this->get_option( 'secret_key' );
		$this->public_key         = $this->get_option( 'public_key' );
		$this->payment_identifier = $this->get_option( 'payment_identifier' );
		$this->product_code       = $this->get_option( 'product_code' );
		$this->testmode           = 'yes' === $this->get_option( 'testmode' );
		$saved_logo               = $this->get_option( 'gateway_logo' );
		$this->icon               = ! empty( $saved_logo )
			? esc_url( $saved_logo )
			: WC_IREMBOPAY_PLUGIN_URL . 'assets/images/irembopay-logo.png';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_filter( 'woocommerce_gateway_icon', [ $this, 'gateway_icon_html' ], 10, 2 );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [ 'title' => __( 'Enable / Disable', 'wc-irembopay' ), 'type' => 'checkbox', 'label' => __( 'Enable IremboPay Gateway', 'wc-irembopay' ), 'default' => 'yes' ],
			'testmode'    => [ 'title' => __( 'Test Mode', 'wc-irembopay' ), 'type' => 'checkbox', 'label' => __( 'Enable sandbox mode', 'wc-irembopay' ), 'default' => 'no', 'desc_tip' => true, 'description' => __( 'Use sandbox credentials while testing.', 'wc-irembopay' ) ],
			'title'       => [ 'title' => __( 'Title', 'wc-irembopay' ), 'type' => 'text', 'default' => 'IremboPay', 'desc_tip' => true, 'description' => __( 'Payment method title shown at checkout.', 'wc-irembopay' ) ],
			'gateway_logo' => [
				'title'       => __( 'Payment Method Logo URL', 'wc-irembopay' ),
				'type'        => 'text',
				'description' => __( 'Paste the full URL of your logo image. Upload your logo via Media Library and paste the URL here. Leave blank to use no logo. Example: https://lms.tangnest.rw/wp-content/uploads/2026/05/irembopay-logo.png', 'wc-irembopay' ),
				'desc_tip'    => true,
				'default'     => '',
				'placeholder' => 'https://yourdomain.com/wp-content/uploads/your-logo.png',
			],
			'description' => [ 'title' => __( 'Description', 'wc-irembopay' ), 'type' => 'textarea', 'default' => __( 'Pay securely using IremboPay.', 'wc-irembopay' ) ],
			'secret_key'  => [ 'title' => __( 'Secret Key', 'wc-irembopay' ), 'type' => 'password', 'desc_tip' => true, 'description' => __( 'Your IremboPay secret key.', 'wc-irembopay' ) ],
			'public_key'  => [ 'title' => __( 'Public Key', 'wc-irembopay' ), 'type' => 'text', 'desc_tip' => true, 'description' => __( 'Your IremboPay public key (used in the JS modal).', 'wc-irembopay' ) ],
			'payment_identifier' => [ 'title' => __( 'Payment Account Identifier', 'wc-irembopay' ), 'type' => 'text', 'default' => 'bankrwf', 'desc_tip' => true, 'description' => __( 'Your IremboPay payment account identifier.', 'wc-irembopay' ) ],
			'product_code'         => [ 'title' => __( 'Default Product Code', 'wc-irembopay' ), 'type' => 'text', 'desc_tip' => true, 'description' => __( 'IremboPay product code applied to all line items (overridable per product).', 'wc-irembopay' ) ],
			'invoice_expiry_hours' => [ 'title' => __( 'Invoice Expiry (hours)', 'wc-irembopay' ), 'type' => 'number', 'default' => '24', 'desc_tip' => true, 'description' => __( 'How many hours before an unpaid invoice expires.', 'wc-irembopay' ) ],
			'webhook_url' => [
				'title'       => __( 'Webhook URL', 'wc-irembopay' ),
				'type'        => 'title',
				'description' => sprintf( __( 'Add this URL in your IremboPay dashboard: <code>%s</code>', 'wc-irembopay' ), esc_url( rest_url( 'irembopay/v1/webhook' ) ) ),
			],
			'webhook_secret' => [ 'title' => __( 'Webhook Secret Key', 'wc-irembopay' ), 'type' => 'password', 'desc_tip' => true, 'description' => __( 'Optional. If set in IremboPay dashboard, enter the same value here to verify webhook authenticity.', 'wc-irembopay' ) ],
			'tutor_section' => [
				'title'       => __( 'Tutor LMS', 'wc-irembopay' ),
				'type'        => 'title',
				'description' => ( defined( 'TUTOR_VERSION' ) || class_exists( 'Tutor\Init' ) )
					? __( '✅ Tutor LMS detected. Per-course product codes can be set on each course edit screen.', 'wc-irembopay' )
					: __( '⚠️ Tutor LMS is not active.', 'wc-irembopay' ),
			],
			'subscription_note' => [
				'title'       => __( 'Built-in Subscriptions', 'wc-irembopay' ),
				'type'        => 'title',
				'description' => __( '✅ This gateway works for ALL WooCommerce products — physical products, digital downloads, courses, and services. Recurring billing is also available natively: enable it on any product under the <strong>IremboPay Subscription</strong> tab.', 'wc-irembopay' ),
			],
		];
	}

	public function gateway_icon_html( string $icon_html, string $gateway_id ): string {
		if ( $gateway_id !== $this->id ) {
			return $icon_html;
		}

		$settings = get_option( 'woocommerce_irembopay_settings', [] );
		$logo_url = ! empty( $settings['gateway_logo'] ) ? esc_url( $settings['gateway_logo'] ) : '';

		if ( empty( $logo_url ) ) {
			return '';
		}

		return '<img src="' . $logo_url . '" alt="IremboPay" style="max-height:80px;width:auto;vertical-align:middle;margin-left:12px;border-radius:6px;display:block;margin-top:6px;" />';
	}

	public function enqueue_scripts(): void {
		if ( ! is_checkout() ) { return; }
		wp_enqueue_script( 'irembopay-inline', 'https://dashboard.irembopay.com/assets/payment/inline.js', [], null, true );
		wp_add_inline_style( 'woocommerce-general', '
			.wc_payment_method label img {
				max-height: 80px !important;
				width: auto !important;
				display: inline-block !important;
				vertical-align: middle !important;
			}
			.wc_payment_method.payment_method_irembopay label {
				display: flex !important;
				align-items: center !important;
				gap: 12px !important;
			}
			.wc_payment_method.payment_method_irembopay label img {
				max-height: 80px !important;
				width: auto !important;
				border-radius: 8px !important;
			}
		' );
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'wc-irembopay' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$transaction_id      = sprintf( 'WC-%d-%s', $order->get_id(), wp_generate_password( 8, false ) );
		$chosen_installments = max( 1, (int) $order->get_meta( '_irembopay_chosen_installments' ) );
		$full_total          = (float) $order->get_total();

		if ( $chosen_installments > 1 ) {
			$installment_amount = (int) round( $full_total / $chosen_installments, 0 );
			$order->update_meta_data( '_irembopay_installment_amount', $installment_amount );
			$order->update_meta_data( '_irembopay_installment_full_amount', $full_total );
			$order->save();
			$payment_items = $this->build_installment_payment_items( $order, $installment_amount );
		} else {
			$payment_items = $this->build_payment_items( $order );
		}

		$expiry_hours   = (int) $this->get_option( 'invoice_expiry_hours', 24 );
		$expiry_at      = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )
		                      ->modify( "+{$expiry_hours} hours" )
		                      ->format( DateTime::ATOM );

		$invoice_data = [
			'transactionId'            => $transaction_id,
			'paymentAccountIdentifier' => $this->payment_identifier,
			'customer'                 => [
				'email'       => $order->get_billing_email(),
				'phoneNumber' => $order->get_billing_phone(),
				'name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			],
			'paymentItems' => $payment_items,
			'description'  => apply_filters( 'irembopay_invoice_description',
				sprintf( __( 'Payment for WooCommerce order #%d', 'wc-irembopay' ), $order->get_id() ),
				$order
			),
			'language'  => 'EN',
			'expiryAt'  => $expiry_at,
		];

		$api      = new IremboPay_API( $this->secret_key );
		$response = $api->create_invoice( $invoice_data );

		if ( empty( $response['success'] ) || empty( $response['data']['invoiceNumber'] ) ) {
			$error = $response['message'] ?? __( 'Unknown error from IremboPay.', 'wc-irembopay' );
			IremboPay_Logger::error( 'Invoice creation failed for order #' . $order_id, [ 'response' => $response ] );
			wc_add_notice( sprintf( __( 'Payment error: %s', 'wc-irembopay' ), $error ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$invoice_number = $response['data']['invoiceNumber'];
		$order->update_meta_data( '_irembopay_invoice_number', $invoice_number );
		$order->update_meta_data( '_irembopay_transaction_id', $transaction_id );
		$order->add_order_note( sprintf( __( 'IremboPay invoice created: %s', 'wc-irembopay' ), $invoice_number ) );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => add_query_arg( [
				'irembopay_payment' => '1',
				'order_id'          => $order->get_id(),
				'invoice_number'    => rawurlencode( $invoice_number ),
				'key'               => $order->get_order_key(),
			], home_url( '/' ) ),
		];
	}

	private function build_installment_payment_items( WC_Order $order, int $installment_amount ): array {
		$items      = [];
		$order_item = array_values( $order->get_items() )[0] ?? null;
		$item       = [ 'unitAmount' => $installment_amount, 'quantity' => 1 ];
		if ( $order_item ) {
			$per_product_code = get_post_meta( $order_item->get_product_id(), '_irembopay_product_code', true );
			$code = ! empty( $per_product_code ) ? $per_product_code : $this->product_code;
			if ( ! empty( $code ) ) { $item['code'] = $code; }
		}
		$items[] = $item;
		return apply_filters( 'irembopay_payment_items', $items, $order );
	}

	private function build_payment_items( WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$line_item = [
				'unitAmount' => (int) round( $item->get_total() / max( 1, $item->get_quantity() ) ),
				'quantity'   => $item->get_quantity(),
			];
			$per_product_code = get_post_meta( $item->get_product_id(), '_irembopay_product_code', true );
			$code = ! empty( $per_product_code ) ? $per_product_code : $this->product_code;
			if ( ! empty( $code ) ) {
				$line_item['code'] = $code;
			}
			$items[] = $line_item;
		}
		return apply_filters( 'irembopay_payment_items', $items, $order );
	}
}
