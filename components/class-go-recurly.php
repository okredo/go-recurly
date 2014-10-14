<?php
/**
 * GO_Recurly
 *
 * This is where we make Recurly API calls as well as the singleton that
 * contains handles to various other class instances we use.
 *
 * @author The Mythical GigaOM <dev@gigaom.com>
 * @link   https://github.com/GigaOM/go-recurly
 */
class GO_Recurly
{
	public $admin = NULL;
	public $meta_key_prefix = 'go-recurly_';
	public $version = '1';
	public $freebies = NULL;
	public $signup_action = 'go_recurly_freebies_signup';

	private $config = NULL;
	private $user_profile = NULL;
	private $recurly_client = NULL;
	private $registered_pages = array();

	/**
	 * constructor
	 *
	 * @param $config array of configuration settings (optional)
	 */
	public function __construct( $config = NULL )
	{
		// filter this to set recurly subscription-related user caps
		add_filter( 'user_has_cap', array( $this, 'user_has_cap' ), 10, 3 );

		add_filter( 'go_subscriptions_signup', array( $this, 'go_subscriptions_signup' ), 10, 3 );
		add_filter( 'go_subscriptions_signup_form', array( $this, 'go_subscriptions_signup_form' ), 10, 2 );

		if ( ! is_admin() )
		{
			add_action( 'init', array( $this, 'init' ) );

			// the hook that wp-tix will use when the freebies invite email
			// link is clicked
			add_action( $this->signup_action, array( $this, 'signup_action' ), 10, 2 );

			// @TODO: handle coupon detection in JS
			$this->detect_coupon();
		}//end else

		// we don't need the rest of the constructor if we're not on Accounts
		if ( $this->config( 'accounts_blog_id' ) != get_current_blog_id() )
		{
			return;
		}

		if ( is_admin() )
		{
			$this->admin();

			// instantiate freebies to get the freebies admin menu
			$this->freebies();
		}
		else
		{
			add_action( 'go_user_profile_email_updated', array( $this, 'go_user_profile_email_updated' ), 10, 2 );
		}

		// for bstat tracking of new subscribers
		add_action( 'go_subscriptions_new_subscriber', array( $this, 'go_subscriptions_new_subscriber' ) );

		add_filter( 'go_remote_identity_nav', array( $this, 'go_remote_identity_nav' ), 12, 2 );
		add_filter( 'go_user_profile_screens', array( $this, 'go_user_profile_screens' ) );
	}//end __construct

	/**
	 * hooked to WordPress init
	 */
	public function init()
	{
		if ( 'POST' == $_SERVER['REQUEST_METHOD'] )
		{
			$this->handle_post();
		}

		// doing this here rather than on the wp_enqueue_scripts hook so
		// that it is done before pre_get_posts
		$this->wp_enqueue_scripts();
	}//end init

	/**
	 * returns our current configuration, or a value in the configuration.
	 *
	 * @param string $key (optional) key to a configuration value
	 * @return mixed Returns the config array, or a config value if
	 *  $key is not NULL, or NULL if $key is specified but isn't set in
	 *  our config file.
	 */
	public function config( $key = NULL )
	{
		if ( empty( $this->config ) )
		{
			$this->config = apply_filters(
				'go_config',
				array(),
				'go-recurly'
			);

			if ( empty( $this->config ) )
			{
				do_action( 'go_slog', 'go-recurly', 'Unable to load go-subscriptions\' config file' );
			}
		}//END if

		if ( ! empty( $key ) )
		{
			return isset( $this->config[ $key ] ) ? $this->config[ $key ] : NULL ;
		}

		return $this->config;
	}//END config

	/**
	 * signup action; handles user click-through; allows a user to sign up
	 * for a subscription
	 *
	 * @param $args array must contain email address of the invited user
	 * @param $ticket array containing the subscription data we need to
	 *  persist until the user clicks through the invitation email
	 */
	public function signup_action( $args, $ticket )
	{
		$this->freebies()->signup( $args, $ticket );
	}//end signup_action

	/**
	 * hooked to WordPress init
	 */
	public function freebies()
	{
		if ( ! $this->freebies  )
		{
			require_once __DIR__ . '/class-go-recurly-freebies.php';
			$this->freebies = new GO_Recurly_Freebies( $this );
		} // end if

		return $this->freebies;
	}//end freebies

	/**
	 * Keeping form post handling segregated (or should we just merge this
	 * into init?)
	 */
	public function handle_post()
	{
		if ( isset( $_POST['recurly_token'] ) )
		{
			// handle the post response from recurly after step 2 signup form
			$this->thankyou();
		}
	}//end handle_post

