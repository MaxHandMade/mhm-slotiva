/**
 * Admin REST client (`slotiva/v1/admin`, Task 13) on top of ui-core's createApiClient, plus labels and formatting
 * shared by the Appointments and Detail screens.
 *
 * ui-core's client only has get/post/del, so `put()` is a local adapter on the same transport (api-fetch, whose nonce
 * middleware the ui-core page loader installs).
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { createApiClient } from '../../../vendor/mhm/ui-core/src-react/api/createApiClient';

export const NAMESPACE = '/slotiva/v1/admin';

const client = createApiClient( NAMESPACE, apiFetch );

/**
 * PUT on the admin namespace, same transport and nonce as the ui-core client.
 *
 * @param {string} path Route under the namespace, e.g. '/schedule'.
 * @param {Object} body JSON body.
 * @return {Promise<Object>} Response body.
 */
export function put( path, body ) {
	const suffix = path.startsWith( '/' ) ? path : `/${ path }`;
	return apiFetch( {
		path: `${ NAMESPACE }${ suffix }`,
		method: 'PUT',
		data: body,
	} );
}

export const api = {
	get: client.get,
	post: client.post,
	del: client.del,
	put,
};

/** Page bootstrap data printed by Admin\Menu (`window.slotivaAdmin`). */
export function pageData() {
	return ( typeof window !== 'undefined' && window.slotivaAdmin ) || {};
}

/** Statuses an admin can still act on (cancel, change time). */
export const ACTIVE = [ 'held', 'confirmed' ];

/** Under this many minutes to the start the detail screen warns (spec §6: 24 hours). */
export const WARN_MINUTES = 1440;

export const listAppointments = ( { tab, page, perPage, order } ) => {
	const params = { tab, page: String( page ), per_page: String( perPage ) };
	if ( order ) {
		params.order = order;
	}
	return api.get( '/appointments', params );
};

export const getAppointment = ( id ) => api.get( `/appointments/${ id }` );

/**
 * POST /appointments. Blank optional fields are left out: `customer.email` has `format: email`, so "" would be a 400.
 *
 * @param {Object}        input
 * @param {number|string} input.productId Product id.
 * @param {string}        input.slot      Slot start (UTC ISO).
 * @param {string}        input.name      Customer name (required).
 * @param {string}        [input.phone]   Phone.
 * @param {string}        [input.email]   E-mail.
 * @param {boolean}       input.notify    Send the confirmation e-mail.
 * @return {Promise<Object>} Created appointment.
 */
export function createAppointment( {
	productId,
	slot,
	name,
	phone,
	email,
	notify,
} ) {
	const customer = { name: String( name || '' ).trim() };
	if ( String( phone || '' ).trim() !== '' ) {
		customer.phone = String( phone ).trim();
	}
	if ( String( email || '' ).trim() !== '' ) {
		customer.email = String( email ).trim();
	}
	return api.post( '/appointments', {
		product_id: Number( productId ),
		slot,
		customer,
		notify: !! notify,
	} );
}

export const reschedule = ( id, { start, countChange, notify, sequence } ) =>
	api.post( `/appointments/${ id }/reschedule`, {
		start,
		count_change: !! countChange,
		notify: !! notify,
		expect_sequence: Number( sequence ),
	} );

export const setStatus = ( id, status, notify = true ) =>
	api.post( `/appointments/${ id }/status`, { status, notify } );

export const extendHold = ( id, hours ) =>
	api.post( `/appointments/${ id }/extend-hold`, { hours: Number( hours ) } );

/**
 * GET /availability (15-minute admin grid). `product` may be left out when `exclude` is set.
 *
 * @param {Object} args
 * @param {number} [args.product]   Product id.
 * @param {number} [args.variation] Variation id.
 * @param {string} args.day         Local Y-m-d.
 * @param {number} [args.exclude]   Reservation whose own units count as free.
 * @return {Promise<Object>} { timezone, tz_label, day, step, slots }.
 */
export function availability( { product, variation, day, exclude } ) {
	const params = { day };
	if ( product ) {
		params.product = String( product );
	}
	if ( variation ) {
		params.variation = String( variation );
	}
	if ( exclude ) {
		params.exclude_reservation = String( exclude );
	}
	return api.get( '/availability', params );
}

export const getSchedule = () => api.get( '/schedule' );

/**
 * PUT /schedule. The route replaces the whole week and needs all 7 days (0 = Monday), else 400.
 *
 * @param {Array<Array<{from:string,to:string}>>} days Seven lists of ranges.
 * @return {Promise<Object>} { days, outside_hours, affected }.
 */
export const saveSchedule = ( days ) => put( '/schedule', { days } );

export const getRules = () => api.get( '/rules' );

