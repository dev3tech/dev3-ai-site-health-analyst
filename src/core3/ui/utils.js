import { useEffect, useRef, useState, useCallback } from '@wordpress/element';

/**
 * Joins class names, skipping falsy values.
 *
 * @param {...*} parts Class names.
 * @return {string} Classes.
 */
export function cx( ...parts ) {
	return parts.flat().filter( Boolean ).join( ' ' );
}

let uid = 0;

/**
 * Stable unique id for label/aria wiring (React 17 has no useId).
 *
 * @param {string} prefix Prefix.
 * @param {string} given  Explicit id, if the caller passed one.
 * @return {string} Id.
 */
export function useUid( prefix = 'dev3sh-ui', given ) {
	const [ id ] = useState( () => given || `${ prefix }-${ ++uid }` );
	return given || id;
}

/**
 * Hash router for admin apps: `#/products/12` → { id: 'products', params: [ '12' ] }.
 *
 * @param {string[]} ids      Known top-level routes.
 * @param {string}   fallback Default route.
 * @return {Array} [ { id, params, path }, navigate( path ) ]
 */
export function useHashRoute( ids, fallback ) {
	const read = useCallback( () => {
		const path = ( typeof window !== 'undefined' ? window.location.hash : '' ).replace( /^#\/?/, '' );
		const [ id, ...params ] = path.split( '/' ).filter( Boolean );
		const known = ids.includes( id );
		return { id: known ? id : fallback || ids[ 0 ], params: known ? params : [], path: known ? path : '' };
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ ids.join( '|' ), fallback ] );
	const [ route, setRoute ] = useState( read );
	useEffect( () => {
		const onHash = () => setRoute( read() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [ read ] );
	const navigate = useCallback( ( path ) => {
		window.location.hash = `#/${ String( path ).replace( /^#?\/?/, '' ) }`;
	}, [] );
	return [ route, navigate ];
}

/**
 * Runs a callback on an interval while `active` is true (clears itself on unmount).
 *
 * @param {Function} callback Callback.
 * @param {number}   delay    Milliseconds.
 * @param {boolean}  active   Whether to run.
 */
export function useInterval( callback, delay, active = true ) {
	const saved = useRef( callback );
	saved.current = callback;
	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		const timer = setInterval( () => saved.current(), delay );
		return () => clearInterval( timer );
	}, [ delay, active ] );
}

/**
 * Formats numbers with the browser locale.
 *
 * @param {number} n       Number.
 * @param {Object} options Intl options.
 * @return {string} Text.
 */
export const formatNumber = ( n, options = {} ) => Number( n || 0 ).toLocaleString( undefined, options );

/**
 * Formats an estimated cost (USD by default; small values keep more decimals).
 *
 * @param {number} n        Amount.
 * @param {string} currency Currency.
 * @return {string} Text.
 */
export function formatMoney( n, currency = 'USD' ) {
	const value = Number( n || 0 );
	const digits = value > 0 && value < 1 ? 3 : 2;
	try {
		return value.toLocaleString( undefined, { style: 'currency', currency, currencyDisplay: 'narrowSymbol', minimumFractionDigits: 2, maximumFractionDigits: digits } );
	} catch ( e ) {
		return `$${ value.toFixed( digits ) }`;
	}
}

/**
 * Keeps a value within bounds.
 *
 * @param {number} n   Value.
 * @param {number} min Min.
 * @param {number} max Max.
 * @return {number} Clamped.
 */
export const clamp = ( n, min, max ) => Math.min( max, Math.max( min, n ) );
