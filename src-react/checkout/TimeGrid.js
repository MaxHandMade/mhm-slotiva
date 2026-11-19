/**
 * Free times of the active day. Taken times are not listed (the API returns free slots only).
 */
import { __ } from '@wordpress/i18n';

import { slotMeta } from '../extensions';

export default function TimeGrid( {
	slots,
	selected,
	onToggle,
	capReached = false,
	multi = false,
} ) {
	if ( ! slots.length ) {
		return (
			<p className="slotiva-picker__muted">
				{ __( 'No free times on this day.', 'mhm-slotiva' ) }
			</p>
		);
	}
	return (
		<div
			className="slotiva-picker__times"
			role="group"
			aria-label={ __( 'Choose a time', 'mhm-slotiva' ) }
		>
			{ slots.map( ( s ) => {
				const on = selected.has( s.start );
				const meta = slotMeta( s );
				return (
					<button
						key={ s.start }
						type="button"
						className={ `slotiva-picker__time${
							on ? ' is-selected' : ''
						}` }
						aria-pressed={ on ? 'true' : 'false' }
						disabled={ ! on && capReached }
						onClick={ () => onToggle( s ) }
					>
						{ on && multi ? (
							<span aria-hidden="true">✓ </span>
						) : null }
						{ s.label }
						{ meta ? (
							<span className="slotiva-picker__time-meta">
								{ meta }
							</span>
						) : null }
					</button>
				);
			} ) }
		</div>
	);
}
