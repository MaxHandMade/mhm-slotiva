/**
 * Working hours (spec §8, frame 8): weekly ranges per day, exceptions (closed and extra open), booking rules.
 *
 * Saving checks the week and the rules first (overlap, 15-minute step), then sends PUT /schedule with all 7 days and
 * PUT /rules with the booking-rule fields only. A change that leaves booked appointments outside the open hours is
 * not blocked (§8): the server reports `affected` and the screen says the appointments are kept.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import Button from '../../../vendor/mhm/ui-core/src-react/components/Button';
import Notice from '../../../vendor/mhm/ui-core/src-react/components/Notice';
import PageHeader from '../../../vendor/mhm/ui-core/src-react/components/PageHeader';
import StatusBadge from '../../../vendor/mhm/ui-core/src-react/components/StatusBadge';
import Widget from '../../../vendor/mhm/ui-core/src-react/components/Widget';

import {
	addDays,
	addException,
	dayLabels,
	deleteException,
	errorMessage,
	getRules,
	getSchedule,
	listExceptions,
	pageData,
	saveRules,
	saveSchedule,
} from '../shared/api';
import { fromMinutes, rulesError, toMinutes, weekError } from './validate';

/** A known Monday: day names come from Intl in the page language (0 = Monday, as the API). */
const MONDAY = '2026-11-16';
const DEFAULT_RANGE = { from: '09:00', to: '17:00' };
/** `<input type="time">` cannot show 24:00: it is displayed as 23:59, and an end typed as 00:00 or 23:59 is midnight. */
const MIDNIGHT_INPUTS = [ '00:00', '23:59' ];

/**
 * The range "+ range" adds: an hour after the last one ends, two hours long, never past midnight; null when the day
 * has no room left (the last range ends at 24:00).
 *
 * @param {Array<{from:string,to:string}>} ranges Current ranges.
 * @return {{from:string,to:string}|null} New range.
 */
function nextRange( ranges ) {
	const last = ranges[ ranges.length - 1 ];
	const end = last ? toMinutes( last.to ) : null;
	if ( end === null ) {
		return last ? null : { ...DEFAULT_RANGE };
	}
	if ( end + 15 > 1440 ) {
		return null;
	}
	const from = end + 75 <= 1440 ? end + 60 : end;
	return {
		from: fromMinutes( from ),
		to: fromMinutes( Math.min( from + 120, 1440 ) ),
	};
}
const DURATIONS = [ 15, 30, 45, 60, 75, 90, 120 ];
const RULE_KEYS = [
	'durationDefault',
	'leadHours',
	'horizonDays',
	'bufferMin',
	'bankHoldHours',
	'cardHoldMin',
	'reminderOn',
	'reminderHours',
];

function dayNames() {
	const lang =
		( typeof document !== 'undefined' && document.documentElement.lang ) ||
		undefined;
	return [ 0, 1, 2, 3, 4, 5, 6 ].map( ( i ) => {
		const [ y, m, d ] = addDays( MONDAY, i ).split( '-' ).map( Number );
		const date = new Date( Date.UTC( y, m - 1, d, 12 ) );
		try {
			return new Intl.DateTimeFormat( lang, {
				weekday: 'long',
				timeZone: 'UTC',
			} ).format( date );
		} catch ( e ) {
			return new Intl.DateTimeFormat( undefined, {
				weekday: 'long',
				timeZone: 'UTC',
			} ).format( date );
		}
	} );
}

/**
 * The warning shown after any change that leaves booked appointments outside the open hours.
 *
 * @param {number} count   Affected appointments.
 * @param {string} listUrl Appointments list.
 * @return {Object} Notice.
 */
function keptNotice( count, listUrl ) {
	return {
		tone: 'warning',
		text: (
			<>
				{ sprintf(
					/* translators: %d: number of appointments. */
					_n(
						'%d booked appointment (confirmed or awaiting payment) is now outside the new schedule. The appointment is kept; contact the customer if it should move.',
						'%d booked appointments (confirmed or awaiting payment) are now outside the new schedule. The appointments are kept; contact the customers if they should move.',
						count,
						'mhm-slotiva'
					),
					count
				) }{ ' ' }
				<a href={ `${ listUrl || '' }&tab=upcoming` }>
					{ __( 'Review upcoming appointments', 'mhm-slotiva' ) }
				</a>
			</>
		),
	};
}

