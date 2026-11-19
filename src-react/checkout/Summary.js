/**
 * Selection summary. Single mode (frame 1): "Your time: …" line. Multi mode (frame 1b): list with ✕ buttons and
 * "N × price" for per-slot pricing.
 */
import { __, sprintf } from '@wordpress/i18n';

import { formatPrice } from './api';
import { selectionLine } from '../extensions';

function removeLabel( range ) {
	return sprintf(
		/* translators: %s: chosen time, e.g. "Tue 17 Nov · 10:00–11:00". */
		__( 'Remove %s', 'mhm-slotiva' ),
		range
	);
}

function ExtraLine( { picks } ) {
	const line = selectionLine( picks );
	return line ? (
		<p className="slotiva-picker__selection-line">{ line }</p>
	) : null;
}

export default function Summary( { picks, multi, pricing, prices, onRemove } ) {
	if ( ! multi ) {
		return (
			<>
				<p className="slotiva-picker__pick" role="status">
					{ picks.length ? (
						<>
							<span className="slotiva-picker__muted">
								{ __( 'Your time:', 'mhm-slotiva' ) }
							</span>{ ' ' }
							<strong>{ picks[ 0 ].long }</strong>
						</>
					) : (
						<span className="slotiva-picker__muted">
							{ __( 'Choose a day and time.', 'mhm-slotiva' ) }
						</span>
					) }
				</p>
				<ExtraLine picks={ picks } />
			</>
		);
	}

	const count = picks.length;
	return (
		<div className="slotiva-picker__summary">
			<h3 className="slotiva-picker__summary-title">
				{ __( 'Your selection', 'mhm-slotiva' ) }
			</h3>
			{ count ? null : (
				<p className="slotiva-picker__muted" role="status">
					{ __( 'No time chosen yet.', 'mhm-slotiva' ) }
				</p>
			) }
			<ul
				className="slotiva-picker__picked"
				aria-label={ __( 'Your selected times', 'mhm-slotiva' ) }
			>
				{ picks.map( ( p ) => (
					<li key={ p.start }>
						<span>{ p.range }</span>
						<button
							type="button"
							className="slotiva-picker__remove"
							aria-label={ removeLabel( p.range ) }
							onClick={ () => onRemove( p.start ) }
						>
							<span aria-hidden="true">✕</span>
						</button>
					</li>
				) ) }
			</ul>
			{ 'per_slot' === pricing && count > 0 ? (
				<p className="slotiva-picker__line-total">
					<span>
						{ sprintf(
							/* translators: 1: number of chosen times, 2: price of one time. */
							__( '%1$d × %2$s', 'mhm-slotiva' ),
							count,
							formatPrice( prices?.price, prices )
						) }
					</span>
					<span>
						{ formatPrice(
							Number( prices?.price || 0 ) * count,
							prices
						) }
					</span>
				</p>
			) : null }
			{ 'per_slot' === pricing ? (
				<p className="slotiva-picker__muted slotiva-picker__note">
					{ __(
						'The quantity in your cart follows the number of times you choose.',
						'mhm-slotiva'
					) }
				</p>
			) : null }
			<ExtraLine picks={ picks } />
		</div>
	);
}
