import { contrastRatio, inkAndPaper } from '../ink';

afterEach( () => {
	document.body.innerHTML = '';
	document.body.removeAttribute( 'style' );
} );

test( 'ink_uses_text_color_and_nearest_opaque_background', () => {
	document.body.innerHTML =
		'<div id="a" style="background-color: rgb(17, 17, 17)"><div id="b" style="background-color: rgba(0, 0, 0, 0.2)"><p id="c" style="color: rgb(238, 238, 238)">x</p></div></div>';
	expect( inkAndPaper( document.getElementById( 'c' ) ) ).toEqual( {
		ink: 'rgb(238, 238, 238)',
		paper: 'rgb(17, 17, 17)',
	} );
} );

test( 'ink_falls_back_to_white_paper', () => {
	document.body.innerHTML =
		'<div id="a"><p id="c" style="color: rgb(10, 20, 30)">x</p></div>';
	expect( inkAndPaper( document.getElementById( 'c' ) ) ).toEqual( {
		ink: 'rgb(10, 20, 30)',
		paper: 'rgb(255, 255, 255)',
	} );
} );

test( 'contrast_guard_picks_black_or_white_paper_when_contrast_is_low', () => {
	// Light text on a light background: paper becomes black, ink is kept.
	document.body.innerHTML =
		'<div style="background-color: rgb(250, 250, 250)"><p id="c" style="color: rgb(238, 238, 238)">x</p></div>';
	expect( inkAndPaper( document.getElementById( 'c' ) ) ).toEqual( {
		ink: 'rgb(238, 238, 238)',
		paper: 'rgb(0, 0, 0)',
	} );
	// Dark text on a dark background: paper becomes white.
	document.body.innerHTML =
		'<div style="background-color: rgb(20, 20, 20)"><p id="c" style="color: rgb(40, 40, 40)">x</p></div>';
	expect( inkAndPaper( document.getElementById( 'c' ) ).paper ).toBe(
		'rgb(255, 255, 255)'
	);
	expect( contrastRatio( 'rgb(0, 0, 0)', 'rgb(255, 255, 255)' ) ).toBeCloseTo(
		21,
		0
	);
	expect( contrastRatio( 'oklch(0.5 0 0)', '#fff' ) ).toBeNull();
} );

test( 'contrast_guard_runs_against_the_white_fallback_paper', () => {
	// No opaque ancestor: paper falls back to white, so light ink must flip it to black.
	document.body.innerHTML =
		'<div><p id="c" style="color: rgb(238, 238, 238)">x</p></div>';
	expect( inkAndPaper( document.getElementById( 'c' ) ) ).toEqual( {
		ink: 'rgb(238, 238, 238)',
		paper: 'rgb(0, 0, 0)',
	} );
	expect( contrastRatio( 'rgb(238, 238, 238)', '#fff' ) ).toBeLessThan( 4.5 );
	expect( contrastRatio( '#000000', '#ffffff' ) ).toBeCloseTo( 21, 0 );
} );
