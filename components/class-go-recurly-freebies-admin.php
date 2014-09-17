<?php

class GO_Recurly_Freebies_Admin
{
	public $core = NULL;
	public $free_period = '2 weeks'; // default free period to be offered

	/**
	 * Constructor
	 * @param $core - an object to act as a delegate handle to the parent class
	 */
	public function __construct( $core )
	{
		$this->core = $core;
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// ajax hook to batch-invite users
		add_action( 'wp_ajax_go_recurly_freebies_batch_invite', array( $this, 'batch_invite_ajax' ) );
	}//end __construct

	/**
	 * hooked to the admin_menu action
	 */
	public function admin_menu()
	{
		add_users_page( 'Invite subscribers', 'Invite subscribers', 'edit-users', 'go-recurly-freebies-manage', array( $this, 'manage_freebies_page' ) );
	}//end admin_menu

	/**
	 * register scripts and stylesheets to support freebie subscription management
	 */
	public function register_scripts_and_styles()
	{
		$script_config = apply_filters( 'go_config', array( 'version' => 1 ), 'go-script-version' );
		wp_register_script( 'go-recurly-freebies-admin', plugins_url( '/js/go-recurly-freebies-admin.js', __FILE__ ), array( 'jquery', 'jquery-blockui' ), $script_config['version'], TRUE );
		wp_enqueue_script( 'go-recurly-freebies-admin' );

		wp_register_style( 'go-recurly-freebies-admin', plugins_url( '/css/go-recurly-freebies-admin.css', __FILE__ ), '', $script_config['version'], 'screen' );
		wp_enqueue_style( 'go-recurly-freebies-admin' );

		// set up ajax end-point in go-recurly-freebies-admin.js:
		wp_localize_script(
			'go-recurly-freebies-admin',
			'go_recurly_freebies_admin',
			array(
				'admin_ajax' => admin_url( 'admin-ajax.php' ),
			)
		);
	}//end register_scripts_and_styles

	/**
	 * ajax hook to ingest a payload from the textarea
	 * checks the arguments and permissions, sanitizes the email addresses, then passes the list on to batch_invite()
	 */
	public function batch_invite_ajax()
	{
		if ( ! $this->verify_nonce() )
		{
			wp_send_json_error( 'Something went wrong (nonce check). Please reload the page and try again' );
		}//end if

		if ( ! current_user_can( 'edit-users' ) )
		{
			wp_send_json_error( 'Something went wrong with permissions, please reload the page and try again' );
		}

		$user_list = sanitize_text_field( $_REQUEST['user_list'] );
		if ( ! $user_list )
		{
			// the client won't send a blank in this field, but if something's tampered with
			// the request and removed the field we should not proceed
			wp_send_json_error( 'You must provide a list of users' );
		}//end if

		$subscription_data = array(
			'free_period' => sanitize_text_field( $_REQUEST['free_period'] ),
			'coupon_code' => sanitize_text_field( $_REQUEST['coupon_code'] ),
		);

		$email_addresses = preg_split( '/[\s,]+/', $user_list );
		$email_addresses = array_map( 'sanitize_email', $email_addresses );
		$this->batch_invite( $email_addresses, $subscription_data );
	}// end batch_invite_ajax

	/**
	 * Invite pre-sanitized user email addresses, checking for validity, per rules defined in https://github.com/GigaOM/legacy-pro/issues/3830
	 *
	 * @param string $email_addresses list of email addresses to process for possible invitation
	 * @param array $subscription_data free period and coupon code info to be set up in WPTix
	 */
	public function batch_invite( $email_addresses, $subscription_data )
	{
		$users_invited = array();
		$skipped = array();
		$invalid = array();
		foreach ( $email_addresses as $email )
		{
			// check email is a real address:
			if ( ! is_email( $email ) )
			{
				$invalid[] = $email;
			}//end if
			else
			{
				if ( email_exists( $email ) )
				{
					// If the email address exists in accounts.go, check to see if an active subscription exists.
					// Do not send invites to users that are already subscribers
					$user = get_user_by( 'email', $email );

					if ( $user->has_cap( 'subscriber' ) )
					{
						$skipped[] = $email;
						continue;
					}//end if
				}//end if

				// invite user
 				if ( $this->invite( $email, $subscription_data ) )
				{
					$users_invited[] = $email;
					//do_action( 'go_slog', 'go-recurly-freebies', 'invited a freebie user', $email );
				}// end if
				else
				{
					$skipped[] = $email;
				}//end else
			}//end else
		}// end foreach

		$result = array( 'invited' => $users_invited, 'skipped' => $skipped, 'invalid' => $invalid );

		wp_send_json_success( $result );
		wp_die();
	}// end batch_invite

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
		wptix()->register_ticket( $this->core->signup_action, $ticket_name, $subscription_data );
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
	 * display form for managing free subscriptions
	 */
	public function manage_freebies_page()
	{
		$this->register_scripts_and_styles();
		require_once __DIR__ . '/templates/invite.php';
	}//end manage_freebies_page

	/**
	 * Helper function to build select options
	 *
	 * @param array $options of options
	 * @param string $existing which option to preselect
	 * @return string $select_options html options
	 */
	public function build_options( $options, $existing )
	{
		$select_options = '';
		foreach ( $options as $option => $text )
		{
			$select_options .= '<option value="' . esc_attr( $option ) . '"' . selected( $option, $existing, FALSE ) . '>' . esc_html( $text ) . "</option>\n";
		} //end foreach

		return $select_options;
	} //end build_options

	/**
	 * nonce field
	 */
	public function nonce_field()
	{
		wp_nonce_field( plugin_basename( __FILE__ ), $this->core->id_base .'-nonce' );
	}//end nonce_field

	/**
	 * verify a nonce
	 */
	public function verify_nonce()
	{
		if ( ! isset( $_REQUEST[ $this->core->id_base .'-nonce' ] ) )
		{
			return FALSE;
		}// end if

		return wp_verify_nonce( $_REQUEST[ $this->core->id_base .'-nonce' ], plugin_basename( __FILE__ ) );
	}//end verify_nonce
}//end class