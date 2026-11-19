/**
 * Week helpers shared by the setup wizard and the Appointments checklist (kept small: both bundles import it).
 */
import { __, sprintf } from '@wordpress/i18n';

// A known Monday: day names come from Intl in the page language (index 0 = Monday, as the API).
const MONDAY = '2026-11-16';

/**
 * Seven day names, Monday first, in the page language.
 *
 * @param {string} style Intl weekday style ('short' | 'long').
 * @return {string[]} Day names.
 */
export function dayNames( style ) {
	const lang = document.documentElement.lang || undefined;
	return Array.from( { length: 7 }, ( _, i ) =>
		new Intl.DateTimeFormat( lang, { weekday: style } ).format(
			new Date( `${ MONDAY }T12:00:00` ).getTime() + i * 86400000
		)
	);
}

/**
 * "Mon–Sat 16:00–22:00 · Sun closed": consecutive days with the same ranges are grouped.
 *
 * @param {Array<Array<{from:string,to:string}>>} days  Seven days from the API.
 * @param {string[]}                              names Short day names, Monday first.
 * @return {string} Summary.
 */
export function hoursSummary( days, names ) {
	const groups = [];
	days.forEach( ( ranges, i ) => {
		const sig = ranges.map( ( r ) => `${ r.from }–${ r.to }` ).join( ', ' );
		const last = groups[ groups.length - 1 ];
		if ( last && last.sig === sig ) {
			last.to = i;
		} else {
			groups.push( { sig, from: i, to: i } );
		}
	} );
	return groups
		.map( ( g ) => {
			const span =
				g.from === g.to
					? names[ g.from ]
					: `${ names[ g.from ] }–${ names[ g.to ] }`;
			return g.sig
				? `${ span } ${ g.sig }`
				: sprintf(
						/* translators: %s: day or day range, e.g. "Sun". */
						__( '%s closed', 'mhm-slotiva' ),
						span
				  );
		} )
		.join( ' · ' );
}