function exceptionWhen( item ) {
	const day = dayLabels( item.date ).long;
	if ( ! item.from_time || ! item.to_time ) {
		return sprintf(
			/* translators: %s: date. */
			__( '%s · all day', 'mhm-slotiva' ),
			day
		);
	}
	return sprintf(
		/* translators: 1: date, 2: start time, 3: end time. */
		__( '%1$s · %2$s–%3$s', 'mhm-slotiva' ),
		day,
		item.from_time,
		item.to_time
	);
}

const EMPTY_FORM = {
	type: 'closed',
	date: '',
	allDay: false,
	from: '',
	to: '',
	note: '',
};

export default function App() {
	const data = pageData();
	const names = dayNames();
	const [ days, setDays ] = useState( null );
	const [ rules, setRules ] = useState( null );
	const [ exceptions, setExceptions ] = useState( [] );
	const [ loadError, setLoadError ] = useState( '' );
	const [ notices, setNotices ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ form, setForm ] = useState( null );
	const [ formError, setFormError ] = useState( '' );

	useEffect( () => {
		let alive = true;
		Promise.all( [ getSchedule(), getRules(), listExceptions() ] ).then(
			( [ schedule, loaded, exc ] ) => {
				if ( ! alive ) {
					return;
				}
				setDays(
					( schedule.days || [] ).map( ( ranges ) => ( {
						open: ranges.length > 0,
						ranges: ranges.map( ( x ) => ( { ...x } ) ),
					} ) )
				);
				const picked = {};
				RULE_KEYS.forEach( ( k ) => ( picked[ k ] = loaded[ k ] ) );
				setRules( picked );
				setExceptions( exc.items || [] );
			},
			( e ) => alive && setLoadError( errorMessage( e ) )
		);
		return () => {
			alive = false;
		};
	}, [] );

	const updateDay = ( i, change ) =>
		setDays( ( prev ) =>
			prev.map( ( d, k ) => ( k === i ? change( d ) : d ) )
		);

	const toggleDay = ( i ) =>
		updateDay( i, ( d ) => {
			if ( d.open ) {
				// Kept in the screen, so switching back on restores them; a closed day is sent empty.
				return { ...d, open: false };
			}
			return {
				open: true,
				ranges: d.ranges.length ? d.ranges : [ { ...DEFAULT_RANGE } ],
			};
		} );

	const addRange = ( i ) =>
		updateDay( i, ( d ) => {
			const next = nextRange( d.ranges );
			return next ? { open: true, ranges: [ ...d.ranges, next ] } : d;
		} );

	const removeRange = ( i, k ) =>
		updateDay( i, ( d ) => {
			const ranges = d.ranges.filter( ( x, j ) => j !== k );
			return { open: ranges.length > 0, ranges };
		} );

	const setRange = ( i, k, key, raw ) => {
		// An end of 00:00 (or the 23:59 a time input shows for it) means midnight, stored as 24:00.
		const value =
			key === 'to' && MIDNIGHT_INPUTS.includes( raw ) ? '24:00' : raw;
		updateDay( i, ( d ) => ( {
			...d,
			ranges: d.ranges.map( ( x, j ) =>
				j === k ? { ...x, [ key ]: value } : x
			),
		} ) );
	};

	const setRule = ( key, value ) =>
		setRules( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const save = () => {
		const problem = weekError( days, names ) || rulesError( rules );
		if ( problem ) {
			setNotices( [ { tone: 'danger', text: problem } ] );
			return;
		}
		setSaving( true );
		const week = days.map( ( d ) =>
			d.open
				? d.ranges.map( ( x ) => ( { from: x.from, to: x.to } ) )
				: []
		);
		const fields = {};
		RULE_KEYS.forEach( ( k ) => {
			fields[ k ] =
				k === 'reminderOn' ? !! rules[ k ] : Number( rules[ k ] );
		} );
		let affected = 0;
		let scheduleSaved = false;
		saveSchedule( week )
			.then( ( r ) => {
				scheduleSaved = true;
				affected = Number(
					r.affected ?? ( r.outside_hours || [] ).length
				);
				return saveRules( fields );
			} )
			.then(
				() => {
					const next = [
						{
							tone: 'success',
							text: __( 'Changes saved.', 'mhm-slotiva' ),
						},
					];
					if ( affected > 0 ) {
						next.push( keptNotice( affected, data.listUrl ) );
					}
					setNotices( next );
				},
				( e ) => {
					if ( ! scheduleSaved ) {
						setNotices( [
							{ tone: 'danger', text: errorMessage( e ) },
						] );
						return;
					}
					// The hours are already saved: say so, and keep the warning about appointments outside them.
					const next = [
						{
							tone: 'danger',
							text: sprintf(
								/* translators: %s: error message. */
								__(
									'Working hours saved, but the booking rules were not: %s',
									'mhm-slotiva'
								),
								errorMessage( e )
							),
						},
					];
					if ( affected > 0 ) {
						next.push( keptNotice( affected, data.listUrl ) );
					}
					setNotices( next );
				}
			)
			.finally( () => setSaving( false ) );
	};

	const afterException = ( text, outside ) => {
		const next = [ { tone: 'success', text } ];
		if ( outside.length > 0 ) {
			next.push( keptNotice( outside.length, data.listUrl ) );
		}
		setNotices( next );
	};

	const submitException = () => {
		if ( ! form.date ) {
			setFormError( __( 'Pick a date.', 'mhm-slotiva' ) );
			return;
		}
		const timed = form.type === 'open' || ! form.allDay;
		if ( timed ) {
			const from = toMinutes( form.from );
			const to = toMinutes( form.to );
			if ( from === null || to === null || from >= to ) {
				setFormError(
					form.type === 'open'
						? __(
								'Enter the extra opening hours: a start and a later end, on a 15-minute step.',
								'mhm-slotiva'
						  )
						: __(
								'Enter a start and a later end on a 15-minute step, or tick "All day".',
								'mhm-slotiva'
						  )
				);
				return;
			}
		}
		setFormError( '' );
		addException( {
			date: form.date,
			type: form.type,
			from: timed ? form.from : '',
			to: timed ? form.to : '',
			note: form.note,
		} ).then(
			( row ) => {
				const { outside_hours: outside = [], ...item } = row;
				setExceptions( ( prev ) =>
					[ ...prev, item ].sort(
						( a, b ) =>
							a.date.localeCompare( b.date ) ||
							String( a.from_time || '' ).localeCompare(
								String( b.from_time || '' )
							)
					)
				);
				setForm( null );
				afterException(
					__( 'Exception added.', 'mhm-slotiva' ),
					outside
				);
			},
			( e ) => setFormError( errorMessage( e ) )
		);
	};

	const removeException = ( item ) =>
		deleteException( item.id ).then(
			( r ) => {
				setExceptions( ( prev ) =>
					prev.filter( ( x ) => x.id !== item.id )
				);
				afterException(
					__( 'Exception deleted.', 'mhm-slotiva' ),
					r.outside_hours || []
				);
			},
			( e ) =>
				setNotices( [ { tone: 'danger', text: errorMessage( e ) } ] )
		);

	if ( loadError ) {
		return (
			<div className="slotiva-schedule">
				<PageHeader
					level={ 1 }
					title={ __( 'Working hours', 'mhm-slotiva' ) }
				/>
				<Notice tone="danger">{ loadError }</Notice>
			</div>
		);
	}
	if ( ! days || ! rules ) {
		return (
			<div className="slotiva-schedule">
				<PageHeader
					level={ 1 }
					title={ __( 'Working hours', 'mhm-slotiva' ) }
				/>
				<p className="slotiva-muted">
					{ __( 'Loading…', 'mhm-slotiva' ) }
				</p>
			</div>
		);
	}

	const numberField = ( key, label, unit, help, extra = {} ) => (
		<div className="slotiva-rule">
			<label
				className="slotiva-rule__label"
				htmlFor={ `slotiva-${ key }` }
			>
				{ label }
			</label>
			<div className="slotiva-rule__control">
				<input
					id={ `slotiva-${ key }` }
					type="number"
					inputMode="numeric"
					min="0"
					value={ rules[ key ] }
					onChange={ ( e ) => setRule( key, e.target.value ) }
					{ ...extra }
				/>
				<span className="slotiva-muted">{ unit }</span>
			</div>
			{ help && <p className="slotiva-rule__help">{ help }</p> }
		</div>
	);

	const durations = DURATIONS.includes( Number( rules.durationDefault ) )
		? DURATIONS
		: [ ...DURATIONS, Number( rules.durationDefault ) ].sort(
				( a, b ) => a - b
		  );

	return (
		<div className="slotiva-schedule">
			<PageHeader
				level={ 1 }
				title={ __( 'Working hours', 'mhm-slotiva' ) }
			/>

			{ notices.map( ( n, i ) => (
				<Notice
					key={ i }
					tone={ n.tone }
					onDismiss={ () =>
						setNotices( ( prev ) =>
							prev.filter( ( x, j ) => j !== i )
						)
					}
					dismissLabel={ __( 'Dismiss this notice.', 'mhm-slotiva' ) }
				>
					{ n.text }
				</Notice>
			) ) }

			<div className="slotiva-schedule__grid">
				<div className="slotiva-schedule__main">
					<Widget
						level={ 2 }
						title={ __( 'Weekly schedule', 'mhm-slotiva' ) }
					>
						<p className="slotiva-widget-intro">
							{ __(
								'One or more ranges per day. A gap between ranges is a break.',
								'mhm-slotiva'
							) }
						</p>
						<div className="slotiva-week">
							{ days.map( ( d, i ) => (
								<div
									key={ i }
									className={ `slotiva-day${
										d.open ? '' : ' is-closed'
									}` }
									role="group"
									aria-label={ names[ i ] }
								>
									<label
										className="slotiva-day__toggle"
										htmlFor={ `slotiva-day-${ i }` }
									>
										<input
											id={ `slotiva-day-${ i }` }
											type="checkbox"
											checked={ d.open }
											onChange={ () => toggleDay( i ) }
										/>
										<span className="slotiva-day__name">
											{ names[ i ] }
										</span>
									</label>
									{ d.open ? (
										<div className="slotiva-day__ranges">
											{ d.ranges.map( ( x, k ) => (
												<span
													key={ k }
													className="slotiva-range"
												>
													<input
														type="time"
														step="900"
														value={ x.from }
														aria-label={ sprintf(
															/* translators: 1: day name, 2: range number. */
															__(
																'%1$s range %2$d start',
																'mhm-slotiva'
															),
															names[ i ],
															k + 1
														) }
														onChange={ ( e ) =>
															setRange(
																i,
																k,
																'from',
																e.target.value
															)
														}
													/>
													<span aria-hidden="true">
														–
													</span>
													<input
														type="time"
														step="900"
														value={
															x.to === '24:00'
																? '23:59'
																: x.to
														}
														title={
															x.to === '24:00'
																? __(
																		'Midnight (end of day)',
																		'mhm-slotiva'
																  )
																: undefined
														}
														aria-label={ sprintf(
															/* translators: 1: day name, 2: range number. */
															__(
																'%1$s range %2$d end',
																'mhm-slotiva'
															),
															names[ i ],
															k + 1
														) }
														onChange={ ( e ) =>
															setRange(
																i,
																k,
																'to',
																e.target.value
															)
														}
													/>
													<button
														type="button"
														className="slotiva-range__remove"
														aria-label={ sprintf(
															/* translators: 1: day name, 2: range number. */
															__(
																'Remove %1$s range %2$d',
																'mhm-slotiva'
															),
															names[ i ],
															k + 1
														) }
														onClick={ () =>
															removeRange( i, k )
														}
													>
														×
													</button>
												</span>
											) ) }
											<button
												type="button"
												className="button-link slotiva-day__add"
												aria-label={ sprintf(
													/* translators: %s: day name. */
													__(
														'Add a range to %s',
														'mhm-slotiva'
													),
													names[ i ]
												) }
												disabled={
													! nextRange( d.ranges )
												}
												onClick={ () => addRange( i ) }
											>
												{ __(
													'+ range',
													'mhm-slotiva'
												) }
											</button>
										</div>
									) : (
										<span className="slotiva-day__closed slotiva-muted">
											{ __( 'Closed', 'mhm-slotiva' ) }
										</span>
									) }
								</div>
							) ) }
						</div>
					</Widget>
				</div>

				<div className="slotiva-schedule__aside">
					<Widget
						level={ 2 }
						title={ __(
							'Closed days and extra hours',
							'mhm-slotiva'
						) }
					>
						<p className="slotiva-widget-intro">
							{ __(
								'Close a holiday, a day off or a few hours, or open extra hours on one date.',
								'mhm-slotiva'
							) }
						</p>
						{ exceptions.length > 0 ? (
							<ul
								className="slotiva-exceptions"
								aria-label={ __( 'Exceptions', 'mhm-slotiva' ) }
							>
								{ exceptions.map( ( item ) => (
									<li
										key={ item.id }
										className="slotiva-exception"
									>
										<span className="slotiva-exception__when">
											{ exceptionWhen( item ) }
											{ item.note && (
												<span className="slotiva-muted">
													{ ` (${ item.note })` }
												</span>
											) }
										</span>
										<StatusBadge
											tone={
												item.type === 'open'
													? 'success'
													: 'neutral'
											}
										>
											{ item.type === 'open'
												? __(
														'Extra open',
														'mhm-slotiva'
												  )
												: __(
														'Closed',
														'mhm-slotiva'
												  ) }
										</StatusBadge>
										<button
											type="button"
											className="button-link button-link-delete slotiva-exception__delete"
											aria-label={ sprintf(
												/* translators: %s: exception date and time. */
												__(
													'Delete %s',
													'mhm-slotiva'
												),
												exceptionWhen( item )
											) }
											onClick={ () =>
												removeException( item )
											}
										>
											{ __( 'Delete', 'mhm-slotiva' ) }
										</button>
									</li>
								) ) }
							</ul>
						) : (
							<ul
								className="slotiva-exceptions"
								aria-label={ __( 'Exceptions', 'mhm-slotiva' ) }
							>
								<li className="slotiva-muted slotiva-exception">
									{ __(
										'No closed days or extra hours yet.',
										'mhm-slotiva'
									) }
								</li>
							</ul>
						) }

						{ form ? (
							<div className="slotiva-exception-form">
								<div className="slotiva-exception-form__fields">
									<p className="slotiva-field">
										<label htmlFor="slotiva-exc-type">
											{ __( 'Type', 'mhm-slotiva' ) }
										</label>
										<select
											id="slotiva-exc-type"
											value={ form.type }
											onChange={ ( e ) =>
												setForm( {
													...form,
													type: e.target.value,
												} )
											}
										>
											<option value="closed">
												{ __(
													'Closed',
													'mhm-slotiva'
												) }
											</option>
											<option value="open">
												{ __(
													'Extra open hours',
													'mhm-slotiva'
												) }
											</option>
										</select>
									</p>
									<p className="slotiva-field">
										<label htmlFor="slotiva-exc-date">
											{ __( 'Date', 'mhm-slotiva' ) }
										</label>
										<input
											id="slotiva-exc-date"
											type="date"
											min={ data.today }
											value={ form.date }
											onChange={ ( e ) =>
												setForm( {
													...form,
													date: e.target.value,
												} )
											}
										/>
									</p>
									{ ( form.type === 'open' ||
										! form.allDay ) && (
										<>
											<p className="slotiva-field">
												<label htmlFor="slotiva-exc-from">
													{ __(
														'From',
														'mhm-slotiva'
													) }
												</label>
												<input
													id="slotiva-exc-from"
													type="time"
													step="900"
													value={ form.from }
													onChange={ ( e ) =>
														setForm( {
															...form,
															from: e.target
																.value,
														} )
													}
												/>
											</p>
											<p className="slotiva-field">
												<label htmlFor="slotiva-exc-to">
													{ __(
														'To',
														'mhm-slotiva'
													) }
												</label>
												<input
													id="slotiva-exc-to"
													type="time"
													step="900"
													value={ form.to }
													onChange={ ( e ) =>
														setForm( {
															...form,
															to: e.target.value,
														} )
													}
												/>
											</p>
										</>
									) }
									<p className="slotiva-field">
										<label htmlFor="slotiva-exc-note">
											{ __(
												'Note (optional)',
												'mhm-slotiva'
											) }
										</label>
										<input
											id="slotiva-exc-note"
											type="text"
											maxLength="255"
											value={ form.note }
											onChange={ ( e ) =>
												setForm( {
													...form,
													note: e.target.value,
												} )
											}
										/>
									</p>
								</div>
								{ form.type === 'closed' && (
									<p className="slotiva-check">
										<input
											id="slotiva-exc-allday"
											type="checkbox"
											checked={ form.allDay }
											onChange={ ( e ) =>
												setForm( {
													...form,
													allDay: e.target.checked,
												} )
											}
										/>
										<label htmlFor="slotiva-exc-allday">
											{ __( 'All day', 'mhm-slotiva' ) }
										</label>
									</p>
								) }
								{ formError && (
									<Notice tone="danger">{ formError }</Notice>
								) }
								<div className="slotiva-exception-form__actions">
									<Button
										variant="primary"
										onClick={ submitException }
									>
										{ __( 'Add', 'mhm-slotiva' ) }
									</Button>
									<Button
										variant="plain"
										onClick={ () => {
											setForm( null );
											setFormError( '' );
										} }
									>
										{ __( 'Cancel', 'mhm-slotiva' ) }
									</Button>
								</div>
							</div>
						) : (
							<p className="slotiva-exceptions__add">
								<Button
									variant="secondary"
									onClick={ () =>
										setForm( { ...EMPTY_FORM } )
									}
								>
									{ __(
										'+ Add closed or extra open time',
										'mhm-slotiva'
									) }
								</Button>
							</p>
						) }
					</Widget>
					<Widget
						level={ 2 }
						title={ __( 'Booking rules', 'mhm-slotiva' ) }
					>
						<div className="slotiva-rule">
							<label
								className="slotiva-rule__label"
								htmlFor="slotiva-durationDefault"
							>
								{ __( 'Appointment length', 'mhm-slotiva' ) }
							</label>
							<div className="slotiva-rule__control">
								<select
									id="slotiva-durationDefault"
									value={ String( rules.durationDefault ) }
									onChange={ ( e ) =>
										setRule(
											'durationDefault',
											e.target.value
										)
									}
								>
									{ durations.map( ( m ) => (
										<option key={ m } value={ String( m ) }>
											{ sprintf(
												/* translators: %d: minutes. */
												__(
													'%d minutes',
													'mhm-slotiva'
												),
												m
											) }
										</option>
									) ) }
								</select>
							</div>
							<p className="slotiva-rule__help">
								{ __(
									'A product can set its own length on its Slotiva tab.',
									'mhm-slotiva'
								) }{ ' ' }
								{ __(
									"Number of hours per order and pricing are set on each product's Slotiva tab.",
									'mhm-slotiva'
								) }
							</p>
						</div>
						{ numberField(
							'leadHours',
							__( 'Earliest booking', 'mhm-slotiva' ),
							__( 'hours ahead', 'mhm-slotiva' )
						) }
						{ numberField(
							'horizonDays',
							__( 'Latest booking', 'mhm-slotiva' ),
							__( 'days ahead', 'mhm-slotiva' ),
							null,
							{ min: '1', max: '62' }
						) }
						{ numberField(
							'bufferMin',
							__( 'Break between appointments', 'mhm-slotiva' ),
							__( 'minutes', 'mhm-slotiva' ),
							null,
							{ step: '15' }
						) }
						{ numberField(
							'bankHoldHours',
							__(
								'Payment time for bank transfers',
								'mhm-slotiva'
							),
							__( 'hours', 'mhm-slotiva' ),
							__(
								'When it runs out, the order is cancelled and the time opens again.',
								'mhm-slotiva'
							),
							{ min: '1' }
						) }
						{ numberField(
							'cardHoldMin',
							__( 'Hold time for card payments', 'mhm-slotiva' ),
							__( 'minutes', 'mhm-slotiva' ),
							__(
								"If the payment does not arrive in this time, the time opens again (keep it longer than the payment provider's own time limit). The order itself is not cancelled.",
								'mhm-slotiva'
							),
							{ min: '15' }
						) }
						<div className="slotiva-rule slotiva-rule--reminder">
							<p className="slotiva-check">
								<input
									id="slotiva-reminderOn"
									type="checkbox"
									checked={ !! rules.reminderOn }
									onChange={ ( e ) =>
										setRule(
											'reminderOn',
											e.target.checked
										)
									}
								/>
								<label htmlFor="slotiva-reminderOn">
									{ __(
										'Send a reminder email before the appointment',
										'mhm-slotiva'
									) }
								</label>
							</p>
							<div className="slotiva-rule__control">
								<input
									id="slotiva-reminderHours"
									type="number"
									inputMode="numeric"
									min="1"
									aria-label={ __(
										'Reminder: hours before the appointment',
										'mhm-slotiva'
									) }
									value={ rules.reminderHours }
									disabled={ ! rules.reminderOn }
									onChange={ ( e ) =>
										setRule(
											'reminderHours',
											e.target.value
										)
									}
								/>
								<span className="slotiva-muted">
									{ __( 'hours before', 'mhm-slotiva' ) }
								</span>
							</div>
						</div>
					</Widget>
				</div>
			</div>

			<div className="slotiva-schedule__footer">
				<Button
					variant="primary"
					onClick={ save }
					disabled={ saving }
					aria-busy={ saving ? 'true' : undefined }
				>
					{ __( 'Save changes', 'mhm-slotiva' ) }
				</Button>
				<p className="slotiva-muted">
					{ sprintf(
						/* translators: %s: site time zone label. */
						__(
							'Time zone: %s (Settings → General). Slotiva releases expired holds itself.',
							'mhm-slotiva'
						),
						data.tzLabel || data.timezone || ''
					) }
				</p>
			</div>
		</div>
	);
}
