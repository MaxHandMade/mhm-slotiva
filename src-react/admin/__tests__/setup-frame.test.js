/**
 * Setup wizard frame: five-step indicator with the current step marked, "Finish later" goes to the list,
 * and the step is kept in the URL.
 */
import '@testing-library/jest-dom';
import {
	render,
	screen,
	fireEvent,
	within,
	waitFor,
} from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import SetupFrame from '../setup/SetupFrame';
import App from '../setup/App';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

beforeEach( () => {
	apiFetch.mockImplementation( ( req ) =>
		Promise.resolve(
			req.method === 'PUT'
				? {}
				: {
						rules: { durationDefault: 30 },
						schedule: { days: [ [], [], [], [], [], [], [] ] },
				  }
		)
	);
	window.slotivaAdmin = {
		listUrl: 'http://x.test/wp-admin/admin.php?page=slotiva',
	};
	window.history.replaceState(
		null,
		'',
		'/wp-admin/admin.php?page=slotiva-setup&step=1'
	);
} );

test( 'renders_five_steps_with_current_marked', () => {
	render( <SetupFrame step={ 1 } total={ 5 } onSkipHref="/list" /> );
	const nav = screen.getByRole( 'navigation', { name: 'Setup steps' } );
	const items = within( nav ).getAllByRole( 'listitem' );
	expect( items ).toHaveLength( 5 );
	expect( items.map( ( li ) => li.textContent ) ).toEqual( [
		'1Service',
		'2Hours',
		'3Product',
		'4Checkout',
		'5Done',
	] );
	expect( items[ 0 ] ).toHaveAttribute( 'aria-current', 'step' );
	expect( nav.querySelectorAll( '[aria-current]' ) ).toHaveLength( 1 );
} );

test( 'welcome_step_marks_no_step', () => {
	render( <SetupFrame step={ 0 } total={ 5 } onSkipHref="/list" /> );
	expect( document.querySelector( '[aria-current]' ) ).toBeNull();
} );

test( 'skip_link_goes_to_list', () => {
	render( <SetupFrame step={ 2 } total={ 5 } onSkipHref="/list" /> );
	expect(
		screen.getByRole( 'link', { name: 'Finish later' } )
	).toHaveAttribute( 'href', '/list' );
} );

test( 'app_keeps_step_in_url_and_navigates_with_footer', async () => {
	render( <App /> );
	await screen.findByRole( 'heading', { name: 'What do you sell?' } );
	expect(
		screen.getByRole( 'link', { name: 'Finish later' } )
	).toHaveAttribute(
		'href',
		'http://x.test/wp-admin/admin.php?page=slotiva'
	);
	const nav = screen.getByRole( 'navigation', { name: 'Setup steps' } );
	expect( within( nav ).getAllByRole( 'listitem' )[ 0 ] ).toHaveAttribute(
		'aria-current',
		'step'
	);

	fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await waitFor( () =>
		expect( window.location.search ).toContain( 'step=2' )
	);
	expect( within( nav ).getAllByRole( 'listitem' )[ 1 ] ).toHaveAttribute(
		'aria-current',
		'step'
	);

	fireEvent.click( screen.getByRole( 'button', { name: /Back/ } ) );
	expect( window.location.search ).toContain( 'step=1' );
	expect( window.location.search ).toContain( 'page=slotiva-setup' );
} );

test( 'out_of_range_step_is_clamped', async () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/admin.php?page=slotiva-setup&step=99'
	);
	render( <App /> );
	// Step 99 clamps to the last step (the done screen): no Continue, no step row, completion note instead.
	// The fixture has no open day, so the done screen asks for one instead of saying "ready".
	await screen.findByRole( 'region', { name: 'Setup summary' } );
	expect( screen.queryByRole( 'button', { name: 'Continue' } ) ).toBeNull();
	expect(
		screen.queryByRole( 'navigation', { name: 'Setup steps' } )
	).toBeNull();
} );

test( 'welcome_has_brand_header_and_no_stepper_or_footer', () => {
	const { container } = render(
		<SetupFrame step={ 0 } total={ 5 } onSkipHref="/list" />
	);
	expect(
		screen.queryByRole( 'navigation', { name: 'Setup steps' } )
	).toBeNull();
	expect( container.querySelector( '.slotiva-stepper' ) ).toBeNull();
	expect( container.querySelector( 'footer' ) ).toBeNull();
	expect( screen.getByText( 'Setup' ) ).toBeInTheDocument();
	expect(
		screen.getByRole( 'link', { name: 'Skip for now, I’ll finish later' } )
	).toHaveAttribute( 'href', '/list' );
} );

test.each( [ 1, 2, 3, 4, 5 ] )( 'footer_rendered_on_step_%d', ( step ) => {
	const { container } = render(
		<SetupFrame step={ step } total={ 5 } onSkipHref="/list" />
	);
	const footer = container.querySelector( 'footer.slotiva-setup__footer' );
	expect( footer ).not.toBeNull();
} );

test( 'compiled_css_keeps_footer_sticky_and_time_inputs_44px', () => {
	const fs = require( 'fs' );
	const path = require( 'path' );
	const file = path.join( __dirname, '../../../build/admin/setup.css' );
	if ( ! fs.existsSync( file ) ) {
		throw new Error( 'build/admin/setup.css missing: run npm run build' );
	}
	const css = fs.readFileSync( file, 'utf8' ).replace( /\s+/g, '' );
	// The compiler sorts declarations alphabetically, so match the rule body without relying on order.
	const footerRule = css.match( /\.slotiva-setup__footer\{([^}]*)\}/g ) || [];
	expect(
		footerRule.some(
			( r ) => r.includes( 'position:sticky' ) && r.includes( 'bottom:0' )
		)
	).toBe( true );
	expect( css ).toMatch(
		/\.slotiva-hours__rangeinput\{[^}]*min-height:44px/
	);
} );
