/**
 * Setup wizard steps 3–5: product + more-than-one-hour + preview, checkout check, done.
 */
import '@testing-library/jest-dom';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import App from '../setup/App';
import { formatMoney, parseMoney } from '../setup/money';
import { saveProduct } from '../setup/steps/Product';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// D4 (1.0.4): these tests drive a full wizard render through many role queries (accessible-name computation in jsdom) and
// user-event clicks; ~0.7 s locally, but on a loaded CI runner the slowest one crossed Jest's 5 s default and flaked.
// A per-file timeout keeps the other suites on the strict default while removing the load-dependent failure.
jest.setTimeout( 20000 );

const r = ( from, to ) => ( { from, to } );
const WEEK = [
	[ r( '16:00', '22:00' ) ],
	[ r( '16:00', '22:00' ) ],
	[ r( '16:00', '22:00' ) ],
	[ r( '16:00', '22:00' ) ],
	[ r( '16:00', '22:00' ) ],
	[ r( '16:00', '22:00' ) ],
	[],
];
const TRY = {
	symbol: '₺',
	decimals: 2,
	decimalSep: ',',
	thousandSep: '.',
	position: 'right_space',
};
const OK = ( detail ) => ( { state: 'ok', detail } );

let calls;
let setup;
let productError;
let nextId;

function route( request ) {
	calls.push( request );
	const method = request.method || 'GET';
	if ( method === 'GET' && /\/admin\/setup$/.test( request.path ) ) {
		return Promise.resolve( setup );
	}
	if (
		method === 'POST' &&
		/\/admin\/setup\/product$/.test( request.path )
	) {
		if ( productError ) {
			return Promise.reject( productError );
		}
		const created = ! request.data.productId;
		return Promise.resolve( {
			product: {
				id: created ? nextId : request.data.productId,
				name: request.data.name || 'Existing',
				price: String( request.data.price ),
			},
		} );
	}
	if ( method === 'POST' && /\/admin\/setup\/state$/.test( request.path ) ) {
		return Promise.resolve( { complete: true, dismissed: false } );
	}
	return Promise.reject( { code: 'unexpected', message: request.path } );
}

const posts = ( path ) =>
	calls.filter( ( c ) => c.method === 'POST' && c.path.endsWith( path ) );

beforeEach( () => {
	document.documentElement.lang = 'en';
	calls = [];
	productError = null;
	nextId = 42;
	setup = {
		complete: false,
		rules: { durationDefault: 60 },
		schedule: { days: WEEK },
		currency: TRY,
		products: [
			{
				id: 5,
				name: 'Per-slot product',
				status: 'publish',
				durationMin: 60,
				minSlots: 1,
				maxSlots: 4,
				pricing: 'per_slot',
				editUrl: 'http://x.test/edit/5',
				permalink: 'http://x.test/product/five/',
				price: '300',
			},
		],
		candidates: [
			{
				id: 7,
				name: 'Old product',
				type: 'simple',
				status: 'publish',
				price: '250',
			},
			{
				id: 9,
				name: 'Variable product',
				type: 'variable',
				status: 'publish',
				price: '100',
			},
		],
		checks: {
			hours: OK( 'Working hours are set.' ),
			product: OK( 'A bookable product is published.' ),
			checkout: OK( 'The checkout page uses the Checkout block.' ),
			timezone: OK( 'Time zone: Europe/Istanbul.' ),
			payment: OK( 'A payment method is available.' ),
		},
	};
	apiFetch.mockImplementation( route );
	window.slotivaAdmin = {
		listUrl: 'http://x.test/wp-admin/admin.php?page=slotiva',
		scheduleUrl: 'http://x.test/wp-admin/admin.php?page=slotiva-schedule',
	};
} );

function openStep( n, extra = '' ) {
	window.history.replaceState(
		null,
		'',
		`/wp-admin/admin.php?page=slotiva-setup&step=${ n }${ extra }`
	);
	return render( <App /> );
}

const nameInput = () => screen.getByLabelText( 'Product name' );
const priceInput = () => screen.getByLabelText( /Price of one hour/ );
const continueBtn = () =>
	screen.getByRole( 'button', { name: /product and continue$/ } );
