/**
 * JS extension points of the picker (`slotiva.picker.slotMeta`, `slotiva.picker.selectionLine`).
 * The parity snapshots in __snapshots__ were taken from the code BEFORE the filters existed.
 */
import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import { addFilter, removeAllFilters } from '@wordpress/hooks';

import TimeGrid from '../TimeGrid';
import Summary from '../Summary';
import { skeleton } from '../../test-utils/skeleton';

const SLOTS = [
	{ start: '2026-11-17T07:00:00Z', label: '10:00' },
	{ start: '2026-11-17T08:00:00Z', label: '11:00' },
];
const PICKS = [
	{
		start: '2026-11-17T07:00:00Z',
		label: '10:00',
		long: 'Tue 17 Nov, 10:00',
		range: 'Tue 17 Nov · 10:00–11:00',
	},
];

const grid = ( multi = false ) => (
	<TimeGrid
		slots={ SLOTS }
		selected={ new Set( [ SLOTS[ 0 ].start ] ) }
		onToggle={ () => {} }
		multi={ multi }
	/>
);
const single = ( picks = PICKS ) => <Summary picks={ picks } multi={ false } />;
const multiSummary = ( pricing = 'per_slot' ) => (
	<Summary
		picks={ PICKS }
		multi
		pricing={ pricing }
		prices={ { price: '1000', currency_minor_unit: 2 } }
		onRemove={ () => {} }
	/>
);

afterEach( () => removeAllFilters( 'slotiva.picker.slotMeta' ) );
afterEach( () => removeAllFilters( 'slotiva.picker.selectionLine' ) );

describe( 'renders_identically_without_filters', () => {
	test.each( [
		[ 'time grid single', () => grid( false ) ],
		[ 'time grid multi', () => grid( true ) ],
		[ 'summary single with pick', () => single() ],
		[ 'summary single empty', () => single( [] ) ],
		[ 'summary multi per slot', () => multiSummary( 'per_slot' ) ],
		[ 'summary multi fixed', () => multiSummary( 'fixed' ) ],
	] )( '%s', ( name, make ) => {
		const { container } = render( make() );
		expect( skeleton( container ) ).toMatchSnapshot();
	} );
} );

const GARBAGE = [
	[ 'undefined', () => undefined ],
	[ 'null', () => null ],
	[ 'a number', () => 42 ],
	[ 'an object', () => ( { a: 1 } ) ],
	[ 'an array', () => [ 'x' ] ],
	[ 'an empty string', () => '' ],
	[ 'whitespace', () => '   ' ],
	[ 'a function', () => () => 'x' ],
	[
		'a thrown error',
		() => {
			throw new Error( 'boom' );
		},
	],
];

/**
 * Fingerprint of `make()` with the filter registered, then of the same render without it.
 *
 * @param {string}   name Filter name.
 * @param {Function} fn   Filter callback.
 * @param {Function} make Returns the element to render.
 * @return {string[]} [ with filter, without filter ].
 */
function fingerprints( name, fn, make ) {
	addFilter( name, 'test/x', fn );
	const first = render( make() );
	const withFilter = skeleton( first.container );
	first.unmount();
	removeAllFilters( name );
	const second = render( make() );
	const without = skeleton( second.container );
	second.unmount();
	return [ withFilter, without ];
}

describe( 'slotiva.picker.slotMeta', () => {
	test( 'renders_meta_text_inside_the_time_button', () => {
		const seen = [];
		addFilter( 'slotiva.picker.slotMeta', 'test/x', ( meta, slot ) => {
			seen.push( [ meta, slot.start ] );
			return `${ slot.label } left: 2`;
		} );
		render( grid() );
		const group = screen.getByRole( 'group', { name: /choose a time/i } );
		const buttons = within( group ).getAllByRole( 'button' );
		expect( buttons ).toHaveLength( 2 );
		expect(
			buttons[ 0 ].querySelector( '.slotiva-picker__time-meta' )
		).toHaveTextContent( '10:00 left: 2' );
		expect( seen[ 0 ] ).toEqual( [ null, SLOTS[ 0 ].start ] );
	} );

	test( 'renders_markup_as_text_not_html', () => {
		addFilter(
			'slotiva.picker.slotMeta',
			'test/x',
			() => '<img src=x onerror=alert(1)>'
		);
		const { container } = render( grid() );
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect(
			container.querySelector( '.slotiva-picker__time-meta' ).textContent
		).toBe( '<img src=x onerror=alert(1)>' );
	} );

	test.each( GARBAGE )(
		'garbage_return_%s_renders_nothing_extra',
		( n, f ) => {
			const [ a, b ] = fingerprints( 'slotiva.picker.slotMeta', f, grid );
			expect( a ).toBe( b );
		}
	);
} );

describe( 'slotiva.picker.selectionLine', () => {
	test( 'renders_line_in_single_mode_without_a_wrapper', () => {
		const seen = [];
		addFilter( 'slotiva.picker.selectionLine', 'test/x', ( line, sel ) => {
			seen.push( [ line, sel ] );
			return 'Includes a deposit';
		} );
		const { container } = render( single() );
		expect(
			container.querySelector( '.slotiva-picker__selection-line' )
		).toHaveTextContent( 'Includes a deposit' );
		expect( container.children ).toHaveLength( 2 );
		expect( seen[ 0 ][ 0 ] ).toBeNull();
		expect( seen[ 0 ][ 1 ] ).toBe( PICKS );
	} );

	test( 'renders_line_in_multi_mode_inside_the_summary', () => {
		addFilter(
			'slotiva.picker.selectionLine',
			'test/x',
			() => 'Extra line'
		);
		const { container } = render( multiSummary() );
		const box = container.querySelector( '.slotiva-picker__summary' );
		expect(
			box.querySelector( '.slotiva-picker__selection-line' )
		).toHaveTextContent( 'Extra line' );
	} );

	test.each( GARBAGE )(
		'garbage_return_%s_renders_nothing_extra',
		( n, f ) => {
			for ( const make of [
				() => single(),
				() => single( [] ),
				() => multiSummary(),
			] ) {
				const [ a, b ] = fingerprints(
					'slotiva.picker.selectionLine',
					f,
					make
				);
				expect( a ).toBe( b );
			}
		}
	);
} );
