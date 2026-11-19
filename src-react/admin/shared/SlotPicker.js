/**
 * Admin slot picker (frame 7 "Change time", also the manual-add dialog): a 7-day strip and the free times of the chosen
 * day from GET /admin/availability (15-minute grid, §15.10). Days with no free time are disabled. With `exclude`, the
 * appointment's own units count as free; its current time is shown but cannot be picked.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { addDays, availability, dayLabels, errorMessage } from './api';

/**
 * @param {Object}   props
 * @param {number}   [props.product]    Product id (optional with `exclude`).
 * @param {number}   [props.variation]  Variation id.
 * @param {number}   [props.exclude]    Reservation whose own units are ignored (reschedule).
 * @param {string}   props.today        Local Y-m-d (site time zone): the strip never starts before it.
 * @param {string}   [props.current]    starts_at of the appointment being moved (shown, not selectable).
 * @param {?Object}  props.value        Selected slot { start, label, day } or null.
 * @param {Function} props.onChange     Called with the slot (or null when the grid changes under it).
 * @param {number}   [props.refreshKey] Bump to refetch (after a 409).
 */
export default function SlotPicker( {
	product,
	variation = 0,
	exclude = 0,
	today,
	current = '',
	value,
	onChange,
	refreshKey = 0,
} ) {
	const [ weekStart, setWeekStart ] = useState( today );
	const [ week, setWeek ] = useState( null );
	const [ day, setDay ] = useState( null );
	const [ error, setError ] = useState( '' );

	const ready = Boolean( product || exclude );

	useEffect( () => {
		if ( ! ready ) {
			setWeek( null );
			return undefined;
		}
		let alive = true;
		setWeek( null );
		setError( '' );
		const days = Array.from( { length: 7 }, ( _, i ) =>
			addDays( weekStart, i )
		);
		Promise.all(
			days.map( ( d ) =>
				availability( { product, variation, day: d, exclude } ).then(
					( r ) => ( { day: d, slots: r.slots || [] } ),
					( e ) => ( { day: d, slots: [], error: e } )
				)
			)
		).then( ( result ) => {
			if ( ! alive ) {
				return;
			}
			const failed = result.find( ( r ) => r.error );
			if ( failed ) {
				setError( errorMessage( failed.error ) );
			}
			setWeek( result );
			setDay( ( prev ) => {
				const keep = result.find(
					( r ) => r.day === prev && r.slots.length
				);
				if ( keep ) {
					return prev;
				}
				const first = result.find( ( r ) =>
					r.slots.some( ( s ) => s.start !== current )
				);
				return first ? first.day : null;
			} );
			// A selection that is no longer free is dropped.
			if (
				value &&
				! result.some( ( r ) =>
					r.slots.some( ( s ) => s.start === value.start )
				)
			) {
				onChange( null );
			}
		} );
		return () => {
			alive = false;
		};
		// value/onChange are read at fetch time only; a new selection must not refetch.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ product, variation, exclude, weekStart, refreshKey, ready, current ] );

	if ( ! ready ) {
		return (
			<p className="slotiva-picker__hint">
				{ __( 'Choose a service first.', 'mhm-slotiva' ) }
			</p>
		);
	}

	const selected = week ? week.find( ( r ) => r.day === day ) : null;

	return (
		<div className="slotiva-picker" aria-busy={ week ? undefined : 'true' }>
			<div className="slotiva-picker__nav">
				<button
					type="button"
					className="button"
					disabled={ weekStart <= today }
					onClick={ () => setWeekStart( addDays( weekStart, -7 ) ) }
				>
					<span aria-hidden="true">‹ </span>
					{ __( 'Previous week', 'mhm-slotiva' ) }
				</button>
				<button
					type="button"
					className="button"
					onClick={ () => setWeekStart( addDays( weekStart, 7 ) ) }
				>
					{ __( 'Next week', 'mhm-slotiva' ) }
					<span aria-hidden="true"> ›</span>
				</button>
			</div>
			<div
				role="group"
				aria-label={ __( 'Day', 'mhm-slotiva' ) }
				className="slotiva-picker__days"
			>
				{ Array.from( { length: 7 }, ( _, i ) => {
					const d = addDays( weekStart, i );
					const info = week
						? week.find( ( r ) => r.day === d )
						: null;
					const free = info
						? info.slots.filter( ( s ) => s.start !== current )
								.length
						: 0;
					const labels = dayLabels( d );
					return (
						<button
							key={ d }
							type="button"
							className="slotiva-picker__day"
							aria-pressed={ d === day }
							aria-label={
								free
									? labels.long
									: sprintf(
											/* translators: %s: day, e.g. "Saturday, 14 November". */
											__(
												'%s, no free time',
												'mhm-slotiva'
											),
											labels.long
									  )
							}
							disabled={ ! free }
							onClick={ () => setDay( d ) }
						>
							<span>{ labels.dow }</span>
							<strong>{ labels.num }</strong>
						</button>
					);
				} ) }
			</div>
			{ ! week && (
				<p className="slotiva-picker__hint">
					{ __( 'Loading free times…', 'mhm-slotiva' ) }
				</p>
			) }
			{ error && (
				<p className="slotiva-picker__hint" role="alert">
					{ error }
				</p>
			) }
			{ week && ! selected && ! error && (
				<p className="slotiva-picker__hint">
					{ __( 'No free time this week.', 'mhm-slotiva' ) }
				</p>
			) }
			{ selected && (
				<div
					role="group"
					aria-label={ __( 'Time', 'mhm-slotiva' ) }
					className="slotiva-picker__times"
				>
					{ selected.slots.map( ( s ) => {
						const own = s.start === current;
						return (
							<button
								key={ s.start }
								type="button"
								className={
									own
										? 'slotiva-picker__time slotiva-picker__time--current'
										: 'slotiva-picker__time'
								}
								aria-pressed={
									!! value && value.start === s.start
								}
								aria-label={
									own
										? sprintf(
												/* translators: %s: time, e.g. 11:30. */
												__(
													'%s (current time)',
													'mhm-slotiva'
												),
												s.label
										  )
										: undefined
								}
								data-start={ s.start }
								disabled={ own }
								onClick={ () =>
									onChange( {
										start: s.start,
										label: s.label,
										day: selected.day,
									} )
								}
							>
								{ s.label }
							</button>
						);
					} ) }
				</div>
			) }
		</div>
	);
}
