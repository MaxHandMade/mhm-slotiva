/**
 * Step 2 (artboard Step2): working hours with a live customer preview. A day row edits ONE range; days with more
 * ranges (lunch break preset, existing schedule) show them read-only and are saved untouched.
 */
import { _n, __, sprintf } from '@wordpress/i18n';

import { api, pageData } from '../../shared/api';
import { dayNames } from '../../shared/hours';
import { toMinutes, weekError } from '../../schedule/validate';
import { PRESETS, presetLabel } from '../presets';
import { startsFor, weeklyCount } from '../preview';
import PreviewPanel from '../ui/PreviewPanel';

export { dayNames };

const DEFAULT_RANGE = { from: '09:00', to: '18:00' };
// <input type="time"> cannot show 24:00: it is displayed as 23:59, and an end typed as 00:00 or 23:59 is midnight.
const MIDNIGHT_INPUTS = [ '00:00', '23:59' ];

/**
 * Wizard day state from the API schedule. `ranges` is kept while a day is switched off so it can come back.
 *
 * @param {Array<Array<{from:string,to:string}>>} days Seven days.
 * @return {Array<{open:boolean,ranges:Array}>} Day state.
 */
export function daysFromApi( days ) {
	return Array.from( { length: 7 }, ( _, i ) => {
		const ranges = ( days[ i ] || [] ).map( ( r ) => ( { ...r } ) );
		return { open: ranges.length > 0, ranges };
	} );
}

const toApi = ( days ) => days.map( ( d ) => ( d.open ? d.ranges : [] ) );

/**
 * Continue handler: PUT /schedule {days} with all seven days (the API replaces the whole week).
 *
 * @param {Object} wiz Wizard state.
 */
export async function saveHours( wiz ) {
	const problem = weekError( wiz.days, dayNames( 'long' ) );
	if ( problem ) {
		throw new Error( problem );
	}
	// Setup with no open day would end on "ready" with nothing bookable. Only the wizard requires one; the Working hours
	// screen may still close every day (holidays).
	if ( ! wiz.days.some( ( d ) => d.open && d.ranges.length > 0 ) ) {
		throw new Error(
			__(
				'Open at least one day, or start from a pattern above.',
				'mhm-slotiva'
			)
		);
	}
	await api.put( '/schedule', { days: toApi( wiz.days ) } );
}

const shown = ( v, isEnd ) => ( isEnd && v === '24:00' ? '23:59' : v );

function TimeInput( { label, value, isEnd, onChange } ) {
	return (
		<input
			type="time"
			step="900"
			aria-label={ label }
			value={ shown( value, isEnd ) }
			onChange={ ( e ) => {
				const v = e.target.value;
				onChange(
					isEnd && MIDNIGHT_INPUTS.includes( v ) ? '24:00' : v
				);
			} }
		/>
	);
}

function hoursLabel( ranges ) {
	const minutes = ranges.reduce( ( sum, r ) => {
		const a = toMinutes( r.from );
		const b = toMinutes( r.to );
		return a === null || b === null || b <= a ? sum : sum + b - a;
	}, 0 );
	const h = Math.round( ( minutes / 60 ) * 10 ) / 10;
	return sprintf(
		/* translators: %s: number of hours in a day. */
		__( '%s h', 'mhm-slotiva' ),
		String( h )
	);
}

function Preview( { wiz } ) {
	const apiDays = toApi( wiz.days );
	const counts = weeklyCount( apiDays, wiz.durationMin );
	const short = dayNames( 'short' );
	const lang = document.documentElement.lang || undefined;

	// The next five open days from today.
	const tiles = [];
	const today = new Date();
	for ( let i = 0; i < 14 && tiles.length < 5; i++ ) {
		const d = new Date(
			today.getFullYear(),
			today.getMonth(),
			today.getDate() + i
		);
		const idx = ( d.getDay() + 6 ) % 7;
		if ( startsFor( apiDays[ idx ], wiz.durationMin ).length > 0 ) {
			tiles.push( { key: i, name: short[ idx ], num: d.getDate() } );
		}
	}
	const firstOpen = apiDays.findIndex(
		( d ) => startsFor( d, wiz.durationMin ).length > 0
	);
	const starts =
		firstOpen < 0 ? [] : startsFor( apiDays[ firstOpen ], wiz.durationMin );
	const count = ( n ) =>
		sprintf(
			/* translators: %d: number of bookable start times (not hours of work). */
			_n( '%d start time', '%d start times', n, 'mhm-slotiva' ),
			n
		);

	return (
		<PreviewPanel
			title={ __( 'Customer sees at checkout', 'mhm-slotiva' ) }
		>
			{ tiles.length > 0 ? (
				<>
					<div className="slotiva-hours__tiles" lang={ lang }>
						{ tiles.map( ( t, i ) => (
							<div
								key={ t.key }
								className={ i === 0 ? 'is-first' : '' }
							>
								<span>{ t.name }</span>
								<strong>{ t.num }</strong>
							</div>
						) ) }
					</div>
					<div className="slotiva-hours__starts">
						{ starts.slice( 0, 9 ).map( ( s ) => (
							<span key={ s }>{ s }</span>
						) ) }
					</div>
				</>
			) : (
				<p className="slotiva-step__note">
					{ __( 'No open days yet.', 'mhm-slotiva' ) }
				</p>
			) }
			<div className="slotiva-hours__stats">
				<div>
					<span>{ __( 'Per day', 'mhm-slotiva' ) }</span>
					<strong>{ count( counts.perDayMax ) }</strong>
				</div>
				<div>
					<span>{ __( 'Per week', 'mhm-slotiva' ) }</span>
					<strong>{ count( counts.perWeek ) }</strong>
				</div>
				<div>
					<span>{ __( 'Last start', 'mhm-slotiva' ) }</span>
					<strong>{ counts.lastStart || '–' }</strong>
				</div>
			</div>
		</PreviewPanel>
	);
}