	/**
	 * register and enqueue scripts and styles
	 */
	public function wp_enqueue_scripts()
	{
		$script_config = apply_filters( 'go_config', array( 'version' => $this->version ), 'go-script-version' );

		wp_register_script(
			'recurly-js',
			plugins_url( 'js/external/recurly-js/recurly.min.js', __FILE__ ),
			array( 'jquery' ),
			$script_config['version'],
			TRUE
		);

		wp_register_script(
			'go-recurly-config',
			plugins_url( 'js/go-recurly-config.js', __FILE__ ),
			array(
				'jquery',
				'recurly-js',
			),
			$script_config['version'],
			TRUE
		);

		//@TODO break up go-recurly.js so the billing and subscrition cc form
		// code are in their own files, so they can each only be enqueued
		// when necessary.
		wp_register_script(
			'go-recurly',
			plugins_url( 'js/go-recurly.js', __FILE__ ),
			array( 'go-recurly-config' ),
			$script_config['version'],
			TRUE
		);

		wp_register_style( 'go-recurly', plugins_url( 'css/go-recurly.css', __FILE__ ), array(), $script_config['version'] );
		wp_register_style( 'recurly-css', plugins_url( 'js/external/recurly-js/themes/default/recurly.css', __FILE__ ), array(), $script_config['version'] );

		// (it would be great to only enqueue these when necessary, but
		// because we implemented the step 2 form as a return value
		// from a filter, we cannot count on being able to still enqueue
		// scripts and styles by the time we decide to inject the step 2
		// form, so unfortunately we must enqueue these hese.)

		// this will pull in recurly-js and go-recurly-config
		// because of go-recurly's dependencies list
		wp_enqueue_script( 'go-recurly' );

		wp_enqueue_style( 'recurly-css' );
		wp_enqueue_style( 'go-recurly' );

		wp_localize_script(
			'go-recurly-config',
			'go_recurly_settings',
			array(
				'subdomain' => $this->config( 'recurly_subdomain' ),
			)
		);
	}//end wp_enqueue_scripts

	/**
	 * retrieves an admin singleton
	 */
	public function admin()
	{
		if ( ! $this->admin )
		{
			require_once __DIR__ . '/class-go-recurly-admin.php';

			$this->admin = new GO_Recurly_Admin( $this );
		}

		return $this->admin;
	}//end admin

	/**
	 * hooked to user_has_cap filter
	 *
	 * @param $all_caps array of capabilities they have (to be filtered)
	 * @param $unused_meta_caps array of the required capabilities they need to have for a successful current_user_can
	 * @param $args array [0] Requested capability
	 *                    [1] User ID
 	 *                    [2] Associated object ID
	 */
	public function user_has_cap( $all_caps, $unused_meta_caps, $args )
	{
		list( $cap, $user_id ) = $args;

		$subscription = $this->get_subscription_meta( $user_id );

		// did_trial indicates that the user had a trial account at one time (not necessarily still in their trial)
		if ( isset( $subscription['sub_trial_started_at'] ) )
		{
			$all_caps['did_trial'] = TRUE;
		}

		// did_subscription indicates that the user at one time successfully paid for a subscription.
		if ( isset( $subscription['sub_did_subscription'] ) )
		{
			$all_caps['did_subscription'] = TRUE;
		}

		// sub_state_active indicates that they have an active subscription
		// sub_state_expired indicates that they have an expired subscription
		if ( isset( $subscription['sub_state'] ) )
		{
			// theoretically could be: "active", "canceled", "expired", "future", "in_trial", "live", or "past_due".
			// since this is set via push, "active" and "expired" seem to be the states that come through consistently
			$all_caps[ 'sub_state_' . $subscription['sub_state'] ] = TRUE;

			// in case this is not being run from the main site, add the subscriber role if their account is active
			if ( 'active' == $subscription['sub_state'] )
			{
				$all_caps['subscriber'] = TRUE;
			}
		}//end if

		// has_subscription_data should be set for any user who went through the recurly sign up
		$account_code = $this->get_account_code( $user_id );

		if ( $account_code )
		{
			$all_caps['has_subscription_data'] = TRUE;
		}

		// login_with_key is for go-softlogins
		if ( empty( $all_caps['has_subscription_data'] ) )
		{
			if ( ! empty( $all_caps['guest-prospect'] ) || ! empty( $all_caps['guest'] ) )
			{
				$all_caps['login_with_key'] = TRUE;
			}
		}//end if

		// nothing else has set this as a subscriber, let's dig deeper
		// @TODO: this doesn't really belong here, but it's the best place we have for now (10/24/2013)
		if ( ! isset( $all_caps['subscriber'] ) )
		{
			// by getting from wp_{config['accounts_blog_id']}_capabilities, we get them for the primary blog instead of whichever is running this
			// @TODO: this makes some assumptions about the table prefix that aren't really square, i.e., we can't do that for primary blog id = 1; that would be "wp_capabilities"
			$capabilities = get_user_meta( $user_id, 'wp_' . $this->config( 'accounts_blog_id' ) . '_capabilities' );

			if ( is_array( $capabilities ) )
			{
				foreach ( $capabilities as $capability )
				{
					if ( isset( $capability['subscriber-lifetime'] ) )
					{
						$all_caps['subscriber'] = TRUE;
					}
				}//end foreach
			}//end if
		}//end if

		return $all_caps;
	}//END user_has_cap

