<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscription_Product {

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs',    [ $this, 'add_tab' ] );
		add_action( 'woocommerce_product_data_panels',  [ $this, 'render_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_subscription_data' ] );
		add_filter( 'woocommerce_get_price_html',       [ $this, 'subscription_price_html' ], 10, 2 );
		add_filter( 'woocommerce_is_sold_individually', [ $this, 'sold_individually' ], 10, 2 );
		add_filter( 'woocommerce_is_purchasable',                  [ $this, 'is_purchasable' ], 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text',        [ $this, 'add_to_cart_text' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_redirect',            [ $this, 'skip_cart_redirect' ] );
		add_action( 'wp_footer',                                   [ $this, 'enqueue_plan_selector_script' ] );

		add_action( 'woocommerce_before_add_to_cart_button',       [ $this, 'render_plan_selector' ] );
		add_filter( 'woocommerce_add_cart_item_data',              [ $this, 'add_plan_to_cart_item' ], 10, 3 );
		add_filter( 'woocommerce_add_cart_item',                   [ $this, 'set_plan_price_in_cart' ] );
		add_filter( 'woocommerce_get_cart_item_from_session',      [ $this, 'set_plan_price_in_cart' ] );
		add_filter( 'woocommerce_get_item_data',                   [ $this, 'display_plan_in_cart' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_plan_to_order' ], 10, 4 );
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
		$id      = $post->ID;
		$is_sub  = get_post_meta( $id, '_irembopay_is_subscription', true );
		$plans   = class_exists( 'IremboPay_Subscription_Plans_DB' ) ? IremboPay_Subscription_Plans_DB::get_by_product( $id ) : [];
		$plan_map = [];
		foreach ( $plans as $p ) { $plan_map[ (int) $p->plan_number ] = $p; }

		$units = [
			'day'   => __( 'Day(s)',   'wc-irembopay' ),
			'week'  => __( 'Week(s)',  'wc-irembopay' ),
			'month' => __( 'Month(s)', 'wc-irembopay' ),
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
				<?php for ( $n = 1; $n <= 3; $n++ ) :
					$p       = $plan_map[ $n ] ?? null;
					$hidden  = ( $n === 3 && ! $p );
				?>
				<div class="irembopay-plan-block" id="irembopay-plan-block-<?php echo $n; ?>"
				     <?php echo $hidden ? 'style="display:none"' : ''; ?>>
					<p style="border-top:1px solid #ddd;margin:16px 0 12px;padding-top:12px;font-weight:600;color:#444">
						<?php printf( esc_html__( '— Plan %d —', 'wc-irembopay' ), $n ); ?>
						<?php if ( $n >= 2 ) : ?>
							<a href="#" class="irembopay-remove-plan" data-plan="<?php echo $n; ?>"
							   style="color:#cc0000;margin-left:12px;font-size:12px;text-decoration:none">
								<?php esc_html_e( '✕ Remove', 'wc-irembopay' ); ?>
							</a>
						<?php endif; ?>
					</p>
					<p class="form-field">
						<label><?php esc_html_e( 'Plan Name', 'wc-irembopay' ); ?></label>
						<input type="text" name="_irembopay_plan[<?php echo $n; ?>][name]"
						       value="<?php echo esc_attr( $p->plan_name ?? '' ); ?>"
						       placeholder="<?php esc_attr_e( 'e.g. Monthly', 'wc-irembopay' ); ?>"
						       style="width:60%">
					</p>
					<p class="form-field">
						<label><?php esc_html_e( 'Price (Rwf)', 'wc-irembopay' ); ?></label>
						<input type="number" name="_irembopay_plan[<?php echo $n; ?>][price]"
						       value="<?php echo esc_attr( $p->price ?? '' ); ?>"
						       min="0" step="1" style="width:120px">
					</p>
					<p class="form-field">
						<label><?php esc_html_e( 'Bill every', 'wc-irembopay' ); ?></label>
						<input type="number" name="_irembopay_plan[<?php echo $n; ?>][interval_value]"
						       value="<?php echo esc_attr( $p->interval_value ?? 1 ); ?>"
						       min="1" max="365" style="width:60px">
						<select name="_irembopay_plan[<?php echo $n; ?>][interval_unit]">
							<?php foreach ( $units as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>"
								        <?php selected( $p->interval_unit ?? 'month', $val ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="form-field">
						<label><?php esc_html_e( 'Total duration', 'wc-irembopay' ); ?></label>
						<input type="number" name="_irembopay_plan[<?php echo $n; ?>][total_duration_value]"
						       value="<?php echo esc_attr( $p->total_duration_value ?? 12 ); ?>"
						       min="1" max="999" style="width:60px">
						<select name="_irembopay_plan[<?php echo $n; ?>][total_duration_unit]">
							<?php foreach ( $units as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>"
								        <?php selected( $p->total_duration_unit ?? 'month', $val ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="form-field">
						<label><?php esc_html_e( 'Grace Period (days)', 'wc-irembopay' ); ?></label>
						<input type="number" name="_irembopay_plan[<?php echo $n; ?>][grace_period]"
						       value="<?php echo esc_attr( $p->grace_period_days ?? 3 ); ?>"
						       min="0" max="30" style="width:60px">
					</p>
				</div>
				<?php endfor; ?>

				<p id="irembopay-add-plan-3" style="padding:0 12px 12px;<?php echo isset( $plan_map[3] ) ? 'display:none' : ''; ?>">
					<a href="#" class="button irembopay-add-plan" data-plan="3">
						<?php esc_html_e( '+ Add Plan 3', 'wc-irembopay' ); ?>
					</a>
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
			function irembopayTogglePriceFields() {
				var isSub = $('#_irembopay_is_subscription').is(':checked');
				$('._regular_price_field, ._sale_price_field').toggle( ! isSub );
			}
			irembopayTogglePriceFields();
			$('#_irembopay_is_subscription').on('change', function(){
				$('.irembopay-sub-fields').toggle(this.checked);
				irembopayTogglePriceFields();
			});
			$('.irembopay-add-plan').on('click', function(e){
				e.preventDefault();
				var n = $(this).data('plan');
				$('#irembopay-plan-block-' + n).show();
				$('#irembopay-add-plan-' + n).hide();
			});
			$(document).on('click', '.irembopay-remove-plan', function(e){
				e.preventDefault();
				var n = $(this).data('plan');
				$('#irembopay-plan-block-' + n).hide();
				$('#irembopay-plan-block-' + n + ' input[type=text], #irembopay-plan-block-' + n + ' input[type=number]').val('');
				$('#irembopay-add-plan-' + n).show();
			});
		});</script>
		<?php
	}

	public function save_subscription_data( int $product_id ): void {
		// Skip autosaves and revisions — $_POST won't contain our plan fields
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $product_id ) ) { return; }
		if ( get_post_type( $product_id ) !== 'product' ) { return; }
		// Plan fields are only present when the product form was fully submitted
		if ( ! isset( $_POST['_irembopay_plan'] ) ) { return; }

		update_post_meta( $product_id, '_irembopay_is_subscription', isset( $_POST['_irembopay_is_subscription'] ) ? 'yes' : 'no' );
		update_post_meta( $product_id, '_irembopay_product_code', sanitize_text_field( $_POST['_irembopay_product_code'] ?? '' ) );

		$raw_plans   = (array) $_POST['_irembopay_plan'];
		$valid_units = [ 'day', 'week', 'month' ];
		$plans       = [];

		foreach ( [ 1, 2, 3 ] as $n ) {
			$p     = isset( $raw_plans[ $n ] ) ? (array) $raw_plans[ $n ] : [];
			$name  = sanitize_text_field( $p['name'] ?? '' );
			$price = (float) ( $p['price'] ?? 0 );
			if ( empty( $name ) || $price <= 0 ) { continue; }

			$int_unit = in_array( $p['interval_unit'] ?? '', $valid_units, true ) ? $p['interval_unit'] : 'month';
			$dur_unit = in_array( $p['total_duration_unit'] ?? '', $valid_units, true ) ? $p['total_duration_unit'] : 'month';

			$plans[] = [
				'plan_number'          => $n,
				'plan_name'            => $name,
				'price'                => $price,
				'interval_value'       => max( 1, absint( $p['interval_value'] ?? 1 ) ),
				'interval_unit'        => $int_unit,
				'total_duration_value' => max( 1, absint( $p['total_duration_value'] ?? 12 ) ),
				'total_duration_unit'  => $dur_unit,
				'grace_period_days'    => max( 0, absint( $p['grace_period'] ?? 3 ) ),
			];
		}

		IremboPay_Subscription_Plans_DB::save_plans_for_product( $product_id, $plans );
		update_post_meta( $product_id, '_irembopay_plans', $plans );
	}

	public function render_plan_selector(): void {
		global $product;
		if ( ! $product || 'yes' !== get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) { return; }
		$plans = IremboPay_Subscription_Plans_DB::get_by_product( $product->get_id() );
		if ( empty( $plans ) ) { return; }

		echo '<div class="irembopay-plan-selector" style="margin-bottom:16px;">';
		echo '<p style="font-weight:600;margin-bottom:8px;font-size:14px;">' . esc_html__( 'Choose your plan', 'wc-irembopay' ) . '</p>';
		echo '<div style="display:flex;flex-direction:column;gap:8px;">';

		foreach ( $plans as $i => $plan ) {
			$checked      = $i === 0;
			$border_color = $checked ? '#2271b1' : '#ddd';
			$bg_color     = $checked ? '#f0f6ff' : '#fff';
			$label        = sprintf( '%s — %s Rwf / %d %s',
				esc_html( $plan->plan_name ),
				number_format( (float) $plan->price, 0 ),
				(int) $plan->interval_value,
				esc_html( $plan->interval_unit )
			);
			printf(
				'<label class="irembopay-plan-option" style="display:flex;align-items:center;gap:10px;border:2px solid %s;border-radius:6px;padding:10px 14px;cursor:pointer;font-size:13px;font-weight:500;background:%s;">
					<input type="radio" name="_irembopay_chosen_plan_id" class="irembopay-plan-radio" value="%d" %s style="width:16px;height:16px;accent-color:#2271b1;cursor:pointer;flex-shrink:0;">
					<span>%s</span>
				</label>',
				esc_attr( $border_color ),
				esc_attr( $bg_color ),
				(int) $plan->id,
				$checked ? 'checked' : '',
				$label
			);
		}

		echo '</div></div>';
	}

	public function add_plan_to_cart_item( array $cart_item_data, int $product_id, int $variation_id ): array {
		$plan_id = isset( $_POST['_irembopay_chosen_plan_id'] ) ? (int) $_POST['_irembopay_chosen_plan_id'] : 0;
		if ( ! $plan_id ) {
			$plans   = IremboPay_Subscription_Plans_DB::get_by_product( $product_id );
			$plan_id = $plans ? (int) $plans[0]->id : 0;
		}
		if ( $plan_id ) {
			$cart_item_data['_irembopay_chosen_plan_id'] = $plan_id;
			$cart_item_data['unique_key']                = md5( microtime() . rand() );
		}
		return $cart_item_data;
	}

	public function set_plan_price_in_cart( array $cart_item ): array {
		if ( empty( $cart_item['_irembopay_chosen_plan_id'] ) ) { return $cart_item; }
		$plan = IremboPay_Subscription_Plans_DB::get( (int) $cart_item['_irembopay_chosen_plan_id'] );
		if ( $plan && isset( $cart_item['data'] ) ) {
			$cart_item['data']->set_price( $plan->price );
		}
		return $cart_item;
	}

	public function display_plan_in_cart( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item['_irembopay_chosen_plan_id'] ) ) { return $item_data; }
		$plan = IremboPay_Subscription_Plans_DB::get( (int) $cart_item['_irembopay_chosen_plan_id'] );
		if ( $plan ) {
			$item_data[] = [
				'key'   => __( 'Plan', 'wc-irembopay' ),
				'value' => esc_html( $plan->plan_name ),
			];
		}
		return $item_data;
	}

	public function save_plan_to_order( \WC_Order_Item_Product $item, string $cart_item_key, array $cart_item_values, \WC_Order $order ): void {
		if ( empty( $cart_item_values['_irembopay_chosen_plan_id'] ) ) { return; }
		$plan_id = (int) $cart_item_values['_irembopay_chosen_plan_id'];
		$item->add_meta_data( '_irembopay_chosen_plan_id', $plan_id, true );
		$order->update_meta_data( '_irembopay_chosen_plan_id', $plan_id );
	}

	public function subscription_price_html( string $price, $product ): string {
		if ( 'yes' !== get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) { return $price; }
		$plans = IremboPay_Subscription_Plans_DB::get_by_product( $product->get_id() );
		if ( empty( $plans ) ) { return $price; }
		$first = $plans[0];
		return sprintf(
			'<span class="irembopay-from-price">%s %s Rwf / %d %s</span>',
			esc_html__( 'From', 'wc-irembopay' ),
			number_format( (float) $first->price, 0 ),
			(int) $first->interval_value,
			esc_html( $first->interval_unit )
		);
	}

	public function enqueue_plan_selector_script(): void {
		?>
		<script>
		(function($){
			$(document).ready(function(){
				$(document).on('change', '.irembopay-plan-radio', function(){
					var $selector = $(this).closest('.irembopay-plan-selector');
					$selector.find('.irembopay-plan-option').css({'border-color':'#ddd','background':'#fff'});
					$(this).closest('label').css({'border-color':'#2271b1','background':'#f0f6ff'});
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	public function skip_cart_redirect( $url ): string {
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item['_irembopay_chosen_plan_id'] ) ) {
					return wc_get_checkout_url();
				}
			}
		}
		return (string) $url;
	}

	public function is_purchasable( bool $purchasable, $product ): bool {
		if ( 'yes' === get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) {
			return true;
		}
		return $purchasable;
	}

	public function add_to_cart_text( string $text, $product ): string {
		if ( 'yes' === get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) {
			return __( 'Subscribe', 'wc-irembopay' );
		}
		return $text;
	}

	public function sold_individually( bool $sold, WC_Product $product ): bool {
		return ( 'yes' === get_post_meta( $product->get_id(), '_irembopay_is_subscription', true ) ) ? true : $sold;
	}
}
