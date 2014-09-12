<?php
$coupons = go_recurly_freebies()->coupon_codes();
if ( ! is_wp_error( $coupons ) )
{
	$coupon_names = wp_list_pluck( $coupons, 'name' );
}
else
{
	$coupon_names = NULL;
}
?>
<div id="invitations" class="go-recurly-freebies">
	<section>
		<h2>Invite subscribers</h2>
		<div id="recurly-status">
			<?php
			if ( NULL === $coupon_names )
			{
				?>
				<h4>
					<?php
					echo esc_html( $coupons->get_error_message() ) . ' Please contact engineering.';
					?>
				</h4>
				<?php
				return;
			}//end if
			elseif ( array() === $coupon_names )
			{
				?>
				<h3>No active coupons configured. Please configure a coupon code on Recurly prior to creating subscription invitations.</h3>
				<?php
				return;
			}//end elseif
			?>
		</div>
		<div id="manage-freebies-area" class="show-on-reset">
			<section>
				<label class="instruction-plan-codes">Associate invited users with coupon:</label>
				<select name="go_recurly_freebies_coupon_code" class="select" id="go_recurly_freebies_coupon_code">
					<?php
					echo go_recurly_freebies()->admin()->build_options(
						$coupon_names,
						'select one'
					);
					?>
				</select>
			</section>
			<section>
				<label class="instruction-plan-codes">Free trial period:</label>
				<select name="go_recurly_freebies_free_period" class="select" id="go_recurly_freebies_free_period">
					<?php
					echo go_recurly_freebies()->admin()->build_options(
						go_recurly_freebies()->config( 'free_period' ),
						go_recurly_freebies()->admin()->free_period
					);
					?>
				</select>
			</section>
			<section>
				<label class="instruction-plan-codes">Subscription Plan: <?php echo esc_html( go_recurly_freebies()->config( 'subscription_plan' ) ) ?></label>
			</section>
			<section class="invitations-area">
				<label class="instruction-initial show-on-reset">Enter email addresses:</label>
				<label class="message-invitations hide-on-reset"></label>
				<div class="invitations-grid">
					<textarea id="batch-email-list" class="show-on-reset"></textarea>
					<div id="report-back" class="hide-on-reset">
						<p></p>
					</div>
				</div>
				<p class="message-invalid-invitations hide-on-reset"></p>
				<p class="message-skipped-invitations hide-on-reset"></p>
				<div class="message-skipped-invitations-grid">
					<div id="report-back-skipped-invitations" class="hide-on-reset">
						<p></p>
					</div>
				</div>
				<button class="invite-users primary button show-on-reset">Invite users</button>
				<button class="invite-users-more primary button hide-on-reset">Invite more users</button>
				<button class="invite-users-try-again primary button hide-on-reset">Try again</button>
				<?php go_recurly_freebies()->admin()->nonce_field(); ?>
			</section>
		</div>
	</section>
</div>