<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php esc_html_e( 'Complete Your Payment', 'wc-irembopay' ); ?></title>
<?php wp_head(); ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem 2rem;max-width:420px;width:100%;text-align:center}
.card h1{font-size:1.25rem;color:#1a1a2e;margin-bottom:.5rem}
.card p{color:#666;font-size:.95rem;line-height:1.5;margin-bottom:1.5rem}
.spinner{display:inline-block;width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin .8s linear infinite;margin-bottom:1.25rem}
@keyframes spin{to{transform:rotate(360deg)}}
.btn{display:inline-block;background:#2563eb;color:#fff;border:none;border-radius:8px;padding:.75rem 2rem;font-size:1rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s}
.btn:hover{background:#1d4ed8}
.cancel-link{display:block;margin-top:1rem;color:#888;font-size:.875rem;text-decoration:none}
.cancel-link:hover{color:#333}
#irembopay-error{display:none;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;margin-top:1rem;font-size:.9rem}
</style>
</head>
<body>
<div class="card">
	<div style="margin-bottom:1.5rem">
		<?php $logo_id = get_theme_mod( 'custom_logo' );
		echo $logo_id ? wp_get_attachment_image( $logo_id, 'medium' ) : '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'; ?>
	</div>
	<div class="spinner" id="irembopay-spinner"></div>
	<h1><?php esc_html_e( 'Completing your payment…', 'wc-irembopay' ); ?></h1>
	<p><?php esc_html_e( 'A secure payment window is opening. Please do not close this page.', 'wc-irembopay' ); ?></p>
	<button id="irembopay-reopen" class="btn" style="display:none"><?php esc_html_e( 'Open Payment Window', 'wc-irembopay' ); ?></button>
	<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="cancel-link"><?php esc_html_e( '← Return to cart', 'wc-irembopay' ); ?></a>
	<div id="irembopay-error"></div>
</div>
<script src="https://dashboard.irembopay.com/assets/payment/inline.js"></script>
<script>
(function(){
	var publicKey     = <?php echo wp_json_encode( $args['public_key'] ); ?>;
	var invoiceNumber = <?php echo wp_json_encode( $args['invoice_number'] ); ?>;
	var successUrl    = <?php echo wp_json_encode( $args['redirect_url'] ?? $args['order']->get_checkout_order_received_url() ); ?>;
	var spinner = document.getElementById('irembopay-spinner');
	var btnOpen = document.getElementById('irembopay-reopen');
	var errBox  = document.getElementById('irembopay-error');

	function showError(msg){
		spinner.style.display='none'; btnOpen.style.display='inline-block';
		errBox.style.display='block'; errBox.textContent=msg||<?php echo wp_json_encode( __( 'Payment failed or was cancelled.', 'wc-irembopay' ) ); ?>;
	}
	function initPayment(){
		errBox.style.display='none'; btnOpen.style.display='none'; spinner.style.display='inline-block';
		IremboPay.initiate({ publicKey:publicKey, invoiceNumber:invoiceNumber, locale:IremboPay.locale.EN,
			callback:function(err){
				if(!err){ window.location.href=successUrl; } else { showError(err && err.message); }
			}
		});
	}
	btnOpen.addEventListener('click', initPayment);
	document.addEventListener('DOMContentLoaded', function(){
		if(typeof IremboPay==='undefined'){ showError(<?php echo wp_json_encode( __( 'Could not load payment library.', 'wc-irembopay' ) ); ?>); return; }
		initPayment();
	});
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