const yesRadio = () => screen.getByRole( 'radio', { name: /^Yes, 2/ } );

async function fillNew( name = 'Private lesson', price = '500' ) {
	await userEvent.type(
		await screen.findByLabelText( 'Product name' ),
		name
	);
	await userEvent.type( priceInput(), price );
}

test( 'money_format_and_parse_follow_store_settings', () => {
	expect( formatMoney( 1234.5, TRY ) ).toBe( '1.234,50 ₺' );
	expect(
		formatMoney( 12.5, {
			symbol: '$',
			decimals: 2,
			decimalSep: '.',
			thousandSep: ',',
			position: 'left',
		} )
	).toBe( '$12.50' );
	expect(
		formatMoney( 7, {
			symbol: '€',
			decimals: 0,
			decimalSep: ',',
			thousandSep: '.',
			position: 'left_space',
		} )
	).toBe( '€ 7' );
	expect( parseMoney( '12,50', TRY ) ).toBe( 12.5 );
	expect( parseMoney( '1.234,50', TRY ) ).toBe( 1234.5 );
	expect( parseMoney( '500', TRY ) ).toBe( 500 );
	expect( parseMoney( '', TRY ) ).toBeNull();
	expect( parseMoney( 'abc', TRY ) ).toBeNull();
	expect( parseMoney( '-3', TRY ) ).toBeNull();
} );

test( 'product_multi_yes_sends_per_slot_max3', async () => {
	openStep( 3, '&new=1' );
	await fillNew();
	await userEvent.click( yesRadio() );
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 1 )
	);
	expect( posts( '/setup/product' )[ 0 ].data ).toEqual( {
		name: 'Private lesson',
		price: '500',
		durationMin: 60,
		multi: true,
		maxSlots: 3,
	} );
	await waitFor( () =>
		expect( window.location.search ).toContain( 'step=4' )
	);
} );

test( 'product_multi_no_hides_counter', async () => {
	openStep( 3, '&new=1' );
	await fillNew();
	expect(
		screen.queryByRole( 'button', { name: 'Increase' } )
	).not.toBeInTheDocument();
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 1 )
	);
	expect( posts( '/setup/product' )[ 0 ].data ).toEqual( {
		name: 'Private lesson',
		price: '500',
		durationMin: 60,
		multi: false,
	} );
} );

test( 'price_table_rows_follow_max', async () => {
	openStep( 3, '&new=1' );
	await fillNew();
	await userEvent.click( yesRadio() );
	const table = screen.getByRole( 'list', { name: 'Price by hours' } );
	expect( within( table ).getAllByRole( 'listitem' ) ).toHaveLength( 3 );
	expect( table ).toHaveTextContent( /3 × 500,00\s₺/ );
	expect( yesRadio() ).toHaveAccessibleName( /^Yes, 2–3 hours too/ );
	await userEvent.click( screen.getByRole( 'button', { name: 'Decrease' } ) );
	expect( within( table ).getAllByRole( 'listitem' ) ).toHaveLength( 2 );
	expect( yesRadio() ).toHaveAccessibleName( /^Yes, 2 hours too/ );
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 1 )
	);
	expect( posts( '/setup/product' )[ 0 ].data.maxSlots ).toBe( 2 );
} );

test( 'preview_shows_two_hour_example_with_cart_line', async () => {
	openStep( 3, '&new=1' );
	await fillNew();
	await userEvent.click( yesRadio() );
	const preview = screen.getByRole( 'complementary' );
	expect( preview ).toHaveTextContent( 'Customer picks 2 hours' );
	expect( preview ).toHaveTextContent( /2 × Private lesson/ );
	expect( preview ).toHaveTextContent( /2 × 500,00\s₺/ );
} );

test( 'step3_second_continue_updates_not_creates', async () => {
	openStep( 3, '&new=1' );
	await fillNew();
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( window.location.search ).toContain( 'step=4' )
	);
	await userEvent.click( screen.getByRole( 'button', { name: /Back/ } ) );
	expect( await screen.findByLabelText( 'Product name' ) ).toHaveValue(
		'Private lesson'
	);
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 2 )
	);
	expect( posts( '/setup/product' )[ 0 ].data.productId ).toBeUndefined();
	expect( posts( '/setup/product' )[ 1 ].data.productId ).toBe( 42 );
} );