	/**
	 * callback for the "go_subscriptions_signup" filter. We return the
	 * signup path if $user is not a subscriber already.
	 */
	public function go_subscriptions_signup( $redirect_url, $user, $post_vars )
	{
		if ( ! isset( $user->ID ) || 0 >= $user->ID  )
		{
			return add_query_arg( $post_vars, $redirect_url );
		}

		if ( ! $user = get_user_by( 'id', $user->ID ) )
		{
			return add_query_arg( $post_vars, $redirect_url );
		}

		if ( user_can( $user, 'subscribe' ) )
		{
			return add_query_arg( $post_vars, $redirect_url );
		}

		return add_query_arg( $post_vars, $this->config( 'signup_path' ) );
	}//END go_subscriptions_signup

	/**
	 * callback for the "go_subscriptions_signup_form" filter. We return the
	 * step-2 subscription form if $user_id is valid and is not a subscriber.
	 */
	public function go_subscriptions_signup_form( $form, $user_id )
	{
		if ( ! $user = get_user_by( 'id', $user_id ) )
		{
			return $form;
		}

		if ( user_can( $user, 'subscribe' ) )
		{
			return $form;
		}

		return $this->subscription_form( $user, array() );
	}//END go_subscriptions_signup_form

	/**
	 * detects if a coupon is set in the URL and sets a coupon cookie
	 */
	public function detect_coupon()
	{
		if ( ! isset( $_GET['coupon'] ) || ! $_GET['coupon'] )
		{
			return;
		}

		setcookie( 'go_recurly_coupon', $_GET['coupon'] );
	}//end detect_coupon

	/**
	 * track new subscriptions
	 *
	 * @param WP_User $current_user
	*/
	public function go_subscriptions_new_subscriber( $current_user )
	{
		if ( ! ( $current_user instanceof WP_User ) )
		{
			return;
		}

		if ( $subscription_meta = $this->get_subscription_meta( $current_user->ID ) )
		{
			$subscription_meta_check_keys = array_filter( $subscription_meta );
		}

		// guest signups won't contain subscription meta, but the
		// new subscriber hook is still invoked
		if ( empty( $subscription_meta_check_keys ) )
		{
			return; // nothing to track here
		}

		$data = array(
			'action'      => 'start',
			'user_id'     => $current_user->ID,
			'info'        => array(
				'account_code'           => $this->get_account_code( $current_user->ID ),
				'subscription_plan_code' => $subscription_meta['subscription']['sub_plan_code'],
				'coupon_code'            => $subscription_meta['subscription']['sub_coupon_code'],
			),
		);

		do_action( 'bstat_insert', $this->footstep( $data ) );
	}//end go_subscriptions_new_subscriber

	/**
	 * track subscription cancellations
	 *
	 * @param int $user_id WP User ID - note: not User object
	*/
	public function track_subscriptions_cancel( $user_id )
	{
		$subscription_meta = $this->get_user_meta( $user_id );

		// the user's Recurly account code & the subscription start date
		$data = array(
			'action'  => 'cancel',
			'user_id' => $user_id,
			'info'    => array(
				'account_code' => $subscription_meta['account_code'],
				'start_date'   => $subscription_meta['subscription']['sub_activated_at'],
			),
		);

		do_action( 'bstat_insert', $this->footstep( $data ) );
	}//end track_subscriptions_cancel

	/**
	 * prepare all required data for writing to bStat
	 *
	 * @param $data data to be inserted
	 * @return object $footstep
	*/
	public function footstep( $data )
	{
		// there're more elements in a footstep, but the rest will
		// be filled out by bstat
		$footstep = (object) array(
			'post'      => $this->config( 'go_recurly_tracking_id' ),
			'user'      => $data['user_id'],
			'component' => 'go-recurly',
			'action'    => $data['action'],
			'info'      => implode( '|', $data['info'] ),
		);

		return $footstep;
	}//end footstep

