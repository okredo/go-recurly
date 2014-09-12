<div class="button-wrap">
	<?php
	if ( $template_variables['pre_text'] )
	{
		?>
		<span class="button-pre-text"><?php echo esc_html( $template_variables['pre_text'] ); ?></span>
		<?php
	}//end if
	?>

	<a href="<?php echo esc_attr( $template_variables['url'] ); ?>" class="button go-subscriptions-signup-button sign-up-form-button <?php echo esc_attr( $template_variables['class'] ); ?> <?php echo ( $template_variables['nojs'] ) ? 'nojs' : '';?>" title="<?php echo esc_attr( $template_variables['text'] ); ?>" data-referring-url="<?php echo esc_attr( $template_variables['referring_url'] ); ?>" data-redirect="<?php echo esc_attr( $template_variables['redirect'] ); ?>" >
		<?php echo esc_html( $template_variables['text'] ); ?>
	</a>

	<?php
	if ( $template_variables['sub_text'] )
	{
		?>
		<span class="button-sub-text"><?php echo esc_html( $template_variables['sub_text'] ); ?></span>
		<?php
	}//end if
	?>
</div>
