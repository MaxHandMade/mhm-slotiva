/**
 * Link into the documentation site. `window.slotivaAdmin.docsBase` (set by Menu::pageData, ends with `docs/`, carries
 * `tr/` for Turkish users) is the only source; without it no link is drawn (callers check for the empty string).
 * Anchors are the heading ids fixed in mhm-slotiva-docs/scripts/fixed-ids.txt and checked by check-plugin-links.sh.
 */

/**
 * @param {string} path     Page path under the docs root, e.g. `faq/faq/`.
 * @param {string} [anchor] Heading id on that page (without `#`).
 * @return {string} Absolute URL, or '' when the base is unknown.
 */
export function docsUrl( path, anchor = '' ) {
	const base = window.slotivaAdmin?.docsBase;
	if ( ! base ) {
		return '';
	}
	return `${ base }${ path }${ anchor ? `#${ anchor }` : '' }`;
}