test( 'existing_product_list_and_prefill', async () => {
	openStep( 3 );
	await userEvent.click(
		await screen.findByRole( 'radio', { name: 'Use an existing product' } )
	);
	const select = screen.getByLabelText( 'Product' );
	expect(
		within( select ).getByRole( 'option', { name: 'Old product' } )
	).toBeInTheDocument();
	expect(
		within( select ).getByRole( 'option', { name: 'Per-slot product' } )
	).toBeInTheDocument();
	expect( screen.queryByLabelText( 'Product name' ) ).not.toBeInTheDocument();

	await userEvent.selectOptions( select, '5' );
	expect( priceInput() ).toHaveValue( '300' );
	expect( yesRadio() ).toBeChecked();
	// The choice names the real upper bound, not the artboard's fixed "2–3".
	expect( yesRadio() ).toHaveAccessibleName( /^Yes, 2–4 hours too/ );
	expect(
		screen.getByText( '4 hours', { selector: '.slotiva-counter__value' } )
	).toBeInTheDocument();

	await userEvent.selectOptions( select, '7' );
	expect( priceInput() ).toHaveValue( '250' );
	expect( yesRadio() ).not.toBeChecked();
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 1 )
	);
	expect( posts( '/setup/product' )[ 0 ].data ).toEqual( {
		productId: 7,
		price: '250',
		durationMin: 60,
		multi: false,
	} );
} );

test( 'variable_product_hides_price_and_sends_current_price', async () => {
	openStep( 3 );
	await userEvent.click(
		await screen.findByRole( 'radio', { name: 'Use an existing product' } )
	);
	await userEvent.selectOptions( screen.getByLabelText( 'Product' ), '9' );
	expect(
		screen.queryByLabelText( /Price of one hour/ )
	).not.toBeInTheDocument();
	expect(
		screen.getByText(
			/prices of variable products are edited on the product/i
		)
	).toBeInTheDocument();
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 1 )
	);
	expect( posts( '/setup/product' )[ 0 ].data.productId ).toBe( 9 );
	expect( posts( '/setup/product' )[ 0 ].data.price ).toBe( '100' );
} );

test( 'validation_error_marks_field', async () => {
	productError = {
		code: 'slotiva_setup_invalid',
		message: 'Some fields are not valid.',
		data: { status: 400, fields: [ 'price' ] },
	};
	openStep( 3, '&new=1' );
	await userEvent.type( await screen.findByLabelText( 'Product name' ), 'X' );
	await userEvent.type( priceInput(), '5' );
	await userEvent.click( continueBtn() );
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		'Some fields are not valid.'
	);
	expect( priceInput() ).toHaveAttribute( 'aria-invalid', 'true' );
	expect( nameInput() ).not.toHaveAttribute( 'aria-invalid', 'true' );
	expect( window.location.search ).toContain( 'step=3' );
} );

test( 'checkout_todo_shows_editor_button_and_instruction', async () => {
	setup.checks.checkout = {
		state: 'todo',
		detail: 'The checkout page does not use the Checkout block.',
		action: 'http://x.test/wp-admin/post.php?post=8&action=edit',
	};
	setup.checks.timezone = {
		state: 'warn',
		detail: 'The site uses a fixed UTC offset (+03:00).',
	};
	openStep( 4 );
	const link = await screen.findByRole( 'link', {
		name: /Convert the checkout page to blocks/,
	} );
	expect( link ).toHaveAttribute(
		'href',
		'http://x.test/wp-admin/post.php?post=8&action=edit'
	);
	expect( link ).toHaveAttribute( 'target', '_blank' );
	expect( link ).toHaveAttribute(
		'rel',
		expect.stringContaining( 'noopener' )
	);
	expect(
		screen.getByText(
			'Select the Classic Checkout block and choose “Transform into blocks”.'
		)
	).toBeInTheDocument();
	expect( screen.getByText( /should be checked/ ) ).toBeInTheDocument();
	expect(
		screen.getByRole( 'button', { name: 'I’ll do this later' } )
	).toBeInTheDocument();
} );

