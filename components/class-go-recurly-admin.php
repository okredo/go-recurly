<?php

class GO_Recurly_Admin
{
	private $core = NULL;

	/**
	 * Constructor
	 */
	public function __construct( $core )
	{
		$this->core = $core;

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		add_action( 'show_user_profile', array( $this, 'show_user_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'edit_user_profile' ) );

		add_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );

		// let's hook up some ajax actions
		add_action( 'wp_ajax_go_recurly_push', array( $this, 'receive_push' ) );
		add_action( 'wp_ajax_nopriv_go_recurly_push', array( $this, 'receive_push' ) );
	}//end __construct

	/**
	 * hooked to the admin_init action
	 */
	public function admin_init()
	{
		wp_register_style( 'go-recurly-admin', plugins_url( 'css/go-recurly-admin.css', __FILE__ ), array(), $this->core->version );

		$this->handle_request();
	}//end admin_init

	/**
	 * hooked to the admin_menu action
	 */
	public function admin_menu()
	{
		add_users_page(
			'Search by Recurly Account Code',
			'Search by Recurly Account Code',
			'edit_users',
			'go-recurly-search-by-account-code',
			array( $this, 'add_users_page' )
		);
	}//end admin_menu

	/**
	 * handles custom admin request
	 */
	public function handle_request()
	{
		if (
			! isset( $_GET['action'] ) ||
			'recurly_sync' != $_GET['action'] ||
			! isset( $_GET['user_id'] )
		)
		{
			return;
		}

		$this->recurly_sync( absint( $_GET['user_id'] ) );
	}//end handle_request

	/**
	 * Process and handle the push request that comes from Recurly
	 */
	public function receive_push()
	{
		if (
			$_SERVER['PHP_AUTH_USER'] != $this->core->config['push_username'] ||
			$_SERVER['PHP_AUTH_PW'] != $this->core->config['push_password']
		)
		{
			header( 'WWW-Authenticate: Basic realm="Gigaom"' );
			header( 'HTTP/1.0 401 Unauthorized', true, 401 );
			exit( 'Bad authentication response' );
		}//end if

		nocache_headers();

		$this->core->recurly_client();

		$xml = file_get_contents( 'php://input' );
		if ( ! $xml )
		{
			header( 'HTTP/1.0 400 Bad Request', true, 400 );
			exit( 'Failed to receive data' );
		}//end if

		$notification = new Recurly_PushNotification( $xml );

		$user = $this->core->recurly_get_user( $notification );

		if ( ! is_object( $user ) )
		{
			// this will be seen as an error in Recurly and it will hold the message to attempt a future delivery.
			header( 'HTTP/1.0 500 Internal Server Error', true, 500 );
			exit( 'Error retrieving user.' );
		}//end if

		switch ( $notification->type )
		{
			case 'new_subscription_notification':
			case 'expired_subscription_notification':
			case 'successful_payment_notification':
			case 'renewed_subscription_notification':
			case 'updated_subscription_notification':
				$this->recurly_sync( $user );
				break;

			// we don't really care about synchronizing recurly data for
			// the other types
			case 'new_account_notification':
			case 'reactivated_account_notification':
			case 'canceled_account_notification':
			// we don't care about accounts. subscriptions are what matters for now.
			case 'canceled_subscription_notification':
			// this comes when they cancel their subscription, but they may still have time left before it expires.
			// we will get a 'expired_subscription_notification' when it is expired.  No reason to react to this at this time.
			case 'billing_info_updated_notification':
			case 'failed_payment_notification':
			case 'successful_refund_notification':
			case 'void_payment_notification':
			// we don't care about what is going on with payments and billing info, dunning will be handled via Recurly dashboard
				break;
		}//end switch

		return TRUE;
	}//end receive_push

	/**
	 * hooked to the edit_user_profile action
	 */
	public function edit_user_profile( $user )
	{
		$this->show_user_profile( $user );
	}//end edit_user_profile

	/**
	 * Output the user profile within the WordPress admin UI for users
	 *
	 * @param $user WP_User object to show
	 */
	public function show_user_profile( $user )
	{
		$meta_vals = $this->core->get_user_meta( $user->ID );
		?>
		<h3>Subscription Info</h3>
		<a class="button" href="<?php echo admin_url(); ?>user-edit.php?user_id=<?php echo absint( $user->ID ); ?>&action=recurly_sync">Synchronize Recurly Data</a><?php
		if ( isset( $this->recurly_error ) )
		{
			// we need the css for recurly-error class
			wp_enqueue_style( 'go-recurly-admin' );
			?>
			<p><span class="recurly-error"><?php echo wp_kses( $this->recurly_error->getMessage(), array() ); ?></span></p>
			<?php
		}//END if
		?>
		<table class="form-table">
			<tbody>
			<?php
			foreach ( $meta_vals as $key => $value )
			{
				if ( is_array( $value ) )
				{
					foreach ( $value as $k => $v )
					{
						$this->show_user_profile_row( $key . ': '.$k, $v );
					}//end foreach
				}//end if
				else
				{
					$this->show_user_profile_row( $key, $value );
				}//end else
			}//end foreach
			?>
			</tbody>
		</table>
		<?php
	}//end show_user_profile

	/**
	 * Outputs a single table row, acts as a service function for show_user_profile() to show a single row in the profile
	 *
	 * @param $key Element heading
	 * @param $value Element value
	 */
	private function show_user_profile_row( $key, $value )
	{
		//don't show some fields
		$disabled_fields = array(
			'company',
			'title',
		);
		if ( in_array( $key, $disabled_fields ) )
		{
			return;
		}//end if

		//change account_code to a recurly link
		//escaping $value here so that we don't have to below (and break the <a>)
		if ( 'account_code' == $key )
		{
			$value = $this->get_recurly_user_url( $value );
		}
		else
		{
			$value = esc_attr( $value );
		}
	 	?>
	 	<tr class="form-field">
			<th><?php echo esc_html( $key ); ?></th>
			<td><code><?php echo wp_kses( $value ); ?></code></td>
		</tr>
		<?php
	}//end show_user_profile_row

	public function get_recurly_user_url( $account_code = FALSE )
	{
		if ( empty( $account_code ) )
		{
			return;
		}

		$url = sprintf(
			'<a href="https://gigaom.recurly.com/accounts/%1$s">%1$s</a>',
			esc_attr( $account_code )
		);

		return $url;
	}//end get_recurly_user_url

	/**
	 * update the user's email address in Recurly if it changed
	 */
	public function profile_update( $user_id, $old_user )
	{
		$user = get_user_by( 'id', $user_id );

		if ( $old_user->user_email == $user->user_email )
		{
			return;
		}

		$this->core->update_email( $user );
	}//end profile_update

	/**
	 * synchronizes recurly data into usermeta
	 *
	 * @param mixed $user integer user id or a WP_User object
	 * @return mixed usermeta if successful, FALSE or WP_Error if we
	 *  cannot sync with recurly
	 */
	public function recurly_sync( $user, $account = NULL )
	{
		$client = $this->core->recurly_client();

		if ( ! ( $user instanceof WP_User ) )
		{
			$user = get_user_by( 'id', $user );
		}

		if ( ! $user || is_wp_error( $user ) )
		{
			return FALSE;
		}

		if ( $account )
		{
			$account_code = $account->account_code;
		}
		else
		{
			$account_code = $this->core->get_or_create_account_code( $user );
		}

		if ( ! $account_code )
		{
			return new WP_Error( 'Could not find Recurly account code for the given user' );
		}

		$user_id = $user->ID;

		$old_meta = get_user_meta( $user_id, $this->core->meta_key_prefix . 'subscription', TRUE );

		// let's start a new meta array
		$meta = array();

		// if we don't have the user's recurly first/last name set yet,
		// then make sure we have a recurly account object so we can
		// save the first/last name in our db
		if ( ! $account && ( ! isset( $old_meta['first_name'] ) || ! isset( $old_meta['last_name'] ) ) )
		{
			$account = $this->core->recurly_get_account( $user->ID );

			if ( $account )
			{
				$meta['first_name'] = $account->first_name;
				$meta['last_name'] = $account->last_name;
			}
		}//end if
		else
		{
			// we already have their recurly first/last name
			if ( isset( $old_meta['first_name'] ) )
			{
				$meta['first_name'] = $old_meta['first_name'];
			}
			if ( isset( $old_meta['last_name'] ) )
			{
				$meta['last_name'] = $old_meta['last_name'];
			}
		}//end else

		try
		{
			$subscriptions = Recurly_SubscriptionList::getForAccount( $account_code, NULL, $client );
		}
		catch ( Recurly_Error $e )
		{
			$this->recurly_error = $e;
			return FALSE;
		}

		// these are the date variables we want to track
		$dates = array(
			'activated_at',
			'canceled_at',
			'expires_at',
			'current_period_started_at',
			'current_period_ends_at',
			'trial_started_at',
			'trial_ends_at',
		);

		foreach ( $subscriptions as $subscription )
		{
			// if we find a trial subscription and there's already an active one that we're tracking,
			// let's skip the trial subscription
			if (
				isset( $subscription->trial_ends_at ) &&
				time() <= $subscription->trial_ends_at->getTimestamp() &&
				isset( $meta['sub_state'] ) &&
				'active' == $meta['sub_state']
			)
			{
				continue;
			}

			// if we're tracking an active subscription, don't let it be changed to one of the other
			// non-active states
			if (
				isset( $meta['sub_state'] ) &&
				'active' == $meta['sub_state'] &&
				'active' != $subscription->state
			)
			{
				continue;
			}

			$meta['sub_plan_code'] = $subscription->plan->plan_code;
			$meta['sub_state'] = $subscription->state;

			// cancelled subscriptions are really active subscriptions that won't renew,
			// see http://docs.recurly.com/api/subscriptions
			// these should behave like active subscriptions, so that's what we're doing here
			$meta['auto_renew'] = TRUE; // default state
			if ( 'canceled' == $meta['sub_state'] )
			{
				$meta['sub_state'] = 'active';
				$meta['auto_renew'] = FALSE;
			}

			foreach ( $dates as $date )
			{
				if ( ! isset( $subscription->$date ) )
				{
					$meta[ "sub_{$date}" ] = '';
					continue;
				}

				$meta[ "sub_{$date}" ] = $subscription->$date->format( 'Y-m-d\TH:i:s\Z' );
			}//end foreach
		}//end foreach

		// the user doesn't have any subscriptions? set some info to defaults and bail.

		if ( empty( $subscriptions ) )
		{
			go_user_profile()->set_role( $user, 'guest' );

			go_subscriptions()->update_subscription_meta( $user_id, $meta );

			return FALSE;
		}//end if

		// set the coupon code
		$meta['sub_coupon_code'] = $this->core->coupon_code( $account_code );

		// handle the storage of recent (and initial) payment
		if ( isset( $meta['sub_state'] ) )
		{
			$args = array(
				'state' => 'successful',
				'type' => 'purchase',
				'per_page' => 1,
			);
			try
			{
				$transactions = Recurly_TransactionList::getForAccount( $account_code, $args );
			}
			catch ( Recurly_Error $e )
			{
				$this->recurly_error = $e;
				return FALSE;
			}

			foreach ( $transactions as $transaction )
			{
				$meta['sub_did_subscription'] = TRUE;
				$meta['sub_last_payment'] = (int) $transaction->amount_in_cents;
				$meta['sub_last_payment_date'] = $transaction->created_at->format( 'Y-m-d\TH:i:s\Z' );
				$meta['sub_last_payment_invnum'] = (int) $transaction->reference;

				if ( isset( $old_meta['sub_initial_payment'] ) )
				{
					$meta['sub_initial_payment'] = $old_meta['sub_initial_payment'];
				}
				else
				{
					$meta['sub_initial_payment'] = $meta['sub_last_payment'];
				}
			}//end foreach
		}//end if

		// set a created date if one has never been set. ever.
		$created_date = get_user_meta( $user_id, $this->core->meta_key_prefix . 'created_date', TRUE );
		if ( ! $created_date )
		{
			update_user_meta( $user_id, $this->core->meta_key_prefix . 'created_date', strtotime( $meta['sub_activated_at'] ) );
		}

		// set the role!
		if ( 'active' == $meta['sub_state'] )
		{
			// if the user's subscription state is active, they are either a trial or a subscriber
			if ( time() >= strtotime( $meta['sub_trial_ends_at'] ) )
			{
				// the current UTC time is greater than their trial end time...so...subscriber!
				go_user_profile()->set_role( $user, 'subscriber' );
			}
			else
			{
				// their trial is still going on, mark them as a trial subscription
				go_user_profile()->set_role( $user, 'subscriber-trial' );
			}
		}//end if
		else
		{
			// looks like this user's subscription isn't active, change them over to a guest
			go_user_profile()->set_role( $user, 'guest' );
		}

		go_subscriptions()->update_subscription_meta( $user_id, $meta );

		$this->core->update_email( $user );

		return $meta;
	}//end recurly_sync

	/**
	 * handles Recurly account code search request
	 */
	public function add_users_page()
	{
		// check for id and if it's there do nonce check
		if (
			isset( $_POST['account-code'] )
			&& isset( $_POST['_wpnonce'] )
			&& wp_verify_nonce( $_POST['_wpnonce'], 'go-recurly-search-by-account-code' )
		)
		{
			// look up via Recurly account code
			// note: if a user has more than one Recurly account code, the following function will only return the last user match found
			if ( ! $user = $this->core->get_user_by_account_code( $_POST['account-code'] ) )
			{
				// display error message, then continue to show the search form
				?>
				<div id='not-found-message' class='updated fade'><p><strong>A user with that Recurly account code not found.</strong></p></div>
				<?php
			}
			else
			{
				// a user was found, redirect to edit page for that user
				?>
				<script type="text/javascript">
					<!--
						window.location = <?php echo json_encode( get_edit_user_link( $user->ID ) ); ?>;
					//-->
				</script>
				<?php
				exit;
			}//end else
		}//end if

		// display form
		?>
		<div class="go-recurly-search-by-account-code">
			<h3>Find User By Recurly Account Code</h3>
			<form id="go-recurly-search-form" method="post" action="">
				<?php wp_nonce_field( 'go-recurly-search-by-account-code' ); ?>
				<ul>
					<li class="field-container account-code">
						<label for="account-code">Account Code</label>
						<input type="text" name="account-code" size="33" value=""/>
					</li>
				</ul>
				<div class="well">
					<input name="submit_button" class="button primary" type="submit"/>
				</div>
			</form>
		</div>
		<?php
	}//end add_users_page
}//end class