if ( 'undefined' === typeof go_recurly_freebies_admin ) {
	var go_recurly_freebies_admin = {
		'event': {},
		'admin_ajax': ''
	};
	// should we alert the user and bail here?
}//end if
else {
	// we received the localized object from the server, now add the event holder object to it:
	go_recurly_freebies_admin.event = {};
}//end else

(function( $ ) {
	'use strict';

	go_recurly_freebies_admin.init = function() {
		// initialize any commonly used dom elements as this.$something
		this.$invitations = $( '#invitations' );
		this.$invite_users = this.$invitations.find( '.invite-users' );
		this.$invite_users_more = this.$invitations.find( '.invite-users-more' );
		this.$invite_users_try_again = this.$invitations.find( '.invite-users-try-again' );
		this.$batch_email_list = this.$invitations.find( '#batch-email-list' );
		this.$report_back = this.$invitations.find( '#report-back' );
		this.$report_back_skipped_invitations = this.$invitations.find( '#report-back-skipped-invitations' );
		this.$instruction_initial = this.$invitations.find( '.instruction-initial' );
		this.$message_invitations = this.$invitations.find( '.message-invitations' );
		this.$message_invalid_invitations = this.$invitations.find( '.message-invalid-invitations' );
		this.$message_skipped_invitations = this.$invitations.find( '.message-skipped-invitations' );
		this.$freebie_nonce = $( '#go-recurly-freebies-nonce' );

		// bind any button click events:
		// - "invite users" button event binding
		$( document ).on( 'click', '#invitations .invite-users', this.event.invite_users );
		// - "invite more" button event binding
		$( document ).on( 'click', '#invitations .invite-users-more', this.event.reset_invitations_form );
		// - "try again" button event binding
		$( document ).on( 'click', '#invitations .invite-users-try-again', this.event.retry_invitations_form );

		// call any setup functions
		this.reset_invitations_form();
	};//end init

	// perform the invitation action
	//  - send the contents of the invitations tab's textarea over for batch invitations
	go_recurly_freebies_admin.invite_users = function() {
		var $user_list = this.$batch_email_list.val();
		if ( ! $user_list ) {
			return;  // just stay on the form if it's been submitted blank
		}

		//add a loading blocker and message
		this.$invitations.block({
			message: 'Inviting Users…',
			css: {
				border: 'none',
				padding: '15px',
				backgroundColor: '#000',
				'-webkit-border-radius': '10px',
				'-moz-border-radius': '10px',
				opacity: 0.5,
				color: '#fff'
			},
			overlayCSS: {
				background: '#fff'
			}
		});

		// call the server action
		$.ajax({
			type: 'POST',
			url: go_recurly_freebies_admin.admin_ajax,
			data: {
				'action': 'go_recurly_freebies_batch_invite',
				'user_list': $user_list,
				'free_period': $( '#go_recurly_freebies_free_period :selected' ).text(),
				'coupon_code': $( '#go_recurly_freebies_coupon_code :selected' ).text(),
				'go-recurly-freebies-nonce': go_recurly_freebies_admin.$freebie_nonce.val()
			}
		})
			.done( function( data ) {
				if ( data.success === false ){
					this.$invitations.unblock();
					this.$invitations.html( data.data );
					return;
				}
				else {
					var parsed = $.parseJSON( data );
					go_recurly_freebies_admin.setup_report_back();
					var new_users = go_recurly_freebies_admin.pretty_print( parsed.invited );
					var invalid_users = parsed.invalid;
					var skipped_users = go_recurly_freebies_admin.pretty_print( parsed.skipped );
					go_recurly_freebies_admin.message( new_users, invalid_users, skipped_users );
				}
			})
			.fail( function( jqXHR, textStatus ) {
				go_recurly_freebies_admin.$invitations.unblock();
				go_recurly_freebies_admin.$invitations.html( textStatus );
			});
	};

	// reset the form
	go_recurly_freebies_admin.reset_invitations_form = function( retry ) {
		$( '.show-on-reset' ).show();
		$( '.hide-on-reset' ).hide();
		if ( true !== retry ) {
			this.$batch_email_list.val('');
		}
	};//end reset_invitations_form

	// set up reporting of results
	go_recurly_freebies_admin.setup_report_back = function() {
		this.$invitations.unblock();
		this.$batch_email_list.hide();
		this.$report_back.show();
		this.$report_back.val('');
		this.$report_back_skipped_invitations.show();
		this.$report_back_skipped_invitations.val('');
	};//end setup_report_back

	// format the results for display
	go_recurly_freebies_admin.pretty_print = function( obj ) {
		var result_data = {
			count: 0,
			result: ''
		};
		if ( ! $.isArray( obj ) ) {
			// handle new users, returned as indexed array
			for ( var i in obj ) {
				result_data.result += obj[i] + '<br>';
				result_data.count++;
			}
		}
		else {
			// handle invalid users, returned as un-indexed array
			for( var j=0, len=obj.length; j < len; j++ ) {
				result_data.result += obj[j] + '<br>';
				result_data.count++;
			}
		}
		return result_data;
	};//end pretty_print

	// display the results of the ajax transaction
	go_recurly_freebies_admin.message = function( new_users, invalid_users, skipped_users ) {
		var invalid_count = invalid_users.length;
		this.$instruction_initial.hide();
		var invitations_msg = '';
		var invalid_msg = '';
		var skipped_msg = '';

		// message re newly invited:
		if ( new_users.count === 1 ) {
			invitations_msg = 'The following user was successfully added:';
		}
		else if ( new_users.count === 0 ) {
			invitations_msg = 'There were no users added. ';
			this.$invite_users_try_again.show();
		}
		else if ( new_users.count > 1 ) {
			invitations_msg = 'The following ' + new_users.count + ' users were successfully added:';
		}

		// message re invalid users (occurs when the address did not pass WP's 'sanitize_email' test):
		if ( invalid_count > 1 ) {
			invalid_msg = 'There were ' + invalid_count + ' users with invalid email addresses who were not invited.';
		}
		// message re single invalid:
		else if ( invalid_count === 1 ) {
			invalid_msg = 'There was one invalid email address.';
		}

		// message re skipped users (occurs when they're already a subscriber):
		if ( skipped_users.count > 1 ) {
			skipped_msg = 'The following ' + skipped_users.count + ' recognized subscribers were skipped:';
		}
		// message re already existing:
		else if ( skipped_users.count === 1 ) {
			skipped_msg = 'We skipped this recognized subscriber:';
		}

		this.$message_invitations.text( invitations_msg );
		this.$message_invitations.show();
		this.$message_invalid_invitations.text( invalid_msg );
		this.$message_invalid_invitations.show();
		this.$message_skipped_invitations.text( skipped_msg );
		this.$message_skipped_invitations.show();
		$( '#report-back' ).find( 'p' ).html( new_users.result );
		$( '#report-back-skipped-invitations' ).find( 'p' ).html( skipped_users.result );
		this.$invite_users.hide();
		this.$invite_users_more.show();
	};//end message

	/**
	 * handles the clicking of the "invite users" button
	 */
	go_recurly_freebies_admin.event.invite_users = function( e ) {
		e.preventDefault();
		go_recurly_freebies_admin.invite_users();
	};

	/**
	 * handles the clicking of the "invite more users" button
	 */
	go_recurly_freebies_admin.event.reset_invitations_form = function( e ) {
		e.preventDefault();
		go_recurly_freebies_admin.reset_invitations_form();
	};

	/**
	 * handles the clicking of the "try again" button
	 */
	go_recurly_freebies_admin.event.retry_invitations_form = function( e ) {
		e.preventDefault();
		go_recurly_freebies_admin.reset_invitations_form( true );
	};

})( jQuery );

jQuery(function( $ ) {
	go_recurly_freebies_admin.init();
});
