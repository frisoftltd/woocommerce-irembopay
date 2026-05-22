<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_Manager {

	const STATUS_ACTIVE          = 'active';
	const STATUS_PENDING_RENEWAL = 'pending_renewal';
	const STATUS_PAUSED          = 'paused';
	const STATUS_CANCELLED       = 'cancelled';
	const STATUS_EXPIRED         = 'expired';

	// ------------------------------------------------------------------ //
	//  Create subscription after initial order is paid
	// ------------------------------------------------------------------ //

	public static function create_from_order( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( ! self::is_subscription_product( $product_id ) ) { continue; }

			$existing = IremboPay_Subscription_DB::get_active_by_user_product( (int) $order->get_customer_id(), $product_id );
			if ( $existing ) { continue; }

			$period   = get_post_meta( $product_id, '_irembopay_sub_period',   true ) ?: 'month';
			$interval = (int) get_post_meta( $product_id, '_irembopay_sub_interval', true ) ?: 1;
			$grace    = (int) get_post_meta( $product_id, '_irembopay_sub_grace',    true ) ?: 3;
			$amount   = (float) ( $item->get_total() / max( 1, $item->get_quantity() ) );
			$now      = current_time( 'mysql' );

			$sub_id = IremboPay_Subscription_DB::insert( [
				'user_id'          => (int) $order->get_customer_id(),
				'parent_order_id'  => (int) $order->get_id(),
				'product_id'       => $product_id,
				'status'           => self::STATUS_ACTIVE,
				'billing_period'   => $period,
				'billing_interval' => $interval,
				'amount'           => $amount,
				'currency'         => $order->get_currency(),
				'next_renewal'     => self::calc_next_renewal( $now, $period, $interval ),
				'start_date'       => $now,
				'grace_period_days'=> $grace,
			] );

			if ( $sub_id ) {
				$order->add_order_note( sprintf(
					__( 'IremboPay subscription #%d created. Next renewal: %s', 'wc-irembopay' ),
					$sub_id, self::calc_next_renewal( $now, $period, $interval )
				) );
				IremboPay_Logger::info( "Subscription #{$sub_id} created for order #{$order->get_id()}." );
				do_action( 'irembopay_subscription_activated', $sub_id, $order );
			}
		}
	}

	// ------------------------------------------------------------------ //
	//  Process renewals (called by WP-Cron daily)
	// ------------------------------------------------------------------ //

	public static function process_due_renewals(): void {
		$due = IremboPay_Subscription_DB::get_due_for_renewal();
		IremboPay_Logger::info( 'Cron: processing ' . count( $due ) . ' due subscription(s).' );
		foreach ( $due as $sub ) { self::trigger_renewal( $sub ); }
		self::expire_grace_passed();
	}

	public static function trigger_renewal( object $sub ): void {
		$settings           = get_option( 'woocommerce_irembopay_settings', [] );
		$secret_key         = $settings['secret_key']         ?? '';
		$payment_identifier = $settings['payment_identifier'] ?? '';
		if ( empty( $secret_key ) ) { IremboPay_Logger::error( 'Renewal skipped — secret key not configured.' ); return; }

		$user = get_userdata( $sub->user_id );
		if ( ! $user ) { IremboPay_Logger::error( "Renewal skipped — user #{$sub->user_id} not found." ); return; }

		$renewal_order = self::create_renewal_order( $sub );
		if ( ! $renewal_order ) { IremboPay_Logger::error( "Failed to create renewal order for subscription #{$sub->id}." ); return; }

		$product_code  = get_post_meta( $sub->product_id, '_irembopay_sub_product_code', true ) ?: ( $settings['product_code'] ?? '' );
		$payment_items = [ array_filter( [ 'unitAmount' => (int) round( $sub->amount ), 'quantity' => 1, 'code' => $product_code ?: null ] ) ];

		$invoice_data = [
			'transactionId'            => 'WC-REN-' . $renewal_order->get_id() . '-' . time(),
			'paymentAccountIdentifier' => $payment_identifier,
			'customer'                 => [
				'email'       => $user->user_email,
				'phoneNumber' => get_user_meta( $user->ID, 'billing_phone', true ) ?: '',
				'name'        => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			],
			'paymentItems' => $payment_items,
			'description'  => self::build_renewal_description( $sub ),
			'language'     => 'EN',
		];

		$api      = new IremboPay_API( $secret_key );
		$response = $api->create_invoice( $invoice_data );

		if ( empty( $response['success'] ) || empty( $response['data']['invoiceNumber'] ) ) {
			$error = $response['message'] ?? 'Unknown error';
			IremboPay_Logger::error( "Renewal invoice failed for subscription #{$sub->id}: {$error}" );
			$renewal_order->update_status( 'failed', sprintf( __( 'IremboPay renewal invoice failed: %s', 'wc-irembopay' ), $error ) );
			return;
		}

		$invoice_number = $response['data']['invoiceNumber'];
		$renewal_order->update_meta_data( '_irembopay_invoice_number', $invoice_number );
		$renewal_order->update_meta_data( '_irembopay_subscription_id', $sub->id );
		$renewal_order->update_status( 'pending', sprintf( __( 'IremboPay renewal invoice created: %s', 'wc-irembopay' ), $invoice_number ) );
		$renewal_order->save();

		IremboPay_Subscription_DB::update( $sub->id, [
			'status'           => self::STATUS_PENDING_RENEWAL,
			'last_invoice'     => $invoice_number,
			'renewal_order_id' => $renewal_order->get_id(),
		] );

		self::send_renewal_email( $sub, $renewal_order, $invoice_number );
		IremboPay_Logger::info( "Renewal invoice {$invoice_number} created for subscription #{$sub->id}." );
	}

	public static function complete_renewal( object $sub, WC_Order $renewal_order ): void {
		$next = self::calc_next_renewal( current_time( 'mysql' ), $sub->billing_period, (int) $sub->billing_interval );
		IremboPay_Subscription_DB::update( $sub->id, [
			'status'           => self::STATUS_ACTIVE,
			'next_renewal'     => $next,
			'renewal_order_id' => null,
			'last_invoice'     => null,
		] );
		$renewal_order->add_order_note( sprintf( __( 'IremboPay subscription #%d renewed. Next renewal: %s', 'wc-irembopay' ), $sub->id, $next ) );
		IremboPay_Logger::info( "Subscription #{$sub->id} renewed. Next: {$next}." );
		do_action( 'irembopay_subscription_renewed', $sub->id, $renewal_order );
	}

	// ------------------------------------------------------------------ //
	//  Status transitions
	// ------------------------------------------------------------------ //

	public static function cancel( int $sub_id, string $reason = '' ): void {
		IremboPay_Subscription_DB::update( $sub_id, [ 'status' => self::STATUS_CANCELLED ] );
		IremboPay_Logger::info( "Subscription #{$sub_id} cancelled. {$reason}" );
		do_action( 'irembopay_subscription_cancelled', $sub_id );
	}

	public static function pause( int $sub_id ): void {
		IremboPay_Subscription_DB::update( $sub_id, [ 'status' => self::STATUS_PAUSED ] );
		do_action( 'irembopay_subscription_paused', $sub_id );
	}

	public static function reactivate( int $sub_id ): void {
		$sub = IremboPay_Subscription_DB::get( $sub_id );
		if ( ! $sub ) { return; }
		$next = self::calc_next_renewal( current_time( 'mysql' ), $sub->billing_period, (int) $sub->billing_interval );
		IremboPay_Subscription_DB::update( $sub_id, [ 'status' => self::STATUS_ACTIVE, 'next_renewal' => $next ] );
		do_action( 'irembopay_subscription_activated', $sub_id, null );
	}

	public static function expire_grace_passed(): void {
		foreach ( IremboPay_Subscription_DB::get_expired_grace() as $sub ) {
			IremboPay_Subscription_DB::update( $sub->id, [ 'status' => self::STATUS_EXPIRED ] );
			IremboPay_Logger::info( "Subscription #{$sub->id} expired (grace period passed)." );
			do_action( 'irembopay_subscription_expired', $sub->id );
		}
	}

	// ------------------------------------------------------------------ //
	//  Helpers
	// ------------------------------------------------------------------ //

	public static function is_subscription_product( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, '_irembopay_is_subscription', true );
	}

	public static function calc_next_renewal( string $from, string $period, int $interval ): string {
		$ts = strtotime( $from );
		switch ( $period ) {
			case 'day':  $ts = strtotime( "+{$interval} day",   $ts ); break;
			case 'week': $ts = strtotime( "+{$interval} week",  $ts ); break;
			case 'year': $ts = strtotime( "+{$interval} year",  $ts ); break;
			default:     $ts = strtotime( "+{$interval} month", $ts ); break;
		}
		return date( 'Y-m-d H:i:s', $ts );
	}

	public static function billing_label( string $period, int $interval ): string {
		$map = [
			'day'   => _n( 'every day',   'every %d days',   $interval, 'wc-irembopay' ),
			'week'  => _n( 'every week',  'every %d weeks',  $interval, 'wc-irembopay' ),
			'month' => _n( 'every month', 'every %d months', $interval, 'wc-irembopay' ),
			'year'  => _n( 'every year',  'every %d years',  $interval, 'wc-irembopay' ),
		];
		return sprintf( $map[ $period ] ?? "every %d {$period}s", $interval );
	}

	private static function create_renewal_order( object $sub ) {
		$user = get_userdata( $sub->user_id );
		if ( ! $user ) { return false; }

		$order = wc_create_order( [ 'customer_id' => $sub->user_id ] );
		$order->set_billing_first_name( get_user_meta( $user->ID, 'billing_first_name', true ) ?: $user->first_name );
		$order->set_billing_last_name(  get_user_meta( $user->ID, 'billing_last_name',  true ) ?: $user->last_name );
		$order->set_billing_email(      $user->user_email );
		$order->set_billing_phone(      get_user_meta( $user->ID, 'billing_phone', true ) ?: '' );
		$order->set_payment_method( 'irembopay' );
		$order->set_payment_method_title( 'IremboPay' );
		$order->set_currency( $sub->currency );
		$order->set_total( $sub->amount );

		$product = wc_get_product( $sub->product_id );
		if ( $product ) { $order->add_product( $product, 1, [ 'total' => $sub->amount, 'subtotal' => $sub->amount ] ); }

		$order->update_meta_data( '_irembopay_subscription_id', $sub->id );
		$order->update_meta_data( '_irembopay_renewal', 'yes' );
		$order->add_order_note( sprintf( __( 'Renewal order for IremboPay subscription #%d', 'wc-irembopay' ), $sub->id ) );
		$order->calculate_totals();
		$order->save();
		return $order;
	}

	private static function build_renewal_description( object $sub ): string {
		$name = get_the_title( $sub->product_id ) ?: __( 'Course subscription', 'wc-irembopay' );
		if ( class_exists( 'IremboPay_Tutor_Integration' ) ) {
			$tutor     = new IremboPay_Tutor_Integration();
			$course_id = $tutor->get_course_id_from_product( (int) $sub->product_id );
			if ( $course_id ) { $name = get_the_title( $course_id ); }
		}
		return sprintf( __( 'Subscription renewal – %s', 'wc-irembopay' ), $name );
	}

	public static function send_renewal_email( object $sub, WC_Order $renewal_order, string $invoice_number ): void {
		$user = get_userdata( $sub->user_id );
		if ( ! $user ) { return; }

		$pay_url = add_query_arg( [
			'irembopay_payment' => '1',
			'order_id'          => $renewal_order->get_id(),
			'invoice_number'    => rawurlencode( $invoice_number ),
			'key'               => $renewal_order->get_order_key(),
		], home_url( '/' ) );

		$site_name     = get_bloginfo( 'name' );
		$amount        = wc_price( $sub->amount, [ 'currency' => $sub->currency ] );
		$product_name  = get_the_title( $sub->product_id ) ?: __( 'course subscription', 'wc-irembopay' );
		$customer_name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
		$grace_days    = (int) $sub->grace_period_days;

		$subject = sprintf( __( '[%s] Action required: renew your course subscription', 'wc-irembopay' ), $site_name );
		$message = self::renewal_email_html( compact( 'customer_name', 'site_name', 'amount', 'pay_url', 'product_name', 'invoice_number', 'grace_days' ) );

		wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
		$renewal_order->add_order_note( sprintf( __( 'Renewal payment email sent to %s.', 'wc-irembopay' ), $user->user_email ) );
	}

	private static function renewal_email_html( array $d ): string {
		ob_start(); ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
.w{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.h{background:#2563eb;color:#fff;padding:28px 32px}.h h1{margin:0;font-size:22px}
.b{padding:32px;color:#333;line-height:1.7}
.box{background:#f0f7ff;border-left:4px solid #2563eb;padding:16px 20px;border-radius:4px;margin:20px 0}
.box strong{font-size:1.35em;color:#1d4ed8}
.btn{display:inline-block;background:#2563eb;color:#fff!important;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:bold;font-size:16px;margin:20px 0}
.warn{background:#fff7ed;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;font-size:.9em;color:#92400e;margin-top:16px}
.meta{font-size:.82em;color:#999;border-top:1px solid #eee;padding-top:14px;margin-top:20px}
.foot{background:#f9f9f9;padding:14px 32px;font-size:12px;color:#aaa;text-align:center}
</style></head><body>
<div class="w">
<div class="h"><h1><?php echo esc_html( $d['site_name'] ); ?></h1></div>
<div class="b">
<p><?php printf( esc_html__( 'Hello %s,', 'wc-irembopay' ), esc_html( $d['customer_name'] ) ); ?></p>
<p><?php printf( esc_html__( 'Your subscription to %s is due for renewal.', 'wc-irembopay' ), '<strong>' . esc_html( $d['product_name'] ) . '</strong>' ); ?></p>
<div class="box"><?php esc_html_e( 'Amount due:', 'wc-irembopay' ); ?><br><strong><?php echo $d['amount']; ?></strong></div>
<a href="<?php echo esc_url( $d['pay_url'] ); ?>" class="btn"><?php esc_html_e( 'Pay Now', 'wc-irembopay' ); ?></a>
<div class="warn">⚠️ <?php printf( esc_html__( 'If payment is not completed within %d days, your course access will be suspended.', 'wc-irembopay' ), $d['grace_days'] ); ?></div>
<p style="font-size:.88em;color:#666"><?php esc_html_e( "If the button doesn't work:", 'wc-irembopay' ); ?><br>
<a href="<?php echo esc_url( $d['pay_url'] ); ?>"><?php echo esc_url( $d['pay_url'] ); ?></a></p>
<div class="meta"><?php printf( esc_html__( 'Invoice: %s', 'wc-irembopay' ), esc_html( $d['invoice_number'] ) ); ?></div>
</div>
<div class="foot">&copy; <?php echo date('Y'); ?> <?php echo esc_html( $d['site_name'] ); ?></div>
</div></body></html>
<?php return ob_get_clean();
	}
}
