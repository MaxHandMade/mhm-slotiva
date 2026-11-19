/**
 * Mirror of the PHP HookContractTest for the JavaScript filters: docs/hooks.md lists exactly the filters the
 * source applies, each with a "since" value, in neutral developer wording.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.join( __dirname, '../..' );
const doc = fs.readFileSync( path.join( root, 'docs/hooks.md' ), 'utf8' );

function sourceFiles( dir ) {
	return fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( e ) => {
		const file = path.join( dir, e.name );
		if ( e.isDirectory() ) {
			return 'test-utils' === e.name || '__tests__' === e.name
				? []
				: sourceFiles( file );
		}
		return /\.js$/.test( e.name ) ? [ file ] : [];
	} );
}

function sourceFilters() {
	const found = new Set();
	sourceFiles( path.join( root, 'src-react' ) ).forEach( ( f ) => {
		const code = fs.readFileSync( f, 'utf8' );
		for ( const m of code.matchAll(
			/applyFilters\(\s*'(slotiva\.[A-Za-z.]+)'/g
		) ) {
			found.add( m[ 1 ] );
		}
	} );
	return [ ...found ].sort();
}

function jsSection() {
	const parts = doc.split( '## JavaScript filters' );
	expect( parts ).toHaveLength( 2 );
	return parts[ 1 ].split( /\n## /, 1 )[ 0 ];
}

test( 'js_filters_doc_lists_exactly_the_filters_applied_by_the_source', () => {
	const rows = jsSection()
		.split( '\n' )
		.filter( ( l ) => /^\| `slotiva\./.test( l ) );
	const documented = rows
		.map( ( l ) => l.match( /^\| `(slotiva\.[A-Za-z.]+)`/ )[ 1 ] )
		.sort();
	expect( sourceFilters() ).toHaveLength( 4 );
	expect( documented ).toEqual( sourceFilters() );
	rows.forEach( ( row ) => expect( row ).toMatch( /\| 1\.\d\.\d \|$/ ) );
} );

test( 'js_filters_doc_uses_neutral_wording', () => {
	expect( doc.toLowerCase() ).not.toMatch(
		/\b(pro|license|licence|premium|upgrade|unlock\w*)\b/
	);
} );

test( 'php_hook_table_is_not_confused_by_the_js_section', () => {
	// The PHP contract test reads the part before "## Actions Slotiva listens to" for `slotiva_…` rows.
	const phpPart = doc.split( '## Actions Slotiva listens to' )[ 0 ];
	expect( phpPart ).not.toContain( 'JavaScript filters' );
} );