test( 'checkout_all_ok_says_ready', async () => {
	openStep( 4 );
	expect(
		await screen.findByText( 'Everything looks ready.' )
	).toBeInTheDocument();
	expect(
		screen.queryByRole( 'link', {
			name: /Convert the checkout page to blocks/,
		} )
	).not.toBeInTheDocument();
	expect(
		screen.queryByRole( 'button', { name: 'I’ll do this later' } )
	).not.toBeInTheDocument();
} );

test( 'done_marks_completed_and_links_permalink', async () => {
	const { container } = openStep( 5 );
	const link = await screen.findByRole( 'link', { name: /Try a booking/ } );
	expect( link ).toHaveAttribute( 'href', 'http://x.test/product/five/' );
	expect( link ).toHaveAttribute( 'target', '_blank' );
	expect( link ).toHaveAttribute(
		'rel',
		expect.stringContaining( 'noopener' )
	);
	// Opens in a new tab: says so to screen readers and carries the external-link icon (artboard Step5).
	expect( link ).toHaveAccessibleName( /\(opens in a new tab\)/ );
	expect( link.querySelector( 'svg[aria-hidden="true"]' ) ).not.toBeNull();
	expect( posts( '/setup/state' ) ).toHaveLength( 1 );
	expect( posts( '/setup/state' )[ 0 ].data ).toEqual( { completed: true } );
	// The summary comes from a GET that follows the POST.
	const order = calls.map( ( c ) => `${ c.method || 'GET' } ${ c.path }` );
	const postAt = order.findIndex( ( c ) => c.includes( '/setup/state' ) );
	expect(
		order.slice( postAt + 1 ).some( ( c ) => c.startsWith( 'GET' ) )
	).toBe( true );
	expect(
		screen.getByRole( 'link', { name: 'Go to appointments' } )
	).toHaveAttribute(
		'href',
		'http://x.test/wp-admin/admin.php?page=slotiva'
	);
	expect( container.querySelector( 'main' ) ).toBeNull();
	// D7: the total is derived from the wizard's own step count, not a literal.
	expect( screen.getByText( '5 of 5 done' ) ).toBeInTheDocument();
} );

test( 'done_with_no_open_day_does_not_claim_ready', async () => {
	setup.schedule = { days: [ [], [], [], [], [], [], [] ] };
	openStep( 5 );
	await screen.findByRole( 'link', { name: /Try a booking/ } );
	expect(
		screen.queryByRole( 'heading', { name: /ready to take bookings/ } )
	).toBeNull();
	expect(
		screen.getByRole( 'heading', { name: /open at least one day/i } )
	).toBeInTheDocument();
	expect(
		document.querySelector( '.slotiva-done__value.is-warn' )
	).toHaveTextContent( /closed/ );
} );

test( 'done_summary_rows_have_change_buttons', async () => {
	openStep( 5 );
	await screen.findByRole( 'link', { name: /Try a booking/ } );
	expect(
		screen.getAllByRole( 'button', { name: /Change/ } ).length
	).toBeGreaterThanOrEqual( 3 );
} );

test( 'bookable_variable_product_hides_price_and_sends_current_price', async () => {
	setup.products[ 0 ].type = 'variable';
	openStep( 3 );
	await userEvent.click(
		await screen.findByRole( 'radio', { name: 'Use an existing product' } )
	);
	await userEvent.selectOptions( screen.getByLabelText( 'Product' ), '5' );
	expect(
		screen.queryByLabelText( /Price of one hour/ )
	).not.toBeInTheDocument();
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 1 )
	);
	expect( posts( '/setup/product' )[ 0 ].data.productId ).toBe( 5 );
	expect( posts( '/setup/product' )[ 0 ].data.price ).toBe( '300' );
} );

test( 'comma_decimal_price_is_sent_canonical_and_previewed_localized', async () => {
	openStep( 3, '&new=1' );
	await fillNew( 'Lesson', '12,50' );
	await userEvent.click( yesRadio() );
	expect( screen.getByRole( 'complementary' ) ).toHaveTextContent(
		/2 × 12,50\s₺/
	);
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 1 )
	);
	expect( posts( '/setup/product' )[ 0 ].data.price ).toBe( '12.5' );
} );

