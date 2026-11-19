/**
 * Documented JavaScript extension points (docs/hooks.md, "JavaScript filters"). Every filter starts from an empty
 * default, so with nothing registered no extra markup is produced. Whatever a callback returns is checked here:
 * wrong shapes are dropped, values are only ever rendered as text, and a callback that throws is ignored.
 */
import { isValidElement } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';

/**
 * @param {*} value Filter result.
 * @return {string|null} The text, or null when it is not a non-blank string.
 */
function text( value ) {
	return 'string' === typeof value && value.trim() ? value : null;
}

/**
 * Text for a time button, from the `slotiva.picker.slotMeta` filter.
 *
 * @param {{start: string, label: string}} slot The time.
 * @return {string|null} Text to show, or null.
 */
export function slotMeta( slot ) {
	try {
		return text( applyFilters( 'slotiva.picker.slotMeta', null, slot ) );
	} catch ( e ) {
		return null;
	}
}

/**
 * Extra line under the chosen time(s), from the `slotiva.picker.selectionLine` filter.
 *
 * @param {Object[]} selection The chosen times.
 * @return {string|null} Text to show, or null.
 */
export function selectionLine( selection ) {
	try {
		return text(
			applyFilters( 'slotiva.picker.selectionLine', null, selection )
		);
	} catch ( e ) {
		return null;
	}
}

/**
 * Valid entries (objects with a non-blank string id and title/label, unique ids) of a filtered list.
 *
 * @param {*}        list    Filter result.
 * @param {string}   textKey Property that must be a non-blank string ("label" or "title").
 * @param {string}   dataKey Property that supplies the value ("render" function or "content" string).
 * @param {Function} isValid Checks the value property.
 * @return {Object[]} Valid entries.
 */
function entries( list, textKey, dataKey, isValid ) {
	if ( ! Array.isArray( list ) ) {
		return [];
	}
	const ids = new Set();
	return list.filter( ( e ) => {
		if (
			! e ||
			'object' !== typeof e ||
			! text( e.id ) ||
			! text( e[ textKey ] ) ||
			! isValid( e[ dataKey ] ) ||
			ids.has( e.id )
		) {
			return false;
		}
		ids.add( e.id );
		return true;
	} );
}

/**
 * Extra columns of the appointments table, from the `slotiva.admin.listColumns` filter. Each column is
 * `{ id, label, render( appointment ) }`; `render` returns the cell content: a string (text) or a React element.
 *
 * @return {{id: string, label: string, render: Function}[]} Valid columns.
 */
export function listColumns() {
	try {
		return entries(
			applyFilters( 'slotiva.admin.listColumns', [] ),
			'label',
			'render',
			( v ) => 'function' === typeof v
		);
	} catch ( e ) {
		return [];
	}
}

/**
 * Cell content of one extra column for one appointment: the string or React element `render` returned, else
 * an empty string (also when `render` throws).
 *
 * @param {{render: Function}} column      Column.
 * @param {Object}             appointment Appointment.
 * @return {string|Object} Text or React element.
 */
export function cellContent( column, appointment ) {
	try {
		const value = column.render( appointment );
		return 'string' === typeof value || isValidElement( value )
			? value
			: '';
	} catch ( e ) {
		return '';
	}
}

/**
 * Extra panels of the appointment detail, from the `slotiva.admin.detailPanels` filter. Each panel is
 * `{ id, title, content }`; `title` is text, `content` is a string (text) or a React element.
 *
 * @param {Object} appointment Appointment.
 * @return {{id: string, title: string, content: (string|Object)}[]} Valid panels.
 */
export function detailPanels( appointment ) {
	try {
		return entries(
			applyFilters( 'slotiva.admin.detailPanels', [], appointment ),
			'title',
			'content',
			( v ) => 'string' === typeof v || isValidElement( v )
		);
	} catch ( e ) {
		return [];
	}
}
