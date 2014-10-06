<div class="go-recurly go-recurly-list">
	<?php
	if ( ! $template_variables['has_recurly_account'] )
	{
		?>
		<p>You do not have any subscriptions.</p>
		<?php
	}
	else
	{
		foreach ( $template_variables['subscriptions'] as $item )
		{
			$sub_starts   = isset( $item->activated_at )     ? $item->activated_at->getTimestamp()     : null;
			$sub_ends     = isset( $item->expires_at )       ? $item->expires_at->getTimestamp()       : null;
			$trial_starts = isset( $item->trial_started_at ) ? $item->trial_started_at->getTimestamp() : null;
			$trial_ends   = isset( $item->trial_ends_at )    ? $item->trial_ends_at->getTimestamp()    : null;
			?>
			<div
				id="subscription_<?php echo esc_attr( $item->uuid ); ?>"
				class="boxed subscription plan-<?php echo esc_attr( $item->plan->plan_code ); ?> status-<?php echo esc_attr( $item->state ); ?> <?php echo ( $trial_ends && time() > $trial_ends ) ? 'trial-expired' : ''; ?>"
			>
				<header>
					<span class="status"><?php echo ucwords( esc_html( $item->state ) ); ?></span>
					<h1><?php echo esc_html( $item->plan->name ); ?></h1>
				</header>
				<section class="body">
					<dl>
						<dt>Subscription <?php echo $sub_ends ? 'period' : 'start'; ?></dt>
						<dd class="subscription-period">
							<?php
							echo $sub_starts ? date( 'M j, Y', $sub_starts ) : '';
							echo $sub_ends ? ' - ' . date( 'M j, Y', $sub_ends ) : '</dd><dt>Subscription end</dt><dd>Auto Renewal ';

							if ( ! $sub_ends )
							{
								?>
								- <a href="<?php echo esc_url( $template_variables['url'] . '/cancel?uuid=' . esc_attr( $item->uuid ) ); ?>" class="go-recurly-cancel">Cancel</a>
								<?php
							}//end if
							?>
						</dd>
						<?php
						if ( $trial_starts )
						{
							?>
							<dt>Trial period</dt>
							<dd class="subscription-trial-period"><?php echo date( 'M j, Y', $trial_starts ); ?> - <?php echo date( 'M j, Y', $trial_ends ); ?></dd>
							<?php
						}//end if
						?>
						<dt>Amount</dt>
						<dd class="subscription-amount">$<?php echo number_format( $item->unit_amount_in_cents / 100, 2 ); ?> <?php echo esc_html( $item->currency ); ?></dd>
					</dl>
					<?php
					?>
				</section>
			</div>
			<?php
		}//end foreach
	}//end else
	?>
</div>