test( 'unparsable_price_is_not_sent_and_marks_field', async () => {
	openStep( 3, '&new=1' );
	await fillNew( 'Lesson', 'abc' );
	await userEvent.click( continueBtn() );
	expect( await screen.findByRole( 'alert' ) ).toBeInTheDocument();
	expect( posts( '/setup/product' ) ).toHaveLength( 0 );
	expect( priceInput() ).toHaveAttribute( 'aria-invalid', 'true' );
	const msg = document.getElementById(
		priceInput().getAttribute( 'aria-describedby' )
	);
	expect( msg ).toHaveTextContent( 'Enter a valid price.' );
	expect( window.location.search ).toContain( 'step=3' );
} );

test( 'step3_footer_button_labels', async () => {
	openStep( 3, '&new=1' );
	expect(
		await screen.findByRole( 'button', {
			name: 'Create the product and continue',
		} )
	).toBeInTheDocument();
	await userEvent.click(
		screen.getByRole( 'radio', { name: 'Use an existing product' } )
	);
	expect(
		screen.getByRole( 'button', { name: 'Save the product and continue' } )
	).toBeInTheDocument();
} );

test( 'missing_product_id_in_response_stays_on_step', async () => {
	apiFetch.mockImplementation( ( req ) =>
		req.method === 'POST' && req.path.endsWith( '/setup/product' )
			? Promise.resolve( { product: {} } )
			: route( req )
	);
	openStep( 3, '&new=1' );
	await fillNew();
	await userEvent.click( continueBtn() );
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		/not saved correctly/
	);
	expect( window.location.search ).toContain( 'step=3' );
} );

test( 'done_unpublished_product_links_edit_not_try', async () => {
	setup.products[ 0 ].status = 'draft';
	openStep( 5 );
	const edit = await screen.findByRole( 'link', {
		name: /^Edit the product \(opens in a new tab\)$/,
	} );
	expect( edit ).toHaveAttribute( 'href', 'http://x.test/edit/5' );
	expect(
		screen.queryByRole( 'link', { name: /Try a booking/ } )
	).not.toBeInTheDocument();
} );

test( 'rerun_with_bookable_product_starts_existing_and_updates_it', async () => {
	openStep( 3 );
	expect(
		await screen.findByRole( 'radio', { name: 'Use an existing product' } )
	).toBeChecked();
	expect( screen.getByLabelText( 'Product' ) ).toHaveValue( '5' );
	expect( priceInput() ).toHaveValue( '300' );
	expect( yesRadio() ).toBeChecked();
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( window.location.search ).toContain( 'step=4' )
	);
	expect( posts( '/setup/product' ) ).toHaveLength( 1 );
	expect( posts( '/setup/product' )[ 0 ].data.productId ).toBe( 5 );
	expect( posts( '/setup/product' )[ 0 ].data.name ).toBeUndefined();
} );

test( 'new_flag_starts_in_new_mode_and_reload_after_create_updates', async () => {
	setup.products = [];
	const first = openStep( 3, '&new=1' );
	expect(
		await screen.findByRole( 'radio', { name: 'Create a new product' } )
	).toBeChecked();
	await fillNew();
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( window.location.search ).toContain( 'step=4' )
	);
	expect( posts( '/setup/product' )[ 0 ].data.productId ).toBeUndefined();
	// The URL now names the created product instead of asking for a new one.
	const params = new URLSearchParams( window.location.search );
	expect( params.get( 'new' ) ).toBeNull();
	expect( params.get( 'product' ) ).toBe( '42' );

	// Reload on step 3: the created product is picked, nothing is created again.
	first.unmount();
	setup.products = [
		{
			id: 42,
			name: 'Private lesson',
			status: 'publish',
			type: 'simple',
			durationMin: 60,
			minSlots: 1,
			maxSlots: 1,
			pricing: 'fixed',
			price: '500',
		},
	];
	openStep( 3, '&product=42' );
	expect( await screen.findByLabelText( 'Product' ) ).toHaveValue( '42' );
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 2 )
	);
	expect( posts( '/setup/product' )[ 1 ].data.productId ).toBe( 42 );
} );

