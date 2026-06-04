<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_Manager {

	const STATUS_ACTIVE          = 'active';
	const STATUS_PENDING_RENEWAL = 'pending_renewal';
	const STATUS_PAUSED          = 'paused';
	const STATUS_CANCELLED       = 'cancelled';
	const STATUS_EXPIRED         = 'expired';
	const STATUS_OWNED           = 'owned';

	// ------------------------------------------------------------------ //
	//  Create subscription after initial order is paid
	// ------------------------------------------------------------------ //

	public static function create_from_order( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( ! self::is_subscription_product( $product_id ) ) { continue; }

			$existing = IremboPay_Subscription_DB::get_active_by_user_product( (int) $order->get_customer_id(), $product_id );
			if ( $existing ) { continue; }

			$period   = get_post_meta( $product_id, '_irembopay_billing_cycle_unit',  true ) ?: 'month';
			$interval = (int) get_post_meta( $product_id, '_irembopay_billing_cycle_value', true ) ?: 1;
			$grace    = (int) get_post_meta( $product_id, '_irembopay_grace_period',        true ) ?: 3;
			$total_payments = (int) get_post_meta( $product_id, '_irembopay_total_payments', true );
			$amount   = (float) ( $item->get_total() / max( 1, $item->get_quantity() ) );

			$now = current_time( 'mysql' );

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
				'end_date'         => null,
				'grace_period_days'=> $grace,
				'total_payments'   => $total_payments,
				'payments_made'    => 1,
			] );

			if ( ! $sub_id ) { continue; }

			$order->add_order_note( sprintf(
				__( 'IremboPay subscription #%d created. Next renewal: %s', 'wc-irembopay' ),
				$sub_id, self::calc_next_renewal( $now, $period, $interval )
			) );
			IremboPay_Logger::info( "Subscription #{$sub_id} created for order #{$order->get_id()}." );
			do_action( 'irembopay_subscription_activated', $sub_id, $order );
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

		$product_code  = get_post_meta( $sub->product_id, '_irembopay_product_code', true ) ?: ( $settings['product_code'] ?? '' );
		$payment_items = [ array_filter( [ 'unitAmount' => (int) round( $sub->amount ), 'quantity' => 1, 'code' => $product_code ?: null ] ) ];

		$expiry_hours = (int) ( $settings['invoice_expiry_hours'] ?? 24 );
		$expiry_at    = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )
		                    ->modify( "+{$expiry_hours} hours" )
		                    ->format( DateTime::ATOM );

		$invoice_data = [
			'transactionId'            => sprintf( 'WC-REN-%d-%s', $renewal_order->get_id(), wp_generate_password( 8, false ) ),
			'paymentAccountIdentifier' => $payment_identifier,
			'customer'                 => [
				'email'       => $user->user_email,
				'phoneNumber' => get_user_meta( $user->ID, 'billing_phone', true ) ?: '',
				'name'        => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			],
			'paymentItems' => $payment_items,
			'description'  => self::build_renewal_description( $sub ),
			'language'     => 'EN',
			'expiryAt'     => $expiry_at,
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
		$payments_made  = (int) $sub->payments_made + 1;
		$total_payments = (int) $sub->total_payments;

		// Check if all payments are complete → grant permanent ownership
		if ( $total_payments > 0 && $payments_made >= $total_payments ) {
			IremboPay_Subscription_DB::update( $sub->id, [
				'status'           => self::STATUS_OWNED,
				'payments_made'    => $payments_made,
				'renewal_order_id' => null,
				'last_invoice'     => null,
			] );
			$renewal_order->add_order_note( sprintf(
				__( 'IremboPay subscription #%d — all %d payments completed. Course ownership granted permanently.', 'wc-irembopay' ),
				$sub->id, $total_payments
			) );
			IremboPay_Logger::info( "Subscription #{$sub->id} — all {$total_payments} payments done. Status: owned." );
			do_action( 'irembopay_subscription_owned', $sub->id, $renewal_order );
			return;
		}

		// Normal renewal — more payments still due
		$next = self::calc_next_renewal( current_time( 'mysql' ), $sub->billing_period, (int) $sub->billing_interval );
		IremboPay_Subscription_DB::update( $sub->id, [
			'status'           => self::STATUS_ACTIVE,
			'payments_made'    => $payments_made,
			'next_renewal'     => $next,
			'renewal_order_id' => null,
			'last_invoice'     => null,
		] );
		$renewal_order->add_order_note( sprintf(
			__( 'IremboPay subscription #%d renewed. Payment %d of %s. Next renewal: %s', 'wc-irembopay' ),
			$sub->id,
			$payments_made,
			$total_payments > 0 ? $total_payments : '∞',
			$next
		) );
		IremboPay_Logger::info( "Subscription #{$sub->id} renewed. Payment {$payments_made}/" . ( $total_payments ?: '∞' ) . ". Next: {$next}." );
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
			self::send_expiry_notification( $sub );
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
			case 'minute': $ts = strtotime( "+{$interval} minute", $ts ); break;
			case 'day':    $ts = strtotime( "+{$interval} day",    $ts ); break;
			case 'week':   $ts = strtotime( "+{$interval} week",   $ts ); break;
			case 'year':   $ts = strtotime( "+{$interval} year",   $ts ); break;
			default:       $ts = strtotime( "+{$interval} month",  $ts ); break;
		}
		return date( 'Y-m-d H:i:s', $ts );
	}

	public static function build_whatsapp_link( string $phone, string $message ): string {
		$digits = preg_replace( '/\D/', '', $phone );
		// Convert Rwanda local format 07x → 2507x
		if ( strlen( $digits ) === 10 && str_starts_with( $digits, '0' ) ) {
			$digits = '250' . ltrim( $digits, '0' );
		}
		// Encode each line separately and join with %0a (lowercase).
		// WhatsApp Web respects %0a but ignores %0A from rawurlencode().
		$lines   = explode( "\n", $message );
		$encoded = implode( '%0a', array_map( 'urlencode', $lines ) );
		return 'https://web.whatsapp.com/send?phone=' . $digits . '&text=' . $encoded;
	}

	private static function get_parent_contact( int $user_id ): array {
		$phone = get_user_meta( $user_id, 'phone_number', true );
		$email = get_user_meta( $user_id, 'parent_email', true );
		$name  = get_user_meta( $user_id, 'parent_name',  true );
		return [
			'phone' => sanitize_text_field( $phone ?: '' ),
			'email' => sanitize_email( $email ?: '' ),
			'name'  => sanitize_text_field( $name ?: '' ),
		];
	}

	private static function get_clean_course_name( int $product_id ): string {
		$name = get_the_title( $product_id );
		// Decode HTML entities (e.g. &#8211; → –) so WhatsApp shows clean text
		return html_entity_decode( $name ?: __( 'course subscription', 'wc-irembopay' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public static function send_expiry_notification( object $sub ): void {
		$user = get_userdata( $sub->user_id );
		if ( ! $user ) { return; }

		$site_name     = get_bloginfo( 'name' );
		$product_name  = get_the_title( $sub->product_id ) ?: __( 'your course subscription', 'wc-irembopay' );
		$amount        = wc_price( $sub->amount, [ 'currency' => $sub->currency ] );
		$customer_name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;

		// Create a renewal order and a fresh invoice so there is a real pay link
		$renewal_order  = self::create_renewal_order( $sub );
		$pay_url        = '';
		$invoice_number = '';

		if ( $renewal_order ) {
			$settings           = get_option( 'woocommerce_irembopay_settings', [] );
			$secret_key         = $settings['secret_key']         ?? '';
			$payment_identifier = $settings['payment_identifier'] ?? '';

			if ( ! empty( $secret_key ) ) {
				$product_code  = get_post_meta( $sub->product_id, '_irembopay_product_code', true ) ?: ( $settings['product_code'] ?? '' );
				$payment_items = [ array_filter( [ 'unitAmount' => (int) round( $sub->amount ), 'quantity' => 1, 'code' => $product_code ?: null ] ) ];
				$expiry_hours  = (int) ( $settings['invoice_expiry_hours'] ?? 24 );
				$expiry_at     = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )
				                     ->modify( "+{$expiry_hours} hours" )
				                     ->format( DateTime::ATOM );

				$invoice_data = [
					'transactionId'            => sprintf( 'WC-EXP-%d-%s', $renewal_order->get_id(), wp_generate_password( 8, false ) ),
					'paymentAccountIdentifier' => $payment_identifier,
					'customer'                 => [
						'email'       => $user->user_email,
						'phoneNumber' => get_user_meta( $user->ID, 'billing_phone', true ) ?: '',
						'name'        => $customer_name,
					],
					'paymentItems' => $payment_items,
					'description'  => sprintf( __( 'Subscription reactivation – %s', 'wc-irembopay' ), $product_name ),
					'language'     => 'EN',
					'expiryAt'     => $expiry_at,
				];

				$api      = new IremboPay_API( $secret_key );
				$response = $api->create_invoice( $invoice_data );

				if ( ! empty( $response['success'] ) && ! empty( $response['data']['invoiceNumber'] ) ) {
					$invoice_number = $response['data']['invoiceNumber'];
					$renewal_order->update_meta_data( '_irembopay_invoice_number', $invoice_number );
					$renewal_order->update_meta_data( '_irembopay_subscription_id', $sub->id );
					$renewal_order->update_status( 'pending', sprintf( __( 'IremboPay expiry reactivation invoice: %s', 'wc-irembopay' ), $invoice_number ) );
					$renewal_order->save();

					$pay_url = add_query_arg( [
						'irembopay_payment' => '1',
						'order_id'          => $renewal_order->get_id(),
						'invoice_number'    => rawurlencode( $invoice_number ),
						'key'               => $renewal_order->get_order_key(),
					], home_url( '/' ) );

					IremboPay_Subscription_DB::update( $sub->id, [
						'last_invoice'     => $invoice_number,
						'renewal_order_id' => $renewal_order->get_id(),
					] );
				}
			}
		}

		// Get parent contact from user profile
		$parent       = self::get_parent_contact( $sub->user_id );
		$to_email     = ! empty( $parent['email'] ) ? $parent['email'] : $user->user_email;
		$to_name      = ! empty( $parent['email'] ) ? __( 'Parent/Guardian', 'wc-irembopay' ) : $customer_name;
		$parent_phone = ! empty( $parent['phone'] ) ? $parent['phone'] : ( $sub->parent_whatsapp ?? '' );

		// Send expiry email to parent (or student if no parent email)
		$subject = sprintf( __( '[%s] Course access suspended — payment required', 'wc-irembopay' ), $site_name );
		$message = self::expiry_email_html( array_merge(
			compact( 'customer_name', 'site_name', 'amount', 'pay_url', 'product_name', 'invoice_number' ),
			[ 'recipient_name' => $to_name ]
		) );
		wp_mail( $to_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
		IremboPay_Logger::info( "Expiry email sent to {$to_email} for subscription #{$sub->id}." );

		// Build WhatsApp link for parent
		if ( ! empty( $parent_phone ) && ! empty( $pay_url ) ) {
			$parent_name   = ! empty( $parent['name'] ) ? $parent['name'] : __( 'Parent/Guardian', 'wc-irembopay' );
			$student_first = trim( explode( ' ', $customer_name )[0] );
			$clean_course  = self::get_clean_course_name( $sub->product_id );
			$amount_text   = number_format( (float) $sub->amount, 0, '.', ',' ) . ' ' . $sub->currency;

			$wa_message = sprintf(
				"Hello %s! 👋\n\n❌ Your child *%s*'s access to *%s* on *%s* has been suspended.\n\n💳 Amount to restore access: %s\n\n👉 Pay Now: %s\n\nAccess is restored automatically once payment is confirmed. Thank you! 🙏",
				$parent_name,
				$student_first,
				$clean_course,
				$site_name,
				$amount_text,
				$pay_url
			);
			$wa_link = self::build_whatsapp_link( $parent_phone, $wa_message );
			IremboPay_Logger::info( "WhatsApp parent link for subscription #{$sub->id}: {$wa_link}" );
			if ( $renewal_order ) {
				$renewal_order->add_order_note(
					sprintf( __( 'Parent WhatsApp link: %s', 'wc-irembopay' ), $wa_link )
				);
				$renewal_order->save();
			}
		}
	}

	public static function billing_label( string $period, int $interval ): string {
		$map = [
			'minute' => _n( 'every minute', 'every %d minutes', $interval, 'wc-irembopay' ),
			'day'    => _n( 'every day',    'every %d days',    $interval, 'wc-irembopay' ),
			'week'   => _n( 'every week',   'every %d weeks',   $interval, 'wc-irembopay' ),
			'month'  => _n( 'every month',  'every %d months',  $interval, 'wc-irembopay' ),
			'year'   => _n( 'every year',   'every %d years',   $interval, 'wc-irembopay' ),
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
		$parent        = self::get_parent_contact( $sub->user_id );

		// Send to parent email if available, otherwise fall back to student email
		$to_email = ! empty( $parent['email'] ) ? $parent['email'] : $user->user_email;
		$to_name  = ! empty( $parent['email'] ) ? __( 'Parent/Guardian', 'wc-irembopay' ) : $customer_name;

		$subject = sprintf( __( '[%s] Action required: renew your child\'s course subscription', 'wc-irembopay' ), $site_name );
		$message = self::renewal_email_html( array_merge(
			compact( 'customer_name', 'site_name', 'amount', 'pay_url', 'product_name', 'invoice_number', 'grace_days' ),
			[ 'recipient_name' => $to_name ]
		) );

		wp_mail( $to_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
		$renewal_order->add_order_note( sprintf(
			__( 'Renewal payment email sent to %s.', 'wc-irembopay' ),
			$to_email
		) );

		// WhatsApp link using parent phone from user profile
		$parent_phone = ! empty( $parent['phone'] ) ? $parent['phone'] : ( $sub->parent_whatsapp ?? '' );
		if ( ! empty( $parent_phone ) ) {
			$parent_name    = ! empty( $parent['name'] ) ? $parent['name'] : __( 'Parent/Guardian', 'wc-irembopay' );
			$student_first  = trim( explode( ' ', $customer_name )[0] );
			$clean_course   = self::get_clean_course_name( $sub->product_id );
			$amount_text    = number_format( (float) $sub->amount, 0, '.', ',' ) . ' ' . $sub->currency;

			$wa_message = sprintf(
				"Hello %s! 👋\n\nThis is a reminder that your child *%s*'s subscription to *%s* on *%s* is due for renewal.\n\n💳 Amount due: %s\n⏳ You have %d days to pay before access is suspended.\n\n👉 Pay Now: %s\n\nThank you! 🙏",
				$parent_name,
				$student_first,
				$clean_course,
				$site_name,
				$amount_text,
				$grace_days,
				$pay_url
			);
			$wa_link = self::build_whatsapp_link( $parent_phone, $wa_message );
			$renewal_order->add_order_note(
				sprintf( __( 'Parent WhatsApp link: %s', 'wc-irembopay' ), $wa_link )
			);
			$renewal_order->save();
			IremboPay_Logger::info( "WhatsApp parent link for renewal #{$sub->id}: {$wa_link}" );
		}
	}

	private static function renewal_email_html( array $d ): string {
		ob_start(); ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
.w{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.h{background:#132241;color:#fff;padding:28px 32px}.h h1{margin:0;font-size:22px}
.b{padding:32px;color:#333;line-height:1.7}
.box{background:#f0f7ff;border-left:4px solid #132241;padding:16px 20px;border-radius:4px;margin:20px 0}
.box strong{font-size:1.35em;color:#132241}
.btn{display:inline-block;background:#132241;color:#fff!important;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:bold;font-size:16px;margin:20px 0}
.warn{background:#fff7ed;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;font-size:.9em;color:#92400e;margin-top:16px}
.meta{font-size:.82em;color:#999;border-top:1px solid #eee;padding-top:14px;margin-top:20px}
.foot{background:#f9f9f9;padding:14px 32px;font-size:12px;color:#aaa;text-align:center}
</style></head><body>
<div class="w">
<div class="h"><h1><?php echo esc_html( $d['site_name'] ); ?></h1></div>
<div class="b">
<p><?php printf( esc_html__( 'Hello %s,', 'wc-irembopay' ), esc_html( $d['recipient_name'] ?? $d['customer_name'] ) ); ?></p>
<p style="color:#666;font-size:.9em"><?php printf( esc_html__( 'This is regarding %s\'s subscription.', 'wc-irembopay' ), esc_html( $d['customer_name'] ) ); ?></p>
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

	private static function expiry_email_html( array $d ): string {
		ob_start(); ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
.w{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.h{background:#dc2626;color:#fff;padding:28px 32px}.h h1{margin:0;font-size:22px}
.b{padding:32px;color:#333;line-height:1.7}
.box{background:#fef2f2;border-left:4px solid #dc2626;padding:16px 20px;border-radius:4px;margin:20px 0}
.box strong{font-size:1.35em;color:#b91c1c}
.btn{display:inline-block;background:#132241;color:#fff!important;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:bold;font-size:16px;margin:20px 0}
.info{background:#f0fdf4;border-left:4px solid #16a34a;padding:12px 16px;border-radius:4px;font-size:.9em;color:#166534;margin-top:16px}
.meta{font-size:.82em;color:#999;border-top:1px solid #eee;padding-top:14px;margin-top:20px}
.foot{background:#f9f9f9;padding:14px 32px;font-size:12px;color:#aaa;text-align:center}
</style></head><body>
<div class="w">
<div class="h"><h1><?php echo esc_html( $d['site_name'] ); ?></h1></div>
<div class="b">
<p><?php printf( esc_html__( 'Hello %s,', 'wc-irembopay' ), esc_html( $d['recipient_name'] ?? $d['customer_name'] ) ); ?></p>
<p style="color:#666;font-size:.9em"><?php printf( esc_html__( 'This is regarding %s\'s subscription.', 'wc-irembopay' ), esc_html( $d['customer_name'] ) ); ?></p>
<div class="box">
<strong>⚠️ <?php esc_html_e( 'Your course access has been suspended', 'wc-irembopay' ); ?></strong><br>
<?php printf( esc_html__( 'Your subscription to %s has expired because the renewal payment was not received in time.', 'wc-irembopay' ), '<strong>' . esc_html( $d['product_name'] ) . '</strong>' ); ?>
</div>
<?php if ( ! empty( $d['pay_url'] ) ) : ?>
<p><?php esc_html_e( 'You can restore access immediately by completing your payment:', 'wc-irembopay' ); ?></p>
<p><?php esc_html_e( 'Amount:', 'wc-irembopay' ); ?> <strong><?php echo $d['amount']; ?></strong></p>
<a href="<?php echo esc_url( $d['pay_url'] ); ?>" class="btn">🔓 <?php esc_html_e( 'Restore Access — Pay Now', 'wc-irembopay' ); ?></a>
<div class="info">✅ <?php esc_html_e( 'Your access will be restored automatically as soon as payment is confirmed.', 'wc-irembopay' ); ?></div>
<p style="font-size:.88em;color:#666;margin-top:16px"><?php esc_html_e( "If the button doesn't work:", 'wc-irembopay' ); ?><br>
<a href="<?php echo esc_url( $d['pay_url'] ); ?>"><?php echo esc_url( $d['pay_url'] ); ?></a></p>
<?php if ( ! empty( $d['invoice_number'] ) ) : ?>
<div class="meta"><?php printf( esc_html__( 'Invoice: %s', 'wc-irembopay' ), esc_html( $d['invoice_number'] ) ); ?></div>
<?php endif; ?>
<?php else : ?>
<p><?php esc_html_e( 'Please contact us to restore your access.', 'wc-irembopay' ); ?></p>
<?php endif; ?>
</div>
<div class="foot">&copy; <?php echo date('Y'); ?> <?php echo esc_html( $d['site_name'] ); ?></div>
</div></body></html>
<?php return ob_get_clean();
	}

}
