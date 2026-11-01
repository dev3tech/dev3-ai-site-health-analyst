/**
 * HTML → plain text for plain fields (headings, buttons, textareas).
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function htmlToText( html ) {
	if ( ! html ) {
		return '';
	}
	const withBreaks = String( html )
		.replace( /<\s*br\s*\/?>/gi, '\n' )
		.replace( /<\/(p|h[1-6]|li|div|blockquote)>/gi, '\n' )
		.replace( /<li[^>]*>/gi, '• ' );
	const doc = new window.DOMParser().parseFromString( withBreaks, 'text/html' );
	return ( doc.body.textContent || '' ).replace( /\n{3,}/g, '\n\n' ).trim();
}

/**
 * Plain text → paragraphs for rich fields.
 *
 * @param {string} text Text.
 * @return {string} HTML.
 */
export function textToHtml( text ) {
	const esc = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	return String( text || '' )
		.split( /\n{2,}/ )
		.map( ( p ) => p.trim() )
		.filter( Boolean )
		.map( ( p ) => `<p>${ esc( p ).replace( /\n/g, '<br>' ) }</p>` )
		.join( '\n' );
}

export const isRich = ( field ) => field === 'wysiwyg' || field === 'html';

/**
 * Converts a result value to what the target field accepts.
 *
 * @param {string} value  Output.
 * @param {string} format plain|html.
 * @param {string} field  Target field hint.
 * @return {string} Value.
 */
export function adaptToField( value, format, field ) {
	if ( isRich( field ) ) {
		return format === 'html' ? value : textToHtml( value );
	}
	return format === 'html' ? htmlToText( value ) : value;
}

export const WAND_SVG =
	'<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2l1.9 5.1L19 9l-5.1 1.9L12 16l-1.9-5.1L5 9l5.1-1.9L12 2zm7 11l.95 2.05L22 16l-2.05.95L19 19l-.95-2.05L16 16l2.05-.95L19 13zM6 15l.7 1.3L8 17l-1.3.7L6 19l-.7-1.3L4 17l1.3-.7L6 15z"/></svg>';
