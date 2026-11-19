/**
 * Setup checklist card on the Appointments page (1.0.2, artboard Checklist).
 */
import '@testing-library/jest-dom';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import App from '../appointments/App';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/components', () => ( {
	Modal: ( { children } ) => <div role="dialog">{ children }</div>,
} ) );

const LIST = {
	items: [],
	total: 0,
	stats: { today: 0, week: 0, awaiting_payment: 0, on_site: 0 },
};

const checks = ( over = {} ) => ( {
	hours: { state: 'ok', detail: 'Working hours are set.' },
	checkout: {
		state: 'ok',
		detail: 'The checkout page uses the Checkout block.',
	},
	product: { state: 'todo', detail: 'No published bookable product yet.' },
	timezone: { state: 'ok', detail: 'Time zone: Europe/Istanbul.' },
	payment: { state: 'ok', detail: 'A payment method is available.' },
	...over,
} );

const setup = ( over = {} ) => ( {
	complete: false,
	dismissed: false,
	checks: checks(),
	...over,
} );

let handler;

beforeEach( () => {
	handler = jest.fn();
	apiFetch.mockImplementation( ( req ) => {
		if ( ! req.method && req.path.includes( '/appointments?' ) ) {
			return Promise.resolve( LIST );
		}
		return handler( req );
	} );
} );

function boot( s ) {
	window.slotivaAdmin = {
		restRoot: 'http://x.test/wp-json/',
		listUrl: 'http://x.test/wp-admin/admin.php?page=slotiva',
		detailUrl:
			'http://x.test/wp-admin/admin.php?page=slotiva&view=detail&id=',
		today: '2026-11-17',
		tzLabel: 'Europe/Istanbul',
		products: [],
		setup: s,
		setupLinks: {
			wizard: 'http://x.test/wp-admin/admin.php?page=slotiva-setup&step=0',
			product:
				'http://x.test/wp-admin/admin.php?page=slotiva-setup&step=3',
			schedule: 'http://x.test/wp-admin/admin.php?page=slotiva-schedule',
			products: 'http://x.test/wp-admin/edit.php?post_type=product',
		},
	};
	return render( <App /> );
}

test( 'checklist_hidden_when_complete_or_dismissed', async () => {
	const a = boot( setup( { complete: true } ) );
	await screen.findByText( 'No appointments here.' );
	expect( screen.queryByRole( 'progressbar' ) ).toBeNull();
	a.unmount();
	const b = boot( setup( { dismissed: true } ) );
	await screen.findByText( 'No appointments here.' );
	expect( screen.queryByRole( 'progressbar' ) ).toBeNull();
	b.unmount();
	boot( undefined );
	await screen.findByText( 'No appointments here.' );
	expect( screen.queryByRole( 'progressbar' ) ).toBeNull();
} );

test( 'checklist_shows_remaining_count_and_progress', async () => {
	boot( setup() );
	await screen.findByText( 'No appointments here.' );
	expect( screen.getByText( '1 step left' ) ).toBeInTheDocument();
	const bar = screen.getByRole( 'progressbar' );
	expect( bar ).toHaveAttribute( 'aria-valuenow', '2' );
	expect( bar ).toHaveAttribute( 'aria-valuemax', '3' );
	expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 3 );
	expect( document.body ).not.toHaveTextContent( /licen[cs]e|lisans/i );
} );

test( 'checklist_with_all_required_done_shows_done_state_and_no_license', async () => {
	boot(
		setup( {
			checks: checks( {
				product: { state: 'ok', detail: 'A bookable product exists.' },
			} ),
		} )
	);
	await screen.findByText( 'No appointments here.' );
	expect( screen.getByText( '0 steps left' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'progressbar' ) ).toHaveAttribute(
		'aria-valuenow',
		'3'
	);
	expect( document.body ).not.toHaveTextContent( /licen[cs]e|lisans/i );
} );

test( 'checklist_counts_two_steps_left', async () => {
	boot(
		setup( {
			checks: checks( {
				hours: { state: 'todo', detail: 'No working hours yet.' },
			} ),
		} )
	);
	expect( await screen.findByText( '2 steps left' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'progressbar' ) ).toHaveAttribute(
		'aria-valuenow',
		'1'
	);
} );

test( 'product_todo_explains_slotiva_tab_and_links_wizard_step3', async () => {
	boot( setup() );
	await screen.findByText( '1 step left' );
	const row = screen.getByText( 'A bookable product' ).closest( 'li' );
	expect( within( row ).getByText( /Slotiva/ ) ).toBeInTheDocument();
	expect(
		within( row ).getByRole( 'link', { name: 'Create with the wizard' } )
	).toHaveAttribute(
		'href',
		expect.stringContaining( 'page=slotiva-setup&step=3' )
	);
	expect(
		within( row ).getByRole( 'link', { name: 'Go to products' } )
	).toHaveAttribute( 'href', expect.stringContaining( 'post_type=product' ) );
} );

test( 'hours_done_row_summarises_the_week_like_the_artboard', async () => {
	const day = [ { from: '09:00', to: '17:00' } ];
	boot(
		setup( {
			schedule: { days: [ day, day, day, day, day, [], [] ] },
		} )
	);
	await screen.findByText( '1 step left' );
	const row = screen.getByText( 'Working hours' ).closest( 'li' );
	// Day names follow the page language (Intl); the grouping is what this row adds.
	expect( row ).toHaveTextContent( /\S+–\S+ 09:00–17:00 · \S+–\S+ closed/ );
	expect( row ).not.toHaveTextContent( 'Working hours are set.' );
} );

test( 'hours_row_keeps_server_detail_without_schedule', async () => {
	boot( setup() );
	await screen.findByText( '1 step left' );
	const row = screen.getByText( 'Working hours' ).closest( 'li' );
	expect( row ).toHaveTextContent( 'Working hours are set.' );
} );

test( 'hide_posts_dismissed_and_removes', async () => {
	handler.mockResolvedValue( { complete: false, dismissed: true } );
	boot( setup() );
	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Hide' } )
	);
	await waitFor( () =>
		expect( screen.queryByRole( 'progressbar' ) ).toBeNull()
	);
	expect( handler ).toHaveBeenCalledWith(
		expect.objectContaining( {
			method: 'POST',
			path: '/slotiva/v1/admin/setup/state',
			data: { dismissed: true },
		} )
	);
} );

test( 'hide_failure_keeps_card_and_shows_error', async () => {
	handler.mockRejectedValue( { code: 'x', message: 'Nope' } );
	boot( setup() );
	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Hide' } )
	);
	expect( await screen.findByText( 'Nope' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'progressbar' ) ).toBeInTheDocument();
} );
