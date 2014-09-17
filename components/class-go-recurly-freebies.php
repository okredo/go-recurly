<?php

class GO_Recurly_Freebies
{
	public $id_base = 'go-recurly-freebies';
	public $signin_url = '/subscription/thanks/';

	private $signup_action = 'go_recurly_freebies_signup';
	private $config;
	private $admin = NULL;

	public function __construct()
	{
		//instantiate subclass to handle admin functionality
		if ( is_admin() )
		{
			$this->admin();
		} // end if

		// the hook that wp-tix will use when the email link is clicked
		add_action( $this->signup_action, array( $this, 'signup' ), 10, 2 );
	} // end __construct

	/**
	 * get the config values or value
	 *
	 * @param string $key if set then return the config value for this key
	 * @return mixed the named config value or all the config values
	 */
	public function config( $key = NULL )
	{
		if ( ! $this->config )
		{
			$this->config = apply_filters( 'go_config', $this->config, 'go-recurly-freebies' );
		}

		if ( ! empty( $key ) )
		{
			return isset( $this->config[ $key ] ) ? $this->config[ $key ] : NULL;
		}

		return $this->config;
	}//end config

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
	 * invites a user to the free subscription
	 *
	 * @param string $email email to invite
	 * @param array $subscription_data data about the subscription, from the invitation form and/or config, e.g., coupon code.
	 * @return boolean TRUE if no errors sending the email (doesn't mean user received it) | FALSE otherwise
	 */
	public function invite( $email, $subscription_data )
	{
		$subscription_data['email'] = $email;// add email field to the free period and coupon code info, to be persisted in WPTix
		$ticket_name = wptix()->generate_md5();
		wptix()->register_ticket( $this->signup_action, $ticket_name, $subscription_data );

		$url = home_url( "/do/$ticket_name/" );
		$data = array(
			'URL' => $url,
			'STYLESHEET_URL' => preg_replace( '/^https:/', 'http:', get_stylesheet_directory_uri() ),
			'DATE_YEAR' => date( 'Y' ),
		);
		$email_template = 'alerts-beta';

		$headers = array();
		$headers[] = 'Content-Type: text/html';
		$headers[] = 'From: research@gigaom.com';
		$headers[] = 'X-MC-Template: ' . $email_template;
		$headers[] = 'X-MC-MergeVars: ' . json_encode( $data );

		$message = '<placeholder>';// this will be replaced by mandrill template
		$subject = 'Gigaom Research Invitation';
		return wp_mail( $email, $subject, $message, $headers );
	}//end invite

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
			$user = go_user_profile()->create_guest_user( $args['email'], 'guest' );
			if ( is_wp_error( $user ) )
			{
				// slog the error and report back to the user
				do_action( 'go_slog', 'go-recurly-freebies', $user->get_error_message() );
				wp_die( 'We are very sorry. There has been an error completing this transaction: ' . $user->get_error_message() . ' Please contact Gigaom Client Services.' );
			}
			$key = go_softlogin()->get_key( $user->ID );
			$redirect = site_url( "/connect/$key/?redirect_to=/subscription/thanks/" );
		}// end if
		else
		{
			$redirect = site_url( '/subscription/thanks/' );
		}// end else

		$result = go_recurly()->subscribe_free_period( $user, $args['free_period'], $args['coupon_code'] );
		if ( is_wp_error( $result ) )
		{
			// slog the error and report back to the user (usually this will be when there is something awry with the coupon_code, which can be remedied on Recurly)
			do_action( 'go_slog', 'go-recurly-freebies', $result->get_error_message() );
			wp_die( 'We are very sorry. There has been an error completing this transaction: ' . $result->get_error_message() . "\n" . ' Please contact Gigaom Client Services.' );
		}

		wptix()->delete_ticket( $ticket->ticket );
		wp_redirect( $redirect );
		exit;
	}//end signup
}// end class