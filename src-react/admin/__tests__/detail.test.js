/**
 * Appointment detail (frame 7): change count, under-24h warning, status actions, reschedule with expect_sequence,
 * 409 handling (slot taken / stale / busy), extend hold for bank transfers only.
 */
import '@testing-library/jest-dom';
import {
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import App from '../detail/App';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const detail = ( over = {} ) => ( {
	id: 7,
	starts_at: '2026-11-17T08:30:00Z',
	ends_at: '2026-11-17T09:00:00Z',
	local_date: '2026-11-17',
	local_time: '11:30',
	product: { id: 11, name: 'Consulting' },
	customer: {
		name: 'Ada Lovelace',
		phone: '0555',
		email: 'ada@example.test',
	},
	status: 'confirmed',
	payment_state: 'on_site',
	order: { id: 11617, url: 'http://x.test/order/11617' },
	location: 'Online',
	events: [
		{
			type: 'status',
			actor: 'system',
			data: { from: '', to: 'held' },
			created_at: '2026-11-09T18:40:00Z',
		},
		{
			type: 'status',
			actor: 'system',
			data: { from: 'held', to: 'confirmed' },
			created_at: '2026-11-09T18:41:00Z',
		},
	],
	minutes_to_start: 300,
	reschedule_count: 1,
	sequence: 4,
	hold_expires_at: null,
	...over,
} );

const SLOTS = ( day ) => ( {
	timezone: 'Europe/Istanbul',
	tz_label: 'Europe/Istanbul (UTC+03:00)',
	day,
	step: 15,
	slots: [
		{ start: `${ day }T07:00:00Z`, label: '10:00' },
		{ start: `${ day }T08:30:00Z`, label: '11:30' },
	],
} );

let calls;
let handlers;
let current;

function route( request ) {
	calls.push( request );
	const method = request.method || 'GET';
	for ( const [ m, re, fn ] of handlers ) {
		if ( m === method && re.test( request.path ) ) {
			return fn( request );
		}
	}
	return Promise.reject( { code: 'unexpected', message: request.path } );
}

beforeEach( () => {
	calls = [];
	current = detail();
	handlers = [
		[ 'GET', /\/appointments\/7$/, () => Promise.resolve( current ) ],
		[
			'GET',
			/\/availability\?/,
			( r ) =>
				Promise.resolve(
					SLOTS(
						new URLSearchParams( r.path.split( '?' )[ 1 ] ).get(
							'day'
						)
					)
				),
		],
	];
	apiFetch.mockImplementation( route );
	window.slotivaAdmin = {
		restRoot: 'http://x.test/wp-json/',
		listUrl: 'http://x.test/wp-admin/admin.php?page=slotiva',
		detailUrl:
			'http://x.test/wp-admin/admin.php?page=slotiva&view=detail&id=',
		today: '2026-11-17',
		timezone: 'Europe/Istanbul',
		tzLabel: 'Europe/Istanbul (UTC+03:00)',
	};
} );

const posts = () => calls.filter( ( c ) => c.method === 'POST' );

async function pickTomorrow1000() {
	const days = await screen.findByRole( 'group', { name: 'Day' } );
	const buttons = within( days ).getAllByRole( 'button' );
	// Second day of the strip = 2026-11-18.
	await waitFor( () => expect( buttons[ 1 ] ).not.toBeDisabled() );
	fireEvent.click( buttons[ 1 ] );
	const times = screen.getByRole( 'group', { name: 'Time' } );
	await waitFor( () =>
		expect(
			within( times ).getByRole( 'button', { name: '10:00' } )
		).toBeInTheDocument()
	);
	fireEvent.click( within( times ).getByRole( 'button', { name: '10:00' } ) );
}

test( 'detail_shows_change_count_and_warning_under_24h', async () => {
	render( <App id={ 7 } /> );
	expect(
		await screen.findByRole( 'heading', { level: 1, name: /11:30–12:00/ } )
	).toBeInTheDocument();
	expect( screen.getByText( '1 change used' ) ).toBeInTheDocument();
	// v1 shows usage only; no limit is displayed or enforced (spec §6).
	expect( screen.queryByText( /free changes/ ) ).toBeNull();

	const left = screen.getByText( /5 h 0 min/ ).closest( 'dd' );
	expect( left ).toHaveTextContent( 'under 24 hours' );
	expect(
		left.querySelector( '.mhmui-detail-list__mark--warning' )
	).not.toBeNull();

	// History from slotiva_events.
	const history = screen.getByRole( 'list', { name: 'History' } );
	expect( within( history ).getAllByRole( 'listitem' ) ).toHaveLength( 2 );
	expect( history ).toHaveTextContent( 'Held → Confirmed' );

	// Badges: status and payment separately.
	expect( screen.getAllByText( 'Confirmed' )[ 0 ] ).toHaveClass(
		'mhmui-status'
	);
	expect(
		screen.getByText( 'Pay on site', { selector: '.mhmui-status' } )
	).toBeInTheDocument();
	// Not a bank transfer → no extend-hold control.
	expect(
		screen.queryByRole( 'button', { name: 'Extend hold' } )
	).toBeNull();
} );

test( 'anonymised_event_has_a_label', async () => {
	current = detail( {
		events: [
			{
				type: 'anonymised',
				actor: 'system',
				data: {},
				created_at: '2026-11-09T18:42:00Z',
			},
		],
	} );
	render( <App id={ 7 } /> );
	const history = await screen.findByRole( 'list', { name: 'History' } );
	expect( history ).toHaveTextContent( 'Personal details removed' );
	expect( history ).not.toHaveTextContent( /^anonymised$/ );
} );

test( 'detail_over_24h_has_no_warning', async () => {
	current = detail( { minutes_to_start: 3000, reschedule_count: 0 } );
	render( <App id={ 7 } /> );
	expect( await screen.findByText( '0 changes used' ) ).toBeInTheDocument();
	const left = screen.getByText( /2 d 2 h/ ).closest( 'dd' );
	expect(
		left.querySelector( '.mhmui-detail-list__mark--warning' )
	).toBeNull();
} );

test( 'status_actions_use_confirm_button', async () => {
	current = detail( { minutes_to_start: -30 } );
	handlers.unshift( [
		'POST',
		/\/appointments\/7\/status$/,
		( r ) => Promise.resolve( detail( { status: r.data.status } ) ),
	] );
	render( <App id={ 7 } /> );
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Mark completed' } )
	);
	expect( posts() ).toHaveLength( 0 );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Yes, mark completed' } )
	);
	await waitFor( () => expect( posts() ).toHaveLength( 1 ) );
	expect( posts()[ 0 ].data ).toEqual( {
		status: 'completed',
		notify: true,
	} );
	await screen.findByText( 'Status updated.' );
} );