test( 'new_flag_with_products_keeps_new_mode', async () => {
	openStep( 3, '&new=1' );
	expect( await screen.findByLabelText( 'Product name' ) ).toHaveValue( '' );
	expect(
		screen.getByRole( 'radio', { name: 'Create a new product' } )
	).toBeChecked();
} );

test( 'unpublished_product_shows_status_and_publish_note', async () => {
	setup.candidates[ 0 ].status = 'draft';
	setup.products[ 0 ].status = 'private';
	openStep( 3 );
	const select = await screen.findByLabelText( 'Product' );
	expect(
		within( select ).getByRole( 'option', { name: 'Old product (Draft)' } )
	).toBeInTheDocument();
	expect(
		within( select ).getByRole( 'option', {
			name: 'Per-slot product (Private)',
		} )
	).toBeInTheDocument();
	expect(
		within( select ).getByRole( 'option', { name: 'Variable product' } )
	).toBeInTheDocument();
	// The preselected private product is published on Continue.
	expect(
		screen.getByText( 'This product will be published.' )
	).toBeVisible();
	await userEvent.selectOptions( select, '9' );
	expect(
		screen.queryByText( 'This product will be published.' )
	).not.toBeInTheDocument();
	await userEvent.selectOptions( select, '7' );
	expect(
		screen.getByText( 'This product will be published.' )
	).toBeVisible();
} );

test( 'price_zero_is_allowed_negative_is_not', async () => {
	openStep( 3, '&new=1' );
	await fillNew( 'Free consultation', '-5' );
	await userEvent.click( continueBtn() );
	expect( await screen.findByRole( 'alert' ) ).toBeInTheDocument();
	expect( posts( '/setup/product' ) ).toHaveLength( 0 );
	expect( priceInput() ).toHaveAttribute( 'aria-invalid', 'true' );

	await userEvent.clear( priceInput() );
	await userEvent.type( priceInput(), '0' );
	await userEvent.click( continueBtn() );
	await waitFor( () =>
		expect( posts( '/setup/product' ) ).toHaveLength( 1 )
	);
	expect( posts( '/setup/product' )[ 0 ].data.price ).toBe( '0' );
} );

test( 'done_service_row_uses_saved_duration_after_reload', async () => {
	// Reload on step 5: the duration comes from the saved rules; the service type was never saved.
	setup.rules = { durationDefault: 45 };
	setup.products[ 0 ].durationMin = 45;
	openStep( 5 );
	await screen.findByRole( 'link', { name: /Try a booking/ } );
	const service = screen
		.getByText( 'Service', { selector: '.slotiva-done__label' } )
		.closest( '.slotiva-done__row' );
	expect( service ).toHaveTextContent( '45 min' );
	expect( service ).not.toHaveTextContent( 'Consulting' );
} );

test( 'step3_shows_own_length_note_for_existing_bookable_product', async () => {
	const note = 'This product keeps its own length (90 min).';
	setup.products[ 0 ].durationMin = 90;
	openStep( 3 );
	expect( await screen.findByText( note, { exact: false } ) ).toBeVisible();
	expect( screen.getByText( note, { exact: false } ) ).toHaveTextContent(
		'Change it in the product’s Slotiva tab.'
	);
	// A candidate has no length of its own: the note goes away.
	await userEvent.selectOptions( screen.getByLabelText( 'Product' ), '7' );
	expect( screen.queryByText( /keeps its own length/ ) ).toBeNull();
} );

test( 'step3_own_length_note_absent_when_length_matches', async () => {
	setup.products[ 0 ].durationMin = 60;
	openStep( 3 );
	await screen.findByLabelText( 'Product' );
	expect( screen.queryByText( /keeps its own length/ ) ).toBeNull();
} );

test( 'step3_preview_uses_chosen_bookable_products_own_length', async () => {
	// Store default 60, product 30: the start times step by 30 minutes, not 60.
	setup.rules = { durationDefault: 60 };
	setup.products[ 0 ].durationMin = 30;
	openStep( 3 );
	await screen.findByLabelText( 'Product' );
	const starts = document.querySelector( '.slotiva-product__starts' );
	expect( starts ).toHaveTextContent( '16:00' );
	expect( starts ).toHaveTextContent( '16:30' );
} );

