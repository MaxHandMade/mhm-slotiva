/**
 * Day strip: one button per day of the horizon. Wide screens page through it with the arrows (7 visible);
 * narrow screens scroll it horizontally (frame 2).
 */
import { useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { dayParts } from './api';

function scroll( ref, direction ) {
	const el = ref.current;
	if ( el && typeof el.scrollBy === 'function' ) {
		el.scrollBy( { left: direction * el.clientWidth, behavior: 'smooth' } );
	}
}

function fullLabel( date ) {
	return sprintf(
		/* translators: %s: date, e.g. "Tuesday, 17 November". */
		__( '%s, fully booked', 'mhm-slotiva' ),
		date
	);
}

function closedLabel( date ) {
	return sprintf(
		/* translators: %s: date, e.g. "Tuesday, 17 November". */
		__( '%s, closed', 'mhm-slotiva' ),
		date
	);
}

function chosenBadge( count ) {
	return sprintf(
		/* translators: %d: number of times chosen on this day. */
		_n( '%d chosen', '%d chosen', count, 'mhm-slotiva' ),
		count
	);
}

function freeBadge( count ) {
	/* translators: %d: number of free times on this day. */
	return sprintf( _n( '%d free', '%d free', count, 'mhm-slotiva' ), count );
}

export default function DayStrip( {
	days,
	activeDay,
	onPick,
	picksByDay = {},
	multi = false,
} ) {
	const ref = useRef( null );

	return (
		<div className="slotiva-picker__days">
			<button
				type="button"
				className="slotiva-picker__nav"
				aria-label={ __( 'Previous days', 'mhm-slotiva' ) }
				onClick={ () => scroll( ref, -1 ) }
			>
				<svg
					width="18"
					height="18"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2"
					aria-hidden="true"
					focusable="false"
				>
					<path d="M15 18l-6-6 6-6" />
				</svg>
			</button>
			<div
				ref={ ref }
				className="slotiva-picker__day-list"
				role="group"
				aria-label={ __( 'Choose a day', 'mhm-slotiva' ) }
			>
				{ days.map( ( d ) => {
					const parts = dayParts( d.date );
					const available = d.open && d.free > 0;
					const picked = picksByDay[ d.date ] || 0;
					if ( ! available ) {
						const label = d.open
							? fullLabel( parts.long )
							: closedLabel( parts.long );
						return (
							<button
								key={ d.date }
								type="button"
								className="slotiva-picker__day is-closed"
								disabled
								aria-label={ label }
							>
								<span className="slotiva-picker__dow">
									{ parts.dow }
								</span>
								<span className="slotiva-picker__num">
									{ parts.num }
								</span>
								<span className="slotiva-picker__badge">
									{ d.open
										? __( 'full', 'mhm-slotiva' )
										: __( 'closed', 'mhm-slotiva' ) }
								</span>
							</button>
						);
					}
					const badge =
						multi && picked > 0
							? chosenBadge( picked )
							: freeBadge( d.free );
					const active = activeDay === d.date;
					return (
						<button
							key={ d.date }
							type="button"
							className={ `slotiva-picker__day${
								active ? ' is-active' : ''
							}${ picked ? ' has-picks' : '' }` }
							aria-pressed={ active ? 'true' : 'false' }
							aria-label={ `${ parts.long }, ${ badge }` }
							onClick={ () => onPick( d.date ) }
						>
							<span
								className="slotiva-picker__dow"
								aria-hidden="true"
							>
								{ parts.dow }
							</span>
							<span
								className="slotiva-picker__num"
								aria-hidden="true"
							>
								{ parts.num }
							</span>
							<span
								className="slotiva-picker__badge"
								aria-hidden="true"
							>
								{ badge }
							</span>
						</button>
					);
				} ) }
			</div>
			<button
				type="button"
				className="slotiva-picker__nav"
				aria-label={ __( 'Next days', 'mhm-slotiva' ) }
				onClick={ () => scroll( ref, 1 ) }
			>
				<svg
					width="18"
					height="18"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2"
					aria-hidden="true"
					focusable="false"
				>
					<path d="M9 18l6-6-6-6" />
				</svg>
			</button>
		</div>
	);
}
