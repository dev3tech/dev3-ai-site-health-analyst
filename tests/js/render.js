/**
 * Tiny render helper for kit components (no extra test libraries needed).
 *
 *     const view = await render( <Button>Hi</Button> );
 *     view.getByText( 'Hi' ).click();
 *     await view.flush();
 *     view.unmount();
 */
import { act } from 'react';
import { createElement, createRoot } from '@wordpress/element';

export async function render( element ) {
	const container = document.createElement( 'div' );
	container.className = 'dev3sh-root';
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => root.render( element ) );
	const all = () => Array.from( document.body.querySelectorAll( '*' ) );
	const byText = ( text ) => all().filter( ( el ) => Array.from( el.childNodes ).some( ( n ) => n.nodeType === 3 && ( text instanceof RegExp ? text.test( n.textContent ) : n.textContent.trim() === text ) ) );
	return {
		container,
		rerender: ( next ) => act( async () => root.render( next ) ),
		unmount: () => {
			act( () => root.unmount() );
			container.remove();
		},
		/* Waits for pending promises/effects. */
		flush: () => act( async () => new Promise( ( r ) => setTimeout( r, 0 ) ) ),
		queryByText: ( text ) => byText( text )[ 0 ] || null,
		getByText: ( text ) => {
			const el = byText( text )[ 0 ];
			if ( ! el ) {
				throw new Error( `Text not found: ${ text }` );
			}
			return el;
		},
		$: ( sel ) => document.body.querySelector( sel ),
		$$: ( sel ) => Array.from( document.body.querySelectorAll( sel ) ),
	};
}

/* Fires a React-compatible change on an input/select/textarea. */
export function change( el, value ) {
	const proto = el.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : el.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
	Object.getOwnPropertyDescriptor( proto, 'value' ).set.call( el, value );
	return act( async () => {
		el.dispatchEvent( new window.Event( el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true } ) );
	} );
}

export function click( el ) {
	return act( async () => {
		el.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
	} );
}

export function key( el, k, opts = {} ) {
	return act( async () => {
		el.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: k, bubbles: true, ...opts } ) );
	} );
}

export { act, createElement };
