<?php

class GO_Recurly_User_Profile
{
	private $core = NULL;

	/**
	 * Constructor
	 *
	 * @param GO_Recurly $core handle to the containing GO_Recurly singleton
	 */
	public function __construct( $core )
	{
		$this->core = $core;
	}//end __construct

	/**
	 * billing screen
	 */
	public function billing()
	{
		if ( isset( $_POST['recurly_token'] ) )
		{
			$args = array(
				'recurly_token' => $_POST['recurly_token'],
			);

			echo $this->core->get_template_part( 'billing-success.php', $args );
			return;
		}//end if

		$client = $this->core->recurly_client();

		if ( $account = $this->core->recurly_get_account( get_current_user_id() ) )
		{
			try
			{
				$billing = Recurly_BillingInfo::get( $account->account_code, $client );
			}
			catch( Exception $e )
			{
				$billing = array();
			}

			$signature = $this->core->sign_billing( $account->account_code );

			$has_recurly_account = TRUE;
		}//end if

		$args = array(
			'account_code' => $has_recurly_account ? $account->account_code : FALSE,
			'signature'    => $signature,
			'success_url'  => home_url( '/my-profile/subscription/billing', 'https' ),
		);

		wp_localize_script( 'go-recurly', 'go_recurly', array(
			'billing' => $this->billing_info_array( $billing ),
		) );

		echo $this->core->get_template_part( 'billing.php', $args );
	}//end billing

	/**
	 * Returns a billing info array of data formatted for recurly js usage
	 *
	 * @param $billing_info Recurly_BillingInfo Billing info object
	 */
	public function billing_info_array( $billing )
	{
		// we don't include credit card info because we don't want
		// that appearing in the source

		$data = array(
			'firstName' => $billing->first_name,
			'lastName'  => $billing->last_name,
			'address1'  => $billing->address1,
			'address2'  => $billing->address2,
			'city'      => $billing->city,
			'state'     => $billing->state,
			'country'   => $billing->country,
			'zip'       => $billing->zip,
			'phone'     => $billing->phone,
			'vatNumber' => $billing->vat_number,
		);

		return $data;
	}//end billing_info_array

	/**
	 * cancel screen
	 */
	public function cancel()
	{
		$subscription_id = preg_replace( '/[^a-z0-9]/', '', $_GET['uuid'] );

		do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'start cancel_content() method', $subscription_id );

		$client = $this->core->recurly_client();

		if ( $account = $this->core->recurly_get_account( get_current_user_id() ) )
		{
			do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'has recurly account', array( $subscription_id, get_current_user_id(), $this->core->get_account_code( get_current_user_id() ) ) );

