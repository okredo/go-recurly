<?php
if ( apply_filters( 'go_site_locked', FALSE ) )
{
	go_site_lock()->lock_screen( 'Subscribing' );
	return;
}//end if
?>
<div id="go-recurly-payment" class="clearfix">
	<div
		id="go-recurly-form"
		data-signature="<?php echo esc_attr( $template_variables['signature'] ); ?>"
		data-success-url="<?php echo esc_attr( $template_variables['url'] ); ?>"
		data-plan-code="<?php echo esc_attr( $template_variables['plan_code'] ); ?>"
		data-terms-url="<?php echo esc_attr( $template_variables['terms_url'] ); ?>"
	></div>
</div>