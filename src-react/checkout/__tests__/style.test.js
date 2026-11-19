/**
 * Compiled picker CSS: the on-accent (selected day/hour text) fallback chain.
 * Contract: a theme palette (base) wins; a theme with primary/contrast but no base keeps white text;
 * only a palette-less theme gets the page paper colour written by the picker script.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const file = path.join( __dirname, '../../../build/checkout/style-index.css' );

function css() {
	if ( ! fs.existsSync( file ) ) {
		throw new Error(
			'build/checkout/style-index.css missing: run npm run build'
		);
	}
	return fs.readFileSync( file, 'utf8' );
}

/**
 * Minimal CSS var() resolver (custom-property semantics): an undefined var() without a fallback makes the whole
 * value invalid (null), which then lets an outer var() take its fallback.
 *
 * @param {string} value Declared value.
 * @param {Object} props Defined custom properties (raw declared values).
 * @param {number} depth Recursion guard.
 * @return {string|null} Resolved value or null (guaranteed-invalid).
 */
function resolve( value, props, depth = 0 ) {
	if ( depth > 20 ) {
		return null;
	}
	const at = value.indexOf( 'var(' );
	if ( at < 0 ) {
		return value;
	}
	// Find the matching ")" of this var(.
	let level = 0;
	let end = -1;
	let comma = -1;
	for ( let i = at + 4; i < value.length; i++ ) {
		const ch = value[ i ];
		if ( ch === '(' ) {
			level++;
		} else if ( ch === ')' ) {
			if ( level === 0 ) {
				end = i;
				break;
			}
			level--;
		} else if ( ch === ',' && level === 0 && comma < 0 ) {
			comma = i;
		}
	}
	const name = value.slice( at + 4, comma < 0 ? end : comma ).trim();
	const fallback = comma < 0 ? null : value.slice( comma + 1, end ).trim();
	let sub = null;
	if ( Object.prototype.hasOwnProperty.call( props, name ) ) {
		sub = resolve( props[ name ], props, depth + 1 );
	}
	if ( sub === null && fallback !== null ) {
		sub = resolve( fallback, props, depth + 1 );
	}
	if ( sub === null ) {
		return null;
	}
	return resolve(
		value.slice( 0, at ) + sub + value.slice( end + 1 ),
		props,
		depth + 1
	);
}

function selectedColor( source ) {
	const m = source.match(
		/\.slotiva-picker__time\.is-selected\{[^}]*?(?:;|\{)color:([^;}]+)/
	);
	if ( ! m ) {
		throw new Error( 'selected hour colour not found' );
	}
	return m[ 1 ];
}

function pickerProps( source ) {
	const m = source.match( /--slotiva-palette-white:([^;}]+)/ );
	return m ? { '--slotiva-palette-white': m[ 1 ] } : {};
}

test( 'on_accent_follows_palette_contract', () => {
	const source = css();
	const color = selectedColor( source );
	const own = pickerProps( source );
	const paper = { '--slotiva-paper': 'rgb(17, 17, 17)' };
	const primary = { '--wp--preset--color--primary': '#3858e9' };

	// Theme palette with base: unchanged.
	expect(
		resolve( color, {
			...own,
			...paper,
			...primary,
			'--wp--preset--color--base': '#f9f9f9',
		} )
	).toBe( '#f9f9f9' );
	// Primary without base: white text on the accent, not the page paper.
	expect( resolve( color, { ...own, ...paper, ...primary } ) ).toMatch(
		/#fff\)?$/
	);
	expect( resolve( color, { ...own, ...paper, ...primary } ) ).not.toContain(
		'rgb(17, 17, 17)'
	);
	// Contrast only: white as well.
	expect(
		resolve( color, {
			...own,
			...paper,
			'--wp--preset--color--contrast': '#111',
		} )
	).not.toContain( 'rgb(17, 17, 17)' );
	// No palette at all: the page paper colour.
	expect( resolve( color, { ...own, ...paper } ) ).toBe( 'rgb(17, 17, 17)' );
	// No palette, no script value: white.
	expect( resolve( color, { ...own } ) ).toBe( '#fff' );
	// Site override still wins.
	expect(
		resolve( color, {
			...own,
			...primary,
			'--slotiva-on-accent': 'red',
		} )
	).toBe( 'red' );
} );
