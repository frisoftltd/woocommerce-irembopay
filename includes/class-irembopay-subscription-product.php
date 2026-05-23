<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_Product {

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs',    [ $this, 'add_tab' ] );
		add_action( 'woocommerce_product_data_panels',  [ $this, 'render_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_fields' ] );
		add_filter( 'woocommerce_get_price_html',       [ $this, 'subscription_price_html' ], 10, 2 );
		add_filter( 'woocommerce_is_sold_individually', [ $this, 'sold_individually' ], 10, 2 );
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
		$period   = get_post_meta( $id, '_irembopay_sub_period',      true ) ?: 'month';
		$interval = get_post_meta( $id, '_irembopay_sub_interval',    true ) ?: '1';
		$grace    = get_post_meta( $id, '_irembopay_sub_grace',       true ) ?: '3';
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
					<label><?php esc_html_e( 'Billing Cycle', 'wc-irembopay' ); ?></label>
					<?php esc_html_e( 'Every', 'wc-irembopay' ); ?>
					<input type="number" name="_irembopay_sub_interval" value="<?php echo esc_attr( $interval ); ?>" min="1" max="365" style="width:60px">
					<select name="_irembopay_sub_period">
						<?php foreach ( [ 'day' => __( 'Day(s)', 'wc-irembopay' ), 'week' => __( 'Week(s)', 'wc-irembopay' ), 'month' => __( 'Month(s)', 'wc-irembopay' ), 'year' => __( 'Year(s)', 'wc-irembopay' ) ] as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $period, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php woocommerce_wp_text_input( [
					'id'                => '_irembopay_sub_grace',
					'label'             => __( 'Grace Period (days)', 'wc-irembopay' ),
					'description'       => __( 'Days to wait for payment before suspending access.', 'wc-irembopay' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'custom_attributes' => [ 'min' => '0', 'max' => '30' ],
					'value'             => $grace,
				] ); ?>
			</div>
			<div class="options_group">
				<?php woocommerce_wp_text_input( [
					'id'          => '_irembopay_product_code',
					'label'       => __( 'IremboPay Product Code', 'wc-irembopay' ),
					'description' => __( 'The IremboPay product code for this product (e.g. PC-02f0b15ac4). Overrides the default product code in gateway settings. Required if selling this product via IremboPay.', 'wc-irembopay' ),
					'desc_tip'    => true,
					'value'       => get_post_meta( $id, '_irembopay_product_code', true ),
					'placeholder' => 'PC-xxxxxxxx',
				] ); ?>
			</div>
		</div>
		<script>jQuery(function($){ $('#_irembopay_is_subscription').on('change', function(){ $('.irembopay-sub-fields').toggle(this.checked); }); });</script>
		<?php
	}

	public function save_fields( int $product_id ): void {
		update_post_meta( $product_id, '_irembopay_is_subscription',  isset( $_POST['_irembopay_is_subscription'] ) ? 'yes' : 'no' );
		update_post_meta( $product_id, '_irembopay_sub_period',        sanitize_text_field( $_POST['_irembopay_sub_period']       ?? 'month' ) );
		update_post_meta( $product_id, '_irembopay_sub_interval',      max( 1, absint( $_POST['_irembopay_sub_interval']          ?? 1 ) ) );
		update_post_meta( $product_id, '_irembopay_sub_grace',         max( 0, absint( $_POST['_irembopay_sub_grace']             ?? 3 ) ) );
		update_post_meta( $product_id, '_irembopay_product_code', sanitize_text_field( $_POST['_irembopay_product_code'] ?? '' ) );
	}

	public function subscription_price_html( string $price, WC_Product $product ): string {
		if ( 'yes' !== get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) { return $price; }
		$period   = get_post_meta( $product->get_id(), '_irembopay_sub_period',   true ) ?: 'month';
		$interval = (int) get_post_meta( $product->get_id(), '_irembopay_sub_interval', true ) ?: 1;
		return $price . ' <span class="irembopay-sub-period"> / ' . esc_html( IremboPay_Subscription_Manager::billing_label( $period, $interval ) ) . '</span>';
	}

	public function sold_individually( bool $sold, WC_Product $product ): bool {
		return ( 'yes' === get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) ? true : $sold;
	}
}