	/**
	 * builds a subscription nav section to be inserted into a user's
	 * remote identity payload
	 *
	 * @param array $nav the menu structure to be filtered
	 * @param WP_User $user a WP_User object
	 */
	public function go_remote_identity_nav( $nav, $user )
	{
		$page_data = $this->page_data();

		unset( $page_data['subscription']['children']['list'] );
		unset( $page_data['subscription']['children']['cancel'] );
		unset( $page_data['subscription']['children']['invoice'] );

		foreach ( $page_data as $page )
		{
			$url = home_url( "/members/{$user->ID}/{$page['slug']}/", 'https' );

			$nav[ $page['slug'] ] = array(
				'title' => $page['name'],
				'url' => $url,
			);

			if ( isset( $page['children'] ) )
			{
				foreach ( $page['children'] as $child )
				{
					$url = isset( $child['url'] ) ? $child['url'] : home_url( "/members/{$user->ID}/{$page['slug']}/{$child['slug']}/", 'https' );

					$nav[ $page['slug'] ]['nav'][ $child['slug'] ] = array(
						'title' => $child['name'],
						'url' => $url,
					);
				}//end foreach
			}//end if
		}//end foreach

		return $nav;
	}//end go_remote_identity_nav

	/**
	 * add subscriptions pages to the menus
	 */
	public function go_user_profile_screens( $screens )
	{
		$pages = $this->page_data();
		return array_merge( $screens, $pages );
	}//end go_user_profile_screens

	/**
	 * Subscription page data
	 */
	public function page_data()
	{
		// we don't show the subscriptions menu if they have no subscription data
		// @TODO: it might be good to give a sign up for free trial CTA in place of where this menu would have been
		if ( ! $this->registered_pages && $this->user_profile() )
		{
			$this->registered_pages = array(
				'subscription' => array(
					'name' => 'Subscriptions',
					'slug' => 'subscription',
					'position' => 100,
					'show_for_displayed_user' => false,
					'screen_function' => array( $this->user_profile(), 'subscriptions' ),
					'default_subnav_slug' => 'list',
					'children' => array(
						'list' => array(
							'name' => 'Details',
							'position' => 10,
							'slug' => 'list',
							'screen_function' => array( $this->user_profile(), 'subscriptions' ),
						),
						'billing' => array(
							'name' => 'Billing',
							'position' => 15,
							'slug' => 'billing',
							'screen_function' => array( $this->user_profile(), 'billing' ),
						),
						'history' => array(
							'name' => 'Payment history',
							'position' => 20,
							'slug' => 'history',
							'screen_function' => array( $this->user_profile(), 'history' ),
						),
						'cancel' => array(
							'name' => 'Cancel subscription',
							'position' => 30,
							'slug' => 'cancel',
							'screen_function' => array( $this->user_profile(), 'cancel' ),
							'hidden' => TRUE,
						),
						'contact_support' => array(
							'name' => 'Contact support',
							'position' => 40,
							'slug' => 'contact',
							'url' => get_site_url( 4, '/contact/', 'http' ),
						),
						'invoice' => array(
							'name' => 'Invoice',
							'position' => 90,
							'slug' => 'invoice',
							'screen_function' => array( $this->user_profile(), 'invoice' ),
							'hidden' => TRUE,
						),
					),
				),
			);

			// set up some of the values that are built from base values
			foreach ( $this->registered_pages as &$parent )
			{
				$parent['item_css_id'] = $parent['slug'];

				foreach ( $parent['children'] as &$child )
				{
					$child['parent_slug'] = $parent['slug'];
					$child['item_css_id'] = "{$parent['slug']}-{$child['slug']}";
				}//end foreach
			}//end foreach
		}//end if

		return $this->registered_pages;
	}//end page_data

	/**
	 * after go-user-profile email settings are changed, sync new info over
	 * to Recurly
	 */
	public function go_user_profile_email_updated( $user, $new_email )
	{
		// make sure the email is set appropriately in the object
		// (this skirts around caching issues by cheating)
		$user->user_email = $new_email;

		return $this->update_email( $user );
	}//end go_user_profile_email_updated

	/**
	 * retrieves a user's account code from user meta
	 *
	 * @param $user_id int WordPress user ID
	 */
	public function get_account_code( $user_id )
	{
		return get_user_meta( $user_id, $this->meta_key_prefix . 'account_code', TRUE );
	}//end get_account_code

	/**
	 * Singleton for the GO_Recurly_User_Profile class
	 */
	public function user_profile()
	{
		if ( ! $this->user_profile )
		{
			// trigger the initialization of the GO_Recurly_User_Profile object
			// and its relevant actions ONLY if the user has ever had subscription info
			$user = wp_get_current_user();

			if ( $user->has_cap( 'has_subscription_data' ) )
			{
				require_once __DIR__ . '/class-go-recurly-user-profile.php';
				$this->user_profile = new GO_Recurly_User_Profile( $this );
			}
		}//end if

		return $this->user_profile;
	}//end user_profile