			try
			{
				$subscription  = Recurly_Subscription::get( $subscription_id, $client );

				do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'found recurly subscription', $subscription );
			}
			catch ( Exception $e )
			{
				echo $this->core->get_template_part( 'no-access.php', array( 'error' => $e->getMessage() ) );

				do_action( 'go_slog', 'subscriptions-cancel', 'FAIL no recurly subscription found', array( $subscription_id, get_current_user_id() ) );

				return;
			}//end catch

			$has_recurly_account = TRUE;
		}//end if

		// bail if the person attempting to cancel a subscription but doesn't own the subscription
		if ( ! $has_recurly_account || $subscription->account->get()->account_code != $account->account_code )
		{
			$args = array(
				'error' => 'mismatched-subscription',
			);

			echo $this->core->get_template_part( 'no-access.php', $args );

			do_action( 'go_slog', 'subscriptions-cancel', 'FAIL no recurly subscription doesn`t match', array( $subscription_id, get_current_user_id() ) );

			return;
		}//end if

		$subscription_meta = $this->core->get_subscription_meta( get_current_user_id() );

		$args = array(
			'account_code'          => $account->account_code,
			'subscription'          => $subscription,
			'subscription_meta'     => $subscription_meta,
			'url'                   => '/members/' . go_user_profile()->displayed_user->ID . '/subscription',
			'subscription_provider' => $this->core->config( 'subscription_provider' ),
			'survey_url'            => $this->core->config( 'survey_url' ),
		);

		if ( isset( $_GET['confirm'] ) )
		{
			do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'cancel_content() confirm conditional', $subscription_id );

			if ( ! check_admin_referer( 'go_recurly_cancel' ) )
			{
				do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'FAIL check_admin_referer() test', $subscription_id );

				return new WP_Error( 'bad nonce', 'Form submission failed security checks.' );
			}//end if

			if ( isset( $subscription->trial_ends_at ) && time() <= $subscription->trial_ends_at->getTimestamp() )
			{
				do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'attempt to cancel trial', $subscription_id );

				// still in the trial, terminate the account immediately, but specify no refund since no charges should be applied.
				$message = $this->core->cancel_subscription( get_current_user_id(), $subscription, 'none' );
			}//end if
			elseif ( strtotime( '-120 days' ) < strtotime( $subscription_meta->sub_last_payment_date ) )
			{
				do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'attempt to cancel subscription with refund', $subscription_id );

				// within 120 days of last payment, terminate the account and refund all their payment
				$message = $this->core->cancel_subscription( get_current_user_id(), $subscription, 'all' );
			}//end elseif
			else
			{
				do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'attempt to cancel subscription without refund', $subscription_id );

				// not in a trial and past 120 days since last payment, cancel the account so no auto-renew happens,
				// but leave the account open until the subscription term is up
				$message = $this->core->cancel_subscription( get_current_user_id(), $subscription );
			}//end else

			if ( FALSE === $message )
			{
				echo $this->core->get_template_part( 'cancel-success.php', $args );

				do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'success and end cancel_content() method', $subscription_id );
			}
			else
			{
				echo $this->core->get_template_part( 'no-access.php', array( 'error' => $message ) );

				do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'fail and end cancel_content() method', $subscription_id );
			}//end else

			return;
		}//end if

		echo $this->core->get_template_part( 'cancel-confirm.php', $args );

		do_action( 'go_slog', 'go-recurly_subscriptions-cancel', 'end cancel_content() method', $subscription_id );
	}//end cancel

	/**
	 * error page
	 */
	public function error_page( $error_code )
	{
		echo $this->core->get_template_part( 'no-access.php', array( 'error' => $error_code, ) );
	}//end error_page

	/**
	 * history screen
	 */
	public function history()
	{
		$client = $this->core->recurly_client();

		if ( $account = $this->core->recurly_get_account( get_current_user_id() ) )
		{
			// @todo: implement paging using the cursor
			$invoices  = Recurly_InvoiceList::getForAccount(
				$account->account_code,
				array(
					'cursor' => 0,
				),
				$client
			);
		}//end if

		$user_meta = $this->core->get_user_meta( get_current_user_id() );
		$timezone_string = ! empty( $user_meta['timezone'] ) ? $user_meta['timezone'] : get_option( 'timezone_string' );

		$args = array(
			'invoices'        => $invoices,
			'url'             => '/members/' . go_user_profile()->displayed_user->ID . '/subscription/invoice/',
			'timezone_string' => $timezone_string,
		);

		echo $this->core->get_template_part( 'history.php', $args );
	}//end history

	/**
	 * invoice screen
	 */
	public function invoice()
	{
		if ( ! ( $account = $this->core->recurly_get_account( get_current_user_id() ) ) )
		{
			$this->error_page( 'no-account' );
			return;
		}//end if

		if ( ! ( $invoice_id = (int) $_GET['id'] ) )
		{
			$this->error_page( 'invalid-invoice' );
			return;
		}//end if

		try
		{
			ob_clean();

			// don't cache the invoice
			nocache_headers();

			$invoice = Recurly_Invoice::get( $invoice_id );
			if ( $account->account_code != $invoice->account->get()->account_code )
			{
				$this->error_page( 'invalid-invoice' );
				return;
			}//end if

			// this page returns a 404 file not found.  Well, that's not accurate.  Return a 200 OK
			header( 'HTTP/1.0 200 OK' );
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment;filename="' . $this->core->config( 'invoice_filename_prefix' ) . $invoice->created_at->format( 'Y-m-d' ) . '.pdf"' );

			$pdf = Recurly_Invoice::getInvoicePdf( $invoice_id, 'en-US' );
		}//end try
		catch( Recurly_NotFoundError $e )
		{
			$this->error_page( 'invalid-invoice' );
			return;
		}//end catch

		echo $pdf;
		die;
	}//end invoice

	/**
	 * subscriptions screen
	 */
	public function subscriptions()
	{
		$client = $this->core->recurly_client();

		$user_id = go_user_profile()->displayed_user->ID;

		if ( $account = $this->core->recurly_get_account( $user_id ) )
		{
			$subscriptions = Recurly_SubscriptionList::getForAccount( $account->account_code, NULL, $client );
			$has_recurly_account = TRUE;
		}

		$args = array(
			'account_code'        => $has_recurly_account ? $account->account_code : FALSE,
			'url'                 => "/members/$user_id/subscription",
			'subscriptions'       => $subscriptions,
			'has_recurly_account' => $has_recurly_account,
		);

		echo $this->core->get_template_part( 'subscriptions.php', $args );
	}//end subscriptions
}//end class
