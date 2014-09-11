<?php
if ( apply_filters( 'go_site_locked', FALSE ) )
{
	go_site_lock()->lock_screen( 'Updating your billing information' );
	return;
}//end if
?>
<div class="go-recurly go-recurly-billing">
<?php
if ( ! $template_variables['account_code'] )
{
	?>
	<p>You do not have any billing information.</p>
</div>
	<?php
	return;
}
?>
	<div id="go-recurly-billing-form"
		data-signature="<?php echo esc_attr( $template_variables['signature'] ); ?>"
		data-success-url="<?php echo esc_attr( $template_variables['success_url'] ); ?>"
		data-account-code="<?php echo esc_attr( $template_variables['account_code'] ); ?>"
	></div>
</div>