	/**
	 * @param int $user_id id of user to cancel the subscription for
	 * @param mixed $subscription can be 'all', a subscription UUID, or a subscription object
	 * @param $terminate_refund boolean can be FALSE, "none", "all", or "partial"
	 * @return boolean returns FALSE if all is well, or an error message if there was problem
	 */
	public function cancel_subscription( $user_id, $subscription = 'all', $terminate_refund = FALSE )
	{
		// @todo: check status of subscription before cancelling, perhaps also catch the recurly errors...
		$this->recurly_client();

		$account_code = $this->get_account_code( $user_id );

		if ( empty( $account_code ) )
		{
			return FALSE; // nothing to cancel
		}

		$subscriptions = array();

		if ( 'all' == $subscription )
		{
			$subscriptions = Recurly_SubscriptionList::getForAccount( $account_code );
		}
		elseif ( is_object( $subscription ) )
		{
			$subscriptions[] = $subscription;
		}
		else
		{
			$subscriptions[] = Recurly_Subscription::get( $subscription );
		}

		$return = FALSE;

		foreach ( $subscriptions as $sub )
		{
			try
			{
				if ( $terminate_refund )
				{
					switch ( $terminate_refund )
					{
						case 'full':
							$sub->terminateAndRefund();
							break;
						case 'partial':
							$sub->terminateAndPartialRefund();
							break;
						case 'none':
							$sub->terminateWithoutRefund();
							break;
					}//end switch
				}//end if
				else
				{
					$sub->cancel();
				}
			}//end try
			catch( Exception $e )
			{
				$return = $e->getMessage();
			}
		}//end foreach

		do_action( 'go_recurly_subscriptions_cancel', $user_id, $subscriptions, $terminate_refund, $return );

		$this->track_subscriptions_cancel( $user_id );

		return $return;
	}//end cancel_subscription

	/**
	 * update a user email address in Recurly
	 *
	 * @param WP_User $user a user object
	 */
	public function update_email( $user )
	{
		$recurly = $this->recurly_client();

		$account_code = $this->get_account_code( $user->ID );

		if ( empty( $account_code ) )
		{
			do_action( 'go_slog', 'go-recurly', 'update_email(): no recurly account code', $user_id );
			return; // nothing to update
		}

		try
		{
			$r_account = Recurly_Account::get( $account_code, $recurly );

			// bail early if we didn't get a proper Account object
			if ( ! ( $r_account instanceof Recurly_Account ) )
			{
				return;
			}

			if ( $r_account->email != $user->user_email )
			{
				$r_account->email = $user->user_email;

				$r_account->update();
			}
		}//end try
		catch( Exception $e )
		{
			// if a recurly account does not exist for the user, don't do anything
		}
	}//end update_email

	/**
	 * Get the second form in the 2-step process, unless they are already
	 * logged in, then fetches the 1st step
	 *
	 * @param WP_User $user a user object
	 * @param array $atts attributes needed by the form
	 * @return mixed FALSE if we're not ready for the 2nd step form yet
	 */
	public function subscription_form( $user, $atts )
	{
		// use get vars from go-subscriptions if applicable
		if ( isset( $_GET['go-subscriptions'] ) && is_array( $_GET['go-subscriptions'] ) )
		{
			$get_vars = $_GET['go-subscriptions'];
		}
		else
		{
			$get_vars = $_GET;
		}

		// test cc #'s:
		// 4111-1111-1111-1111  -  will succeed
		// 4000-0000-0000-0002  - will be declined
		$sc_atts = shortcode_atts(
			array(
				'plan_code' => $this->config( 'default_recurly_plan_code' ),
				'terms_url' => $this->config( 'tos_url' ),
				'thankyou_path' => $this->config( 'thankyou_path' ),
				'support_email' => $this->config( 'support_email' ),
			),
			$atts
		);

		if (
			isset( $get_vars['plan_code'] ) &&
			$get_vars['plan_code'] != $this->config( 'default_recurly_plan_code' )
		)
		{
			// if a plan code is passed in that doesn't match the default plan code, use that
			$sc_atts['plan_code'] = trim( $get_vars['plan_code'] );
		}
		else
		{
			// otherwise, use the default (and adjust based on previous trial)
			if ( $user && $user->has_cap( 'did_trial' ) )
			{
				// this strips off any trailing "-7daytrial" in plan code
				list( $sc_atts['plan_code'] ) = explode( '-', $sc_atts['plan_code'] );
			}
		}//end else

		if ( ! $user && isset( $get_vars['email'] ) && is_email( $get_vars['email'] ) )
		{
			// we will load the object so that we can see if they already have a recurly account code
			$user->user_email = $get_vars['email'];
		}//end if

		$this->recurly_client();

		$account_code = $this->get_or_create_account_code( $user );

		if ( empty( $account_code ) || empty( $user->user_email ) )
		{
			// the user is loading the 2nd step form prematurely. return the
			// form for step 1
			// @TODO: fix this dependency somehow
			return go_subscriptions()->signup_form( $atts );
		}

		$signature = $this->sign_subscription( $account_code, $sc_atts['plan_code'] );
		$coupon    = $_COOKIE['go_subscription_coupon'] ?: '';

		$usermeta = $this->get_user_meta( $user->ID );

		wp_localize_script( 'go-recurly', 'go_recurly', array(
			'account' => array(
				'firstName'   => $user->user_firstname,
				'lastName'    => $user->user_lastname,
				'email'       => $user->user_email,
				'companyName' => $usermeta['company'],
			),
			'billing' => array(
				'firstName' => $user->user_firstname,
				'lastName'  => $user->user_lastname,
			),
			'subscription' => array(
				'couponCode' => $coupon,
			),
			'tos_url' => $this->config( 'tos_url' ),
			'privacy_policy_url' => $this->config( 'privacy_policy_url' ),
		) );

		$args = array(
			'signature' => $signature,
			'url'       => wp_validate_redirect( $sc_atts['thankyou_path'], $this->config( 'thankyou_path' ) ),
			'plan_code' => $sc_atts['plan_code'],
			'terms_url' => $sc_atts['terms_url'],
			'support_email' => $sc_atts['support_email'],
		);

		return $this->get_template_part( 'subscription-form.php', $args );
	}//end subscription_form

