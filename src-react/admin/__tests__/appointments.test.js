/**
 * Appointments screen (frame 5/6): tabs, separate status/payment badges, cancel with in-page confirmation, manual add.
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

import App from '../appointments/App';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// The real Modal pulls @wordpress/components' private-API lock, which fails to initialise under Jest here; a plain
// dialog with the same contract (title, onRequestClose, children) stands in. The build uses the real one (wp-components).
jest.mock( '@wordpress/components', () => ( {
	Modal: ( { title, onRequestClose, children } ) => (
		<div role="dialog" aria-label={ title }>
			<h1>{ title }</h1>
			<button type="button" onClick={ onRequestClose }>
				Close
			</button>
			{ children }
		</div>
	),
} ) );

const appt = ( over = {} ) => ( {
	id: 1,
	starts_at: '2026-11-17T07:00:00Z',
	ends_at: '2026-11-17T07:30:00Z',
	local_date: '2026-11-17',
	local_time: '10:00',
	product: { id: 11, name: 'Consulting' },
	customer: {
		name: 'Ada Lovelace',
		phone: '0555',
		email: 'ada@example.test',
	},
	status: 'confirmed',
	payment_state: 'paid',
	order: { id: 501, url: 'http://x.test/wp-admin/post.php?post=501' },
	location: '',
	...over,
} );

const LIST = {
	items: [
		appt(),
		appt( {
			id: 2,
			local_time: '14:00',
			starts_at: '2026-11-17T11:00:00Z',
			ends_at: '2026-11-17T11:30:00Z',
			customer: { name: 'Bob', phone: '', email: '' },
			status: 'held',
			payment_state: 'awaiting_transfer',
			order: { id: 502, url: '' },
		} ),
	],
	total: 2,
	stats: { today: 3, week: 7, awaiting_payment: 1, on_site: 4 },
};

const SLOTS = {
	timezone: 'Europe/Istanbul',
	tz_label: 'Europe/Istanbul (UTC+03:00)',
	step: 15,
	slots: [
		{ start: '2026-11-17T07:00:00Z', label: '10:00' },
		{ start: '2026-11-17T07:15:00Z', label: '10:15' },
	],
};

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
	calls = [];
	handlers = [
		[ 'GET', /\/appointments\?/, () => Promise.resolve( LIST ) ],
		[
			'GET',
			/\/availability\?/,
			( r ) =>
				Promise.resolve( {
					...SLOTS,
					day: new URLSearchParams( r.path.split( '?' )[ 1 ] ).get(
						'day'
					),
				} ),
		],
	];
	apiFetch.mockImplementation( route );
	window.slotivaAdmin = {
		restRoot: 'http://x.test/wp-json/',
		exportNonce: 'n0nce',
		listUrl: 'http://x.test/wp-admin/admin.php?page=slotiva',
		detailUrl:
			'http://x.test/wp-admin/admin.php?page=slotiva&view=detail&id=',
		today: '2026-11-17',
		tzLabel: 'Europe/Istanbul (UTC+03:00)',
		timezone: 'Europe/Istanbul',
		products: [ { id: 11, name: 'Consulting' } ],
	};
} );

const listCalls = () =>
	calls.filter( ( c ) => ! c.method && c.path.includes( '/appointments?' ) );

test( 'list_renders_tabs_and_badges', async () => {
	render( <App /> );
	await screen.findByText( 'Ada Lovelace' );

	const nav = screen.getByRole( 'navigation', {
		name: 'Appointment filter',
	} );
	const tabs = within( nav ).getAllByRole( 'link' );
	expect( tabs.map( ( t ) => t.textContent.replace( /\d.*$/, '' ) ) ).toEqual(
		[ 'Today', 'Upcoming', 'Past', 'Cancelled' ]
	);
	expect(
		within( nav ).getByRole( 'link', { name: /Upcoming/ } )
	).toHaveAttribute( 'aria-current', 'page' );
	expect( listCalls()[ 0 ].path ).toContain( 'tab=upcoming' );

	// Status and payment are two separate badges with their own tones.
	const row = screen.getByText( 'Ada Lovelace' ).closest( 'tr' );
	expect( within( row ).getByText( 'Confirmed' ) ).toHaveClass(
		'mhmui-status--success'
	);
	expect( within( row ).getByText( 'Paid' ) ).toHaveClass(
		'mhmui-status--success'
	);
	const held = screen.getByText( 'Bob' ).closest( 'tr' );
	expect( within( held ).getByText( 'Held' ) ).toHaveClass(
		'mhmui-status--warning'
	);
	expect( within( held ).getByText( 'Awaiting transfer' ) ).toHaveClass(
		'mhmui-status--warning'
	);
	expect(
		within( row ).getByRole( 'link', { name: 'Open' } )
	).toHaveAttribute(
		'href',
		'http://x.test/wp-admin/admin.php?page=slotiva&view=detail&id=1'
	);
	expect(
		within( row ).getByRole( 'link', { name: '#501' } )
	).toHaveAttribute( 'href', 'http://x.test/wp-admin/post.php?post=501' );

	// Stats cards.
	expect(
		screen.getByText( 'This week' ).closest( '.mhmui-stat-card' )
	).toHaveTextContent( '7' );
	expect(
		screen.getByText( 'Pay on site', { selector: '.mhmui-stat-card *' } )
	).toBeInTheDocument();

	// CSV export is a plain link with the nonce in the query.
	expect(
		screen.getByRole( 'link', { name: 'Export CSV' } )
	).toHaveAttribute(
		'href',
		'http://x.test/wp-json/slotiva/v1/admin/export.csv?_wpnonce=n0nce'
	);

	// Switching tab refetches that tab.
	fireEvent.click( within( nav ).getByRole( 'link', { name: /Past/ } ) );
	await waitFor( () =>
		expect( listCalls().at( -1 ).path ).toContain( 'tab=past' )
	);
} );

test( 'cancel_requires_confirm', async () => {
	handlers.unshift( [
		'POST',
		/\/appointments\/1\/status$/,
		() => Promise.resolve( { ...appt(), status: 'cancelled' } ),
	] );
	render( <App /> );
	const row = ( await screen.findByText( 'Ada Lovelace' ) ).closest( 'tr' );

	fireEvent.click( within( row ).getByRole( 'button', { name: 'Cancel' } ) );
	// Nothing is sent until the in-page confirmation is accepted.
	expect( calls.filter( ( c ) => c.method === 'POST' ) ).toHaveLength( 0 );
	expect(
		within( row ).getByText( /Cancel this appointment\?/ )
	).toBeInTheDocument();

	const before = listCalls().length;
	fireEvent.click(
		within( row ).getByRole( 'button', { name: 'Cancel appointment' } )
	);
	await waitFor( () =>
		expect( calls.filter( ( c ) => c.method === 'POST' ) ).toHaveLength( 1 )
	);
	const post = calls.find( ( c ) => c.method === 'POST' );
	expect( post.path ).toBe( '/slotiva/v1/admin/appointments/1/status' );
	expect( post.data ).toEqual( { status: 'cancelled', notify: true } );
	await screen.findByText( 'Appointment cancelled.' );
	await waitFor( () =>
		expect( listCalls().length ).toBeGreaterThan( before )
	);
} );

test( 'cancel_can_be_dismissed_without_request', async () => {
	render( <App /> );
	const row = ( await screen.findByText( 'Ada Lovelace' ) ).closest( 'tr' );
	fireEvent.click( within( row ).getByRole( 'button', { name: 'Cancel' } ) );
	fireEvent.click( within( row ).getByRole( 'button', { name: 'Keep' } ) );
	expect( calls.filter( ( c ) => c.method === 'POST' ) ).toHaveLength( 0 );
} );

async function openAddAndPick() {
	render( <App /> );
	await screen.findByText( 'Ada Lovelace' );
	fireEvent.click(
		screen.getByRole( 'button', { name: '+ Add appointment' } )
	);
	const dialog = await screen.findByRole( 'dialog' );
	const time = await within( dialog ).findByRole( 'button', {
		name: '10:15',
	} );
	fireEvent.click( time );
	fireEvent.change( within( dialog ).getByLabelText( /Customer name/ ), {
		target: { value: 'Grace Hopper' },
	} );
	fireEvent.change( within( dialog ).getByLabelText( /Phone/ ), {
		target: { value: '0555 111' },
	} );
	return dialog;
}

test( 'manual_add_posts_and_refreshes', async () => {
	handlers.unshift( [
		'POST',
		/\/appointments$/,
		( r ) =>
			Promise.resolve(
				appt( {
					id: 9,
					customer: r.data.customer,
					status: 'confirmed',
					payment_state: 'manual',
					order: null,
				} )
			),
	] );
	const dialog = await openAddAndPick();
	const before = listCalls().length;
	fireEvent.click(
		within( dialog ).getByRole( 'button', { name: 'Add appointment' } )
	);

	await waitFor( () =>
		expect( calls.filter( ( c ) => c.method === 'POST' ) ).toHaveLength( 1 )
	);
	const post = calls.find( ( c ) => c.method === 'POST' );
	expect( post.path ).toBe( '/slotiva/v1/admin/appointments' );
	// Blank e-mail is omitted (the schema rejects "").
	expect( post.data ).toEqual( {
		product_id: 11,
		slot: '2026-11-17T07:15:00Z',
		customer: { name: 'Grace Hopper', phone: '0555 111' },
		notify: true,
	} );
	await screen.findByText( 'Appointment added.' );
	await waitFor( () =>
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument()
	);
	await waitFor( () =>
		expect( listCalls().length ).toBeGreaterThan( before )
	);
} );

test( 'manual_add_409_slot_taken_keeps_dialog_and_refreshes_times', async () => {
	handlers.unshift( [
		'POST',
		/\/appointments$/,
		() =>
			Promise.reject( {
				code: 'slotiva_slot_taken',
				message: 'taken',
				data: { status: 409, taken: [ '2026-11-17T07:15:00Z' ] },
			} ),
	] );
	const dialog = await openAddAndPick();
	const availBefore = calls.filter( ( c ) =>
		c.path.includes( '/availability' )
	).length;
	fireEvent.click(
		within( dialog ).getByRole( 'button', { name: 'Add appointment' } )
	);
	expect(
		await within( dialog ).findByText(
			'That time was just taken. Pick another time.'
		)
	).toBeInTheDocument();
	expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	await waitFor( () =>
		expect(
			calls.filter( ( c ) => c.path.includes( '/availability' ) ).length
		).toBeGreaterThan( availBefore )
	);
} );

test( 'manual_add_requires_name', async () => {
	render( <App /> );
	await screen.findByText( 'Ada Lovelace' );
	fireEvent.click(
		screen.getByRole( 'button', { name: '+ Add appointment' } )
	);
	const dialog = await screen.findByRole( 'dialog' );
	fireEvent.click(
		await within( dialog ).findByRole( 'button', { name: '10:00' } )
	);
	fireEvent.change( within( dialog ).getByLabelText( /Customer name/ ), {
		target: { value: '   ' },
	} );
	fireEvent.click(
		within( dialog ).getByRole( 'button', { name: 'Add appointment' } )
	);
	expect(
		await within( dialog ).findByText( 'Enter the customer name.' )
	).toBeInTheDocument();
	expect( calls.filter( ( c ) => c.method === 'POST' ) ).toHaveLength( 0 );
} );

test( 'anonymised_customer_renders_a_dash_not_an_empty_cell', async () => {
	const original = LIST.items[ 1 ].customer;
	LIST.items[ 1 ].customer = { name: '', phone: '', email: '' };
	try {
		render( <App /> );
		const row = ( await screen.findByText( '#502' ) ).closest( 'tr' );
		expect( within( row ).getByText( '—' ) ).toBeInTheDocument();
		expect( within( row ).getByText( 'Held' ) ).toBeInTheDocument();
	} finally {
		LIST.items[ 1 ].customer = original;
	}
} );
