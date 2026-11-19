/**
 * Setup wizard steps 0–2: welcome, service type + duration, working hours with a live customer preview.
 */
import '@testing-library/jest-dom';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import App from '../setup/App';
import { PRESETS } from '../setup/presets';
import { startsFor, weeklyCount } from '../setup/preview';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const r = ( from, to ) => ( { from, to } );
const EMPTY_WEEK = [ [], [], [], [], [], [], [] ];
const EXISTING = [
	[ r( '10:00', '12:00' ), r( '13:00', '17:00' ) ],
	[ r( '10:00', '12:00' ) ],
	[],
	[],
	[],
	[],
	[ r( '11:00', '13:00' ) ],
];

let calls;
let setup;
let putError;

function route( request ) {
	calls.push( request );
	const method = request.method || 'GET';
	if ( method === 'GET' && /\/admin\/setup$/.test( request.path ) ) {
		return Promise.resolve( setup );
	}
	if ( method === 'PUT' && putError ) {
		return Promise.reject( { code: 'x', message: putError } );
	}
	if (
		method === 'PUT' &&
		/\/admin\/(rules|schedule)$/.test( request.path )
	) {
		return Promise.resolve( request.data );
	}
	if (
		method === 'POST' &&
		/\/admin\/setup\/product$/.test( request.path )
	) {
		return Promise.resolve( { product: { id: 42 } } );
	}
	if ( method === 'POST' && /\/admin\/setup\/state$/.test( request.path ) ) {
		return Promise.resolve( { complete: true, dismissed: false } );
	}
	return Promise.reject( { code: 'unexpected', message: request.path } );
}

const puts = ( path ) =>
	calls.filter( ( c ) => c.method === 'PUT' && c.path.endsWith( path ) );

beforeEach( () => {
	document.documentElement.lang = 'en';
	calls = [];
	putError = '';
	setup = {
		rules: { durationDefault: 30 },
		schedule: { days: EMPTY_WEEK },
	};
	apiFetch.mockImplementation( route );
	window.slotivaAdmin = {
		listUrl: 'http://x.test/wp-admin/admin.php?page=slotiva',
		scheduleUrl: 'http://x.test/wp-admin/admin.php?page=slotiva-schedule',
	};
} );

function openStep( n ) {
	window.history.replaceState(
		null,
		'',
		`/wp-admin/admin.php?page=slotiva-setup&step=${ n }`
	);
	return render( <App /> );
}

test( 'welcome_starts_wizard', async () => {
	openStep( 0 );
	await userEvent.click(
		screen.getByRole( 'button', { name: /Let’s start/ } )
	);
	expect( window.location.search ).toContain( 'step=1' );
	expect(
		await screen.findByRole( 'heading', { name: 'What do you sell?' } )
	).toBeInTheDocument();
	expect( screen.getByRole( 'radio', { name: /Consulting/ } ) ).toBeChecked();
} );

test( 'service_continue_puts_rules_duration', async () => {
	openStep( 1 );
	await userEvent.click(
		await screen.findByRole( 'radio', { name: '60 min' } )
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await waitFor( () =>
		expect( window.location.search ).toContain( 'step=2' )
	);
	expect( puts( '/rules' ) ).toHaveLength( 1 );
	expect( puts( '/rules' )[ 0 ].path ).toBe( '/slotiva/v1/admin/rules' );
	expect( puts( '/rules' )[ 0 ].data ).toEqual( { durationDefault: 60 } );
} );

test( 'service_shows_saved_duration', async () => {
	setup.rules.durationDefault = 90;
	openStep( 1 );
	expect(
		await screen.findByRole( 'radio', { name: '90 min' } )
	).toBeChecked();
} );

test( 'custom_duration_must_be_multiple_of_15', async () => {
	openStep( 1 );
	await userEvent.click(
		await screen.findByRole( 'radio', { name: 'Other…' } )
	);
	const input = screen.getByLabelText( 'Minutes' );
	await userEvent.clear( input );
	await userEvent.type( input, '50' );
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		/multiple of 15 minutes/
	);
	expect( puts( '/rules' ) ).toHaveLength( 0 );
	expect( window.location.search ).toContain( 'step=1' );

	await userEvent.clear( input );
	await userEvent.type( input, '75' );
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await waitFor( () => expect( puts( '/rules' ) ).toHaveLength( 1 ) );
	expect( puts( '/rules' )[ 0 ].data ).toEqual( { durationDefault: 75 } );
} );

test( 'service_rest_error_stays_on_step_and_shows_message', async () => {
	putError = 'Server says no';
	openStep( 1 );
	await userEvent.click(
		await screen.findByRole( 'radio', { name: '45 min' } )
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		'Server says no'
	);
	expect( window.location.search ).toContain( 'step=1' );
} );

test( 'hours_preset_evening_fills_mon_sat_16_22_sunday_closed', async () => {
	openStep( 2 );
	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Evenings 16–22, Mon–Sat' } )
	);
	expect( screen.getByLabelText( 'Monday start' ) ).toHaveValue( '16:00' );
	expect( screen.getByLabelText( 'Saturday end' ) ).toHaveValue( '22:00' );
	expect(
		screen.getByRole( 'checkbox', { name: 'Sunday' } )
	).not.toBeChecked();
	expect(
		screen.getByRole( 'checkbox', { name: 'Saturday' } )
	).toBeChecked();
	expect(
		screen.getByRole( 'button', { name: 'Evenings 16–22, Mon–Sat' } )
	).toHaveAttribute( 'aria-pressed', 'true' );
	expect( PRESETS.find( ( p ) => p.id === 'evening' ).days[ 6 ] ).toEqual(
		[]
	);
} );

