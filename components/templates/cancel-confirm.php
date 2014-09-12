<div class="go-recurly">
<?php
if (
	isset( $template_variables['subscription']->trial_ends_at ) &&
	time() <= $template_variables['subscription']->trial_ends_at->getTimestamp()
)
{
	?>
	<h3>Leaving so soon?</h3>
	<p>By clicking the submit button below, you are confirming that you would like to cancel your trial of Gigaom Research. Since you are canceling during the free trial period, your credit card will not be charged.</p>
	<?php
}//end if
elseif (
	isset( $template_variables['subscription_meta']->sub_last_payment_date ) &&
	strtotime( '-120 days' ) < strtotime( $template_variables['subscription_meta']->sub_last_payment_date )
)
{
	?>
	<h3>Sorry to see you go!</h3>
	<p>By clicking the submit button below, you are confirming that you would like to cancel your subscription of Gigaom Research. Please allow several days for a refund to be applied to your credit card.</p>
	<?php
}//end elseif
else
{
	?>
	<h3>Sorry to see you go!</h3>
	<p>By clicking the submit button below, you are confirming that you do not want to renew your subscription to Gigaom Research. You will not be charged on your renewal date and your access to Gigaom research and analysis will be restricted.</p>
	<?php
}//end else
?>

<p>Before you go we'd really appreciate it if you shared with us feedback on why Gigaom Research did not meet your needs via a <a href="http://www.surveymonkey.com/s/TGP82MJ">simple survey.</a> One question only, it'll just take a sec. Thanks!</p>

<form name="cancel" method="get" id="cancel-form" class="go-standard">
	<?php wp_nonce_field( 'go_recurly_cancel' ); ?>
	<input type="hidden" name="confirm" value=""/>
	<?php
	if ( isset( $template_variables['subscription']->uuid ) )
	{
		?>
		<input type="hidden" name="uuid" value="<?php echo $template_variables['subscription']->uuid; ?>"/>
		<?php
	}// end if
	?>
	<p>
		<label for="term">
			<input name="term" id="term" value="1" type="checkbox" class="go-checkbox">
			<span>Yes, I wish to cancel.</span>
		</label>
	</p>
	<div id="cancel-errors" class="errors"></div>
	<button type="submit" id="submit" class="button">Submit</button>
</form>
</div>