	/**
	 * Get the template part in an output buffer and return it
	 *
	 * @param string $template_name
	 * @param array $template_variables used in included templates
	 *
	 * @todo Rudimentary part/child theme file_exists() checks
	 */
	public function get_template_part( $template_name, $template_variables = array() )
	{
		ob_start();
		include __DIR__ . '/templates/' . $template_name;
		return ob_get_clean();
	}//end get_template_part

	/**
	 * retrieves a user by account code
	 */
	public function get_user_by_account_code( $account_code )
	{
		if ( ! $account_code )
		{
			return FALSE;
		}

		$args = array(
			'fields' => 'all_with_meta',
			'meta_key' => $this->meta_key_prefix . 'account_code',
			'meta_value' => sanitize_key( $account_code ),
		);

		if ( ! ( $query = new WP_User_Query( $args ) ) )
		{
			return FALSE;
		}

		$user = array_shift( $query->results );

		return $user;
	}//end get_user_by_account_code

	/**
	 * helper function for getting prefixed subscription meta
	 */
	public function get_subscription_meta( $user_id )
	{
		return get_user_meta( $user_id, $this->meta_key_prefix . 'subscription', TRUE );
	}//end get_subscription_meta

	/**
	 * helper function for updating prefixed subscription meta
	 */
	public function update_subscription_meta( $user_id, $meta )
	{
		return update_user_meta( $user_id, $this->meta_key_prefix . 'subscription', $meta );
	}//end update_subscription_meta

	/**
	 * return all the meta that is set by this plugin in an array
	 * @param int $user_id WordPress user id
	 * @return array of meta values
	 */
	public function get_user_meta( $user_id )
	{
		// Note: we are not locally caching this as get_user_meta() should be doing it for us,
		//   and this plugin might accidentally invalidate the cache, not worth detecting that
		$meta_vals = array();

		$profile_data = apply_filters( 'go_user_profile_get_meta', array(), $user_id );

		$meta_vals['company'] = isset( $profile_data['company'] ) ? $profile_data['company'] : '';
		$meta_vals['title'] = isset( $profile_data['title'] ) ? $profile_data['title'] : '';
		$meta_vals['timezone'] = isset( $profile_data['timezone'] ) ? $profile_data['timezone'] : '';

		$meta_vals['account_code'] = $this->get_account_code( $user_id );
		$meta_vals['subscription'] = $this->get_subscription_meta( $user_id );
		$meta_vals['created_date'] = get_user_meta( $user_id, $this->meta_key_prefix . 'created_date', TRUE );
		$meta_vals['converted_meta'] = go_subscriptions()->get_converted_meta( $user_id );

		return $meta_vals;
	}//end get_user_meta

	/**
	 * get the account_code if it exists, otherwise, create it for the user
	 */
	public function get_or_create_account_code( $user )
	{
		if ( ! ( $user instanceof WP_User ) || 0 == $user->ID )
		{
			return FALSE;
		}

		$account_code = $this->get_account_code( $user->ID );

		if ( ! $account_code )
		{
			$account_code = md5( uniqid() );

			update_user_meta( $user->ID, $this->meta_key_prefix . 'account_code', $account_code );
		}

		return $account_code;
	}//end get_or_create_account_code

