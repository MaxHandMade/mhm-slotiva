/**
 * Client-side checks for the Working hours screen. They mirror the server (WeeklySchedule::save, Rules::validate) so
 * the admin gets a precise message before any request; the server stays the authority and its errors are shown too.
 */
import { __, sprintf } from '@wordpress/i18n';

export const STEP = 15;

/**
 * "HH:MM" → minutes, or null when not a 15-minute wall-clock time. "24:00" is allowed as a day end.
 *
 * @param {string} value Time.
 * @return {number|null} Minutes since midnight.
 */
export function toMinutes( value ) {
	const m = /^(\d{1,2}):(\d{2})$/.exec( String( value || '' ) );
	if ( ! m ) {
		return null;
	}
	const total = Number( m[ 1 ] ) * 60 + Number( m[ 2 ] );
	if ( Number( m[ 2 ] ) > 59 || total > 1440 || total % STEP !== 0 ) {
		return null;
	}
	return total;
}

/**
 * Minutes → "HH:MM" (clamped to the day).
 *
 * @param {number} minutes Minutes since midnight.
 * @return {string} Time.
 */
export function fromMinutes( minutes ) {
	const t = Math.max( 0, Math.min( 1440, minutes ) );
	return `${ String( Math.floor( t / 60 ) ).padStart( 2, '0' ) }:${ String(
		t % 60
	).padStart( 2, '0' ) }`;
}

/**
 * First problem in the week, or ''.
 *
 * @param {Array<{open:boolean,ranges:Array<{from:string,to:string}>}>} days     Seven days.
 * @param {Array<string>}                                               dayNames Seven day names.
 * @return {string} Message.
 */
export function weekError( days, dayNames ) {
	for ( let i = 0; i < days.length; i++ ) {
		if ( ! days[ i ].open ) {
			continue;
		}
		const name = dayNames[ i ];
		const ranges = [];
		for ( const range of days[ i ].ranges ) {
			const from = toMinutes( range.from );
			const to = toMinutes( range.to );
			if ( from === null || to === null ) {
				return sprintf(
					/* translators: %s: day name. */
					__(
						'%s: times must be on a 15-minute step (for example 09:00, 09:15, 09:30).',
						'mhm-slotiva'
					),
					name
				);
			}
			if ( from >= to ) {
				return sprintf(
					/* translators: 1: day name, 2: range start, 3: range end. */
					__(
						'%1$s: %2$s–%3$s must end after it starts.',
						'mhm-slotiva'
					),
					name,
					range.from,
					range.to
				);
			}
			ranges.push( { from, to, label: `${ range.from }–${ range.to }` } );
		}
		ranges.sort( ( a, b ) => a.from - b.from );
		for ( let k = 1; k < ranges.length; k++ ) {
			if ( ranges[ k ].from < ranges[ k - 1 ].to ) {
				return sprintf(
					/* translators: 1: day name, 2: first range, 3: second range. */
					__(
						'%1$s: %2$s and %3$s overlap. Ranges on the same day must not overlap.',
						'mhm-slotiva'
					),
					name,
					ranges[ k - 1 ].label,
					ranges[ k ].label
				);
			}
		}
	}
	return '';
}

const whole = ( v ) => /^\d+$/.test( String( v ).trim() );

/**
 * First problem in the booking rules, or ''. Same limits as Rules::validate.
 *
 * @param {Object} rules Rule fields as typed (strings or numbers).
 * @return {string} Message.
 */
export function rulesError( rules ) {
	const n = ( key ) => Number( rules[ key ] );
	if (
		! whole( rules.durationDefault ) ||
		n( 'durationDefault' ) < STEP ||
		n( 'durationDefault' ) % STEP !== 0
	) {
		return __(
			'The appointment length must be a multiple of 15 minutes.',
			'mhm-slotiva'
		);
	}
	if ( ! whole( rules.leadHours ) ) {
		return __(
			'The earliest booking time must be a whole number of hours (0 or more).',
			'mhm-slotiva'
		);
	}
	if (
		! whole( rules.horizonDays ) ||
		n( 'horizonDays' ) < 1 ||
		n( 'horizonDays' ) > 62
	) {
		return __(
			'The latest booking day must be between 1 and 62 days ahead.',
			'mhm-slotiva'
		);
	}
	if ( ! whole( rules.bufferMin ) || n( 'bufferMin' ) % STEP !== 0 ) {
		return __(
			'The break between appointments must be 0 or a multiple of 15 minutes.',
			'mhm-slotiva'
		);
	}
	if ( ! whole( rules.bankHoldHours ) || n( 'bankHoldHours' ) < 1 ) {
		return __(
			'The bank transfer payment time must be at least 1 hour.',
			'mhm-slotiva'
		);
	}
	if ( ! whole( rules.cardHoldMin ) || n( 'cardHoldMin' ) < STEP ) {
		return __(
			'The card payment hold must be at least 15 minutes.',
			'mhm-slotiva'
		);
	}
	if ( ! whole( rules.reminderHours ) || n( 'reminderHours' ) < 1 ) {
		return __(
			'The reminder must be sent at least 1 hour before.',
			'mhm-slotiva'
		);
	}
	return '';
}
