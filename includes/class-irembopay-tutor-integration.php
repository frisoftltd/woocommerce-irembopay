<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Tutor_Integration {

	const TUTOR_PRODUCT_META = '_tutor_product';
	const TUTOR_BUNDLE_META  = '_tutor_course_bundle';
	const COURSE_CODE_META   = '_irembopay_product_code';

	public function __construct() {
		add_action( 'tutor_course_settings_before',  [ $this, 'render_course_code_field' ] );
		add_action( 'save_post_courses',             [ $this, 'save_course_code_field' ] );
		add_filter( 'irembopay_payment_items',       [ $this, 'apply_course_product_codes' ], 10, 2 );
		add_filter( 'irembopay_invoice_description', [ $this, 'build_invoice_description' ],  10, 2 );
		add_action( 'irembopay_payment_complete',    [ $this, 'enroll_student' ] );
		add_action( 'tutor_after_enrolled',          [ $this, 'log_enrollment' ], 10, 3 );
	}

	// ------------------------------------------------------------------ //
	//  Admin: per-course product code field
	// ------------------------------------------------------------------ //

	public function render_course_code_field(): void {
		global $post;
		if ( ! $post || get_post_type( $post->ID ) !== 'courses' ) { return; }
		$code = get_post_meta( $post->ID, self::COURSE_CODE_META, true );
		wp_nonce_field( 'irembopay_save_course_code', 'irembopay_course_nonce' ); ?>
		<div class="tutor-option-field-row">
			<div class="tutor-option-field-label">
				<label for="irembopay_product_code"><?php esc_html_e( 'IremboPay Product Code', 'wc-irembopay' ); ?></label>
			</div>
			<div class="tutor-option-field">
				<input type="text" id="irembopay_product_code" name="irembopay_product_code"
					value="<?php echo esc_attr( $code ); ?>" placeholder="e.g. PC-0a6b09684c" class="tutor-form-control">
			</div>
		</div>
		<?php
	}

	public function save_course_code_field( int $post_id ): void {
		if ( ! isset( $_POST['irembopay_course_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['irembopay_course_nonce'] ) ), 'irembopay_save_course_code' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		update_post_meta( $post_id, self::COURSE_CODE_META, sanitize_text_field( $_POST['irembopay_product_code'] ?? '' ) );
	}

	// ------------------------------------------------------------------ //
	//  Payment item enrichment
	// ------------------------------------------------------------------ //

	public function apply_course_product_codes( array $items, WC_Order $order ): array {
		$order_items = array_values( $order->get_items() );
		foreach ( $items as $index => &$item ) {
			$order_item = $order_items[ $index ] ?? null;
			if ( ! $order_item ) { continue; }
			$course_id = $this->get_course_id_from_product( $order_item->get_product_id() );
			if ( $course_id ) {
				$code = get_post_meta( $course_id, self::COURSE_CODE_META, true );
				if ( ! empty( $code ) ) { $item['code'] = $code; }
			}
		}
		unset( $item );
		return $items;
	}

	public function build_invoice_description( string $description, WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$pid = $item->get_product_id();
			if ( $this->is_bundle_product( $pid ) ) {
				$names = array_merge( $names, $this->get_bundle_course_names( $pid ) );
				continue;
			}
			$course_id = $this->get_course_id_from_product( $pid );
			$names[]   = $course_id ? get_the_title( $course_id ) : $item->get_name();
		}
		if ( empty( $names ) ) { return $description; }
		return sprintf( __( 'Course access: %s', 'wc-irembopay' ), implode( ', ', array_unique( $names ) ) );
	}

	// ------------------------------------------------------------------ //
	//  Enrollment
	// ------------------------------------------------------------------ //

	public function enroll_student( WC_Order $order ): void {
		if ( ! function_exists( 'tutor_utils' ) ) { return; }
		$user_id = $order->get_customer_id();
		if ( ! $user_id ) { return; }
		foreach ( $order->get_items() as $item ) {
			$pid = $item->get_product_id();
			$course_id = $this->get_course_id_from_product( $pid );
			if ( $course_id ) { $this->enroll_user_in_course( $user_id, $course_id, $order->get_id() ); continue; }
			if ( $this->is_bundle_product( $pid ) ) {
				foreach ( $this->get_bundle_course_ids( $pid ) as $cid ) {
					$this->enroll_user_in_course( $user_id, $cid, $order->get_id() );
				}
			}
		}
	}

	public function log_enrollment( int $course_id, int $user_id, int $enrollment_id ): void {
		IremboPay_Logger::info( "Tutor: user #{$user_id} enrolled in course #{$course_id} (enrollment #{$enrollment_id})." );
	}

	// ------------------------------------------------------------------ //
	//  Subscription access control
	// ------------------------------------------------------------------ //

	public function revoke_course_access( int $sub_id ): void {
		if ( ! function_exists( 'tutor_utils' ) ) { return; }
		$sub = IremboPay_Subscription_DB::get( $sub_id );
		if ( $sub ) { $this->update_enrollment_status( $sub, 'cancel' ); }
	}

	public function restore_course_access( int $sub_id, ?WC_Order $order ): void {
		if ( ! function_exists( 'tutor_utils' ) ) { return; }
		$sub = IremboPay_Subscription_DB::get( $sub_id );
		if ( $sub ) { $this->update_enrollment_status( $sub, 'approved', $order ? $order->get_id() : (int) $sub->parent_order_id ); }
	}

	private function update_enrollment_status( object $sub, string $action, int $order_id = 0 ): void {
		$pid      = (int) $sub->product_id;
		$user_id  = (int) $sub->user_id;
		$order_id = $order_id ?: (int) $sub->parent_order_id;
		$course_ids = $this->is_bundle_product( $pid ) ? $this->get_bundle_course_ids( $pid ) : array_filter( [ $this->get_course_id_from_product( $pid ) ] );

		foreach ( $course_ids as $course_id ) {
			if ( $action === 'approved' ) {
				$this->enroll_user_in_course( $user_id, $course_id, $order_id );
			} else {
				global $wpdb;
				$wpdb->update( $wpdb->posts, [ 'post_status' => 'cancel' ], [ 'post_type' => 'tutor_enrolled', 'post_author' => $user_id, 'post_parent' => $course_id ], [ '%s' ], [ '%s', '%d', '%d' ] );
				IremboPay_Logger::info( "Tutor enrollment cancelled: user #{$user_id} / course #{$course_id}." );
			}
		}
	}

	// ------------------------------------------------------------------ //
	//  Public helpers (used by subscription manager)
	// ------------------------------------------------------------------ //

	public function get_course_id_from_product( int $product_id ): ?int {
		$courses = get_posts( [ 'post_type' => 'courses', 'meta_key' => self::TUTOR_PRODUCT_META, 'meta_value' => $product_id, 'numberposts' => 1, 'fields' => 'ids' ] );
		return ! empty( $courses ) ? (int) $courses[0] : null;
	}

	public function is_bundle_product( int $product_id ): bool {
		return ! empty( get_post_meta( $product_id, self::TUTOR_BUNDLE_META, true ) );
	}

	public function get_bundle_course_ids( int $product_id ): array {
		$raw = get_post_meta( $product_id, self::TUTOR_BUNDLE_META, true );
		if ( empty( $raw ) ) { return []; }
		$ids = is_array( $raw ) ? $raw : ( strpos( $raw, ',' ) !== false ? explode( ',', $raw ) : [ $raw ] );
		return array_filter( array_map( 'intval', $ids ) );
	}

	private function get_bundle_course_names( int $product_id ): array {
		return array_filter( array_map( fn( $id ) => get_the_title( $id ), $this->get_bundle_course_ids( $product_id ) ) );
	}

	private function enroll_user_in_course( int $user_id, int $course_id, int $order_id ): void {
		if ( ! function_exists( 'tutor_utils' ) || tutor_utils()->is_enrolled( $course_id, $user_id ) ) { return; }
		$eid = tutor_utils()->do_enroll( $course_id, $order_id, $user_id );
		$eid
			? IremboPay_Logger::info( "Enrolled user #{$user_id} in course #{$course_id} via order #{$order_id}." )
			: IremboPay_Logger::error( "Failed to enroll user #{$user_id} in course #{$course_id}." );
	}
}