test( 'completed_and_no_show_are_disabled_before_the_start', async () => {
	// Server refuses both with 409 while starts_at is in the future; the buttons are locked with a reason.
	current = detail( { minutes_to_start: 300 } );
	render( <App id={ 7 } /> );
	const done = await screen.findByRole( 'button', {
		name: 'Mark completed',
	} );
	const noShow = screen.getByRole( 'button', { name: 'Mark no-show' } );
	expect( done ).toHaveAttribute( 'aria-disabled', 'true' );
	expect( noShow ).toHaveAttribute( 'aria-disabled', 'true' );
	expect( screen.getByText( /once it has started/ ) ).toBeInTheDocument();
	fireEvent.click( done );
	expect(
		screen.queryByRole( 'button', { name: 'Yes, mark completed' } )
	).toBeNull();
} );

test( 'completed_and_no_show_are_enabled_after_the_start', async () => {
	current = detail( { minutes_to_start: -30 } );
	render( <App id={ 7 } /> );
	const done = await screen.findByRole( 'button', {
		name: 'Mark completed',
	} );
	expect( done ).not.toHaveAttribute( 'aria-disabled', 'true' );
	expect(
		screen.getByRole( 'button', { name: 'Mark no-show' } )
	).not.toHaveAttribute( 'aria-disabled', 'true' );
	expect( screen.queryByText( /once it has started/ ) ).toBeNull();
} );

test( 'reschedule_posts_and_shows_new_time', async () => {
	handlers.unshift( [
		'POST',
		/\/appointments\/7\/reschedule$/,
		() =>
			Promise.resolve(
				detail( {
					starts_at: '2026-11-18T07:00:00Z',
					ends_at: '2026-11-18T07:30:00Z',
					local_date: '2026-11-18',
					local_time: '10:00',
					reschedule_count: 2,
					sequence: 5,
					minutes_to_start: 1800,
				} )
			),
	] );
	render( <App id={ 7 } /> );
	await pickTomorrow1000();
	expect( screen.getByText( /^New:/ ).closest( 'p' ) ).toHaveTextContent(
		'10:00'
	);

	// Own current time is shown but not selectable.
	const days = screen.getByRole( 'group', { name: 'Day' } );
	fireEvent.click( within( days ).getAllByRole( 'button' )[ 0 ] );
	await waitFor( () =>
		expect(
			within( screen.getByRole( 'group', { name: 'Time' } ) ).getByRole(
				'button',
				{
					name: /11:30/,
				}
			)
		).toBeDisabled()
	);
	fireEvent.click( within( days ).getAllByRole( 'button' )[ 1 ] );
	fireEvent.click(
		await within(
			screen.getByRole( 'group', { name: 'Time' } )
		).findByRole( 'button', { name: '10:00' } )
	);

	fireEvent.click( screen.getByRole( 'button', { name: 'Change time' } ) );
	await waitFor( () => expect( posts() ).toHaveLength( 1 ) );
	expect( posts()[ 0 ].data ).toEqual( {
		start: '2026-11-18T07:00:00Z',
		count_change: true,
		notify: true,
		expect_sequence: 4,
	} );
	expect(
		await screen.findByRole( 'heading', { level: 1, name: /10:00–10:30/ } )
	).toBeInTheDocument();
	expect( screen.getByText( '2 changes used' ) ).toBeInTheDocument();
	expect( screen.getByText( /Time changed to/ ) ).toBeInTheDocument();
} );

