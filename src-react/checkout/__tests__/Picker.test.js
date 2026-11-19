/**
 * Slot picker behaviour tests (Task 9).
 *
 * The 409 path is driven the same way the installed WooCommerce 11.2.1 does it: the checkout POST error body is
 * passed to `processErrorResponse` (wc-blocks-data.js), which calls `core/notices` `createNotice( 'error', message,
 * { id: code, context: data.context || 'wc/cart', isDismissible: true } )`. `notifyLikeWooCommerce` mirrors that call
 * with a real Store API 409 body.
 */
import '@testing-library/jest-dom';
import {
	render,
	screen,
	fireEvent,
	waitFor,
	act,
	within,
} from '@testing-library/react';
import { createReduxStore, register, dispatch, select } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';

import Picker from '../Picker';

// The real `core/notices` store (WooCommerce writes checkout errors there). Loaded by file path: the package entry
// also pulls in @wordpress/components, which is not needed here and is not a runtime dependency of the picker.
// @wordpress/data registers `core/notices` on the default registry itself; registering only when missing.
const path = require( 'path' );
const { store: noticesStore } = require(
	path.join(
		path.dirname( require.resolve( '@wordpress/notices/package.json' ) ),
		'build/store/index.cjs'
	)
);
if ( ! select( 'core/notices' ) ) {
	register( noticesStore );
}

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock(
	'@woocommerce/blocks-checkout',
	() => ( {
		extensionCartUpdate: jest.fn( () => Promise.resolve() ),
		registerCheckoutBlock: jest.fn(),
	} ),
	{ virtual: true }
);
jest.mock(
	'@woocommerce/settings',
	() => ( {
		getSetting: ( key, fallback ) =>
			'slotiva-slot-picker_data' === key
				? { contactUrl: 'mailto:shop@example.test' }
				: fallback,
	} ),
	{ virtual: true }
);

// Minimal stand-in for WooCommerce's `wc/store/validation` store (same action/selector names).
const VALIDATION = 'wc/store/validation';
register(
	createReduxStore( VALIDATION, {
		reducer( state = {}, action ) {
			switch ( action.type ) {
				case 'SET':
					return { ...state, ...action.errors };
				case 'CLEAR': {
					const next = { ...state };
					delete next[ action.key ];
					return next;
				}
				case 'RESET':
					return {};
			}
			return state;
		},
		actions: {
			setValidationErrors: ( errors ) => ( { type: 'SET', errors } ),
			clearValidationError: ( key ) => ( { type: 'CLEAR', key } ),
			reset: () => ( { type: 'RESET' } ),
		},
		selectors: {
			getValidationError: ( state, key ) => state[ key ],
			getValidationErrors: ( state ) => state,
		},
	} )
);

// Stand-in for `wc/store/checkout` with WooCommerce 11.2.1's `disableCheckoutFor` thunk (wc-blocks-data.js):
// `__internalStartCalculation(); try { return await fn() } finally { __internalFinishCalculation() }`.
const CHECKOUT = 'wc/store/checkout';
register(
	createReduxStore( CHECKOUT, {
		reducer( state = { calculating: 0, before: false }, action ) {
			switch ( action.type ) {
				case 'START':
					return { ...state, calculating: state.calculating + 1 };
				case 'FINISH':
					return { ...state, calculating: state.calculating - 1 };
				case 'BEFORE':
					return { ...state, before: true };
				case 'IDLE':
					return { ...state, before: false };
			}
			return state;
		},
		actions: {
			// Test-only: the status flips WooCommerce makes on every "Place order" press.
			beforeProcessing: () => ( { type: 'BEFORE' } ),
			idle: () => ( { type: 'IDLE' } ),
			disableCheckoutFor:
				( fn ) =>
				async ( { dispatch: storeDispatch } ) => {
					storeDispatch( { type: 'START' } );
					try {
						return await fn();
					} finally {
						storeDispatch( { type: 'FINISH' } );
					}
				},
		},
		selectors: {
			isCalculating: ( state ) => state.calculating > 0,
			isBeforeProcessing: ( state ) => state.before,
		},
	} )
);

