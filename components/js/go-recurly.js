(function( $ ) {

	var methods = {};

	methods.init = function() {
	};

	methods.billing = function() {
		return this.each( function() {
			var $el = $(this);

			Recurly.buildBillingInfoUpdateForm({
				target: '#' + $el.attr('id'),
				signature   : $el.data('signature'),
				successURL  : $el.data('success-url'),
				accountCode : $el.data('account-code'),
				billingInfo : go_recurly.billing,
				afterInject: function( form ) {
					$('.title:first', $( form )).html('Update Billing Information');
				}
			});
		});
	};

	methods.subscription = function( options ) {
		return this.each( function() {
			var $el = $(this);

			Recurly.buildSubscriptionForm({
				target: '#' + $el.attr('id'),
				signature: $el.data('signature'),
				successURL: $el.data('success-url'),
				planCode: $el.data('plan-code'),
				distinguishContactFromBillingInfo: true,
				collectCompany: true,
				termsOfServiceURL: $el.data('terms-url'),
				account: go_recurly.account,
				billingInfo: go_recurly.billing,
				subscription: go_recurly.subscription,
				afterInject: function( form ) {
					$( '.subscribe button[type="submit"]' ).addClass( 'button button-primary' ).removeClass( 'submit' );

					var $tos = $('.accept_tos', $( form ));
					$tos.find('#tos_check').attr('checked', 'checked').hide();
					$tos.find('#accept_tos').hide();
					// @todo: move tos content to config
					$tos.append('<p>By continuing, you are agreeing to our <a href="' + go_recurly_settings.tos_url + '">Terms of Service</a> and <a href="' + go_recurly_settings.privacy_policy_url + '">Privacy Policy</a>.</p>');

					$('div.check').click(); // apply any coupon code
				}
			});

		});
	};

	$.fn.GoSubscriptions = function( method ) {
		if ( methods[ method ] ) {
			return methods[ method ].apply( this, Array.prototype.slice.call( arguments, 1 ) );
		} else if ( typeof 'object' === method || ! method ) {
			return methods.init.apply( this, arguments );
		} else {
			$.error( 'Method ' + method + ' does not exist on jQuery.GoSubscriptions' );
		}//end else

		return null;
	};

	// listen for the "goPostSignUp" HTMLEvents that's fired at the end
	// of the sign-up process
	document.addEventListener( 'goPostSignUp', function( e ) {
		$( '#go-recurly-form' ).GoSubscriptions( 'subscription' );
		$( '#go-recurly-billing-form' ).GoSubscriptions( 'billing' );
	}, false );
})( jQuery );