/**
 * PUT /rules with only the given fields; the server keeps the rest.
 *
 * @param {Object} fields camelCase rule fields.
 * @return {Promise<Object>} The full rules.
 */
export const saveRules = ( fields ) => put( '/rules', fields );

export const listExceptions = () => api.get( '/exceptions' );

/**
 * POST /exceptions. Times are sent only together; neither means the whole day. A blank note is left out.
 *
 * @param {Object} input
 * @param {string} input.date   Local Y-m-d.
 * @param {string} input.type   'closed' | 'open'.
 * @param {string} [input.from] "HH:MM".
 * @param {string} [input.to]   "HH:MM".
 * @param {string} [input.note] Note.
 * @return {Promise<Object>} Created row + outside_hours.
 */
export function addException( { date, type, from, to, note } ) {
	const body = { date, type };
	if ( from && to ) {
		body.from_time = from;
		body.to_time = to;
	}
	if ( String( note || '' ).trim() !== '' ) {
		body.note = String( note ).trim();
	}
	return api.post( '/exceptions', body );
}

export const deleteException = ( id ) => api.del( `/exceptions/${ id }` );

/**
 * CSV download link. The route takes its nonce as `_wpnonce` in the query, so this is a plain link, not a fetch.
 *
 * @param {string} restRoot rest_url() (may already carry `?rest_route=`).
 * @param {string} nonce    wp_rest nonce.
 * @return {string} URL.
 */
export function exportUrl( restRoot, nonce ) {
	const root = String( restRoot || '/wp-json/' );
	const route = 'slotiva/v1/admin/export.csv';
	const sep = root.includes( '?' ) ? '&' : '?';
	const base = root.includes( '?' )
		? `${ root }${ route }`
		: `${ root.replace( /\/?$/, '/' ) }${ route }`;
	return `${ base }${ sep }_wpnonce=${ encodeURIComponent( nonce || '' ) }`;
}

export function statusLabel( status ) {
	const labels = {
		held: __( 'Held', 'mhm-slotiva' ),
		confirmed: __( 'Confirmed', 'mhm-slotiva' ),
		completed: __( 'Completed', 'mhm-slotiva' ),
		no_show: __( 'No-show', 'mhm-slotiva' ),
		cancelled: __( 'Cancelled', 'mhm-slotiva' ),
		expired: __( 'Expired', 'mhm-slotiva' ),
		conflict: __( 'Conflict', 'mhm-slotiva' ),
		superseded: __( 'Superseded', 'mhm-slotiva' ),
	};
	return labels[ status ] || status;
}

export function statusTone( status ) {
	return (
		{
			held: 'warning',
			confirmed: 'success',
			completed: 'neutral',
			no_show: 'danger',
			cancelled: 'danger',
			expired: 'neutral',
			conflict: 'danger',
			superseded: 'neutral',
		}[ status ] || 'neutral'
	);
}

export function paymentLabel( state ) {
	const labels = {
		pending: __( 'Pending', 'mhm-slotiva' ),
		paid: __( 'Paid', 'mhm-slotiva' ),
		on_site: __( 'Pay on site', 'mhm-slotiva' ),
		awaiting_transfer: __( 'Awaiting transfer', 'mhm-slotiva' ),
		refunded: __( 'Refunded', 'mhm-slotiva' ),
		manual: __( 'Manual', 'mhm-slotiva' ),
	};
	return labels[ state ] || state;
}

export function paymentTone( state ) {
	return (
		{
			pending: 'warning',
			paid: 'success',
			on_site: 'info',
			awaiting_transfer: 'warning',
			refunded: 'neutral',
			manual: 'neutral',
		}[ state ] || 'neutral'
	);
}

/**
 * User-facing text for a REST error (codes from Task 13). Unknown codes fall back to the server message.
 *
 * @param {Object} error api-fetch rejection ({ code, message, data }).
 * @return {string} Message.
 */