/** Real Store API 409 body shape (CheckoutFlow::claim → RouteException). */
const REAL_409 = {
	code: 'slotiva_slot_taken',
	message:
		'The time you chose was just taken. Please choose another time; your details are kept.',
	data: { status: 409, taken: [ '2026-11-16T07:00:00Z' ] },
};

function notifyLikeWooCommerce( body ) {
	dispatch( noticesStore ).createNotice( 'error', body.message, {
		id: body.code,
		context: body?.data?.context || 'wc/cart',
		isDismissible: true,
	} );
}

const DAYS = [
	{ date: '2026-11-16', open: true, free: 3 },
	{ date: '2026-11-17', open: true, free: 2 },
	{ date: '2026-11-18', open: false, free: 0 },
];
const SLOTS = {
	'2026-11-16': [
		{ start: '2026-11-16T07:00:00Z', label: '10:00' },
		{ start: '2026-11-16T08:00:00Z', label: '11:00' },
		{ start: '2026-11-16T09:00:00Z', label: '12:00' },
	],
	'2026-11-17': [
		{ start: '2026-11-17T07:00:00Z', label: '10:00' },
		{ start: '2026-11-17T08:00:00Z', label: '11:00' },
	],
};

function mockApi( days = DAYS ) {
	apiFetch.mockImplementation( ( { path: requestPath } ) => {
		const url = new URL( requestPath, 'http://x.test' );
		const day = url.searchParams.get( 'day' );
		return Promise.resolve( {
			timezone: 'Europe/Istanbul',
			tz_label: 'Europe/Istanbul (UTC+03:00)',
			days: day ? days.filter( ( d ) => d.date === day ) : days,
			slots: day ? SLOTS[ day ] || [] : [],
		} );
	} );
}

function cart( ext = {} ) {
	return {
		cartItems: [
			{
				key: 'abc',
				id: 42,
				quantity: 1,
				name: 'Consulting',
				prices: {
					price: '75000',
					currency_minor_unit: 2,
					currency_prefix: '',
					currency_suffix: ' ₺',
					currency_decimal_separator: ',',
					currency_thousand_separator: '.',
				},
				extensions: {
					slotiva: {
						bookable: true,
						min: 1,
						max: 1,
						pricing: 'fixed',
						duration: 60,
						product_id: 42,
						policy: 'Free changes up to 24 hours before.',
						...ext,
					},
				},
			},
		],
	};
}

function setup( ext, days ) {
	mockApi( days );
	const setExtensionData = jest.fn();
	const utils = render(
		<Picker
			cart={ cart( ext ) }
			checkoutExtensionData={ { setExtensionData } }
		/>
	);
	return { ...utils, setExtensionData };
}

const timeGroup = () => screen.getByRole( 'group', { name: /choose a time/i } );
const timeButton = ( label ) =>
	within( timeGroup() ).getByRole( 'button', {
		name: new RegExp( `^✓? ?${ label }` ),
	} );
const lastSlots = ( fn ) => {
	const calls = fn.mock.calls.filter(
		( c ) => 'slotiva' === c[ 0 ] && 'slots' === c[ 1 ]
	);
	return calls.length ? calls[ calls.length - 1 ][ 2 ] : undefined;
};

beforeEach( () => {
	apiFetch.mockReset();
	extensionCartUpdate.mockClear();
	dispatch( VALIDATION ).reset();
	[ 'wc/cart', 'wc/checkout' ].forEach( ( context ) =>
		select( noticesStore )
			.getNotices( context )
			.forEach( ( n ) =>
				dispatch( noticesStore ).removeNotice( n.id, context )
			)
	);
} );

