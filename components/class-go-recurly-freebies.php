<?php

class GO_Recurly_Freebies
{
	public $recurly_client;
	public $version = '1';
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
	 * Set up Recurly client, retrieve active (redeemable) coupons, and cache a subset of coupon object data here in the main class.
	 * Hooked to subclass's admin_init because we want to message back to the subclass-managed view.
	 *
	 * @return array $cached_coupons List of recurly coupon objects, or WP_Error
	 */
	public function coupon_codes()
	{
		// check cached coupons
		$cached_coupons = wp_cache_get( 'active-coupons' );

		if ( false === $cached_coupons )
		{
			// prepare to use recurly
			if ( ! $this->recurly_client = go_recurly()->recurly_client() )
			{
				return new WP_Error( 'recurly_client_error', 'Error initializing Recurly PHP client.' );
			}//end if

			try
			{
				$coupons = Recurly_CouponList::get( array( 'state' => 'redeemable' ) );
				foreach ( $coupons as $coupon )
				{
					$cached_coupons[] = array(
						'name' => $coupon->coupon_code,
						'coupon_code' => $coupon->coupon_code,
					);
				}//end foreach

				// set in cache
				wp_cache_add( 'active-coupons', $cached_coupons );
			}// end try
			catch ( Exception $e )
			{
				// we don't care which specific Recurly error is being trapped, we just need to report the condition
				// note: the view will not display the rest of the form if we are in this state
				return new WP_Error( $e->getMessage() );
			}// end catch
		}// end if

		return $cached_coupons;
	} // end coupon_codes

	/**
	 * invites a user to the free subscription
	 *
	 * @param string $email email to invite
	 * @param array $subscription_data data about the subscription, from the invitation form and/or config, e.g., coupon code.
	 * @return boolean TRUE if no errors sending the email (doesn't mean user received it) | FALSE otherwise
	 */
	protected function invite( $email, $subscription_data )
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

		$result = $this->subscribe( $user, $args['free_period'], $args['coupon_code'] );
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

	/**
	 * process user's subscription freebie, per rules specified in https://github.com/GigaOM/legacy-pro/issues/3830
	 *
	 * @param $user array must contain user object
	 * @param $free_period configured in the admin dashboard; user cc is dunned at the end of this period
	 * @param $coupon_code configured in the admin dashboard, from the list of redeemable coupons supplied by recurly
	 * @return TRUE for a good subscription transaction, WP_Error otherwise
	 */
	public function subscribe( $user, $free_period, $coupon_code )
	{
		// 1. check for account code:
		// If we get an account code back, we next check what type of subscription they have.
		// If they do have an existing subscription we want to leave it intact.
		// This is a double-check in case a user has subscribed using another mechanism during the time period since invited by this plugin.
		$account_code = go_recurly()->recurly_account_code( $user );

		if ( ! $account_code )
		{
			return new WP_Error( 'go_recurly_freebies_subscribe_error', 'no account code found for this user ' . $user->user_email );
		}//end if

		// prepare to use recurly
		if ( ! $this->recurly_client = go_recurly()->recurly_client() )
		{
			return new WP_Error( 'recurly_client_error', 'Error initializing Recurly PHP client.' );
		}//end if

		try
		{
			if ( $subscriptions = Recurly_SubscriptionList::getForAccount( $account_code ) )
			{
				return new WP_Error( 'go_recurly_freebies_subscribe_error', 'this user already has a subscription ' . $user->user_email );
			}//end if
		}
		catch ( Recurly_NotFoundError $e )
		{
			// note that this is not a return condition - we want to create a subscription for a user in this case
			//do_action( 'go_slog', 'go-recurly-freebies', 'okay to subscribe user ' . $user->user_email );
		}
		catch ( Exception $e )
		{
			return new WP_Error( 'go_recurly_freebies_subscribe_error', get_class( $e ) . ': ' . $e->getMessage() );
		}

		// 2. we have an account code and no subscription exists, safe to create subscription
		$subscription = new Recurly_Subscription();
		$subscription->plan_code = 'annual';
		$subscription->currency = 'USD';
		$subscription->collection_method = 'manual';
		$subscription->net_terms = 0;

		// from admin form
		$subscription->trial_ends_at = date( 'Y-m-d H:i:s', strtotime( 'today + ' . $free_period ) );
		$subscription->coupon_code = $coupon_code;

		// create account
		$account = new Recurly_Account();
		$account->account_code = $account_code;
		$account->email = $user->user_email;

		// associate subscription with account
		$subscription->account = $account;

		try
		{
			// create the new account
			$subscription->create();

			// change the subscription collection method
			$subscription->collection_method = 'automatic';
			$subscription->updateAtRenewal(); // Update when the subscription renews, i.e., apply this change to the subscription at renewal time
		}
		catch ( Recurly_NotFoundError $e )
		{
			return new WP_Error( 'go_recurly_freebies_subscribe_error', 'record could not be found ' . $user->user_email );
		}
		catch ( Recurly_ValidationError $e )
		{
			// If there are multiple errors, they are comma delimited:
			$messages = explode( ',', $e->getMessage() );
			return new WP_Error( 'go_recurly_freebies_subscribe_error', 'recurly validation problems when attempting to subscribe user ' . $user->user_email . ' ' . implode( '\n', $messages ) );
		}
		catch ( Recurly_ServerError $e )
		{
			return new WP_Error( 'go_recurly_freebies_subscribe_error', 'problem communicating with recurly ' . $user->user_email );
		}
		catch ( Exception $e )
		{
			return new WP_Error( 'go_recurly_freebies_subscribe_error', get_class( $e ) . ': ' . $e->getMessage() . ' when attempting to subscribe user ' . $user->user_email );
		}

		// synchronize recurly data into usermeta
		$ret = go_recurly()->recurly_sync( $user );

		if ( ! $ret || is_wp_error( $ret ) )
		{
			return new WP_Error( 'go_recurly_freebies_subscribe_recurly_sync_error', 'failed to sync new user recurly account code to recurly when attempting to subscribe user ' . $user->user_email );
		}

		return TRUE;
	} // END subscribe
}// end class