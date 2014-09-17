<h3>An Error occurred</h3>
<p>
	<?php
	switch ( $template_variables['error'] )
	{
		case 'mismatched-subscription':
			?>
			You do not have access to cancel this subscription.
			<?php
			break;
		case 'no-account':
			?>
			Your account could not be located.  Please <a href="/contact/">contact support</a>.
			<?php
			break;
		case 'invalid-invoice':
			?>
			That invoice could not be found.
			<?php
			break;
		default:
			echo esc_html( $template_variables['error'] );
	}//end switch
	?>
</p>