test( 'renders_nothing_without_bookable_line', () => {
	mockApi();
	const { container } = render(
		<Picker
			cart={ {
				cartItems: [
					{
						key: 'x',
						id: 1,
						extensions: { slotiva: { bookable: false } },
					},
				],
			} }
			checkoutExtensionData={ { setExtensionData: jest.fn() } }
		/>
	);
	expect( container ).toBeEmptyDOMElement();
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'renders_days_and_times_from_api', async () => {
	setup();
	expect(
		screen
			.getByText( /loading free times/i )
			.closest( '[aria-busy="true"]' )
	).not.toBeNull();
	await screen.findByRole( 'group', { name: /choose a time/i } );
	expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
		'/slotiva/v1/availability?product=42'
	);
	const days = within(
		screen.getByRole( 'group', { name: /choose a day/i } )
	).getAllByRole( 'button' );
	expect( days ).toHaveLength( 3 );
	expect( days[ 0 ] ).toHaveAttribute( 'aria-pressed', 'true' );
	expect( days[ 2 ] ).toBeDisabled();
	expect(
		screen.getByText( 'Europe/Istanbul (UTC+03:00)' )
	).toBeInTheDocument();
	await waitFor( () => expect( timeButton( '10:00' ) ).toBeInTheDocument() );
	expect( within( timeGroup() ).getAllByRole( 'button' ) ).toHaveLength( 3 );
	expect(
		screen.getByText( 'Free changes up to 24 hours before.' )
	).toBeInTheDocument();
} );

test( 'single_mode_replaces_selection', async () => {
	const { setExtensionData } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	fireEvent.click( timeButton( '11:00' ) );
	expect( timeButton( '10:00' ) ).toHaveAttribute( 'aria-pressed', 'false' );
	expect( timeButton( '11:00' ) ).toHaveAttribute( 'aria-pressed', 'true' );
	expect( lastSlots( setExtensionData ) ).toEqual( [
		'2026-11-16T08:00:00Z',
	] );
} );

test( 'multi_mode_adds_and_removes_and_caps_at_max', async () => {
	const { setExtensionData } = setup( { max: 4, pricing: 'per_slot' } );
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	fireEvent.click( timeButton( '11:00' ) );
	fireEvent.click( timeButton( '12:00' ) );
	// Second day.
	const dayButtons = within(
		screen.getByRole( 'group', { name: /choose a day/i } )
	).getAllByRole( 'button' );
	fireEvent.click( dayButtons[ 1 ] );
	await waitFor( () =>
		expect( within( timeGroup() ).getAllByRole( 'button' ) ).toHaveLength(
			2
		)
	);
	fireEvent.click( timeButton( '10:00' ) );
	expect( lastSlots( setExtensionData ) ).toHaveLength( 4 );
	// Fifth is disabled + status message.
	expect( timeButton( '11:00' ) ).toBeDisabled();
	expect(
		screen.getByText( /you can choose at most 4 times/i )
	).toBeInTheDocument();
	// Remove one via the summary ✕.
	const summary = screen.getByRole( 'list', {
		name: /your selected times/i,
	} );
	expect( within( summary ).getAllByRole( 'listitem' ) ).toHaveLength( 4 );
	fireEvent.click(
		within( summary ).getAllByRole( 'button', { name: /remove/i } )[ 0 ]
	);
	expect( lastSlots( setExtensionData ) ).toHaveLength( 3 );
	expect( timeButton( '11:00' ) ).not.toBeDisabled();
	expect(
		screen.queryByText( /you can choose at most 4 times/i )
	).toBeNull();
	expect( screen.getByText( /3 × 750,00 ₺/ ) ).toBeInTheDocument();
} );

