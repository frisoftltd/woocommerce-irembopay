<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_Product {

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs',    [ $this, 'add_tab' ] );
		add_action( 'woocommerce_product_data_panels',  [ $this, 'render_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_fields' ] );
		add_filter( 'woocommerce_get_price_html',       [ $this, 'subscription_price_html' ], 10, 2 );
		add_filter( 'woocommerce_is_sold_individually', [ $this, 'sold_individually' ], 10, 2 );

		add_action( 'woocommerce_before_add_to_cart_button',       [ $this, 'render_installment_dropdown' ] );
		add_filter( 'woocommerce_add_cart_item_data',              [ $this, 'add_installment_to_cart_item' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data',                   [ $this, 'display_installment_in_cart' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_installment_to_order' ], 10, 4 );
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
					<span style="margin-left:8px"><?php esc_html_e( 'Every', 'wc-irembopay' ); ?></span>
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
			<?php
			$allow_inst    = get_post_meta( $id, '_irembopay_allow_installments', true );
			$inst_options  = get_post_meta( $id, '_irembopay_installment_options', true );
			$inst_options  = is_array( $inst_options ) ? $inst_options : [];
			?>
			<div class="options_group irembopay-sub-fields" <?php echo $is_sub !== 'yes' ? 'style="display:none"' : ''; ?>>
				<?php woocommerce_wp_checkbox( [
					'id'          => '_irembopay_allow_installments',
					'label'       => __( 'Allow Installments', 'wc-irembopay' ),
					'description' => __( 'Let customers split this payment into multiple installments.', 'wc-irembopay' ),
					'value'       => $allow_inst,
				] ); ?>
				<?php if ( $allow_inst !== 'yes' ) : ?>
				<div class="form-field irembopay-inst-options" style="padding-left:162px; margin-bottom:10px; display:none;">
				<?php else : ?>
				<div class="form-field irembopay-inst-options" style="padding-left:162px; margin-bottom:10px;">
				<?php endif; ?>
					<label style="display:block; margin-bottom:8px; font-weight:600;"><?php esc_html_e( 'Installment Options', 'wc-irembopay' ); ?></label>
					<div style="display:flex; flex-wrap:wrap; gap:16px; margin-top:4px;">
					<?php foreach ( [ 1, 2, 3, 4, 6 ] as $n ) :
						$checked = in_array( (string) $n, $inst_options, true ) || in_array( $n, $inst_options, true );
					?>
						<label style="
							display:flex !important;
							align-items:center !important;
							gap:8px !important;
							font-size:15px !important;
							font-weight:600 !important;
							cursor:pointer !important;
							background:#f0f0f1;
							border:1px solid #c3c4c7;
							border-radius:4px;
							padding:8px 16px !important;
							min-width:60px;
							justify-content:center;
						">
							<input type="checkbox"
							       name="_irembopay_installment_options[]"
							       value="<?php echo esc_attr( $n ); ?>"
							       style="
							           width:18px !important;
							           height:18px !important;
							           margin:0 !important;
							           cursor:pointer !important;
							       "
							       <?php checked( $checked ); ?>>
							<span style="font-size:15px !important;"><?php echo esc_html( $n ); ?>x</span>
						</label>
					<?php endforeach; ?>
					</div>
				</div>
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
		<script>jQuery(function($){
		$('#_irembopay_is_subscription').on('change', function(){ $('.irembopay-sub-fields').toggle(this.checked); });
		$('#_irembopay_allow_installments').on('change', function(){ $('.irembopay-inst-options').toggle(this.checked); });
	});</script>
		<?php
	}

	public function save_fields( int $product_id ): void {
		update_post_meta( $product_id, '_irembopay_is_subscription',  isset( $_POST['_irembopay_is_subscription'] ) ? 'yes' : 'no' );
		update_post_meta( $product_id, '_irembopay_sub_period',        sanitize_text_field( $_POST['_irembopay_sub_period']       ?? 'month' ) );
		update_post_meta( $product_id, '_irembopay_sub_interval',      max( 1, absint( $_POST['_irembopay_sub_interval']          ?? 1 ) ) );
		update_post_meta( $product_id, '_irembopay_sub_grace',         max( 0, absint( $_POST['_irembopay_sub_grace']             ?? 3 ) ) );
		update_post_meta( $product_id, '_irembopay_product_code', sanitize_text_field( $_POST['_irembopay_product_code'] ?? '' ) );

		update_post_meta( $product_id, '_irembopay_allow_installments', isset( $_POST['_irembopay_allow_installments'] ) ? 'yes' : 'no' );
		$raw_opts = isset( $_POST['_irembopay_installment_options'] ) ? (array) $_POST['_irembopay_installment_options'] : [];
		$valid    = array_values( array_intersect( array_map( 'intval', $raw_opts ), [ 1, 2, 3, 4, 6 ] ) );
		update_post_meta( $product_id, '_irembopay_installment_options', $valid );
	}

	public function render_installment_dropdown(): void {
		global $product;
		if ( ! $product || 'yes' !== get_post_meta( $product->get_id(), '_irembopay_allow_installments', true ) ) { return; }
		$options = get_post_meta( $product->get_id(), '_irembopay_installment_options', true );
		if ( empty( $options ) || ! is_array( $options ) ) { return; }
		$options = array_unique( array_map( 'intval', $options ) );
		sort( $options );
		if ( ! in_array( 1, $options, true ) ) { array_unshift( $options, 1 ); }
		$price = (float) $product->get_price();
		?>
		<div class="irembopay-installment-selector" style="margin-bottom:1.2em;">
			<label for="irembopay_installments" style="font-weight:bold;display:block;margin-bottom:.4em;">
				<?php esc_html_e( 'Pay in:', 'wc-irembopay' ); ?>
			</label>
			<select name="irembopay_installments" id="irembopay_installments" style="min-width:260px">
				<?php foreach ( $options as $n ) :
					$per   = $n > 0 ? (int) round( $price / $n, 0 ) : (int) $price;
					$label = $n === 1
						? sprintf( __( '1 installment — %s today', 'wc-irembopay' ), strip_tags( wc_price( $per ) ) )
						: sprintf( __( '%d installments — %s per installment', 'wc-irembopay' ), $n, strip_tags( wc_price( $per ) ) );
				?>
					<option value="<?php echo esc_attr( $n ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	public function add_installment_to_cart_item( array $cart_item_data, int $product_id, int $variation_id ): array {
		if ( 'yes' !== get_post_meta( $product_id, '_irembopay_allow_installments', true ) ) { return $cart_item_data; }
		$n = absint( $_POST['irembopay_installments'] ?? 1 );
		if ( $n > 1 ) {
			$cart_item_data['irembopay_installments'] = $n;
		}
		return $cart_item_data;
	}

	public function display_installment_in_cart( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item['irembopay_installments'] ) ) {
			$n           = (int) $cart_item['irembopay_installments'];
			$item_data[] = [
				'key'   => __( 'Payment plan', 'wc-irembopay' ),
				'value' => sprintf( _n( '%d installment', '%d installments', $n, 'wc-irembopay' ), $n ),
			];
		}
		return $item_data;
	}

	public function save_installment_to_order( \WC_Order_Item_Product $item, string $cart_item_key, array $cart_item_values, \WC_Order $order ): void {
		if ( ! empty( $cart_item_values['irembopay_installments'] ) ) {
			$n = (int) $cart_item_values['irembopay_installments'];
			$item->add_meta_data( '_irembopay_chosen_installments', $n, true );
			$order->update_meta_data( '_irembopay_chosen_installments', $n );
		}
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
