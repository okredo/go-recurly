<?php

class GO_Recurly_Freebies
{
	public $id_base = 'go-recurly-freebies';
	public $signin_url = '/subscription/thanks/';

	private $core = NULL;
	private $admin = NULL;

	/**
	 * @param GO_Recurly $core the containing GO_Recurly singleton
	 */
	public function __construct( $core )
	{
		$this->core = $core;

		//instantiate subclass to handle admin functionality
		if ( is_admin() )
		{
			$this->admin();
		} // end if
	} // end __construct

	/**
	 * retrieves an admin singleton
	 */
	public function admin()
	{
		if ( ! $this->admin )
		{
			require_once __DIR__ . '/class-go-recurly-freebies-admin.php';
			$this->admin = new GO_Recurly_Freebies_Admin( $this );
		}//end if

		return $this->admin;
	}//end admin

	/**
	 * signup action; handles user click-through; allows a user to sign up for a subscription
	 *
	 * @param $args array must contain email address of the invited user
	 * @param $ticket array containing the subscription data we need to persist until the user clicks through the invitation email
	 */
	public function signup( $args, $ticket )
	{
		if ( ! isset( $args['email'] ) || ! is_email( $args['email'] ) )
		{
			return;
		}// end if
		$user = get_user_by( 'email', $args['email'] );
		if ( ! $user )
		{
			$user = go_user_profile()->create_guest_user( $args['email'], 'guest', FALSE );

			if ( is_wp_error( $user ) )
			{
				// slog the error and report back to the user
				do_action( 'go_slog', 'go-recurly-freebies', $user->get_error_message() );
				wp_die( 'We are very sorry. There has been an error completing this transaction: ' . $user->get_error_message() . '. Please contact ' . $this->core->config( 'support_department' ) . '.' );
			}
			$key = go_softlogin()->get_key( $user->ID );
			$redirect = site_url( "/connect/$key/?redirect_to=/subscription/thanks/" );
		}// end if
		else
		{
			$redirect = site_url( '/subscription/thanks/' );
		}// end else

		$result = go_recurly()->subscribe_free_period( $user, $args['free_period'], $args['coupon_code'] );

		// subscribe_free_period() now calls go_subscriptions()->send_welcome_email(), which calls wp_set_password().
		// That clears the user's activation key we just generated
		// - so we reset the key back into the user's record:
		go_softlogin()->set_key( $user->ID, $user->user_activation_key );

		if ( is_wp_error( $result ) )
		{
			// slog the error and report back to the user (usually this will be when there is something awry with the coupon_code, which can be remedied on Recurly)
			do_action( 'go_slog', 'go-recurly-freebies', $result->get_error_message() );
			wp_die( 'We are very sorry. There has been an error completing this transaction: ' . $result->get_error_message() . "\n" . ' Please contact ' . $this->core->config( 'support_department' ) . '.' );
		}

		wptix()->delete_ticket( $ticket->ticket );
		wp_redirect( $redirect );
		exit;
	}//end signup
}// end class
