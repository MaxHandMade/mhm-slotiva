/**
 * Setup wizard presentational components (Task 4): real form controls, keyboard operable, state never colour-only.
 */
import '@testing-library/jest-dom';
import { render, screen, fireEvent, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { existsSync, readFileSync } from 'fs';
import { join } from 'path';

import Stepper from '../setup/ui/Stepper';
import ChoiceCard from '../setup/ui/ChoiceCard';
import ChipGroup from '../setup/ui/ChipGroup';
import Counter from '../setup/ui/Counter';
import Segmented from '../setup/ui/Segmented';
import PreviewPanel from '../setup/ui/PreviewPanel';
import CheckRow from '../setup/ui/CheckRow';

test( 'counter_clamps_and_disables_at_bounds', () => {
	const onChange = jest.fn();
	const { rerender } = render(
		<Counter
			label="Max per order"
			value={ 1 }
			min={ 1 }
			max={ 3 }
			onChange={ onChange }
		/>
	);
	expect( screen.getByRole( 'button', { name: 'Decrease' } ) ).toBeDisabled();
	fireEvent.click( screen.getByRole( 'button', { name: 'Increase' } ) );
	expect( onChange ).toHaveBeenLastCalledWith( 2 );

	rerender(
		<Counter
			label="Max per order"
			value={ 3 }
			min={ 1 }
			max={ 3 }
			onChange={ onChange }
			format={ ( v ) => `${ v } h` }
		/>
	);
	expect( screen.getByRole( 'button', { name: 'Increase' } ) ).toBeDisabled();
	expect( screen.getByText( '3 h' ) ).toBeInTheDocument();
	expect(
		screen.getByRole( 'group', { name: 'Max per order' } )
	).toBeTruthy();
	fireEvent.click( screen.getByRole( 'button', { name: 'Decrease' } ) );
	expect( onChange ).toHaveBeenLastCalledWith( 2 );
	onChange.mockClear();
	fireEvent.click( screen.getByRole( 'button', { name: 'Increase' } ) );
	expect( onChange ).not.toHaveBeenCalled();
} );

test( 'choice_card_is_keyboard_selectable', async () => {
	const user = userEvent.setup();
	const onChange = jest.fn();
	render(
		<ChoiceCard
			name="kind"
			value="salon"
			checked={ false }
			onChange={ onChange }
			title="Salon"
			description="Haircuts and more"
		/>
	);
	const radio = screen.getByRole( 'radio', { name: 'Salon' } );
	expect( radio.tagName ).toBe( 'INPUT' );
	expect( radio ).toHaveAttribute( 'name', 'kind' );
	expect( radio ).toHaveAccessibleDescription( 'Haircuts and more' );
	// Tab order: a native, enabled, visible-to-AT radio that is not removed from the tab sequence.
	expect( radio ).not.toBeDisabled();
	expect( radio.tabIndex ).toBeGreaterThanOrEqual( 0 );
	expect( radio ).not.toHaveAttribute( 'hidden' );
	expect( radio ).toBeVisible();
	// Real keyboard path: Tab until the radio has focus, then Space (no manual click).
	await user.tab();
	expect( radio ).toHaveFocus();
	await user.keyboard( ' ' );
	expect( onChange ).toHaveBeenCalledTimes( 1 );
	expect( onChange ).toHaveBeenCalledWith( 'salon' );
} );

test( 'chip_group_marks_selected', () => {
	const onChange = jest.fn();
	render(
		<ChipGroup
			name="dur"
			label="Duration"
			options={ [
				{ value: 30, label: '30 min' },
				{ value: 60, label: '60 min' },
			] }
			value={ 60 }
			onChange={ onChange }
		/>
	);
	const group = screen.getByRole( 'radiogroup', { name: 'Duration' } );
	expect( within( group ).getAllByRole( 'radio' ) ).toHaveLength( 2 );
	expect( screen.getByRole( 'radio', { name: '60 min' } ) ).toBeChecked();
	expect( screen.getByRole( 'radio', { name: '30 min' } ) ).not.toBeChecked();
	// Selected state is also a drawn check, not only colour.
	expect(
		screen.getByText( '60 min' ).closest( 'label' ).querySelector( 'svg' )
	).not.toBeNull();
	fireEvent.click( screen.getByRole( 'radio', { name: '30 min' } ) );
	expect( onChange ).toHaveBeenCalledWith( 30 );
} );

test( 'segmented_switches', () => {
	const onChange = jest.fn();
	render(
		<Segmented
			label="Product"
			options={ [
				{ value: 'new', label: 'Create new' },
				{ value: 'existing', label: 'Use existing' },
			] }
			value="new"
			onChange={ onChange }
		/>
	);
	expect(
		screen.getByRole( 'radiogroup', { name: 'Product' } )
	).toBeTruthy();
	expect( screen.getByRole( 'radio', { name: 'Create new' } ) ).toBeChecked();
	fireEvent.click( screen.getByRole( 'radio', { name: 'Use existing' } ) );
	expect( onChange ).toHaveBeenCalledWith( 'existing' );
} );

test.each( [
	[ 'ok', 'Done' ],
	[ 'todo', 'To do' ],
	[ 'warn', 'Check' ],
	[ 'optional', 'Optional' ],
] )( 'check_row_states_have_text_not_color_only (%s)', ( state, text ) => {
	render(
		<CheckRow state={ state } title="Time zone" detail="Istanbul">
			<a href="#fix">Fix it</a>
		</CheckRow>
	);
	expect( screen.getByText( text ) ).toBeVisible();
	expect( screen.getByText( 'Time zone' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Istanbul' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'link', { name: 'Fix it' } ) ).toBeTruthy();
	const tone = {
		ok: 'success',
		warn: 'warning',
		todo: 'info',
		optional: 'neutral',
	}[ state ];
	expect( screen.getByRole( 'listitem' ) ).toHaveClass(
		`slotiva-check-row--${ tone }`
	);
} );

test( 'stepper_marks_done_current_and_upcoming', () => {
	render(
		<Stepper steps={ [ 'Service', 'Hours', 'Product' ] } current={ 2 } />
	);
	const nav = screen.getByRole( 'navigation', { name: 'Setup steps' } );
	const items = within( nav ).getAllByRole( 'listitem' );
	expect( items ).toHaveLength( 3 );
	expect( items[ 0 ] ).toHaveClass( 'is-done' );
	expect( items[ 0 ].querySelector( 'svg' ) ).not.toBeNull();
	expect( items[ 1 ] ).toHaveAttribute( 'aria-current', 'step' );
	expect( items[ 2 ] ).not.toHaveAttribute( 'aria-current' );
	expect( items[ 2 ] ).toHaveClass( 'is-upcoming' );
	expect( items[ 2 ].textContent ).toBe( '3Product' );
} );

test( 'stepper_has_compact_progress_form_for_phone_width', () => {
	render(
		<Stepper steps={ [ 'Service', 'Hours', 'Product' ] } current={ 3 } />
	);
	const bar = screen.getByRole( 'progressbar' );
	expect( bar ).toHaveAttribute( 'aria-valuenow', '3' );
	expect( bar ).toHaveAttribute( 'aria-valuemax', '3' );
	expect( screen.getByText( '3 / 3 · Product' ) ).toBeInTheDocument();
} );

test( 'stepper_compact_label_is_omitted_when_current_exceeds_steps', () => {
	render( <Stepper steps={ [ 'Service', 'Hours' ] } current={ 3 } /> );
	expect( screen.queryByRole( 'progressbar' ) ).toBeNull();
	expect( screen.queryByText( /3 \/ 2/ ) ).toBeNull();
} );

/**
 * Splits compiled CSS into flat rules, remembering the enclosing at-rule (`@media ...`) of each one.
 * Minimal: tracks brace depth; good enough for webpack-minified output.
 * Throws on unbalanced braces instead of looping forever.
 *
 * @param {string} css Compiled stylesheet.
 * @return {{selector: string, body: string, media: string|null}[]} Flat list of rules.
 */
function splitRules( css ) {
	const rules = [];
	let i = 0;
	const walk = ( media, end ) => {
		while ( i < end ) {
			const open = css.indexOf( '{', i );
			if ( open === -1 || open >= end ) {
				return;
			}
			const head = css.slice( i, open ).trim();
			let depth = 1;
			let j = open + 1;
			while ( depth > 0 ) {
				if ( j >= css.length ) {
					throw new Error(
						`splitRules: unbalanced braces, "{" at offset ${ open } is never closed`
					);
				}
				if ( css[ j ] === '{' ) {
					depth++;
				} else if ( css[ j ] === '}' ) {
					depth--;
				}
				j++;
			}
			if ( head.startsWith( '@media' ) ) {
				i = open + 1;
				walk( head, j - 1 );
				i = j;
			} else {
				rules.push( {
					selector: head,
					body: css.slice( open + 1, j - 1 ),
					media,
				} );
				i = j;
			}
		}
	};
	walk( null, css.length );
	return rules;
}

const builtCss = join( __dirname, '../../../build/admin/setup.css' );

const missingBuildMessage = `${ builtCss } is missing: run npm run build first`;
const hasBuiltCss = existsSync( builtCss );

// CI: a missing build is a failure, never a silent skip. Locally: skip, but say so.
if ( ! hasBuiltCss && ! process.env.CI ) {
	// eslint-disable-next-line no-console
	console.warn(
		`SKIPPED compiled setup.css checks: ${ missingBuildMessage }`
	);
}

( hasBuiltCss || process.env.CI ? describe : describe.skip )(
	'compiled setup.css (run npm run build first)',
	() => {
		beforeAll( () => {
			if ( ! existsSync( builtCss ) ) {
				throw new Error( missingBuildMessage );
			}
		} );

		test( 'stepper_display_rules_live_only_inside_media_queries', () => {
			const rules = splitRules( readFileSync( builtCss, 'utf8' ) ).filter(
				( r ) =>
					/slotiva-stepper__(compact|nav)/.test( r.selector ) &&
					/(^|;)display:/.test( r.body )
			);
			// Both sides exist, and none is a top-level (order-dependent) rule.
			expect( rules.length ).toBeGreaterThanOrEqual( 3 );
			expect( rules.filter( ( r ) => r.media === null ) ).toEqual( [] );
			const show = ( q, sel, v ) =>
				rules.some(
					( r ) =>
						r.media.replace( /\s/g, '' ).includes( q ) &&
						r.selector.includes( sel ) &&
						r.body.includes( `display:${ v }` )
				);
			expect( show( '(max-width:782px)', '__nav', 'none' ) ).toBe( true );
			expect( show( '(max-width:782px)', '__compact', 'flex' ) ).toBe(
				true
			);
			expect( show( '(min-width:783px)', '__compact', 'none' ) ).toBe(
				true
			);
		} );
	}
);

test( 'stepper_markup_carries_the_classes_the_media_queries_target', () => {
	render(
		<Stepper steps={ [ 'Service', 'Hours', 'Product' ] } current={ 2 } />
	);
	const nav = screen.getByRole( 'navigation', { name: 'Setup steps' } );
	expect( nav ).toHaveClass( 'slotiva-stepper__nav' );
	expect( screen.getByRole( 'progressbar' ).parentElement ).toHaveClass(
		'slotiva-stepper__compact'
	);
} );

test( 'stepper_has_no_progress_on_welcome_screen', () => {
	render( <Stepper steps={ [ 'Service', 'Hours' ] } current={ 0 } /> );
	expect( screen.queryByRole( 'progressbar' ) ).toBeNull();
} );

test( 'preview_panel_is_a_labelled_aside', () => {
	render(
		<PreviewPanel title="Customer sees at checkout">
			<p>body</p>
		</PreviewPanel>
	);
	const aside = screen.getByRole( 'complementary' );
	expect( aside ).toHaveAccessibleName( 'Customer sees at checkout' );
	expect( within( aside ).getByText( 'body' ) ).toBeInTheDocument();
} );