test( 'calls_setExtensionData_with_iso_slots', async () => {
	const { setExtensionData } = setup( { max: 4, pricing: 'per_slot' } );
	await waitFor( () => timeButton( '10:00' ) );
	expect( lastSlots( setExtensionData ) ).toEqual( [] );
	fireEvent.click( timeButton( '12:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	// Chronological ISO-8601 UTC strings.
	expect( lastSlots( setExtensionData ) ).toEqual( [
		'2026-11-16T07:00:00Z',
		'2026-11-16T09:00:00Z',
	] );
} );

test( 'per_slot_calls_extensionCartUpdate_with_count', async () => {
	setup( { max: 4, pricing: 'per_slot' } );
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	// Quantity already 1 → no update for the first slot.
	expect( extensionCartUpdate ).not.toHaveBeenCalled();
	fireEvent.click( timeButton( '11:00' ) );
	await waitFor( () =>
		expect( extensionCartUpdate ).toHaveBeenLastCalledWith( {
			namespace: 'slotiva',
			data: { count: 2 },
		} )
	);
} );

test( 'fixed_pricing_never_updates_cart', async () => {
	setup( { max: 4, pricing: 'fixed' } );
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	fireEvent.click( timeButton( '11:00' ) );
	expect( extensionCartUpdate ).not.toHaveBeenCalled();
} );

test( 'blocks_submit_without_selection', async () => {
	setup();
	await waitFor( () => timeButton( '10:00' ) );
	expect(
		select( VALIDATION ).getValidationError( 'slotiva-slots' )
	).toEqual(
		expect.objectContaining( {
			message: expect.stringMatching( /choose/i ),
			hidden: true,
		} )
	);
	fireEvent.click( timeButton( '10:00' ) );
	await waitFor( () =>
		expect(
			select( VALIDATION ).getValidationError( 'slotiva-slots' )
		).toBeUndefined()
	);
	// Deselect → blocked again.
	fireEvent.click( timeButton( '10:00' ) );
	await waitFor( () =>
		expect(
			select( VALIDATION ).getValidationError( 'slotiva-slots' )
		).toBeDefined()
	);
} );

test( 'shows_validation_message_once_woocommerce_unhides_it', async () => {
	setup();
	await waitFor( () => timeButton( '10:00' ) );
	act( () => {
		dispatch( VALIDATION ).setValidationErrors( {
			'slotiva-slots': {
				message: 'Choose a day and time for your booking.',
				hidden: false,
			},
		} );
	} );
	expect(
		await screen.findByText( 'Choose a day and time for your booking.' )
	).toBeInTheDocument();
} );

test( 'on_409_refetches_and_shows_alert', async () => {
	const { setExtensionData } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	const callsBefore = apiFetch.mock.calls.length;
	act( () => notifyLikeWooCommerce( REAL_409 ) );
	const alert = await screen.findByRole( 'alert' );
	expect( alert ).toHaveTextContent(
		/10:00 was just taken by someone else/i
	);
	expect( lastSlots( setExtensionData ) ).toEqual( [] );
	await waitFor( () =>
		expect( apiFetch.mock.calls.length ).toBeGreaterThanOrEqual(
			callsBefore + 2
		)
	);
	expect(
		apiFetch.mock.calls.slice( callsBefore ).map( ( c ) => c[ 0 ].path )
	).toEqual(
		expect.arrayContaining( [
			'/slotiva/v1/availability?product=42',
			'/slotiva/v1/availability?product=42&day=2026-11-16',
		] )
	);
	// WooCommerce's generic notice is consumed so the message is not shown twice.
	expect( select( noticesStore ).getNotices( 'wc/cart' ) ).toHaveLength( 0 );
	expect(
		select( VALIDATION ).getValidationError( 'slotiva-slots' )
	).toBeDefined();
} );

test( 'busy_and_quota_show_message_and_keep_selection', async () => {
	const { setExtensionData } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	act( () =>
		notifyLikeWooCommerce( {
			code: 'slotiva_hold_quota',
			message: 'x',
			data: { status: 429 },
		} )
	);
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		/too many bookings awaiting payment/i
	);
	expect( lastSlots( setExtensionData ) ).toEqual( [
		'2026-11-16T07:00:00Z',
	] );
	act( () =>
		notifyLikeWooCommerce( {
			code: 'slotiva_busy',
			message: 'x',
			data: { status: 409 },
		} )
	);
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		/being processed/i
	);
	expect( lastSlots( setExtensionData ) ).toEqual( [
		'2026-11-16T07:00:00Z',
	] );
} );

test( 'empty_horizon_shows_contact_state', async () => {
	setup( {}, [
		{ date: '2026-11-16', open: true, free: 0 },
		{ date: '2026-11-17', open: false, free: 0 },
		{ date: '2026-11-18', open: true, free: 0 },
	] );
	// Today + 2-day horizon.
	expect(
		await screen.findByText( /no free times in the next 2 days/i )
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'link', { name: /contact us/i } )
	).toHaveAttribute( 'href', 'mailto:shop@example.test' );
	expect(
		screen.queryByRole( 'group', { name: /choose a time/i } )
	).toBeNull();
	expect(
		select( VALIDATION ).getValidationError( 'slotiva-slots' )
	).toBeDefined();
} );