	/**
	 * initializes and instantiates Recurly_Client and Recurly_js
	 */
	public function recurly_client( $client_object = null )
	{
		// allow for mock client objects
		if ( $client_object )
		{
			$this->recurly_client = $client_object;
		}//end if

		if ( ! $this->recurly_client )
		{
			require_once __DIR__ . '/external/recurly-client-php/lib/recurly.php';

			// Required for the API
			Recurly_Client::$apiKey = $this->config( 'recurly_api_key' );

			// Optional for Recurly.js:
			Recurly_js::$privateKey = $this->config( 'recurly_js_api_key' );

			$this->recurly_client = new Recurly_Client;
		}//end if

		return $this->recurly_client;
	} // end recurly_client

	/**
	 * Wrapper function to access the Recurly API and get the user account details
	 */
	public function recurly_get_account( $user_id )
	{
		$client = $this->recurly_client();

		try
		{
			$account_code = $this->get_account_code( $user_id );

			if ( empty( $account_code ) )
			{
				do_action( 'go_slog', 'go-recurly', 'expected Recurly account code, but found none', $user_id );
				return FALSE;
			}// end if

			$account = Recurly_Account::get( $account_code, $client );
		}
		catch( Recurly_NotFoundError $e )
		{
			$account = FALSE;
		}

		return $account;
	}//end recurly_get_account

	/**
	 * Translate a Recurly API notification to a WP_User object:
	 *
	 *   - if $notification->account->account_code is present
	 *     - try to look up user by matching the account_code with the
	 *       go_recurly_account_code user metadata value
	 *     - if not found then "throw a hissy fit!"
	 *
	 *   - if account code is not present, then try to look up the
	 *     user by $notification->account->email.
	 *     - if not found then try to create a new user for that email address
	 *       and "throw a hissy fit" if we cannot create that user
	 *     - upon creating the new user, the user's go_recurly_account_code
	 *       should be generated and sync'ed to recurly.
	 *
	 * @param $notification object Recurly notification object (parsed from XML)
	 * @return WP_User object which is either newly created or existing.
	 * @return FALSE if we failed to create a new user or if we cannot
	 *  look up the user by recurly account code.
	 */
	public function recurly_get_user( $notification )
	{
		if ( ! empty( $notification->account->account_code ) )
		{
			// $notification->account and its children are SimpleXMLElement
			// objects, which must be casted to string to get to their
			// text contents.
			$account_code = (string) $notification->account->account_code;

			if ( $user = $this->get_user_by_account_code( $account_code ) )
			{
				return $user;
			}

			do_action( 'go_slog', 'go-recurly', 'failed to find a user with recurly account code: ' . $account_code, (string) $notification->account );
		}//END if
		elseif ( ! empty( $notification->account->email ) )
		{
			$email = (string) $notification->account->email;

			// this shouldn't happen very often at all. but if it does,
			// we'll try to look up the user by $notification->account->email
			$user = get_user_by( 'email', $email );

			if ( $user )
			{
				return $user;
			}

			// else try to create a new user by the email
			$new_user = array(
				'email' => $email,
				'first_name' => (string) $notification->account->first_name,
				'last_name' => (string) $notification->account->last_name,
				'company' => (string) $notification->account->company_name,
			);

			$user_id = go_subscriptions()->create_guest_user( $new_user );

			if ( is_wp_error( $user_id ) )
			{
				do_action( 'go_slog', 'go-recurly', 'failed to create a new guest user with email: ' . $email, array( $notification, $user_id ) );
				return FALSE;
			}

			// make sure the user has a recurly account code and that it's
			// sync'ed to recurly
			$user = get_user_by( 'id', $user_id );

			$recurly_account_code = $this->get_or_create_account_code( $user );

			$ret = $this->admin()->recurly_sync( $user );

			if ( ! $ret || is_wp_error( $ret ) )
			{
				do_action( 'go_slog', 'go-recurly', 'failed to sync new user recurly account code to recurly!!!', array( $notification, $ret ) );
				return FALSE;
			}

			return $user;
		}//END elseif

		return FALSE;
	}//end recurly_get_user

	/**
	 * Sign the billing form (presented within User Profile forms)
	 *
	 * @param $account_code string Recurly account code
	 * @return string signature
	 */
	public function sign_billing( $account_code )
	{
		$this->recurly_client();

		$signature = Recurly_js::sign(
			array(
				'account' => array(
					'account_code' => $account_code,
				),
			)
		);

		return $signature;
	}//end sign_billing