test( 'hours_preview_counts', async () => {
	setup.rules.durationDefault = 60;
	openStep( 2 );
	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Evenings 16–22, Mon–Sat' } )
	);
	const preview = screen.getByRole( 'complementary' );
	expect(
		within( preview ).getByText( 'Per day' ).parentElement
	).toHaveTextContent( '6 start times' );
	expect(
		within( preview ).getByText( 'Per week' ).parentElement
	).toHaveTextContent( '36 start times' );
	expect(
		within( preview ).getByText( 'Last start' ).parentElement
	).toHaveTextContent( '21:00' );
	expect( startsFor( [ r( '16:00', '22:00' ) ], 60 ) ).toHaveLength( 6 );
	expect(
		weeklyCount( PRESETS.find( ( p ) => p.id === 'evening' ).days, 60 )
	).toEqual( { perDayMax: 6, perWeek: 36, lastStart: '21:00' } );
} );

test( 'hours_existing_schedule_shown_no_preset_selected', async () => {
	setup.schedule.days = EXISTING;
	openStep( 2 );
	expect( await screen.findByLabelText( 'Tuesday start' ) ).toHaveValue(
		'10:00'
	);
	expect(
		screen.getByRole( 'checkbox', { name: 'Wednesday' } )
	).not.toBeChecked();
	const pressed = screen
		.getAllByRole( 'button' )
		.filter( ( b ) => b.getAttribute( 'aria-pressed' ) === 'true' );
	expect( pressed ).toHaveLength( 0 );
} );

test( 'hours_multi_range_day_kept_on_save', async () => {
	setup.schedule.days = EXISTING;
	openStep( 2 );
	await screen.findByLabelText( 'Tuesday start' );
	// Monday's two ranges are shown read-only with a link to the full editor.
	expect( screen.queryByLabelText( 'Monday start' ) ).toBeNull();
	expect( screen.getByRole( 'link', { name: /2 ranges/ } ) ).toHaveAttribute(
		'href',
		'http://x.test/wp-admin/admin.php?page=slotiva-schedule'
	);
	// Touch another day; Monday must stay as it is.
	await userEvent.clear( screen.getByLabelText( 'Tuesday end' ) );
	await userEvent.type( screen.getByLabelText( 'Tuesday end' ), '13:00' );
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await waitFor( () => expect( puts( '/schedule' ) ).toHaveLength( 1 ) );
	const { days } = puts( '/schedule' )[ 0 ].data;
	expect( days ).toHaveLength( 7 );
	expect( days[ 0 ] ).toEqual( EXISTING[ 0 ] );
	expect( days[ 1 ] ).toEqual( [ r( '10:00', '13:00' ) ] );
	expect( days[ 6 ] ).toEqual( EXISTING[ 6 ] );
} );

test( 'hours_continue_with_no_open_day_stays_and_explains', async () => {
	openStep( 2 );
	await screen.findByRole( 'checkbox', { name: 'Monday' } );
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	expect(
		await screen.findByText( /Open at least one day/ )
	).toBeInTheDocument();
	expect( puts( '/schedule' ) ).toHaveLength( 0 );
	expect( window.location.search ).toContain( 'step=2' );
} );

test( 'hours_continue_puts_schedule', async () => {
	openStep( 2 );
	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Evenings 16–22, Mon–Sat' } )
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await waitFor( () =>
		expect( window.location.search ).toContain( 'step=3' )
	);
	const put = puts( '/schedule' )[ 0 ];
	expect( put.path ).toBe( '/slotiva/v1/admin/schedule' );
	expect( put.data.days ).toEqual(
		PRESETS.find( ( p ) => p.id === 'evening' ).days
	);
} );

test( 'hours_closing_a_day_sends_empty_list_and_reopen_restores', async () => {
	setup.schedule.days = EXISTING;
	openStep( 2 );
	await screen.findByLabelText( 'Tuesday start' );
	await userEvent.click( screen.getByRole( 'checkbox', { name: 'Sunday' } ) );
	await userEvent.click( screen.getByRole( 'checkbox', { name: 'Sunday' } ) );
	await userEvent.click(
		screen.getByRole( 'checkbox', { name: 'Tuesday' } )
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await waitFor( () => expect( puts( '/schedule' ) ).toHaveLength( 1 ) );
	const { days } = puts( '/schedule' )[ 0 ].data;
	expect( days[ 1 ] ).toEqual( [] );
	expect( days[ 6 ] ).toEqual( EXISTING[ 6 ] );
} );

test( 'default_service_type_shows_on_step5_without_clicking_a_card', async () => {
	// R2: Continue on step 1 counts as choosing the (default) service type.
	openStep( 1 );
	await screen.findByRole( 'radio', { name: /Consulting/ } );
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Evenings 16–22, Mon–Sat' } )
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await userEvent.type(
		await screen.findByLabelText( /Price of one hour/ ),
		'500'
	);
	await userEvent.click(
		await screen.findByRole( 'button', {
			name: 'Create the product and continue',
		} )
	);
	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Continue' } )
	);
	await screen.findByRole( 'heading', { level: 1 } );
	const service = (
		await screen.findByText( 'Service', {
			selector: '.slotiva-done__label',
		} )
	).closest( '.slotiva-done__row' );
	expect( service ).toHaveTextContent( 'Consulting · 30 min' );
} );
