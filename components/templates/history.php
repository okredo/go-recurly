<div class="go-subscriptions go-subscriptions-history">
	<?php
	if ( ! is_object( $template_variables['invoices'] ) )
	{
		?>
		<p>You have not made any payments.</p>
		<?php
	}//end if
	else
	{
		foreach ( $template_variables['invoices'] as $invoice )
		{
			$timestamp = $invoice->created_at->getTimestamp();
			$invoice->created_at->setTimezone( new DateTimeZone( 'America/Los_Angeles' ) );

			?>
			<div id="invoice_<?php echo esc_attr( $invoice->invoice_number ); ?>" class="boxed invoice status-<?php echo esc_attr( $invoice->status ); ?>">
				<header>
					<span class="status"><?php echo ucwords( str_replace( '_', ' ', 'collected' == $invoice->state ? 'Processed' : esc_html( $invoice->state ) ) ); ?></span>
					<h1><a href="<?php echo esc_url( $template_variables['url'] ); ?>?id=<?php echo esc_attr( $invoice->invoice_number ); ?>" target="_blank">Invoice #<?php echo esc_html( $invoice->invoice_number ); ?></a></h1>
				</header>
				<section class="body">
					<dl>
						<dt>Billed on</dt>
						<dd><?php echo $invoice->created_at->format( 'M j, Y g:ia' ) . ' PST'; ?></dd>
						<dt>Total</dt>
						<dd>$ <?php echo number_format( $invoice->total_in_cents / 100, 2 ); ?> <?php echo esc_html( $invoice->currency ); ?></dd>
					</dl>
				</div>
			</div>
			<?php
		}//end foreach
	}//end else
	?>
</div>
