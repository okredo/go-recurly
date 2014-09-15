<?php

class GO_Recurly_Test extends WP_UnitTestCase
{
	// list of user ids to clean up at tearDown time. user_id => bool
	private $users_to_cleanup = array();

	public function setUp()
	{
		// clear WP's object caches
		$this->flush_cache();
	}//END setUp

	public function tearDown()
	{
		foreach ( $this->users_to_cleanup as $user_id => $unused )
		{
			$ret = $this->delete_user( $user_id );
		}//END foreach
	}//END tearDown

	public function flush_cache()
	{
		parent::flush_cache();

		// clear memcache if enabled
		$save_handler = ini_get( 'session.save_handler' );
		$save_path = ini_get( 'session.save_path' );

		try
		{
			if ( ! $save_path )
			{
				$save_path = 'tcp://127.0.0.1:11211';
			}

			$memcache = new Memcache;

			$save_path = str_replace( 'tcp://', '', $save_path );
			$save_path = explode( ':', $save_path );

			$memcache->connect( $save_path[0], $save_path[1] );
			$memcache->flush();
		}//END try
		catch( Exception $e )
		{
			var_dump( $e );
		}

		// override go-subscriptions' configuration. because the way it's
		// loaded by go-subscriptions, we can't just use our own filter,
		// but have to set the singleton's config class var
		go_subscriptions()->config['subscriptions_blog_id'] = 4;
	}//END flush_cache

	/**
	 * baseline test just to make sure we can even bring up the singleton
	 */
	public function test_singleton()
	{
		$this->assertTrue( is_object( go_recurly() ) );
	}//END test_singletone

	// tests the case where recurly notification's account code matches
	// one of our users
	public function test_recurly_get_user_with_account_code()
	{
		$user = $this->create_user( array(
										'user_nicename' => 'pacman',
										'user_login' => 'pacman',
										'user_email' => 'pacman_testtest@gigaom.com',
		) );
		$this->assertTrue( FALSE !== $user );
		$this->users_to_cleanup[ $user->ID ] = TRUE;

		$recurly_account_code = go_recurly()->get_or_create_account_code( $user );
		$this->assertTrue( FALSE !== $recurly_account_code );

		$recurly_notification = $this->get_recurly_notification( $recurly_account_code, $user->user_email );

		$recurly_user = go_recurly()->recurly_get_user( $recurly_notification );

		$this->assertTrue( FALSE !== $recurly_user );
		$this->users_to_cleanup[ $recurly_user->ID ] = TRUE;

		$this->assertEquals( $recurly_user->user_email, $user->user_email );
		$this->assertEquals( $recurly_user->user_login, $user->user_login );
	}//END test_recurly_get_user_with_account_code

	// tests the case where recurly notification's account code does not
	// match one of our users. we should not look up a user by email.
	public function test_recurly_get_user_with_bad_account_code()
	{
		$user = $this->create_user( array(
										'user_nicename' => 'mspacman',
										'user_login' => 'mspacman',
										'user_email' => 'mspacman_testtest@gigaom.com',
		) );
		$this->assertTrue( FALSE !== $user );
		$this->users_to_cleanup[ $user->ID ] = TRUE;

		$recurly_account_code = go_recurly()->get_or_create_account_code( $user );
		$this->assertTrue( FALSE !== $recurly_account_code );

		$recurly_notification = $this->get_recurly_notification( 'BAD_ACCOUNT_CODE', $user->user_email );

		$recurly_user = go_recurly()->recurly_get_user( $recurly_notification );

		$this->assertEquals( FALSE, $recurly_user );
	}//END test_recurly_get_user_with_bad_account_code

	// tests the case where the recurly notification does not have an
	// account code, but the user email does match one of our users.
	public function test_recurly_get_user_with_email()
	{
		$user = $this->create_user( array(
										'user_nicename' => 'pacmanjr',
										'user_login' => 'pacmanjr',
										'user_email' => 'pacmanjr_testtest@gigaom.com',
		) );
		$this->assertTrue( FALSE !== $user );
		$this->users_to_cleanup[ $user->ID ] = TRUE;

		$recurly_account_code = go_recurly()->get_or_create_account_code( $user );
		$this->assertTrue( FALSE !== $recurly_account_code );

		$recurly_notification = $this->get_recurly_notification( '', $user->user_email );

		$recurly_user = go_recurly()->recurly_get_user( $recurly_notification );

		$this->assertTrue( FALSE !== $recurly_user );
		$this->users_to_cleanup[ $recurly_user->ID ] = TRUE;
		$this->assertEquals( $recurly_user->user_email, $user->user_email );
		$this->assertEquals( $recurly_user->user_login, $user->user_login );
	}//END test_recurly_get_user_with_email

