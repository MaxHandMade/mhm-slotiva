/**
 * JS extension points of the admin screens (`slotiva.admin.listColumns`, `slotiva.admin.detailPanels`).
 * The parity snapshots in __snapshots__ were taken from the code BEFORE the filters existed.
 */
import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { addFilter, removeAllFilters } from '@wordpress/hooks';

import List from '../appointments/App';
import Detail from '../detail/App';
import { skeleton } from '../../test-utils/skeleton';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/components', () => ( {
	Modal: ( { title, children } ) => (
		<div role="dialog" aria-label={ title }>
			{ children }
		</div>
	),
} ) );

const appt = ( over = {} ) => ( {
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
	payment_state: 'paid',
	order: { id: 501, url: 'http://x.test/order/501' },
	location: 'Online',
	events: [
		{
			type: 'status',
			actor: 'system',
			data: { from: '', to: 'held' },
			created_at: '2026-11-09T18:40:00Z',
		},
	],
	minutes_to_start: 300,
	reschedule_count: 1,
	sequence: 4,
	hold_expires_at: null,
	...over,
} );

const LIST = {
	items: [
		appt(),
		appt( { id: 8, customer: { name: 'Bob' }, order: null } ),
	],
	total: 2,
	stats: { today: 1, week: 2, awaiting_payment: 0, on_site: 0 },
};

beforeEach( () => {
	apiFetch.mockImplementation( ( request ) => {
		if ( /\/appointments\?/.test( request.path ) ) {
			return Promise.resolve( LIST );
		}
		if ( /\/appointments\/7$/.test( request.path ) ) {
			return Promise.resolve( appt() );
		}
		return Promise.resolve( {
			timezone: 'Europe/Istanbul',
			slots: [],
			days: [],
		} );
	} );
	window.slotivaAdmin = {
		restRoot: 'http://x.test/wp-json/',
		listUrl: 'http://x.test/wp-admin/admin.php?page=slotiva',
		detailUrl:
			'http://x.test/wp-admin/admin.php?page=slotiva&view=detail&id=',
		today: '2026-11-17',
		timezone: 'Europe/Istanbul',
		tzLabel: 'Europe/Istanbul (UTC+03:00)',
		products: [ { id: 11, name: 'Consulting' } ],
	};
} );
afterEach( () => {
	removeAllFilters( 'slotiva.admin.listColumns' );
	removeAllFilters( 'slotiva.admin.detailPanels' );
} );

describe( 'renders_identically_without_filters', () => {
	test( 'list', async () => {
		const { container } = render( <List /> );
		await screen.findByText( 'Ada Lovelace' );
		expect( skeleton( container ) ).toMatchSnapshot();
	} );
	test( 'list loading and empty states', async () => {
		apiFetch.mockImplementation( () =>
			Promise.resolve( { items: [], total: 0, stats: {} } )
		);
		const { container } = render( <List /> );
		expect( skeleton( container ) ).toMatchSnapshot( 'loading' );
		await screen.findByText( 'No appointments here.' );
		expect( skeleton( container ) ).toMatchSnapshot( 'empty' );
	} );
	test( 'detail', async () => {
		const { container } = render( <Detail id={ 7 } /> );
		await screen.findByText( 'History' );
		expect( skeleton( container ) ).toMatchSnapshot();
	} );
} );

const GARBAGE = [
	[ 'undefined', () => undefined ],
	[ 'null', () => null ],
	[ 'a number', () => 42 ],
	[ 'a string', () => 'column' ],
	[ 'a plain object', () => ( { id: 'a' } ) ],
	[ 'an array of non-objects', () => [ 1, 'x', null, undefined, [ 2 ] ] ],
	[
		'objects of the wrong shape',
		() => [ {}, { id: 'a' }, { id: 1, label: 'L', render: () => 'x' } ],
	],
	[
		'a throwing filter',
		() => {
			throw new Error( 'boom' );
		},
	],
];

