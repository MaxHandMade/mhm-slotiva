/**
 * Locale-independent structural fingerprint of a DOM tree, used by the hook parity tests: element names, classes,
 * ids, roles, aria-* and data-* attribute NAMES and colSpan, with every text node reduced to a marker. An extra
 * wrapper, column, box, heading or placeholder changes the fingerprint; date/number formatting does not.
 *
 * @param {Node}   node  Root node.
 * @param {number} depth Indent level.
 * @return {string} Indented outline.
 */
export function skeleton( node, depth = 0 ) {
	const pad = '  '.repeat( depth );
	if ( node.nodeType === 3 ) {
		return node.textContent.trim() ? `${ pad }"#"\n` : '';
	}
	if ( node.nodeType !== 1 ) {
		return '';
	}
	const attrs = Array.from( node.attributes )
		.map( ( a ) => a.name )
		.filter(
			( n ) =>
				/^(class|id|role|colspan|type|disabled)$/i.test( n ) ||
				/^(aria|data)-/.test( n )
		)
		.sort()
		.map( ( n ) =>
			/^(class|id|role|colspan|type)$/i.test( n )
				? `${ n }=${ node.getAttribute( n ) }`
				: n
		)
		.join( ' ' );
	let out = `${ pad }<${ node.tagName.toLowerCase() }${
		attrs ? ' ' + attrs : ''
	}>\n`;
	node.childNodes.forEach( ( c ) => {
		out += skeleton( c, depth + 1 );
	} );
	return out;
}
