/**
 * Availability requests, cart helpers and formatting for the checkout slot picker.
 */
import apiFetch from '@wordpress/api-fetch';

/** Store API namespace (StoreApiExtension::NS). */
export const NAMESPACE = 'slotiva';

/** Validation store key used to block "Place order" while no slot is chosen. */
export const VALIDATION_KEY = 'slotiva-slots';

/** WooCommerce data stores (strings so the picker does not bundle WooCommerce packages). */
export const VALIDATION_STORE = 'wc/store/validation';
export const NOTICES_STORE = 'core/notices';

/**
 * Notice contexts WooCommerce 11.2.1 uses for checkout POST errors: `processErrorResponse` falls back to
 * `wc/cart` for unknown codes (wc-blocks-data.js); `wc/checkout` is watched as well in case a later version changes it.
 */
export const NOTICE_CONTEXTS = [ 'wc/cart', 'wc/checkout' ];

/** Server error codes the picker handles itself (CheckoutFlow, SlotValidator). */
/** Selection is no longer valid: clear it, refresh availability, own message per code. */
export const REFRESH_CODES = [
	'slotiva_slot_taken',
	'slotiva_not_bookable',
	'slotiva_bad_format',
	'slotiva_duplicate',
];
/** Selection kept, own message. `slotiva_qty_mismatch` also re-syncs the cart quantity. */
export const MESSAGE_CODES = [
	'slotiva_busy',
	'slotiva_hold_quota',
	'slotiva_overlap',
	'slotiva_qty_mismatch',
	'slotiva_no_slots',
	'slotiva_count_range',
];

/** Checkout store: `disableCheckoutFor` keeps "Place order" disabled while the quantity update runs. */
export const CHECKOUT_STORE = 'wc/store/checkout';

/**
 * GET /slotiva/v1/availability.
 *
 * @param {Object} args
 * @param {number} args.product   Product id.
 * @param {number} args.variation Variation id (0 = none).
 * @param {string} [args.day]     Local Y-m-d; when set the response carries that day's free slots.
 * @return {Promise<{timezone:string,tz_label:string,days:Array,slots:Array}>} Response body.
 */
export function fetchAvailability( { product, variation, day } ) {
	const query = new URLSearchParams( { product: String( product ) } );
	if ( variation ) {
		query.set( 'variation', String( variation ) );
	}
	if ( day ) {
		query.set( 'day', day );
	}
	return apiFetch( {
		path: `/slotiva/v1/availability?${ query.toString() }`,
	} );
}

/**
 * First cart line whose Store API extension says it is bookable (v1 allows one).
 *
 * @param {Object} cart useStoreCart() data (cartItems) or a raw Store API cart (items).
 * @return {{item:Object, ext:Object}|null} Line and its `slotiva` extension data.
 */
export function findBookableLine( cart ) {
	const items = cart?.cartItems || cart?.items || [];
	for ( const item of items ) {
		const ext = item?.extensions?.[ NAMESPACE ];
		if ( ext && ext.bookable ) {
			return { item, ext };
		}
	}
	return null;
}

/**
 * Variation id for the availability request: the cart line id is the variation when it differs from the product.
 *
 * @param {Object} item Cart line.
 * @param {Object} ext  `slotiva` extension data.
 * @return {number} Variation id or 0.
 */
export function variationOf( item, ext ) {
	const id = Number( item?.id || 0 );
	return id && id !== Number( ext.product_id ) ? id : 0;
}

function locale() {
	const lang =
		typeof document !== 'undefined' ? document.documentElement.lang : '';
	return lang || undefined;
}

function format( date, options ) {
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

/**
 * Display parts for a local calendar date (`Y-m-d`); formatted in UTC so the date never shifts.
 *
 * @param {string} ymd Local date.
 * @return {{dow:string,num:string,mon:string,short:string,long:string}} Labels.
 */
export function dayParts( ymd ) {
	const [ y, m, d ] = ymd.split( '-' ).map( Number );
	const date = new Date( Date.UTC( y, m - 1, d, 12 ) );
	return {
		dow: format( date, { weekday: 'short' } ),
		num: String( d ),
		mon: format( date, { month: 'short' } ),
		short: format( date, {
			weekday: 'short',
			day: 'numeric',
			month: 'short',
		} ),
		long: format( date, {
			weekday: 'long',
			day: 'numeric',
			month: 'long',
		} ),
	};
}

/**
 * "HH:MM" + minutes → "HH:MM" (wall clock arithmetic; slots never cross midnight in v1 schedules).
 *
 * @param {string} label   Start label.
 * @param {number} minutes Duration.
 * @return {string} End label.
 */
export function endLabel( label, minutes ) {
	const [ h, m ] = String( label ).split( ':' ).map( Number );
	const total =
		( ( ( h * 60 + m + Number( minutes || 0 ) ) % 1440 ) + 1440 ) % 1440;
	return `${ String( Math.floor( total / 60 ) ).padStart(
		2,
		'0'
	) }:${ String( total % 60 ).padStart( 2, '0' ) }`;
}

/**
 * Store API price (minor units) → display string with the store's currency settings.
 *
 * @param {string|number} minor  Amount in minor units.
 * @param {Object}        prices Cart line `prices` (currency_* fields).
 * @return {string} Formatted price.
 */
export function formatPrice( minor, prices = {} ) {
	const unit = Number.isInteger( prices.currency_minor_unit )
		? prices.currency_minor_unit
		: 2;
	const value = Number( minor || 0 ) / Math.pow( 10, unit );
	const [ whole, frac ] = value.toFixed( unit ).split( '.' );
	const thousands = prices.currency_thousand_separator ?? ',';
	const decimal = prices.currency_decimal_separator ?? '.';
	const grouped = whole.replace( /\B(?=(\d{3})+(?!\d))/g, thousands );
	const number = frac ? `${ grouped }${ decimal }${ frac }` : grouped;
	return `${ prices.currency_prefix ?? '' }${ number }${
		prices.currency_suffix ?? ''
	}`;
}