describe( 'slotiva.admin.listColumns', () => {
	test( 'renders_extra_column_header_and_cells_before_actions', async () => {
		const seen = [];
		addFilter( 'slotiva.admin.listColumns', 'test/x', ( cols ) => {
			seen.push( cols );
			return [
				...cols,
				{
					id: 'seats',
					label: 'Seats',
					render: ( a ) => `seats-${ a.id }`,
				},
			];
		} );
		render( <List /> );
		await screen.findByText( 'Ada Lovelace' );
		expect( seen[ 0 ] ).toEqual( [] );
		const headers = screen
			.getAllByRole( 'columnheader' )
			.map( ( h ) => h.textContent );
		expect( headers ).toHaveLength( 8 );
		expect( headers[ 6 ] ).toBe( 'Seats' );
		expect( screen.getByText( 'seats-7' ) ).toHaveAttribute(
			'data-label',
			'Seats'
		);
		expect( screen.getByText( 'seats-8' ) ).toBeInTheDocument();
	} );

	test( 'loading_and_empty_rows_span_all_columns', async () => {
		addFilter( 'slotiva.admin.listColumns', 'test/x', () => [
			{ id: 'a', label: 'A', render: () => '1' },
			{ id: 'b', label: 'B', render: () => '2' },
		] );
		apiFetch.mockImplementation( () =>
			Promise.resolve( { items: [], total: 0, stats: {} } )
		);
		render( <List /> );
		const empty = await screen.findByText( 'No appointments here.' );
		expect( empty ).toHaveAttribute( 'colspan', '9' );
	} );

	test( 'renders_values_as_text_and_bad_cells_stay_empty', async () => {
		addFilter( 'slotiva.admin.listColumns', 'test/x', () => [
			{ id: 'x', label: 'X', render: () => '<b>bold</b>' },
			{ id: 'y', label: 'Y', render: () => 42 },
			{
				id: 'z',
				label: 'Z',
				render: () => {
					throw new Error( 'boom' );
				},
			},
			{ id: 'x', label: 'Duplicate', render: () => 'dup' },
		] );
		const { container } = render( <List /> );
		await screen.findByText( 'Ada Lovelace' );
		expect( container.querySelector( 'tbody b' ) ).toBeNull();
		expect( screen.getAllByText( '<b>bold</b>' ) ).toHaveLength( 2 );
		expect( screen.queryByText( 'Duplicate' ) ).toBeNull();
		expect( screen.getAllByRole( 'columnheader' ) ).toHaveLength( 10 );
	} );

	test.each( GARBAGE )(
		'garbage_return_%s_renders_nothing_extra',
		async ( n, f ) => {
			addFilter( 'slotiva.admin.listColumns', 'test/x', f );
			const { container } = render( <List /> );
			await screen.findByText( 'Ada Lovelace' );
			// Entries with an invalid shape (including a numeric id) are dropped.
			expect( screen.getAllByRole( 'columnheader' ) ).toHaveLength( 7 );
			expect(
				container.querySelectorAll( 'tbody tr:first-child td' )
			).toHaveLength( 7 );
		}
	);
} );

describe( 'slotiva.admin.detailPanels', () => {
	test( 'renders_panel_after_history_with_the_appointment', async () => {
		const seen = [];
		addFilter( 'slotiva.admin.detailPanels', 'test/x', ( panels, a ) => {
			seen.push( [ panels, a ] );
			return [
				...panels,
				{ id: 'notes', title: 'Notes', content: `Note for ${ a.id }` },
			];
		} );
		render( <Detail id={ 7 } /> );
		const heading = await screen.findByRole( 'heading', {
			level: 3,
			name: 'Notes',
		} );
		expect( screen.getByText( 'Note for 7' ) ).toBeInTheDocument();
		expect( seen[ 0 ][ 0 ] ).toEqual( [] );
		expect( seen[ 0 ][ 1 ].id ).toBe( 7 );
		const history = screen.getByRole( 'heading', {
			level: 3,
			name: 'History',
		} );
		const order = screen.getAllByRole( 'heading', { level: 3 } );
		expect( order.indexOf( heading ) ).toBeGreaterThan(
			order.indexOf( history )
		);
	} );

	test( 'renders_values_as_text', async () => {
		addFilter( 'slotiva.admin.detailPanels', 'test/x', () => [
			{ id: 'a', title: '<i>T</i>', content: '<script>x</script>' },
		] );
		const { container } = render( <Detail id={ 7 } /> );
		await screen.findByText( '<i>T</i>' );
		expect( container.querySelector( 'i, script' ) ).toBeNull();
		expect( screen.getByText( '<script>x</script>' ) ).toBeInTheDocument();
	} );

	test.each( GARBAGE )(
		'garbage_return_%s_renders_nothing_extra',
		async ( n, f ) => {
			const first = render( <Detail id={ 7 } /> );
			await screen.findByText( 'History' );
			const reference = skeleton( first.container );
			first.unmount();
			addFilter( 'slotiva.admin.detailPanels', 'test/x', f );
			const second = render( <Detail id={ 7 } /> );
			await screen.findByText( 'History' );
			expect( skeleton( second.container ) ).toBe( reference );
		}
	);
} );