test( 'load_error_offers_retry', async () => {
	apiFetch.mockImplementationOnce( () =>
		Promise.reject( new Error( 'net' ) )
	);
	const setExtensionData = jest.fn();
	render(
		<Picker
			cart={ cart() }
			checkoutExtensionData={ { setExtensionData } }
		/>
	);
	const retry = await screen.findByRole( 'button', { name: /try again/i } );
	mockApi();
	fireEvent.click( retry );
	await waitFor( () => expect( timeButton( '10:00' ) ).toBeInTheDocument() );
} );

test( 'keyboard_operable_buttons_have_aria_pressed', async () => {
	setup( { max: 4 } );
	await waitFor( () => timeButton( '10:00' ) );
	const all = [
		...within(
			screen.getByRole( 'group', { name: /choose a day/i } )
		).getAllByRole( 'button' ),
		...within( timeGroup() ).getAllByRole( 'button' ),
	];
	all.filter( ( b ) => ! b.disabled ).forEach( ( b ) => {
		expect( b.tagName ).toBe( 'BUTTON' );
		expect( b ).toHaveAttribute( 'type', 'button' );
		expect( b ).toHaveAttribute( 'aria-pressed' );
	} );
	const b = timeButton( '11:00' );
	b.focus();
	expect( b ).toHaveFocus();
	fireEvent.click( b );
	expect( timeButton( '11:00' ) ).toHaveAttribute( 'aria-pressed', 'true' );
	expect( timeButton( '11:00' ) ).toHaveFocus();
} );

test( 'conflict_alert_is_not_doubled_by_validation_message', async () => {
	setup();
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	act( () => notifyLikeWooCommerce( REAL_409 ) );
	await screen.findByRole( 'alert' );
	// WooCommerce unhides validation errors after a failed checkout.
	act( () => {
		dispatch( VALIDATION ).setValidationErrors( {
			'slotiva-slots': {
				message: 'Choose a day and time for your booking.',
				hidden: false,
			},
		} );
	} );
	await waitFor( () =>
		expect( apiFetch.mock.calls.length ).toBeGreaterThanOrEqual( 4 )
	);
	await waitFor( () => expect( timeButton( '11:00' ) ).toBeInTheDocument() );
	expect( screen.getAllByRole( 'alert' ) ).toHaveLength( 1 );
	// A new choice clears the alert.
	fireEvent.click( timeButton( '11:00' ) );
	expect( screen.queryByRole( 'alert' ) ).toBeNull();
} );

test( 'placed_before_payment_step_inside_checkout_form', async () => {
	mockApi();
	const { PlacedPicker } = require( '../index' );
	const form = document.createElement( 'form' );
	form.className = 'wc-block-checkout__form';
	form.innerHTML =
		'<fieldset id="contact-fields"></fieldset><fieldset id="billing-fields"></fieldset>' +
		'<div class="wp-block-woocommerce-checkout-payment-block"><fieldset id="payment-method"></fieldset></div>' +
		'<div class="wc-block-checkout__actions"></div><div id="tail"></div>';
	document.body.appendChild( form );
	render(
		<PlacedPicker
			cart={ cart() }
			checkoutExtensionData={ { setExtensionData: jest.fn() } }
		/>,
		{ container: form.querySelector( '#tail' ) }
	);
	await waitFor( () => timeButton( '10:00' ) );
	const ids = [ ...form.children ].map( ( c ) => c.id || c.className );
	expect( ids ).toEqual( [
		'contact-fields',
		'billing-fields',
		'slotiva-picker-host',
		'wp-block-woocommerce-checkout-payment-block',
		'wc-block-checkout__actions',
		'tail',
	] );
	expect( form.querySelector( '#tail .slotiva-picker' ) ).toBeNull();
	form.remove();
} );

