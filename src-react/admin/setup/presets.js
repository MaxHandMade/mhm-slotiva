/**
 * Working-hours presets of the wizard (plan: Davranış sözleşmesi, Adım 2). `days` is the API shape:
 * index 0 = Monday … 6 = Sunday, each a list of {from,to} ranges (empty = closed).
 */
import { __ } from '@wordpress/i18n';

const r = ( from, to ) => ( { from, to } );
const week = ( count, ranges ) =>
	Array.from( { length: 7 }, ( _, i ) =>
		i < count ? ranges.map( ( x ) => ( { ...x } ) ) : []
	);

export const PRESETS = [
	{ id: 'weekdays', days: week( 5, [ r( '09:00', '18:00' ) ] ) },
	{ id: 'evening', days: week( 6, [ r( '16:00', '22:00' ) ] ) },
	{ id: 'everyday', days: week( 7, [ r( '10:00', '20:00' ) ] ) },
	{
		id: 'lunch',
		days: week( 5, [ r( '09:00', '12:00' ), r( '13:00', '18:00' ) ] ),
	},
];

export function presetLabel( id ) {
	switch ( id ) {
		case 'weekdays':
			return __( 'Weekdays 9–18', 'mhm-slotiva' );
		case 'evening':
			return __( 'Evenings 16–22, Mon–Sat', 'mhm-slotiva' );
		case 'everyday':
			return __( 'Every day 10–20', 'mhm-slotiva' );
		default:
			return __( 'Weekdays with a lunch break', 'mhm-slotiva' );
	}
}