	/**
	 * Sign the subscription form
	 *
	 * @param $account_code string Recurly account code
	 * @param $plan_code string Recurly subscription plan code
	 * @return string signature
	 */
	public function sign_subscription( $account_code, $plan_code )
	{
		$this->recurly_client();

		$signature = Recurly_js::sign(
			array(
				'account' => array(
					'account_code' => $account_code,
				),
				'subscription' => array(
					'plan_code' => $plan_code,
				),
			)
		);

		return $signature;
	}//end sign_subscription

	public function get_account_from_token()
	{
		$this->recurly_client();

		// note: the object we get back here varies in really important ways
		// from what we get in the Recurly push
		$result = Recurly_js::fetch( $_POST['recurly_token'] );

		if ( 'Recurly_BillingInfo' == get_class( $result ) )
		{
			// this was a billing info update
			return FALSE;
		}

		return $result->account->get();
	}//END get_account_from_token

	/**
	 * Lookup potential coupon code for account
	 *
	 * @param $account_code the Recurly account code
	 */
	public function coupon_code( $account_code )
	{
		$this->recurly_client();

		try
		{
			$coupon_redemption = Recurly_CouponRedemption::get( $account_code );
			if ( ! is_object( $coupon_redemption ) || ! is_object( $coupon_redemption->coupon  ) )
			{
				return NULL;
			}

			$coupon = $coupon_redemption->coupon->get();

			if ( ! is_object( $coupon ) || ! isset( $coupon->coupon_code ) )
			{
				return NULL;
			}

			return $coupon->coupon_code;
		}// end try
		catch ( Exception $e )
		{
			return NULL;
		}
	}// end coupon_code

	/**
	 * Retrieve active (redeemable) coupons, and cache a subset of coupon object data here in the main freebies class.
	 * Hooked to freebies admin subclass's admin_init because we want to message back to the subclass-managed view.
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
	 * Process a user's subscription for a free period, per rules specified in https://github.com/GigaOM/legacy-pro/issues/3830
	 *
	 * @param $user array must contain user object
	 * @param $free_period configured in the admin dashboard; user cc is dunned at the end of this period
	 * @param $coupon_code configured in the admin dashboard, from the list of redeemable coupons supplied by recurly
	 * @return TRUE for a good subscription transaction, WP_Error otherwise
	 */
	public function subscribe_free_period( $user, $free_period, $coupon_code )
	{
		// 1. check for account code:
		// If we get an account code back, we next check what type of subscription they have.
		// If they do have an existing subscription we want to leave it intact.
		// This is a double-check in case a user has subscribed using another mechanism during the time period since invited by this plugin.
		$account_code = $this->get_or_create_account_code( $user );

		if ( ! $account_code )
		{
			return new WP_Error( 'go_recurly_freebies_subscribe_error', 'no account code found for this user ' . $user->user_email );
		}//end if

		// prepare to use recurly
		if ( ! $this->recurly_client() )
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
		$ret = $this->admin()->recurly_sync( $user );

		if ( ! $ret || is_wp_error( $ret ) )
		{
			return new WP_Error( 'go_recurly_freebies_subscribe_recurly_sync_error', 'failed to sync new user recurly account code to recurly when attempting to subscribe user ' . $user->user_email );
		}

		return TRUE;
	} // END subscribe_free_period

	/**
	 * respond to step 2 signup form post response from recurly
	 */
	private function thankyou()
	{
		if ( ! $account = $this->get_account_from_token() )
		{
			// this was a billing info update, we don't want to give them
			// the new account thank you page...
			return;
		}

		if ( ! $account->account_code )
		{
			// @todo we might want to show an error of some sort in this case.
			// according to the Recurly docs, this should never happen.
			return FALSE;
		}

		$current_user = wp_get_current_user();

		$user = $current_user->ID ? $current_user : get_user_by( 'email', $account->email );

		if ( $account->account_code == go_recurly()->get_or_create_account_code( $user ) )
		{
			$meta_vals = $this->admin()->recurly_sync( $user );

			// note: this function will delete the user cache as a side-effect,
			// so, login_user must be called AFTER this function
			go_subscriptions()->send_welcome_email( $user->ID, $meta_vals );
		}

		// we only want to set a durable login on a user who is already
		// logged in at this point
		if ( $current_user && 0 < $current_user->ID )
		{
			// they were logged in when signing up and they were redirected
			// here and the account_code matches.  Safe to login durable.
			go_subscriptions()->login_user( $current_user->ID, TRUE );
		}

		wp_redirect( $this->config( 'thankyou_path' ) );
		exit;
	}//end thankyou
}//end class

/**
 * singleton function for go_recurly
 */
function go_recurly()
{
	global $go_recurly;

	if ( ! isset( $go_recurly ) || ! is_object( $go_recurly ) )
	{
		$go_recurly = new GO_Recurly();
	}

	return $go_recurly;
}//end go_recurly