test( 'host_moves_before_payment_step_that_mounts_later', async () => {
	mockApi();
	const { PlacedPicker } = require( '../index' );
	const form = document.createElement( 'form' );
	form.className = 'wc-block-checkout__form';
	form.innerHTML =
		'<fieldset id="contact-fields"></fieldset><div class="wc-block-checkout__actions"></div><div id="tail"></div>';
	document.body.appendChild( form );
	render(
		<PlacedPicker
			cart={ cart() }
			checkoutExtensionData={ { setExtensionData: jest.fn() } }
		/>,
		{ container: form.querySelector( '#tail' ) }
	);
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	const payment = document.createElement( 'fieldset' );
	payment.id = 'payment-method';
	// Observed on the real page: the step mounts later and lands before the host.
	form.insertBefore( payment, form.querySelector( '.slotiva-picker-host' ) );
	await waitFor( () =>
		expect(
			[ ...form.children ].map( ( c ) => c.id || c.className )
		).toEqual( [
			'contact-fields',
			'slotiva-picker-host',
			'payment-method',
			'wc-block-checkout__actions',
			'tail',
		] )
	);
	// Moving the host keeps the React state (selection survives).
	expect( timeButton( '10:00' ) ).toHaveAttribute( 'aria-pressed', 'true' );
	form.remove();
} );

test( 'count_update_failure_rolls_back_and_resends', async () => {
	extensionCartUpdate.mockImplementationOnce( () =>
		Promise.reject( { code: 'slotiva_busy', message: 'x' } )
	);
	setup( { max: 4, pricing: 'per_slot' } );
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	fireEvent.click( timeButton( '11:00' ) );
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		/being processed/i
	);
	expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
	// Cart is still at 1: deselect (count 1 = cart, nothing to send) and reselect → count 2 is sent again.
	fireEvent.click( timeButton( '11:00' ) );
	fireEvent.click( timeButton( '11:00' ) );
	await waitFor( () =>
		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 2 )
	);
	expect( extensionCartUpdate ).toHaveBeenLastCalledWith( {
		namespace: 'slotiva',
		data: { count: 2 },
	} );
} );

test( 'submit_disabled_while_count_update_in_flight', async () => {
	let finish;
	extensionCartUpdate.mockImplementationOnce(
		() =>
			new Promise( ( resolve ) => {
				finish = resolve;
			} )
	);
	setup( { max: 4, pricing: 'per_slot' } );
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	fireEvent.click( timeButton( '11:00' ) );
	await waitFor( () =>
		expect( select( CHECKOUT ).isCalculating() ).toBe( true )
	);
	await act( async () => {
		finish();
	} );
	expect( select( CHECKOUT ).isCalculating() ).toBe( false );
} );

test( 'qty_mismatch_shows_message_and_resyncs', async () => {
	setup( { max: 4, pricing: 'per_slot' } );
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	fireEvent.click( timeButton( '11:00' ) );
	await waitFor( () =>
		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 )
	);
	act( () =>
		notifyLikeWooCommerce( {
			code: 'slotiva_qty_mismatch',
			message: 'x',
			data: { status: 400 },
		} )
	);
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		/quantity in your cart/i
	);
	await waitFor( () =>
		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 2 )
	);
	expect( extensionCartUpdate ).toHaveBeenLastCalledWith( {
		namespace: 'slotiva',
		data: { count: 2 },
	} );
	expect( select( noticesStore ).getNotices( 'wc/cart' ) ).toHaveLength( 0 );
} );

test( 'not_bookable_has_own_message_and_refreshes', async () => {
	const { setExtensionData } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	const before = apiFetch.mock.calls.length;
	act( () =>
		notifyLikeWooCommerce( {
			code: 'slotiva_not_bookable',
			message: 'x',
			data: { status: 400 },
		} )
	);
	const alert = await screen.findByRole( 'alert' );
	expect( alert ).toHaveTextContent( /can no longer be booked/i );
	expect( alert ).not.toHaveTextContent( /someone else/i );
	expect( lastSlots( setExtensionData ) ).toEqual( [] );
	await waitFor( () =>
		expect( apiFetch.mock.calls.length ).toBeGreaterThan( before )
	);
} );

