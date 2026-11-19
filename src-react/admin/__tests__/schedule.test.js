/**
 * Working hours (frame 8): weekly ranges (add/remove, overlap rejected before any request), exceptions (closed and
 * extra open), booking rules (15-minute step), and the "kept, outside the new schedule" notice from `affected`.
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

import App from '../schedule/App';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const r = ( from, to ) => ( { from, to } );
const WEEK = [
	[ r( '10:00', '12:00' ), r( '13:00', '17:00' ) ],
	[ r( '10:00', '12:00' ) ],
	[ r( '10:00', '12:00' ) ],
	[ r( '10:00', '12:00' ) ],
	[ r( '10:00', '12:00' ) ],
	[],
	[],
];

const RULES = {
	durationDefault: 30,
	leadHours: 24,
	horizonDays: 14,
	bufferMin: 0,
	bankHoldHours: 24,
	cardHoldMin: 45,
	reminderOn: true,
	reminderHours: 24,
	policyText: '',
	retentionMonths: 24,
	deleteDataOnUninstall: false,
};

const EXCEPTIONS = [
	{
		id: 3,
		date: '2026-10-29',
		from_time: null,
		to_time: null,
		type: 'closed',
		note: 'Public holiday',
	},
	{
		id: 4,
		date: '2026-11-18',
		from_time: '14:00',
		to_time: '16:00',
		type: 'closed',
		note: '',
	},
];

let calls;
let handlers;

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
	// Day names come from Intl in the page language; pin it so the test does not follow the machine locale.
	document.documentElement.lang = 'en';
	calls = [];
	handlers = [
		[
			'GET',
			/\/admin\/schedule$/,
			() => Promise.resolve( { days: WEEK } ),
		],
		[ 'GET', /\/admin\/rules$/, () => Promise.resolve( RULES ) ],
		[
			'GET',
			/\/admin\/exceptions$/,
			() => Promise.resolve( { items: EXCEPTIONS } ),
		],
		[
			'PUT',
			/\/admin\/schedule$/,
			( q ) =>
				Promise.resolve( {
					days: q.data.days,
					outside_hours: [],
					affected: 0,
				} ),
		],
		[
			'PUT',
			/\/admin\/rules$/,
			( q ) => Promise.resolve( { ...RULES, ...q.data } ),
		],
	];
	apiFetch.mockImplementation( route );
	window.slotivaAdmin = {
		restRoot: 'http://x.test/wp-json/',
		listUrl: 'http://x.test/wp-admin/admin.php?page=slotiva',
		today: '2026-11-16',
		tzLabel: 'Europe/Istanbul (UTC+03:00)',
		timezone: 'Europe/Istanbul',
	};
} );

const puts = ( path ) =>
	calls.filter( ( c ) => c.method === 'PUT' && c.path.endsWith( path ) );

async function ready() {
	render( <App /> );
	await screen.findByLabelText( 'Monday range 1 start' );
}

test( 'adds_and_removes_ranges', async () => {
	await ready();
	const monday = screen.getByRole( 'group', { name: 'Monday' } );
	expect( within( monday ).getAllByLabelText( /start$/ ) ).toHaveLength( 2 );

	// Remove the afternoon range, then add a new one: it starts where the last one ends.
	fireEvent.click(
		within( monday ).getByRole( 'button', {
			name: 'Remove Monday range 2',
		} )
	);
	expect( within( monday ).getAllByLabelText( /start$/ ) ).toHaveLength( 1 );
	fireEvent.click(
		within( monday ).getByRole( 'button', {
			name: 'Add a range to Monday',
		} )
	);
	expect( screen.getByLabelText( 'Monday range 2 start' ) ).toHaveValue(
		'13:00'
	);
	fireEvent.change( screen.getByLabelText( 'Monday range 2 start' ), {
		target: { value: '14:00' },
	} );
	fireEvent.change( screen.getByLabelText( 'Monday range 2 end' ), {
		target: { value: '18:00' },
	} );

	// A closed day opens with one range; turning a day off sends it empty.
	const saturday = screen.getByRole( 'group', { name: 'Saturday' } );
	expect( within( saturday ).getByText( 'Closed' ) ).toBeInTheDocument();
	fireEvent.click( within( saturday ).getByRole( 'checkbox' ) );
	expect( screen.getByLabelText( 'Saturday range 1 start' ) ).toBeVisible();
	fireEvent.click(
		within( screen.getByRole( 'group', { name: 'Friday' } ) ).getByRole(
			'checkbox'
		)
	);

	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );
	await screen.findByText( 'Changes saved.' );

	const body = puts( '/schedule' )[ 0 ].data;
	expect( body.days ).toHaveLength( 7 );
	expect( body.days[ 0 ] ).toEqual( [
		r( '10:00', '12:00' ),
		r( '14:00', '18:00' ),
	] );
	expect( body.days[ 4 ] ).toEqual( [] );
	expect( body.days[ 5 ] ).toEqual( [ r( '09:00', '17:00' ) ] );
	expect( body.days[ 6 ] ).toEqual( [] );
	expect( puts( '/rules' ) ).toHaveLength( 1 );
} );

test( 'rejects_overlap_message', async () => {
	await ready();
	fireEvent.change( screen.getByLabelText( 'Monday range 2 start' ), {
		target: { value: '11:00' },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );

	expect(
		await screen.findByText(
			'Monday: 10:00–12:00 and 11:00–17:00 overlap. Ranges on the same day must not overlap.'
		)
	).toBeInTheDocument();
	expect( puts( '/schedule' ) ).toHaveLength( 0 );
	expect( puts( '/rules' ) ).toHaveLength( 0 );

	// The server's own refusal (another tab, an older client) reads the same way.
	fireEvent.change( screen.getByLabelText( 'Monday range 2 start' ), {
		target: { value: '13:00' },
	} );
	handlers.unshift( [
		'PUT',
		/\/admin\/schedule$/,
		() =>
			Promise.reject( {
				code: 'slotiva_overlap',
				message: 'Ranges on the same day must not overlap.',
				data: { status: 400 },
			} ),
	] );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );
	expect(
		await screen.findByText( 'Ranges on the same day must not overlap.' )
	).toBeInTheDocument();
	expect( puts( '/rules' ) ).toHaveLength( 0 );
} );

test( 'affected_bookings_show_kept_notice', async () => {
	handlers.unshift( [
		'PUT',
		/\/admin\/schedule$/,
		( q ) =>
			Promise.resolve( {
				days: q.data.days,
				outside_hours: [ 41, 42 ],
				affected: 2,
			} ),
	] );
	await ready();
	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );
	expect(
		await screen.findByText(
			/2 booked appointments \(confirmed or awaiting payment\) are now outside the new schedule\. The appointments are kept/
		)
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'link', { name: 'Review upcoming appointments' } )
	).toHaveAttribute(
		'href',
		'http://x.test/wp-admin/admin.php?page=slotiva&tab=upcoming'
	);
} );

test( 'exception_open_type_saved', async () => {
	let nextId = 9;
	handlers.unshift( [
		'POST',
		/\/admin\/exceptions$/,
		( q ) =>
			Promise.resolve( {
				id: nextId++,
				note: '',
				from_time: null,
				to_time: null,
				...q.data,
				outside_hours: [],
			} ),
	] );
	await ready();

	// Existing exceptions: all-day closed and a closed time window.
	const list = screen.getByRole( 'list', { name: 'Exceptions' } );
	expect( within( list ).getAllByRole( 'listitem' ) ).toHaveLength( 2 );
	expect( within( list ).getByText( /all day/ ) ).toBeInTheDocument();
	expect( within( list ).getByText( /14:00–16:00/ ) ).toBeInTheDocument();

	fireEvent.click(
		screen.getByRole( 'button', {
			name: '+ Add closed or extra open time',
		} )
	);
	fireEvent.change( screen.getByLabelText( 'Type' ), {
		target: { value: 'open' },
	} );
	fireEvent.change( screen.getByLabelText( 'Date' ), {
		target: { value: '2026-11-21' },
	} );
	// Extra open hours need times: "all day" is offered only for closing.
	expect( screen.queryByLabelText( 'All day' ) ).not.toBeInTheDocument();
	fireEvent.change( screen.getByLabelText( 'From' ), {
		target: { value: '10:00' },
	} );
	fireEvent.change( screen.getByLabelText( 'To' ), {
		target: { value: '14:00' },
	} );
	fireEvent.change( screen.getByLabelText( 'Note (optional)' ), {
		target: { value: 'Saturday workshop' },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Add' } ) );

	await waitFor( () =>
		expect( within( list ).getAllByRole( 'listitem' ) ).toHaveLength( 3 )
	);
	const post = calls.find( ( c ) => c.method === 'POST' );
	expect( post.data ).toEqual( {
		date: '2026-11-21',
		type: 'open',
		from_time: '10:00',
		to_time: '14:00',
		note: 'Saturday workshop',
	} );
	expect( within( list ).getByText( 'Extra open' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Exception added.' ) ).toBeInTheDocument();

	// A closed all-day exception sends no times.
	fireEvent.click(
		screen.getByRole( 'button', {
			name: '+ Add closed or extra open time',
		} )
	);
	fireEvent.change( screen.getByLabelText( 'Date' ), {
		target: { value: '2026-12-31' },
	} );
	fireEvent.click( screen.getByLabelText( 'All day' ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Add' } ) );
	await waitFor( () =>
		expect( within( list ).getAllByRole( 'listitem' ) ).toHaveLength( 4 )
	);
	expect( calls.filter( ( c ) => c.method === 'POST' )[ 1 ].data ).toEqual( {
		date: '2026-12-31',
		type: 'closed',
	} );
} );

test( 'exception_delete_and_kept_notice', async () => {
	handlers.unshift( [
		'POST',
		/\/admin\/exceptions$/,
		( q ) =>
			Promise.resolve( {
				id: 10,
				note: '',
				...q.data,
				outside_hours: [ 41 ],
			} ),
	] );
	handlers.unshift( [
		'DELETE',
		/\/admin\/exceptions\/3$/,
		() => Promise.resolve( { deleted: true, id: 3, outside_hours: [] } ),
	] );
	await ready();
	const list = screen.getByRole( 'list', { name: 'Exceptions' } );
	fireEvent.click(
		within( list ).getAllByRole( 'button', { name: /^Delete/ } )[ 0 ]
	);
	await waitFor( () =>
		expect( within( list ).getAllByRole( 'listitem' ) ).toHaveLength( 1 )
	);

	fireEvent.click(
		screen.getByRole( 'button', {
			name: '+ Add closed or extra open time',
		} )
	);
	fireEvent.change( screen.getByLabelText( 'Date' ), {
		target: { value: '2026-11-17' },
	} );
	fireEvent.click( screen.getByLabelText( 'All day' ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Add' } ) );
	expect(
		await screen.findByText(
			/1 booked appointment \(confirmed or awaiting payment\) is now outside the new schedule/
		)
	).toBeInTheDocument();
} );

test( 'rules_step_validation_message', async () => {
	await ready();
	fireEvent.change( screen.getByLabelText( 'Break between appointments' ), {
		target: { value: '10' },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );
	expect(
		await screen.findByText(
			'The break between appointments must be 0 or a multiple of 15 minutes.'
		)
	).toBeInTheDocument();
	expect( puts( '/schedule' ) ).toHaveLength( 0 );
	expect( puts( '/rules' ) ).toHaveLength( 0 );

	fireEvent.change( screen.getByLabelText( 'Break between appointments' ), {
		target: { value: '15' },
	} );
	fireEvent.change( screen.getByLabelText( 'Hold time for card payments' ), {
		target: { value: '10' },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );
	expect(
		await screen.findByText(
			'The card payment hold must be at least 15 minutes.'
		)
	).toBeInTheDocument();
	expect( puts( '/rules' ) ).toHaveLength( 0 );

	fireEvent.change( screen.getByLabelText( 'Hold time for card payments' ), {
		target: { value: '60' },
	} );
	fireEvent.change( screen.getByLabelText( 'Appointment length' ), {
		target: { value: '45' },
	} );
	fireEvent.click(
		screen.getByLabelText( /Send a reminder email/, { selector: 'input' } )
	);
	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );
	await screen.findByText( 'Changes saved.' );
	expect( puts( '/rules' )[ 0 ].data ).toEqual( {
		durationDefault: 45,
		leadHours: 24,
		horizonDays: 14,
		bufferMin: 15,
		bankHoldHours: 24,
		cardHoldMin: 60,
		reminderOn: false,
		reminderHours: 24,
	} );
} );

test( 'rules_failure_after_schedule_saved_keeps_warning', async () => {
	handlers.unshift( [
		'PUT',
		/\/admin\/rules$/,
		() =>
			Promise.reject( {
				code: 'rest_cookie_invalid_nonce',
				message: 'Cookie check failed',
			} ),
	] );
	handlers.unshift( [
		'PUT',
		/\/admin\/schedule$/,
		( q ) =>
			Promise.resolve( {
				days: q.data.days,
				outside_hours: [ 41, 42 ],
				affected: 2,
			} ),
	] );
	await ready();
	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );
	expect(
		await screen.findByText(
			'Working hours saved, but the booking rules were not: Cookie check failed'
		)
	).toBeInTheDocument();
	expect(
		screen.getByText(
			/2 booked appointments \(confirmed or awaiting payment\) are now outside/
		)
	).toBeInTheDocument();
	expect( screen.queryByText( 'Changes saved.' ) ).not.toBeInTheDocument();
} );

test( 'add_range_disabled_when_day_full_and_midnight_end_kept', async () => {
	handlers.unshift( [
		'GET',
		/\/admin\/schedule$/,
		() =>
			Promise.resolve( {
				days: [
					[ r( '10:00', '24:00' ) ],
					[ r( '20:00', '22:00' ) ],
					[],
					[],
					[],
					[],
					[],
				],
			} ),
	] );
	await ready();
	// A stored 24:00 end cannot be shown by a time input: it reads 23:59 and is saved back as 24:00.
	expect( screen.getByLabelText( 'Monday range 1 end' ) ).toHaveValue(
		'23:59'
	);
	expect(
		screen.getByRole( 'button', { name: 'Add a range to Monday' } )
	).toBeDisabled();

	// After 22:00 there is still room: the new range starts at 23:00 and ends at midnight, never 24:00–24:00.
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Add a range to Tuesday' } )
	);
	expect( screen.getByLabelText( 'Tuesday range 2 start' ) ).toHaveValue(
		'23:00'
	);
	expect( screen.getByLabelText( 'Tuesday range 2 end' ) ).toHaveValue(
		'23:59'
	);
	expect(
		screen.getByRole( 'button', { name: 'Add a range to Tuesday' } )
	).toBeDisabled();

	// Typing 00:00 as an end means midnight.
	fireEvent.change( screen.getByLabelText( 'Tuesday range 1 end' ), {
		target: { value: '00:00' },
	} );
	fireEvent.change( screen.getByLabelText( 'Tuesday range 2 start' ), {
		target: { value: '12:00' },
	} );
	fireEvent.change( screen.getByLabelText( 'Tuesday range 2 end' ), {
		target: { value: '13:00' },
	} );
	fireEvent.change( screen.getByLabelText( 'Tuesday range 1 start' ), {
		target: { value: '14:00' },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );
	await screen.findByText( 'Changes saved.' );
	const body = puts( '/schedule' )[ 0 ].data;
	expect( body.days[ 0 ] ).toEqual( [ r( '10:00', '24:00' ) ] );
	expect( body.days[ 1 ] ).toEqual( [
		r( '14:00', '24:00' ),
		r( '12:00', '13:00' ),
	] );
} );

test( 'schedule_duration_help_mentions_product_tab', async () => {
	await ready();
	expect(
		screen.getByText(
			/Number of hours per order and pricing are set on each product's Slotiva tab\./
		)
	).toBeInTheDocument();
} );
