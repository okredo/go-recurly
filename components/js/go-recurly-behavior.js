(function( $ ) {
	$(function() {
		$(document).delegate( '.subscribe', 'submit', function( e ) {
			e.preventDefault();
			$('.subscribe button[type="submit"]').addClass('disabled');
		});

		$(document).delegate( '.subscribe input', 'keydown', function( e ) {
			$submit = $('.subscribe button[type="submit"]');
			if ( $submit.hasClass('disabled') ) {
				$submit.removeClass('disabled');
			}//end if
		});

		$(document).delegate( '.subscribe select', 'change', function( e ) {
			$submit = $('.subscribe button[type="submit"]');
			if ( $submit.hasClass('disabled') ) {
				$submit.removeClass('disabled');
			}//end if
		});

		$(document).on( 'click', '.go-subscriptions-signup-button .button, .go-subscriptions-signup-link', function( e ) {

			// by default assume logged-in users have emails.
			var has_email = $('body').hasClass('logged-in');
			if ( 'undefined' != typeof go_recurly_settings.user_has_email ) {
				// check if the server has an email for this user or not
				has_email = go_recurly_settings.user_has_email;
			}

			if ( false == $(this).hasClass( 'nojs' ) && ( false == $('body').hasClass('logged-in') || false == has_email ) ) {
				e.preventDefault();
				var redirect = $(this).data('redirect');
				redirect = redirect ? '&redirect=' + escape( redirect ) : '';

				var referringurl = $(this).data('referring-url');
				referringurl = referringurl ? '&referring_url=' + escape( referringurl ) : '';

				var converted_post_id = '';
				var converted_vertical = '';
				var classes = $('body').attr('class').split(/\s+/);
				$.each( classes, function( i, v ) {
					var stuff = v.split(/-/);
					if( 'postid' == stuff[0] ) {
						converted_post_id = '&converted_post_id=' + stuff[1];
					} // end if
					if( 'vertical' == stuff[0] ) {
						// vertical names may contain dashes (-)
						stuff.shift();
						converted_vertical = '&converted_vertical=' + stuff.join( '-' );
					}
				});

				var lightbox_size = 307;

				if ( $('body').hasClass( 'go-device-tablet' ) || $('body').hasClass( 'go-device-full' ) ) {
					lightbox_size = 435;
				}

				$(this).colorbox({
					href: go_recurly_ajax.url + '?action=go-subscriptions-signup-form' + redirect + referringurl + converted_post_id + converted_vertical
					, close: 'x'
					, open: true
					, title: ' '
					, width: lightbox_size
					, scrolling: false
					, onLoad: function() {
							var $colorbox = $('#colorbox');
							$colorbox.addClass('go-subscriptions-signup-lightbox');
					}
					, onClosed: function() {
						$('.go-subscriptions-signup-lightbox').removeClass('go-subscriptions-signup-lightbox');
					}
					, onComplete : function() {
						$( '#colorbox' ).find( 'input:visible:first' ).focus();
						// ie fix for placeholder attributes
						// from: http://kamikazemusic.com/quick-tips/jquery-html5-placeholder-fix/
						if( Modernizr && ! Modernizr.input.placeholder ) {
							$('input').each( function() {
								if( $(this).val() == '' && $(this).attr('placeholder') != '' ) {
									$(this).val( $(this).attr('placeholder') );

									$(this).focus(function() {
										if( $(this).val() == $(this).attr('placeholder') ) {
											$(this).val('');
										}
									});

									$(this).blur( function() {
										if( $(this).val() == '' ) {
											$(this).val($(this).attr('placeholder'));
										}
									});
								}
							});
						}//end if placeholder fix
						else
						{
							// we only want to auto focus if placeholders are supported...otherwise the browsers without placeholder will never see the email label
							$('.go-subscriptions-signup-lightbox input[name="email"]').focus();
						}//end else

						$(this).colorbox.resize();
					}
				});
			} // end if
		});

		// Display the signup lightbox by if the #go-subscriptions-signup hash is present
		if ( '#go-subscriptions-signup' == window.location.hash ) {
			$( '.go-subscriptions-signup-button .button, .go-subscriptions-signup-link' ).trigger( 'click' );
		} // END if

		$('#go-recurly-form').GoSubscriptions('subscription');
		$('#go-recurly-billing-form').GoSubscriptions('billing');
	});

	$(document).on( 'submit', '#cancel-form', function( e ) {
		var $el = $(this);

		if ( ! $el.find('input[type="checkbox"]').is(':checked') ) {
			$el.find('#cancel-errors').html('You must confirm your cancellation');
			e.preventDefault();
		}//end if
	});
})( jQuery );