test( 'step3_preview_uses_store_default_for_a_candidate', async () => {
	setup.rules = { durationDefault: 60 };
	setup.products[ 0 ].durationMin = 30;
	openStep( 3 );
	await userEvent.selectOptions(
		await screen.findByLabelText( 'Product' ),
		'7'
	);
	const starts = document.querySelector( '.slotiva-product__starts' );
	expect( starts ).toHaveTextContent( '17:00' );
	expect( starts ).not.toHaveTextContent( '16:30' );
} );

test( 'step5_shows_chosen_existing_products_length_not_store_default', async () => {
	setup.products[ 0 ].durationMin = 90;
	openStep( 5 );
	await screen.findByRole( 'link', { name: /Try a booking/ } );
	const service = screen
		.getByText( 'Service', { selector: '.slotiva-done__label' } )
		.closest( '.slotiva-done__row' );
	expect( service ).toHaveTextContent( '90 min' );
} );

test( 'saveProduct rejects a price above the ceiling', async () => {
	const wiz = ( priceText ) => ( {
		mode: 'new',
		currency: TRY,
		priceText,
		durationMin: 60,
		multi: false,
		productName: 'Lesson',
	} );
	apiFetch.mockImplementation( () =>
		Promise.resolve( { product: { id: 9 } } )
	);
	await expect(
		saveProduct( wiz( '1.000.000.000.000,01' ) )
	).rejects.toMatchObject( { data: { fields: [ 'price' ] } } );
	await expect(
		saveProduct( wiz( '1.000.000.000.000,00' ) )
	).resolves.toEqual( {
		createdId: 9,
	} );
} );

describe( 'docs help links', () => {
	const BASE = 'https://maxhandmade.github.io/mhm-slotiva-docs/docs/';
	beforeEach( () => {
		window.slotivaAdmin.docsBase = BASE;
	} );

	test( 'step4_warn_rows_link_to_the_faq_ok_rows_do_not', async () => {
		setup.checks.timezone = { state: 'warn', detail: 'UTC offset.' };
		setup.checks.payment = { state: 'warn', detail: 'No gateway.' };
		setup.checks.checkout = {
			state: 'todo',
			detail: 'Classic layout.',
			action: 'http://x.test/wp-admin/post.php?post=8&action=edit',
		};
		openStep( 4 );
		const links = await screen.findAllByRole( 'link', {
			name: /How to fix/,
		} );
		expect( links.map( ( a ) => a.getAttribute( 'href' ) ) ).toEqual( [
			`${ BASE }faq/faq/#time-zone`,
			`${ BASE }faq/faq/#no-payment-method`,
			`${ BASE }faq/faq/#picker-missing`,
		] );
		links.forEach( ( a ) => {
			expect( a ).toHaveAttribute( 'target', '_blank' );
			expect( a ).toHaveAttribute(
				'rel',
				expect.stringContaining( 'noopener' )
			);
		} );
	} );

	test( 'step4_ok_rows_have_no_fix_link', async () => {
		openStep( 4 );
		await screen.findByText( 'Everything looks ready.' );
		expect(
			screen.queryByRole( 'link', { name: /How to fix/ } )
		).not.toBeInTheDocument();
	} );

	test( 'step4_without_docs_base_draws_no_link', async () => {
		delete window.slotivaAdmin.docsBase;
		setup.checks.payment = { state: 'warn', detail: 'No gateway.' };
		openStep( 4 );
		await screen.findByText( 'No gateway.' );
		expect(
			screen.queryByRole( 'link', { name: /How to fix/ } )
		).not.toBeInTheDocument();
	} );

	test( 'step5_footer_links_to_the_setup_guide', async () => {
		openStep( 5 );
		const link = await screen.findByRole( 'link', {
			name: /First-time setup guide/,
		} );
		expect( link.getAttribute( 'href' ) ).toBe(
			`${ BASE }getting-started/setup-wizard/`
		);
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute(
			'rel',
			expect.stringContaining( 'noopener' )
		);
	} );
} );
