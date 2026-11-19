/**
 * Screen-reader note for a link that opens in a new tab (every `target="_blank"` link carries it).
 */
import { __ } from '@wordpress/i18n';

export default function NewTabNote() {
	return (
		<span className="screen-reader-text">
			{ ' ' }
			{ __( '(opens in a new tab)', 'mhm-slotiva' ) }
		</span>
	);
}

/**
 * An `<a target="_blank">` that adds the note after its children; usable as a createInterpolateElement tag.
 *
 * @param {Object} props          Link props (`href`, `className`, …).
 * @param {*}      props.children Link text.
 */
export function NewTabLink( { children, ...props } ) {
	return (
		<a { ...props } target="_blank" rel="noopener noreferrer">
			{ children }
			<NewTabNote />
		</a>
	);
}