test( 'notice_in_wc_checkout_context_is_handled', async () => {
	setup();
	await waitFor( () => timeButton( '10:00' ) );
	fireEvent.click( timeButton( '10:00' ) );
	act( () =>
		notifyLikeWooCommerce( {
			...REAL_409,
			data: { ...REAL_409.data, context: 'wc/checkout' },
		} )
	);
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		/just taken/i
	);
	expect( select( noticesStore ).getNotices( 'wc/checkout' ) ).toHaveLength(
		0
	);
} );

test( 'host_without_anchor_waits_in_form_and_moves_later', async () => {
	mockApi();
	const { PlacedPicker } = require( '../index' );
	const form = document.createElement( 'form' );
	form.className = 'wc-block-checkout__form';
	form.innerHTML =
		'<fieldset id="contact-fields"></fieldset><div id="tail"></div>';
	document.body.appendChild( form );
	render(
		<PlacedPicker
			cart={ cart() }
			checkoutExtensionData={ { setExtensionData: jest.fn() } }
		/>,
		{ container: form.querySelector( '#tail' ) }
	);
	await waitFor( () => timeButton( '10:00' ) );
	expect(
		form.querySelector( '.slotiva-picker-host .slotiva-picker' )
	).not.toBeNull();
	const actions = document.createElement( 'div' );
	actions.className = 'wc-block-checkout__actions';
	form.appendChild( actions );
	await waitFor( () =>
		expect(
			form.querySelector( '.slotiva-picker-host' ).nextElementSibling
		).toBe( actions )
	);
	form.remove();
} );

test( 'host_removed_and_observer_disconnected_on_unmount', async () => {
	mockApi();
	const disconnect = jest.spyOn(
		window.MutationObserver.prototype,
		'disconnect'
	);
	const { PlacedPicker } = require( '../index' );
	const form = document.createElement( 'form' );
	form.className = 'wc-block-checkout__form';
	form.innerHTML =
		'<fieldset id="payment-method"></fieldset><div id="tail"></div>';
	document.body.appendChild( form );
	// The host observer is the one that watches the checkout form (the picker's own theme observer watches html/body).
	const observe = jest.spyOn( window.MutationObserver.prototype, 'observe' );
	const { unmount } = render(
		<PlacedPicker
			cart={ cart() }
			checkoutExtensionData={ { setExtensionData: jest.fn() } }
		/>,
		{ container: form.querySelector( '#tail' ) }
	);
	await waitFor( () => timeButton( '10:00' ) );
	const hostObservers = () =>
		new Set(
			observe.mock.instances.filter(
				( _, i ) => observe.mock.calls[ i ][ 0 ] === form
			)
		);
	const host = [ ...hostObservers() ];
	const hostDisconnects = () =>
		disconnect.mock.instances.filter( ( o ) => host.includes( o ) ).length;
	expect( host ).toHaveLength( 1 );
	const calls = hostDisconnects();
	unmount();
	expect( form.querySelector( '.slotiva-picker-host' ) ).toBeNull();
	expect( hostDisconnects() ).toBe( calls + 1 );
	observe.mockRestore();
	disconnect.mockRestore();
	form.remove();
} );

test( 'picker_sets_ink_paper_vars_on_root', async () => {
	document.body.style.backgroundColor = 'rgb(17, 17, 17)';
	// jsdom does not inherit `color`; a rule on the root stands in for the theme's inherited text colour.
	const sheet = document.createElement( 'style' );
	sheet.textContent = '.slotiva-picker { color: rgb(238, 238, 238); }';
	document.head.appendChild( sheet );
	const { container } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	const root = container.querySelector( '.slotiva-picker' );
	expect( root.style.getPropertyValue( '--slotiva-ink' ) ).toBe(
		'rgb(238, 238, 238)'
	);
	expect( root.style.getPropertyValue( '--slotiva-paper' ) ).toBe(
		'rgb(17, 17, 17)'
	);
	document.body.removeAttribute( 'style' );
	sheet.remove();
} );

