/**
 * Step 4 (artboard Step4): the checkout check. Rows come from a fresh GET /setup (the check runs every time the step
 * opens). When the checkout page does not use the Checkout block the button opens the page editor (or WooCommerce
 * advanced settings when the page is missing) in a new tab; the plugin never rewrites page content.
 */
import { __ } from '@wordpress/i18n';

import { docsUrl } from '../../shared/docs';
import NewTabNote, { NewTabLink } from '../../shared/NewTabNote';
import CheckRow from '../ui/CheckRow';

// Detail text plus an inline "How to fix" link into the FAQ (rows that are not ok only; no link without a docs base).
function withFix( row, link ) {
	const url = row.state === 'ok' ? '' : link;
	if ( ! url ) {
		return row.detail;
	}
	return (
		<>
			{ row.detail }
			{ row.detail ? ' ' : '' }
			<NewTabLink href={ url }>
				{ __( 'How to fix', 'mhm-slotiva' ) }
			</NewTabLink>
		</>
	);
}

export default function Checkout( { status } ) {
	const checks = status.checks || {};
	const row = ( key ) => checks[ key ] || { state: 'optional', detail: '' };
	const tz = row( 'timezone' );
	const pay = row( 'payment' );
	const co = row( 'checkout' );
	const states = [ tz.state, pay.state, co.state ];

	let lead = __( 'Everything looks ready.', 'mhm-slotiva' );
	if ( states.includes( 'todo' ) ) {
		lead = __(
			'The hour picker shows at checkout. One thing needs fixing first.',
			'mhm-slotiva'
		);
	} else if ( states.includes( 'warn' ) ) {
		lead = __(
			'Your checkout is ready, but review the items marked Check.',
			'mhm-slotiva'
		);
	}

	return (
		<div className="slotiva-step">
			<div>
				<h1>
					{ __( 'We checked your checkout page', 'mhm-slotiva' ) }
				</h1>
				<p className="slotiva-step__lead">{ lead }</p>
			</div>
			<ul className="slotiva-checks">
				<CheckRow
					state={ tz.state }
					title={
						tz.state === 'ok'
							? __( 'The time zone is set', 'mhm-slotiva' )
							: __(
									'The time zone should be checked',
									'mhm-slotiva'
							  )
					}
					detail={ withFix( tz, docsUrl( 'faq/faq/', 'time-zone' ) ) }
				/>
				<CheckRow
					state={ pay.state }
					title={
						pay.state === 'ok'
							? __(
									'A payment method is available',
									'mhm-slotiva'
							  )
							: __(
									'No payment method is enabled',
									'mhm-slotiva'
							  )
					}
					detail={ withFix(
						pay,
						docsUrl( 'faq/faq/', 'no-payment-method' )
					) }
				/>
				<CheckRow
					state={ co.state }
					title={
						co.state === 'ok'
							? __(
									'The checkout page uses blocks',
									'mhm-slotiva'
							  )
							: __(
									'The checkout page uses the classic layout',
									'mhm-slotiva'
							  )
					}
					detail={ withFix(
						co,
						docsUrl( 'faq/faq/', 'picker-missing' )
					) }
				>
					{ co.state !== 'ok' && co.action && (
						<>
							<a
								className="slotiva-check-row__button"
								href={ co.action }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __(
									'Convert the checkout page to blocks',
									'mhm-slotiva'
								) }
								<NewTabNote />
							</a>
							<p className="slotiva-check-row__howto">
								{ __(
									'Select the Classic Checkout block and choose “Transform into blocks”.',
									'mhm-slotiva'
								) }
							</p>
						</>
					) }
				</CheckRow>
			</ul>
			<p className="slotiva-step__note slotiva-step__note--plain">
				{ __(
					'This check runs every time the step opens. If something breaks later, it also shows above the Appointments list.',
					'mhm-slotiva'
				) }
			</p>
		</div>
	);
}