export function errorMessage( error ) {
	const code = error && error.code;
	const known = {
		slotiva_slot_taken: __(
			'That time was just taken. Pick another time.',
			'mhm-slotiva'
		),
		slotiva_stale: __(
			'This appointment was changed elsewhere. The latest version is shown now; check it and try again.',
			'mhm-slotiva'
		),
		slotiva_busy: __(
			'Another change to this order is in progress. Try again in a moment.',
			'mhm-slotiva'
		),
		slotiva_invalid_slot: __(
			'That time cannot be booked: it is in the past, off the 15-minute grid or outside opening hours.',
			'mhm-slotiva'
		),
		slotiva_invalid_state: __(
			'This appointment cannot be changed in its current state.',
			'mhm-slotiva'
		),
		slotiva_not_extendable: __(
			'The payment window of this appointment cannot be extended.',
			'mhm-slotiva'
		),
		slotiva_not_found: __(
			'The appointment or service was not found.',
			'mhm-slotiva'
		),
		// Working hours, exceptions and rules (PUT /schedule, POST /exceptions, PUT /rules).
		slotiva_overlap: __(
			'Ranges on the same day must not overlap.',
			'mhm-slotiva'
		),
		slotiva_invalid_time: __(
			'Times must be on a 15-minute step (for example 09:00, 09:15, 09:30).',
			'mhm-slotiva'
		),
		slotiva_invalid_range: __(
			'A range must end after it starts.',
			'mhm-slotiva'
		),
		slotiva_invalid_step: __(
			'Lengths and breaks must be multiples of 15 minutes.',
			'mhm-slotiva'
		),
		slotiva_invalid_value: __(
			'One of the values is out of range. Check the numbers and try again.',
			'mhm-slotiva'
		),
	};
	if ( code && known[ code ] ) {
		return known[ code ];
	}
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}
	return __( 'Something went wrong. Try again.', 'mhm-slotiva' );
}

function locale() {
	const lang =
		typeof document !== 'undefined' ? document.documentElement.lang : '';
	return lang || undefined;
}

function fmt( date, options ) {
	try {
		return new Intl.DateTimeFormat( locale(), {
			timeZone: 'UTC',
			...options,
		} ).format( date );
	} catch ( e ) {
		return new Intl.DateTimeFormat( undefined, {
			timeZone: 'UTC',
			...options,
		} ).format( date );
	}
}

const ymdDate = ( ymd ) => {
	const [ y, m, d ] = String( ymd ).split( '-' ).map( Number );
	return new Date( Date.UTC( y, m - 1, d, 12 ) );
};

/**
 * Labels for a local calendar day (`Y-m-d`), formatted in UTC so the date never shifts.
 *
 * @param {string} ymd Local date.
 * @return {{dow:string,num:string,short:string,long:string}} Labels.
 */
export function dayLabels( ymd ) {
	const date = ymdDate( ymd );
	return {
		dow: fmt( date, { weekday: 'short' } ),
		num: String( date.getUTCDate() ),
		short: fmt( date, {
			day: 'numeric',
			month: 'short',
			weekday: 'short',
		} ),
		long: fmt( date, { day: 'numeric', month: 'long', weekday: 'long' } ),
	};
}

/**
 * `Y-m-d` plus n days.
 *
 * @param {string} ymd  Local date.
 * @param {number} days Days to add (may be negative).
 * @return {string} Local date.
 */
export function addDays( ymd, days ) {
	const date = ymdDate( ymd );
	date.setUTCDate( date.getUTCDate() + days );
	return date.toISOString().slice( 0, 10 );
}

/**
 * Wall-clock end label: start "HH:MM" plus the appointment length (ends_at − starts_at). No time zone maths needed.
 *
 * @param {Object} appt AppointmentDTO.
 * @return {string} "HH:MM".
 */
export function endTime( appt ) {
	const minutes = Math.round(
		( Date.parse( appt.ends_at ) - Date.parse( appt.starts_at ) ) / 60000
	);
	const [ h, m ] = String( appt.local_time ).split( ':' ).map( Number );
	const total = ( ( ( h * 60 + m + minutes ) % 1440 ) + 1440 ) % 1440;
	return `${ String( Math.floor( total / 60 ) ).padStart(
		2,
		'0'
	) }:${ String( total % 60 ).padStart( 2, '0' ) }`;
}

/**
 * "HH:MM–HH:MM".
 *
 * @param {Object} appt AppointmentDTO.
 * @return {string} Range.
 */
export const timeRange = ( appt ) =>
	`${ appt.local_time }–${ endTime( appt ) }`;

/**
 * Time left to the start, for the detail screen ("3 d 4 h", "5 h 20 min", "Started").
 *
 * @param {number} minutes minutes_to_start (negative once started).
 * @return {string} Text.
 */
export function timeToStart( minutes ) {
	if ( minutes <= 0 ) {
		return __( 'Already started or past', 'mhm-slotiva' );
	}
	const d = Math.floor( minutes / 1440 );
	const h = Math.floor( ( minutes % 1440 ) / 60 );
	const m = minutes % 60;
	if ( d > 0 ) {
		return sprintf(
			/* translators: 1: days, 2: hours. */
			__( '%1$d d %2$d h', 'mhm-slotiva' ),
			d,
			h
		);
	}
	return sprintf(
		/* translators: 1: hours, 2: minutes. */
		__( '%1$d h %2$d min', 'mhm-slotiva' ),
		h,
		m
	);
}