test( 'place_order_without_pick_scrolls_and_focuses_first_day', async () => {
	const scroll = jest.fn();
	window.HTMLElement.prototype.scrollIntoView = scroll;
	const { container } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	expect( scroll ).not.toHaveBeenCalled();
	// WooCommerce unhides every validation error when "Place order" is pressed.
	act( () => {
		dispatch( VALIDATION ).setValidationErrors( {
			'slotiva-slots': {
				message: 'Choose a day and time for your booking.',
				hidden: false,
			},
		} );
	} );
	await waitFor( () => expect( scroll ).toHaveBeenCalled() );
	const first = container.querySelector(
		'.slotiva-picker__days button.slotiva-picker__day:not(:disabled)'
	);
	expect( first ).toHaveFocus();
	delete window.HTMLElement.prototype.scrollIntoView;
} );

const unhideOurs = () =>
	act( () => {
		dispatch( VALIDATION ).setValidationErrors( {
			'slotiva-slots': {
				message: 'Choose a day and time for your booking.',
				hidden: false,
			},
		} );
	} );
const pressPlaceOrder = () =>
	act( () => {
		dispatch( CHECKOUT ).beforeProcessing();
		dispatch( CHECKOUT ).idle();
	} );

test( 'second_place_order_attempt_refocuses_first_day', async () => {
	const scroll = jest.fn();
	window.HTMLElement.prototype.scrollIntoView = scroll;
	const { container } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	unhideOurs();
	const first = container.querySelector(
		'.slotiva-picker__days button.slotiva-picker__day:not(:disabled)'
	);
	await waitFor( () => expect( first ).toHaveFocus() );
	const callsAfterFirst = scroll.mock.calls.length;
	const other = document.createElement( 'button' );
	document.body.appendChild( other );
	other.focus();
	expect( first ).not.toHaveFocus();
	// Error already visible: only the status transition is new.
	pressPlaceOrder();
	await waitFor( () => expect( first ).toHaveFocus() );
	expect( scroll.mock.calls.length ).toBeGreaterThan( callsAfterFirst );
	other.remove();
	delete window.HTMLElement.prototype.scrollIntoView;
} );

test( 'no_focus_when_another_validation_error_is_visible', async () => {
	const scroll = jest.fn();
	window.HTMLElement.prototype.scrollIntoView = scroll;
	const { container } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	act( () => {
		dispatch( VALIDATION ).setValidationErrors( {
			'billing-email': { message: 'Enter an email.', hidden: false },
		} );
	} );
	unhideOurs();
	pressPlaceOrder();
	await new Promise( ( r ) => setTimeout( r, 250 ) );
	expect( scroll ).not.toHaveBeenCalled();
	expect(
		container.querySelector( '.slotiva-picker__day.is-active' )
	).not.toHaveFocus();
	delete window.HTMLElement.prototype.scrollIntoView;
} );

test( 'ink_vars_follow_theme_class_toggle_and_observer_disconnects', async () => {
	const disconnect = jest.spyOn(
		window.MutationObserver.prototype,
		'disconnect'
	);
	document.body.style.backgroundColor = 'rgb(255, 255, 255)';
	const sheet = document.createElement( 'style' );
	sheet.textContent = '.slotiva-picker { color: rgb(20, 20, 20); }';
	document.head.appendChild( sheet );
	const { container, unmount } = setup();
	await waitFor( () => timeButton( '10:00' ) );
	const root = container.querySelector( '.slotiva-picker' );
	expect( root.style.getPropertyValue( '--slotiva-paper' ) ).toBe(
		'rgb(255, 255, 255)'
	);
	// Theme switches to dark by changing the body (class/style), not the OS preference.
	document.body.style.backgroundColor = 'rgb(240, 240, 240)';
	sheet.textContent = '.slotiva-picker { color: rgb(30, 30, 30); }';
	document.body.className = 'is-dark';
	await waitFor( () =>
		expect( root.style.getPropertyValue( '--slotiva-ink' ) ).toBe(
			'rgb(30, 30, 30)'
		)
	);
	disconnect.mockClear();
	unmount();
	expect( disconnect ).toHaveBeenCalled();
	disconnect.mockRestore();
	document.body.removeAttribute( 'style' );
	document.body.removeAttribute( 'class' );
	sheet.remove();
} );