	// test the case where the recurly notification does not contain an
	// account code, and the user email does not match an existing user.
	// we should create a new guest user in this case
	public function test_recurly_get_user_with_new_user()
	{
		$user_email = 'the_pacman_testtest@gigaom.com';

		$recurly_notification = $this->get_recurly_notification( '', $user_email );

		try
		{
			// we expect this to fail the first time because of a "headers
			// already sent" error.
			$recurly_user = go_recurly()->recurly_get_user( $recurly_notification );
		}
		catch ( Exception $e )
		{
			$this->assertTrue( 0 === strpos( $e->getMessage(), 'Cannot modify header information' ) );
			try
			{
				$recurly_user = go_recurly()->recurly_get_user( $recurly_notification );
			}
			catch ( Exception $e )
			{
				echo $e->getMessage();
			}
		}//END catch

		$this->assertTrue( FALSE !== $recurly_user );
		$this->users_to_cleanup[ $recurly_user->ID ] = TRUE;
		$this->assertEquals( $recurly_user->user_email, $user_email );
		$this->assertTrue( 0 < $recurly_user->ID );
	}//END test_recurly_get_user_with_new_user

	// test the case where the recurly notification does not contain an
	// account code nor an user email. we should not get a user back
	// in this case
	public function test_recurly_get_user_bad_notification()
	{
		$recurly_notification = $this->get_recurly_notification( '', '' );

		$recurly_user = go_recurly()->recurly_get_user( $recurly_notification );

		$this->assertEquals( FALSE, $recurly_user );
	}//END test_recurly_get_user_bad_notification

	private function create_user( $args )
	{
		if ( $user = get_user_by( 'slug', $args['user_nicename'] ) )
		{
			return $user;
		}
		else
		{
			$user_id = wp_insert_user( $args );
			if ( is_wp_error( $user_id ) )
			{
				var_dump( $user_id );
				return FALSE;
			}
			return get_user_by( 'id', $user_id );
		}
	}//END create_user

	/**
	 * our own delete_user to work around WP's hesitance to really delete a
	 * user on a multi-site blog.
	 */
	private function delete_user( $user_id )
	{
		clean_user_cache( $user_id );

		global $wpdb;
		$meta = $wpdb->get_col( $wpdb->prepare( "SELECT umeta_id FROM $wpdb->usermeta WHERE user_id = %d", $id ) );
		foreach ( $meta as $mid )
		{
			delete_metadata_by_mid( 'user', $mid );
		}

		$wpdb->delete( $wpdb->users, array( 'ID' => $user_id ) );
	}//END delete_user

	/**
	 * get a recurly notification object for a given account code and
	 * user email
	 */
	private function get_recurly_notification( $account_code, $user_email )
	{
		$now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$activated = $now->sub( new DateInterval( 'P1M' ) );
		$started = $activated;
		$ends = $now->add( new DateInterval( 'P1Y' ) );

		$xml =
'<?xml version="1.0" encoding="UTF-8"?>
<updated_subscription_notification>
  <account>
    <account_code>' . $account_code . '</account_code>
    <username nil="true"></username>
    <email>' . $user_email . '</email>
    <first_name>Pac</first_name>
    <last_name>Man</last_name>
    <company_name nil="true"></company_name>
  </account>
  <subscription>
    <plan>
      <plan_code>1dpt</plan_code>
      <name>Subscription One</name>
    </plan>
    <uuid>292332928954ca62fa48048be5ac98ec</uuid>
    <state>active</state>
    <quantity type="integer">1</quantity>
    <total_amount_in_cents type="integer">200</total_amount_in_cents>
    <activated_at type="datetime">' . $activated->format( 'Y-m-d\TH:i:s\Z' ) . '</activated_at>
    <canceled_at nil="true" type="datetime"></canceled_at>
    <expires_at nil="true" type="datetime"></expires_at>
    <current_period_started_at type="datetime">' . $started->format( 'Y-m-d\TH:i:s\Z' ) . '</current_period_started_at>
    <current_period_ends_at type="datetime">' . $ends->format( 'Y-m-d\TH:i:s\Z' ) . '</current_period_ends_at>
    <trial_started_at nil="true" type="datetime">
    </trial_started_at><trial_ends_at nil="true" type="datetime">
    </trial_ends_at>
    <collection_method>automatic</collection_method>
  </subscription>
</updated_subscription_notification>';

		require_once( dirname( __DIR__ ) . '/components/external/recurly-client/lib/recurly.php' );

		return new Recurly_PushNotification( $xml );
	}//END get_recurly_notification
}//END class