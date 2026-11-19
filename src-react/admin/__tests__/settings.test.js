/**
 * Settings: policy text, retention (months), delete data on uninstall (off by default), link to WooCommerce emails.
 * Only these three rule fields are sent; the booking rules belong to the Working hours screen.
 */
import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import App from '../settings/App';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const RULES = {
	durationDefault: 30,
	leadHours: 24,
	horizonDays: 14,
	bufferMin: 0,
	bankHoldHours: 24,
	cardHoldMin: 45,
	reminderOn: true,
	reminderHours: 24,
	policyText: 'Free changes up to 24 hours before.',
	retentionMonths: 24,
	deleteDataOnUninstall: false,
};

let calls;
let putResult;

beforeEach( () => {
	calls = [];
	putResult = null;
	apiFetch.mockImplementation( ( request ) => {
		calls.push( request );
		if ( ! request.method && request.path.endsWith( '/admin/rules' ) ) {
			return Promise.resolve( RULES );
		}
		if (
			request.method === 'PUT' &&
			request.path.endsWith( '/admin/rules' )
		) {
			return (
				putResult || Promise.resolve( { ...RULES, ...request.data } )
			);
		}
		return Promise.reject( { code: 'unexpected', message: request.path } );
	} );
	window.slotivaAdmin = {
		restRoot: 'http://x.test/wp-json/',
		emailSettingsUrl:
			'http://x.test/wp-admin/admin.php?page=wc-settings&tab=email',
	};
} );

const puts = () => calls.filter( ( c ) => c.method === 'PUT' );

test( 'settings_save_policy_retention_and_uninstall', async () => {
	render( <App /> );
	const policy = await screen.findByLabelText(
		'Cancellation and change policy'
	);
	expect( policy ).toHaveValue( 'Free changes up to 24 hours before.' );
	const wipe = screen.getByLabelText(
		/Delete all Slotiva data when the plugin is deleted/
	);
	expect( wipe ).not.toBeChecked();
	expect(
		screen.getByRole( 'link', { name: 'Open WooCommerce email settings' } )
	).toHaveAttribute(
		'href',
		'http://x.test/wp-admin/admin.php?page=wc-settings&tab=email'
	);

	fireEvent.change( policy, {
		target: { value: 'Cancel up to 24 hours before.' },
	} );
	fireEvent.change( screen.getByLabelText( 'Keep personal details for' ), {
		target: { value: '12' },
	} );
	fireEvent.click( wipe );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save settings' } ) );
	await screen.findByText( 'Settings saved.' );

	expect( puts() ).toHaveLength( 1 );
	expect( puts()[ 0 ].data ).toEqual( {
		policyText: 'Cancel up to 24 hours before.',
		retentionMonths: 12,
		deleteDataOnUninstall: true,
	} );
} );

test( 'settings_retention_must_be_a_whole_month', async () => {
	render( <App /> );
	await screen.findByLabelText( 'Cancellation and change policy' );
	fireEvent.change( screen.getByLabelText( 'Keep personal details for' ), {
		target: { value: '0' },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save settings' } ) );
	const range = 'Keep personal details for 1 to 120 whole months.';
	expect( await screen.findByText( range ) ).toBeInTheDocument();
	fireEvent.change( screen.getByLabelText( 'Keep personal details for' ), {
		target: { value: '121' },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save settings' } ) );
	expect( await screen.findByText( range ) ).toBeInTheDocument();
	expect(
		screen.getByLabelText( 'Keep personal details for' )
	).toHaveAttribute( 'max', '120' );
	expect( puts() ).toHaveLength( 0 );

	// A server refusal is shown, not swallowed.
	putResult = Promise.reject( {
		code: 'slotiva_invalid_value',
		message: 'Hour/month values are out of range.',
	} );
	fireEvent.change( screen.getByLabelText( 'Keep personal details for' ), {
		target: { value: '6' },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Save settings' } ) );
	expect(
		await screen.findByText(
			'One of the values is out of range. Check the numbers and try again.'
		)
	).toBeInTheDocument();
} );

const PRODUCTS = [
	{
		id: 12,
		name: 'One to one',
		durationMin: 60,
		minSlots: 1,
		maxSlots: 3,
		pricing: 'per_slot',
		editUrl: 'http://x.test/wp-admin/post.php?post=12&action=edit',
	},
	{
		id: 13,
		name: 'Intro call',
		durationMin: 30,
		minSlots: 1,
		maxSlots: 1,
		pricing: 'fixed',
		editUrl: 'http://x.test/wp-admin/post.php?post=13&action=edit',
	},
];

test( 'settings_lists_bookable_products_with_edit_links', async () => {
	window.slotivaAdmin.bookable = PRODUCTS;
	window.slotivaAdmin.setupLinks = {
		wizard: 'http://x.test/wp-admin/admin.php?page=slotiva-setup&step=0',
		product: 'http://x.test/wp-admin/admin.php?page=slotiva-setup&step=3',
	};
	render( <App /> );
	expect( await screen.findByText( 'One to one' ) ).toBeInTheDocument();
	expect( screen.getByText( '60 min' ) ).toBeInTheDocument();
	expect( screen.getByText( '1–3 hours' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Per slot' ) ).toBeInTheDocument();
	expect( screen.getByText( '1 hour' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Fixed' ) ).toBeInTheDocument();
	const links = screen.getAllByRole( 'link', {
		name: /Open Slotiva tab/,
	} );
	expect( links ).toHaveLength( 2 );
	expect( links[ 0 ].getAttribute( 'href' ) ).toContain(
		'post.php?post=12&action=edit'
	);
	expect( links[ 0 ].getAttribute( 'href' ) ).toContain(
		'#slotiva_product_data'
	);
	expect(
		screen
			.getByRole( 'link', { name: /New bookable product/ } )
			.getAttribute( 'href' )
	).toContain( 'step=3' );
	// The horizontally scrollable table box is reachable and named for keyboard users.
	const box = screen.getByRole( 'region', {
		name: 'Bookable products table',
	} );
	expect( box ).toHaveAttribute( 'tabindex', '0' );
	expect( box ).toContainElement( screen.getByRole( 'table' ) );
} );

test( 'settings_has_open_wizard_link', async () => {
	window.slotivaAdmin.bookable = PRODUCTS;
	window.slotivaAdmin.setupLinks = {
		wizard: 'http://x.test/wp-admin/admin.php?page=slotiva-setup&step=0',
		product: 'http://x.test/wp-admin/admin.php?page=slotiva-setup&step=3',
	};
	render( <App /> );
	const link = await screen.findByRole( 'link', {
		name: /Open the setup wizard/,
	} );
	expect( link.getAttribute( 'href' ) ).toContain( 'slotiva-setup' );
} );

test( 'settings_empty_state_links_wizard', async () => {
	window.slotivaAdmin.bookable = [];
	window.slotivaAdmin.setupLinks = {
		wizard: 'http://x.test/wp-admin/admin.php?page=slotiva-setup&step=0',
		product: 'http://x.test/wp-admin/admin.php?page=slotiva-setup&step=3',
	};
	render( <App /> );
	expect(
		await screen.findByText( 'No bookable products yet' )
	).toBeInTheDocument();
	expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	expect(
		screen
			.getByRole( 'link', { name: /Create your first bookable product/ } )
			.getAttribute( 'href' )
	).toContain( 'step=3' );
} );