describe( 'admin filters accept React elements', () => {
	test( 'detail_panel_renders_a_table_element', async () => {
		addFilter( 'slotiva.admin.detailPanels', 'test/x', () => [
			{
				id: 'people',
				title: 'People in this time',
				content: (
					<table className="x-people">
						<tbody>
							<tr>
								<td>Ada</td>
								<td>
									<a href="http://x.test/o/1">Open</a>
								</td>
							</tr>
						</tbody>
					</table>
				),
			},
		] );
		const { container } = render( <Detail id={ 7 } /> );
		await screen.findByRole( 'heading', {
			level: 3,
			name: 'People in this time',
		} );
		const panel = container.querySelector( '.slotiva-detail__panel' );
		expect( panel.querySelector( 'table.x-people' ) ).not.toBeNull();
		expect( panel.querySelector( 'p' ) ).toBeNull();
		expect( screen.getByRole( 'link', { name: 'Open' } ) ).toHaveAttribute(
			'href',
			'http://x.test/o/1'
		);
	} );

	test( 'list_cell_renders_an_element_and_text_still_works', async () => {
		addFilter( 'slotiva.admin.listColumns', 'test/x', () => [
			{
				id: 'el',
				label: 'Link',
				render: ( a ) => <a href={ `#a${ a.id }` }>Row { a.id }</a>,
			},
			{ id: 'tx', label: 'Text', render: ( a ) => `t${ a.id }` },
		] );
		render( <List /> );
		await screen.findByText( 'Ada Lovelace' );
		expect( screen.getByRole( 'link', { name: 'Row 7' } ) ).toHaveAttribute(
			'href',
			'#a7'
		);
		expect( screen.getByText( 't8' ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'columnheader' ) ).toHaveLength( 9 );
	} );

	test( 'non_element_values_are_ignored_without_throwing', async () => {
		const junk = [
			undefined,
			null,
			5,
			{},
			{ type: 'div' },
			[ 1, 'x' ],
			() => 1,
		];
		addFilter( 'slotiva.admin.listColumns', 'test/x', () =>
			junk.map( ( v, i ) => ( {
				id: `c${ i }`,
				label: `C${ i }`,
				render: () => v,
			} ) )
		);
		addFilter( 'slotiva.admin.detailPanels', 'test/x', () =>
			junk.map( ( v, i ) => ( {
				id: `p${ i }`,
				title: `P${ i }`,
				content: v,
			} ) )
		);
		const list = render( <List /> );
		await screen.findByText( 'Ada Lovelace' );
		const cells = list.container.querySelectorAll(
			'tbody tr:first-child td'
		);
		expect( cells ).toHaveLength( 7 + junk.length );
		Array.from( cells )
			.slice( 6, 6 + junk.length )
			.forEach( ( c ) => expect( c.textContent ).toBe( '' ) );
		list.unmount();
		const first = render( <Detail id={ 7 } /> );
		await screen.findByText( 'History' );
		expect(
			first.container.querySelector( '.slotiva-detail__panel' )
		).toBeNull();
	} );
} );
