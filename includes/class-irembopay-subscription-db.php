<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_DB {
	private static string $table = '';

	public static function table(): string {
		global $wpdb;
		if ( empty( self::$table ) ) { self::$table = $wpdb->prefix . 'irembopay_subscriptions'; }
		return self::$table;
	}

	public static function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$sql = "CREATE TABLE {$table} (
			id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id          BIGINT(20) UNSIGNED NOT NULL,
			parent_order_id  BIGINT(20) UNSIGNED NOT NULL,
			product_id       BIGINT(20) UNSIGNED NOT NULL,
			status           VARCHAR(20)  NOT NULL DEFAULT 'active',
			billing_period   VARCHAR(20)  NOT NULL DEFAULT 'month',
			billing_interval TINYINT(3)   UNSIGNED NOT NULL DEFAULT 1,
			amount           DECIMAL(12,2) NOT NULL DEFAULT '0.00',
			currency         VARCHAR(10)  NOT NULL DEFAULT 'RWF',
			next_renewal     DATETIME     NOT NULL,
			start_date       DATETIME     NOT NULL,
			end_date         DATETIME     DEFAULT NULL,
			grace_period_days TINYINT(3)  UNSIGNED NOT NULL DEFAULT 3,
			last_invoice     VARCHAR(120) DEFAULT NULL,
			renewal_order_id BIGINT(20)   UNSIGNED DEFAULT NULL,
			parent_whatsapp  VARCHAR(30)  DEFAULT NULL,
			created_at       DATETIME     NOT NULL,
			updated_at       DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id  (user_id),
			KEY status   (status),
			KEY next_renewal (next_renewal)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		// Migrate existing installs: add parent_whatsapp if not present
		$col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'parent_whatsapp'" );
		if ( empty( $col ) ) {
		    $wpdb->query( "ALTER TABLE {$table} ADD COLUMN parent_whatsapp VARCHAR(30) DEFAULT NULL AFTER renewal_order_id" );
		}
		update_option( 'irembopay_subscription_db_version', '1.1' );
	}

	public static function insert( array $data ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array_merge( [ 'status' => 'active', 'billing_interval' => 1, 'grace_period_days' => 3, 'created_at' => $now, 'updated_at' => $now ], $data );
		return $wpdb->insert( self::table(), $data ) ? (int) $wpdb->insert_id : false;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return (bool) $wpdb->update( self::table(), $data, [ 'id' => $id ] );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	public static function get_by_user( int $user_id, string $status = 'any' ): array {
		global $wpdb; $t = self::table();
		return $status === 'any'
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC", $user_id ) )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND status = %s ORDER BY id DESC", $user_id, $status ) );
	}

	public static function get_due_for_renewal(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE status = 'active' AND next_renewal <= %s ORDER BY next_renewal ASC", current_time( 'mysql' ) ) );
	}

	public static function get_expired_grace(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE status = 'pending_renewal' AND DATE_ADD(next_renewal, INTERVAL grace_period_days DAY) < %s", current_time( 'mysql' ) ) );
	}

	public static function get_active_by_user_product( int $user_id, int $product_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE user_id = %d AND product_id = %d AND status = 'active' LIMIT 1", $user_id, $product_id ) );
	}

	public static function get_by_renewal_order( int $renewal_order_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE renewal_order_id = %d LIMIT 1", $renewal_order_id ) );
	}

	public static function count( string $status = 'any' ): int {
		global $wpdb; $t = self::table();
		return $status === 'any'
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" )
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", $status ) );
	}

	public static function get_list( string $status = 'any', int $per_page = 20, int $offset = 0 ): array {
		global $wpdb; $t = self::table();
		$where = $status !== 'any' ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
		return $wpdb->get_results( "SELECT * FROM {$t} {$where} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}" );
	}

	public static function search( string $term, string $status = 'any', int $per_page = 25, int $offset = 0 ): array {
		global $wpdb;
		$t    = self::table();
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$where_status = $status !== 'any' ? $wpdb->prepare( 'AND s.status = %s', $status ) : '';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT s.* FROM {$t} s
			 LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
			 LEFT JOIN {$wpdb->usermeta} um_name  ON um_name.user_id  = s.user_id AND um_name.meta_key  = 'parent_name'
			 LEFT JOIN {$wpdb->usermeta} um_phone ON um_phone.user_id = s.user_id AND um_phone.meta_key = 'phone_number'
			 WHERE (
			     u.display_name      LIKE %s OR
			     u.user_email        LIKE %s OR
			     um_name.meta_value  LIKE %s OR
			     um_phone.meta_value LIKE %s OR
			     s.parent_order_id   LIKE %s OR
			     s.renewal_order_id  LIKE %s
			 )
			 {$where_status}
			 ORDER BY s.id DESC
			 LIMIT %d OFFSET %d",
			$like, $like, $like, $like, $like, $like,
			$per_page, $offset
		) );
	}

	public static function search_count( string $term, string $status = 'any' ): int {
		global $wpdb;
		$t    = self::table();
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$where_status = $status !== 'any' ? $wpdb->prepare( 'AND s.status = %s', $status ) : '';

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT s.id) FROM {$t} s
			 LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
			 LEFT JOIN {$wpdb->usermeta} um_name  ON um_name.user_id  = s.user_id AND um_name.meta_key  = 'parent_name'
			 LEFT JOIN {$wpdb->usermeta} um_phone ON um_phone.user_id = s.user_id AND um_phone.meta_key = 'phone_number'
			 WHERE (
			     u.display_name      LIKE %s OR
			     u.user_email        LIKE %s OR
			     um_name.meta_value  LIKE %s OR
			     um_phone.meta_value LIKE %s OR
			     s.parent_order_id   LIKE %s OR
			     s.renewal_order_id  LIKE %s
			 )
			 {$where_status}",
			$like, $like, $like, $like, $like, $like
		) );
	}
}