export default function Hours( { wiz, update } ) {
	const names = dayNames( 'long' );
	const { scheduleUrl } = pageData();

	const setDay = ( i, patch ) =>
		update( {
			preset: null,
			days: wiz.days.map( ( d, k ) =>
				k === i ? { ...d, ...patch } : d
			),
		} );
	const toggle = ( i ) => {
		const d = wiz.days[ i ];
		if ( d.open ) {
			setDay( i, { open: false } );
		} else {
			const ranges = d.ranges.length
				? d.ranges
				: [ { ...DEFAULT_RANGE } ];
			setDay( i, { open: true, ranges } );
		}
	};
	const setRange = ( i, key, value ) => {
		const ranges = [ { ...wiz.days[ i ].ranges[ 0 ], [ key ]: value } ];
		setDay( i, { ranges } );
	};
	const applyPreset = ( preset ) =>
		update( { preset: preset.id, days: daysFromApi( preset.days ) } );

	return (
		<div className="slotiva-hours">
			<div className="slotiva-step">
				<div>
					<h1>{ __( 'When do you work?', 'mhm-slotiva' ) }</h1>
					<p className="slotiva-step__lead">
						{ __(
							'Start from a ready-made pattern, then adjust the days.',
							'mhm-slotiva'
						) }
					</p>
				</div>

				<div
					className="slotiva-hours__presets"
					role="group"
					aria-label={ __( 'Ready-made patterns', 'mhm-slotiva' ) }
				>
					{ PRESETS.map( ( p ) => (
						<button
							key={ p.id }
							type="button"
							aria-pressed={ wiz.preset === p.id }
							className={
								wiz.preset === p.id ? 'is-checked' : undefined
							}
							onClick={ () => applyPreset( p ) }
						>
							{ presetLabel( p.id ) }
						</button>
					) ) }
				</div>

				<div className="slotiva-hours__days">
					{ wiz.days.map( ( d, i ) => (
						<div
							key={ names[ i ] }
							className={ `slotiva-hours__day${
								d.open ? '' : ' is-closed'
							}` }
						>
							<label
								htmlFor={ `slotiva-day-${ i }` }
								className="slotiva-hours__name"
							>
								<input
									id={ `slotiva-day-${ i }` }
									type="checkbox"
									checked={ d.open }
									onChange={ () => toggle( i ) }
								/>
								{ names[ i ] }
							</label>
							{ ! d.open && (
								<span className="slotiva-hours__closed">
									{ __( 'Closed', 'mhm-slotiva' ) }
								</span>
							) }
							{ d.open && d.ranges.length === 1 && (
								<>
									<span className="slotiva-hours__range">
										<TimeInput
											label={ sprintf(
												/* translators: %s: day name. */
												__( '%s start', 'mhm-slotiva' ),
												names[ i ]
											) }
											value={ d.ranges[ 0 ].from }
											onChange={ ( v ) =>
												setRange( i, 'from', v )
											}
										/>
										{ ' – ' }
										<TimeInput
											label={ sprintf(
												/* translators: %s: day name. */
												__( '%s end', 'mhm-slotiva' ),
												names[ i ]
											) }
											value={ d.ranges[ 0 ].to }
											isEnd
											onChange={ ( v ) =>
												setRange( i, 'to', v )
											}
										/>
									</span>
									<span className="slotiva-hours__total">
										{ hoursLabel( d.ranges ) }
									</span>
								</>
							) }
							{ d.open && d.ranges.length > 1 && (
								<>
									<span className="slotiva-hours__range">
										{ d.ranges
											.map(
												( r ) => `${ r.from }–${ r.to }`
											)
											.join( ', ' ) }
										<br />
										{ scheduleUrl ? (
											<a href={ scheduleUrl }>
												{ sprintf(
													/* translators: %d: number of ranges. */
													_n(
														'%d range · edit in Working hours',
														'%d ranges · edit in Working hours',
														d.ranges.length,
														'mhm-slotiva'
													),
													d.ranges.length
												) }
											</a>
										) : (
											sprintf(
												/* translators: %d: number of ranges. */
												_n(
													'%d range',
													'%d ranges',
													d.ranges.length,
													'mhm-slotiva'
												),
												d.ranges.length
											)
										) }
									</span>
									<span className="slotiva-hours__total">
										{ hoursLabel( d.ranges ) }
									</span>
								</>
							) }
						</div>
					) ) }
				</div>
				<p className="slotiva-step__note slotiva-step__note--plain">
					{ __(
						'A second range such as a lunch break, holidays and days off can be added later under Slotiva → Working hours.',
						'mhm-slotiva'
					) }
				</p>
			</div>
			<Preview wiz={ wiz } />
		</div>
	);
}