test( 'conflict_409_shows_notice', async () => {
	let reply = {
		code: 'slotiva_slot_taken',
		message: 'taken',
		data: { status: 409 },
	};
	handlers.unshift( [
		'POST',
		/\/appointments\/7\/reschedule$/,
		() => Promise.reject( reply ),
	] );
	render( <App id={ 7 } /> );
	await pickTomorrow1000();
	const availBefore = calls.filter( ( c ) =>
		c.path.includes( '/availability' )
	).length;
	fireEvent.click( screen.getByRole( 'button', { name: 'Change time' } ) );
	expect(
		await screen.findByText(
			'That time was just taken. Pick another time.'
		)
	).toBeInTheDocument();
	// Old time kept; grid refreshed.
	expect(
		screen.getByRole( 'heading', { level: 1, name: /11:30–12:00/ } )
	).toBeInTheDocument();
	await waitFor( () =>
		expect(
			calls.filter( ( c ) => c.path.includes( '/availability' ) ).length
		).toBeGreaterThan( availBefore )
	);

	// Stale sequence: the detail is fetched again and a notice explains why.
	reply = { code: 'slotiva_stale', message: 'stale', data: { status: 409 } };
	current = detail( { sequence: 6, reschedule_count: 2 } );
	await pickTomorrow1000();
	const gets = calls.filter( ( c ) =>
		/\/appointments\/7$/.test( c.path )
	).length;
	fireEvent.click( screen.getByRole( 'button', { name: 'Change time' } ) );
	expect(
		await screen.findByText( /changed elsewhere/ )
	).toBeInTheDocument();
	await waitFor( () =>
		expect(
			calls.filter( ( c ) => /\/appointments\/7$/.test( c.path ) ).length
		).toBeGreaterThan( gets )
	);
	expect( await screen.findByText( '2 changes used' ) ).toBeInTheDocument();

	// Busy lock.
	reply = { code: 'slotiva_busy', message: 'busy', data: { status: 409 } };
	await pickTomorrow1000();
	fireEvent.click( screen.getByRole( 'button', { name: 'Change time' } ) );
	expect(
		await screen.findByText( /Another change to this order/ )
	).toBeInTheDocument();
	expect( posts().at( -1 ).data.expect_sequence ).toBe( 6 );
} );

test( 'extend_hold_only_for_awaiting_transfer', async () => {
	current = detail( {
		status: 'held',
		payment_state: 'awaiting_transfer',
		hold_expires_at: '2026-11-18T09:00:00Z',
	} );
	handlers.unshift( [
		'POST',
		/\/appointments\/7\/extend-hold$/,
		() =>
			Promise.resolve( {
				extended: 1,
				order_id: 11617,
				hold_expires_at: '2026-11-19T09:00:00Z',
			} ),
	] );
	render( <App id={ 7 } /> );
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Extend hold' } )
	);
	await waitFor( () => expect( posts() ).toHaveLength( 1 ) );
	expect( posts()[ 0 ].path ).toBe(
		'/slotiva/v1/admin/appointments/7/extend-hold'
	);
	expect( posts()[ 0 ].data ).toEqual( { hours: 24 } );
	await screen.findByText( /Payment window extended/ );
} );

test( 'conflict_can_be_rescheduled_or_cancelled', async () => {
	// Final review F3: a paid booking whose slot was taken (conflict) gets a new time or is cancelled in the app.
	current = detail( { status: 'conflict', payment_state: 'paid' } );
	render( <App id={ 7 } /> );
	expect(
		await screen.findByRole( 'button', { name: 'Cancel appointment…' } )
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'button', { name: 'Change time' } )
	).toBeInTheDocument();
	expect(
		screen.queryByRole( 'button', { name: 'Mark completed' } )
	).toBeNull();
} );

test( 'conflict_cancel_confirm_does_not_claim_a_freed_time', async () => {
	// 1.0.1: a conflict holds no time, so its cancel confirmation must not say the time becomes free.
	current = detail( { status: 'conflict', payment_state: 'paid' } );
	render( <App id={ 7 } /> );
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Cancel appointment…' } )
	);
	expect( screen.getByText( /holds no time/ ) ).toBeInTheDocument();
	expect( screen.queryByText( /becomes free again/ ) ).toBeNull();
} );

test( 'active_cancel_confirm_says_the_time_becomes_free', async () => {
	render( <App id={ 7 } /> );
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Cancel appointment…' } )
	);
	expect( screen.getByText( /becomes free again/ ) ).toBeInTheDocument();
} );
