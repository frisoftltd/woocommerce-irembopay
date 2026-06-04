<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_Product {

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs',    [ $this, 'add_tab' ] );
		add_action( 'woocommerce_product_data_panels',  [ $this, 'render_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_subscription_data' ] );
		add_filter( 'woocommerce_get_price_html',       [ $this, 'subscription_price_html' ], 10, 2 );
		add_filter( 'tutor_course_details_wc_add_to_cart_price', [ $this, 'tutor_cart_price_suffix' ], 10, 2 );
		add_filter( 'get_tutor_course_price',           [ $this, 'tutor_single_course_price' ], 10, 2 );
		add_filter( 'tutor_course_price',               [ $this, 'tutor_course_price_html' ], 10, 2 );
		add_filter( 'woocommerce_is_sold_individually', [ $this, 'sold_individually' ], 10, 2 );
		add_filter( 'woocommerce_is_purchasable',       [ $this, 'is_purchasable' ], 10, 2 );
	}

	public function add_tab( array $tabs ): array {
		$tabs['irembopay_subscription'] = [
			'label'    => __( 'IremboPay Subscription', 'wc-irembopay' ),
			'target'   => 'irembopay_subscription_data',
			'class'    => [],
			'priority' => 60,
		];
		return $tabs;
	}

	public function render_panel(): void {
		global $post;
		$id       = $post->ID;
		$is_sub   = get_post_meta( $id, '_irembopay_is_subscription', true );
		$cy_value = get_post_meta( $id, '_irembopay_billing_cycle_value', true ) ?: 1;
		$cy_unit  = get_post_meta( $id, '_irembopay_billing_cycle_unit', true ) ?: 'month';
		$grace    = get_post_meta( $id, '_irembopay_grace_period', true );
		if ( $grace === '' || $grace === false ) { $grace = 3; }

		$units = [
			'minute' => __( 'Minute(s) — ⚠️ Testing only', 'wc-irembopay' ),
			'day'    => __( 'Day(s)',   'wc-irembopay' ),
			'week'   => __( 'Week(s)',  'wc-irembopay' ),
			'month'  => __( 'Month(s)', 'wc-irembopay' ),
		];
		?>
		<div id="irembopay_subscription_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php woocommerce_wp_checkbox( [
					'id'          => '_irembopay_is_subscription',
					'label'       => __( 'Enable Subscription', 'wc-irembopay' ),
					'description' => __( 'Charge customers on a recurring basis using IremboPay — no extra plugin needed.', 'wc-irembopay' ),
					'value'       => $is_sub,
				] ); ?>
			</div>
			<div class="options_group irembopay-sub-fields" <?php echo $is_sub !== 'yes' ? 'style="display:none"' : ''; ?>>
				<p class="form-field">
					<label><?php esc_html_e( 'Bill every', 'wc-irembopay' ); ?></label>
					<input type="number" name="_irembopay_billing_cycle_value"
					       value="<?php echo esc_attr( $cy_value ); ?>"
					       min="1" max="365" style="width:60px">
					<select name="_irembopay_billing_cycle_unit">
						<?php foreach ( $units as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>"
							        <?php selected( $cy_unit, $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="form-field">
					<label><?php esc_html_e( 'Grace Period (days)', 'wc-irembopay' ); ?></label>
					<input type="number" name="_irembopay_grace_period"
					       value="<?php echo esc_attr( $grace ); ?>"
					       min="0" max="30" style="width:60px">
				</p>
				<p class="form-field">
					<label><?php esc_html_e( 'Total Payments', 'wc-irembopay' ); ?></label>
					<input type="number" name="_irembopay_total_payments"
					       value="<?php echo esc_attr( get_post_meta( $id, '_irembopay_total_payments', true ) ?: 0 ); ?>"
					       min="0" max="999" style="width:60px">
					<span class="description"><?php esc_html_e( 'Number of payments before course is owned permanently. 0 = renews forever.', 'wc-irembopay' ); ?></span>
				</p>
			</div>
			<div class="options_group">
				<?php woocommerce_wp_text_input( [
					'id'          => '_irembopay_product_code',
					'label'       => __( 'IremboPay Product Code', 'wc-irembopay' ),
					'description' => __( 'The IremboPay product code for this product (e.g. PC-02f0b15ac4). Overrides the default product code in gateway settings.', 'wc-irembopay' ),
					'desc_tip'    => true,
					'value'       => get_post_meta( $id, '_irembopay_product_code', true ),
					'placeholder' => 'PC-xxxxxxxx',
				] ); ?>
			</div>
		</div>
		<script>jQuery(function($){
			$('#_irembopay_is_subscription').on('change', function(){
				$('.irembopay-sub-fields').toggle(this.checked);
			});
			if($('#_irembopay_is_subscription').is(':checked')){
				$('.irembopay-sub-fields').show();
			}
		});</script>
		<?php
	}

	public function save_subscription_data( int $product_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $product_id ) ) { return; }
		if ( get_post_type( $product_id ) !== 'product' ) { return; }

		update_post_meta( $product_id, '_irembopay_is_subscription', isset( $_POST['_irembopay_is_subscription'] ) ? 'yes' : 'no' );

		$valid_units = [ 'minute', 'day', 'week', 'month' ];
		$cy_value    = max( 1, absint( $_POST['_irembopay_billing_cycle_value'] ?? 1 ) );
		$cy_unit     = in_array( $_POST['_irembopay_billing_cycle_unit'] ?? '', $valid_units, true )
		               ? $_POST['_irembopay_billing_cycle_unit']
		               : 'month';
		$grace       = max( 0, absint( $_POST['_irembopay_grace_period'] ?? 3 ) );

		update_post_meta( $product_id, '_irembopay_billing_cycle_value', $cy_value );
		update_post_meta( $product_id, '_irembopay_billing_cycle_unit',  $cy_unit );
		update_post_meta( $product_id, '_irembopay_grace_period',        $grace );
		$total_payments = max( 0, absint( $_POST['_irembopay_total_payments'] ?? 0 ) );
		update_post_meta( $product_id, '_irembopay_total_payments', $total_payments );
		update_post_meta( $product_id, '_irembopay_product_code', sanitize_text_field( $_POST['_irembopay_product_code'] ?? '' ) );
	}

	public function subscription_price_html( string $price, $product ): string {
		if ( 'yes' !== get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) {
			return $price;
		}

		$interval       = (int) get_post_meta( $product->get_id(), '_irembopay_billing_cycle_value', true ) ?: 1;
		$unit           = get_post_meta( $product->get_id(), '_irembopay_billing_cycle_unit', true ) ?: 'month';
		$total_payments = (int) get_post_meta( $product->get_id(), '_irembopay_total_payments', true );

		$suffix = $this->build_price_suffix( $interval, $unit, $total_payments );

		// Prevent double suffix if filter fires twice
		if ( str_contains( $price, $suffix ) ) {
			return $price;
		}

		return $price . ' ' . $suffix;
	}

	public function tutor_cart_price_suffix( string $html, $product ): string {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}
		if ( 'yes' !== get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) {
			return $html;
		}

		$interval       = (int) get_post_meta( $product->get_id(), '_irembopay_billing_cycle_value', true ) ?: 1;
		$unit           = get_post_meta( $product->get_id(), '_irembopay_billing_cycle_unit', true ) ?: 'month';
		$total_payments = (int) get_post_meta( $product->get_id(), '_irembopay_total_payments', true );
		$suffix         = $this->build_price_suffix( $interval, $unit, $total_payments );

		return $html . '<div style="font-size:1rem;font-weight:600;color:#222;margin-top:6px;letter-spacing:0.01em;">'
			. esc_html( $suffix )
			. '</div>';
	}

	public function tutor_single_course_price( $price, $course_id ): string {
		$product_id = get_post_meta( $course_id, '_tutor_course_product_id', true )
		           ?: get_post_meta( $course_id, '_tutor_product', true );

		if ( ! $product_id ) {
			return $price;
		}

		if ( 'yes' !== get_post_meta( $product_id, '_irembopay_is_subscription', true ) ) {
			return $price;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) { return $price; }

		$interval       = (int) get_post_meta( $product_id, '_irembopay_billing_cycle_value', true ) ?: 1;
		$unit           = get_post_meta( $product_id, '_irembopay_billing_cycle_unit', true ) ?: 'month';
		$total_payments = (int) get_post_meta( $product_id, '_irembopay_total_payments', true );
		$sale_price     = $product->get_sale_price() ?: $product->get_price();
		$suffix         = $this->build_price_suffix( $interval, $unit, $total_payments );

		return '<span class="tutor-course-price">'
			. wc_price( $sale_price )
			. ' <span style="font-weight:400;font-size:.9em;color:#555">' . esc_html( $suffix ) . '</span>'
			. '</span>';
	}

	public function tutor_course_price_html( $price, $product ): string {
		if ( ! $product || 'yes' !== get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) {
			return $price;
		}

		$interval       = (int) get_post_meta( $product->get_id(), '_irembopay_billing_cycle_value', true ) ?: 1;
		$unit           = get_post_meta( $product->get_id(), '_irembopay_billing_cycle_unit', true ) ?: 'month';
		$total_payments = (int) get_post_meta( $product->get_id(), '_irembopay_total_payments', true );
		$sale_price     = wc_price( $product->get_sale_price() ?: $product->get_price() );
		$suffix         = $this->build_price_suffix( $interval, $unit, $total_payments );

		return $sale_price . ' <span style="font-weight:400;font-size:.9em;color:#555">' . $suffix . '</span>';
	}

	private function build_price_suffix( int $interval, string $unit, int $total_payments ): string {
		if ( $total_payments > 1 ) {
			// Installment: show total number of months
			$total_months = $interval * $total_payments;
			return sprintf(
				'/ %d %s',
				$total_months,
				$total_months === 1 ? $unit : $unit . 's'
			);
		}
		// Standard recurring
		if ( $interval === 1 ) {
			return '/ ' . $unit;
		}
		return sprintf( '/ %d %ss', $interval, $unit );
	}

	public function sold_individually( bool $sold, WC_Product $product ): bool {
		return ( 'yes' === get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) ? true : $sold;
	}

	public function is_purchasable( bool $purchasable, $product ): bool {
		if ( 'yes' === get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) {
			return true;
		}
		return $purchasable;
	}
}
