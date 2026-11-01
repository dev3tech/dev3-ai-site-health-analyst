/**
 * Boot data printed by Assets::config(). Never contains the API key.
 */
const config = window.dev3shConfig || {};

export default config;

/**
 * Absolute URL for a dev3sh/v1 route. Handles both pretty permalinks (/wp-json/dev3sh/v1/)
 * and plain ones (index.php?rest_route=/dev3sh/v1/), where a route's own query string
 * must be joined with "&" instead of "?".
 *
 * @param {string} path Route relative to dev3sh/v1, optionally with a query string.
 * @param {string} base REST base (defaults to the localized one).
 * @return {string} URL.
 */
export function restUrl( path, base = config.restUrl || '/wp-json/dev3sh/v1/' ) {
	const [ route, query ] = String( path ).replace( /^\//, '' ).split( /\?(.*)/s );
	const url = base + route;
	if ( ! query ) {
		return url;
	}
	return url + ( url.includes( '?' ) ? '&' : '?' ) + query;
}
