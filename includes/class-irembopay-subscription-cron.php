<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_Cron {
	const RENEWAL_HOOK     = 'irembopay_process_renewals';
	const EXPIRY_HOOK      = 'irembopay_process_expiry';
	const INSTALLMENT_HOOK = 'irembopay_process_installments';

	public function __construct() {
		add_action( self::RENEWAL_HOOK,     [ $this, 'run_renewals'     ] );
		add_action( self::EXPIRY_HOOK,      [ $this, 'run_expiry'       ] );
		add_action( self::INSTALLMENT_HOOK, [ $this, 'run_installments' ] );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::RENEWAL_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', self::RENEWAL_HOOK );
		}
		if ( ! wp_next_scheduled( self::EXPIRY_HOOK ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::EXPIRY_HOOK );
		}
		if ( ! wp_next_scheduled( self::INSTALLMENT_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ) + 3600, 'daily', self::INSTALLMENT_HOOK );
		}
	}

	public static function unschedule(): void {
		foreach ( [ self::RENEWAL_HOOK, self::EXPIRY_HOOK, self::INSTALLMENT_HOOK ] as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) { wp_unschedule_event( $ts, $hook ); }
		}
	}

	public function run_renewals(): void {
		IremboPay_Logger::info( 'Cron: renewal job started.' );
		IremboPay_Subscription_Manager::process_due_renewals();
		IremboPay_Logger::info( 'Cron: renewal job finished.' );
	}

	public function run_expiry(): void {
		IremboPay_Logger::info( 'Cron: expiry job started.' );
		IremboPay_Subscription_Manager::expire_grace_passed();
		IremboPay_Logger::info( 'Cron: expiry job finished.' );
	}

	public function run_installments(): void {
		IremboPay_Logger::info( 'Cron: installment job started.' );
		IremboPay_Installment_Manager::process_upcoming_reminders();
		IremboPay_Installment_Manager::process_due_installments();
		IremboPay_Installment_Manager::process_overdue_installments();
		IremboPay_Logger::info( 'Cron: installment job finished.' );
	}
}
