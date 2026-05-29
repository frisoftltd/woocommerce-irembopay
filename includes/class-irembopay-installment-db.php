<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Installment_DB {
	private static string $table = '';

	public static function table(): string {
		global $wpdb;
		if ( empty( self::$table ) ) { self::$table = $wpdb->prefix . 'irembopay_installments'; }
		return self::$table;
	}

	public static function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$sql     = "CREATE TABLE {$table} (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscription_id    BIGINT UNSIGNED NOT NULL,
			order_id           BIGINT UNSIGNED NOT NULL,
			installment_no     TINYINT UNSIGNED NOT NULL,
			total_installments TINYINT UNSIGNED NOT NULL,
			amount             DECIMAL(10,2) NOT NULL,
			currency           VARCHAR(10) NOT NULL DEFAULT 'RWF',
			due_date           DATETIME NOT NULL,
			paid_date          DATETIME NULL,
			invoice_number     VARCHAR(100) NULL,
			status             ENUM('pending','paid','overdue','failed') DEFAULT 'pending',
			created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY subscription_id (subscription_id),
			KEY status          (status),
			KEY due_date        (due_date),
			KEY invoice_number  (invoice_number)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'irembopay_installment_db_version', '1.0' );
	}

	public static function insert( array $data ) {
		global $wpdb;
		return $wpdb->insert( self::table(), $data ) ? (int) $wpdb->insert_id : false;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table(), $data, [ 'id' => $id ] );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	public static function get_by_invoice( string $invoice_number ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE invoice_number = %s LIMIT 1', $invoice_number ) );
	}

	public static function get_by_subscription( int $sub_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE subscription_id = %d ORDER BY installment_no ASC', $sub_id ) );
	}

	public static function get_due_pending(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE status = 'pending' AND due_date <= %s ORDER BY due_date ASC",
			current_time( 'mysql' )
		) );
	}

	public static function get_upcoming_pending( int $days_ahead = 1 ): array {
		global $wpdb;
		$target = date( 'Y-m-d', strtotime( "+{$days_ahead} day" ) );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE status = 'pending' AND DATE(due_date) = %s",
			$target
		) );
	}

	public static function get_all_overdue(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM " . self::table() . " WHERE status = 'overdue'" );
	}

	public static function count_remaining( int $sub_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::table() . " WHERE subscription_id = %d AND status IN ('pending','overdue')",
			$sub_id
		) );
	}
}
