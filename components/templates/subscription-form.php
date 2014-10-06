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
	<div id="marketing-box" class="boxed">
		<h1>Rest assured</h1>
		<ul>
			<li>Your credit card will not be charged until the 7-day trial is complete.</li>
			<li>Cancel online anytime during the trial if it's not right for you.</li>
			<li><a href="mailto:<?php echo esc_attr( $template_variables['support_email'] ); ?>">Email</a> us at any time for support.</li>
		</ul>
	</div>
</div>