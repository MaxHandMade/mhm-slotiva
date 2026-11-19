/**
 * Customer-facing preview maths for the Working hours step. Appointment starts follow the appointment length:
 * a range 16:00–22:00 with 60-minute appointments starts at 16:00, 17:00 … 21:00.
 */
import { fromMinutes, toMinutes } from '../schedule/validate';

/**
 * Start times of one day.
 *
 * @param {Array<{from:string,to:string}>} day         Ranges of the day.
 * @param {number}                         durationMin Appointment length in minutes.
 * @return {string[]} "HH:MM" starts, ascending. Ranges that are not valid 15-minute times are skipped.
 */
export function startsFor( day, durationMin ) {
	if ( ! Number.isInteger( durationMin ) || durationMin < 1 ) {
		return [];
	}
	const starts = [];
	for ( const range of day ) {
		const from = toMinutes( range.from );
		const to = toMinutes( range.to );
		if ( from === null || to === null ) {
			continue;
		}
		for ( let t = from; t + durationMin <= to; t += durationMin ) {
			starts.push( t );
		}
	}
	return starts.sort( ( a, b ) => a - b ).map( fromMinutes );
}

/**
 * Week totals.
 *
 * @param {Array<Array<{from:string,to:string}>>} days        Seven days (closed = empty list).
 * @param {number}                                durationMin Appointment length in minutes.
 * @return {{perDayMax:number, perWeek:number, lastStart:string}} Busiest day, whole week, and the last start of the
 *                                                                 first open day ('' when nothing is open).
 */
export function weeklyCount( days, durationMin ) {
	const all = days.map( ( d ) => startsFor( d, durationMin ) );
	const firstOpen = all.find( ( s ) => s.length > 0 );
	return {
		perDayMax: Math.max( 0, ...all.map( ( s ) => s.length ) ),
		perWeek: all.reduce( ( sum, s ) => sum + s.length, 0 ),
		lastStart: firstOpen ? firstOpen[ firstOpen.length - 1 ] : '',
	};
}
