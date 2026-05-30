<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_Plans_DB {
	private static string $table = '';

	public static function table(): string {
		global $wpdb;
		if ( empty( self::$table ) ) { self::$table = $wpdb->prefix . 'irembopay_subscription_plans'; }
		return self::$table;
	}

	public static function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$sql     = "CREATE TABLE {$table} (
			id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			product_id           BIGINT UNSIGNED NOT NULL,
			plan_number          TINYINT UNSIGNED NOT NULL,
			plan_name            VARCHAR(100) NOT NULL,
			price                DECIMAL(10,2) NOT NULL,
			interval_value       TINYINT UNSIGNED NOT NULL,
			interval_unit        ENUM('day','week','month') NOT NULL,
			total_duration_value TINYINT UNSIGNED NOT NULL,
			total_duration_unit  ENUM('day','week','month') NOT NULL,
			grace_period_days    TINYINT UNSIGNED NOT NULL DEFAULT 3,
			created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY product_plan (product_id, plan_number)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'irembopay_plans_db_version', '1.0' );
	}

	public static function get( int $plan_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $plan_id ) );
	}

	public static function get_by_product( int $product_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE product_id = %d ORDER BY plan_number ASC',
			$product_id
		) );
	}

	public static function save_plans_for_product( int $product_id, array $plans ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'product_id' => $product_id ] );
		foreach ( $plans as $plan ) {
			$wpdb->insert( self::table(), array_merge( [ 'product_id' => $product_id ], $plan ) );
		}
	}
}
