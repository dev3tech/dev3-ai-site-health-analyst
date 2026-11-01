import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import config, { restUrl } from './config';

const MIN_INTERVAL = 800;
let inFlight = null;
let lastSubmit = 0;

/**
 * Normalises any failure into { code, message, hint, retryable }.
 *
 * @param {*} error Raw error.
 * @return {Object} Error envelope.
 */
export function normaliseError( error ) {
	if ( error && error.name === 'AbortError' ) {
		return { code: 'dev3sh_aborted', message: __( 'Request cancelled.', 'dev3-ai-site-health-analyst' ), hint: '', retryable: true, aborted: true };
	}
	if ( typeof navigator !== 'undefined' && navigator.onLine === false ) {
		return {
			code: 'dev3sh_browser_offline',
			message: __( 'You appear to be offline.', 'dev3-ai-site-health-analyst' ),
			hint: __( 'Check your internet connection, then try again.', 'dev3-ai-site-health-analyst' ),
			retryable: true,
		};
	}
	if ( error && error.code === 'fetch_error' ) {
		return {
			code: 'dev3sh_network',
			message: __( 'Could not reach your WordPress site.', 'dev3-ai-site-health-analyst' ),
			hint: __( 'Check your connection or reload the editor.', 'dev3-ai-site-health-analyst' ),
			retryable: true,
		};
	}
	if ( error && error.code === 'rest_cookie_invalid_nonce' ) {
		return {
			code: 'dev3sh_bad_nonce',
			message: __( 'Your session has expired.', 'dev3-ai-site-health-analyst' ),
			hint: __( 'Save your work and reload the page.', 'dev3-ai-site-health-analyst' ),
			retryable: false,
		};
	}
	return {
		code: ( error && error.code ) || 'dev3sh_unknown',
		message: ( error && error.message ) || __( 'Something went wrong.', 'dev3-ai-site-health-analyst' ),
		hint: ( error && error.hint ) || '',
		retryable: !! ( error && error.retryable ),
	};
}

/**
 * apiFetch against dev3sh/v1 with the REST nonce.
 *
 * @param {string} path    Route relative to dev3sh/v1.
 * @param {Object} options apiFetch options.
 * @return {Promise<*>} Response.
 */
export function request( path, options = {} ) {
	return apiFetch( {
		url: restUrl( path ),
		...options,
		headers: { 'X-WP-Nonce': config.nonce, ...( options.headers || {} ) },
	} ).catch( ( error ) => {
		throw normaliseError( error );
	} );
}

/**
 * Generation with client-side throttling: one request at a time, and no resubmits within 800 ms.
 *
 * @param {Object} body Request body.
 * @return {Promise<Object>} Result.
 */
export function generate( body ) {
	const now = Date.now();
	if ( inFlight ) {
		return Promise.reject( { code: 'dev3sh_busy', message: __( 'The AI is still working on the previous request.', 'dev3-ai-site-health-analyst' ), hint: '', retryable: true, silent: true } );
	}
	if ( now - lastSubmit < MIN_INTERVAL ) {
		return Promise.reject( { code: 'dev3sh_throttled', message: '', hint: '', retryable: true, silent: true } );
	}
	lastSubmit = now;
	const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
	const promise = request( 'generate', { method: 'POST', data: body, signal: controller ? controller.signal : undefined } );
	inFlight = { controller };
	return promise.finally( () => {
		inFlight = null;
	} );
}

/**
 * Cancels the in-flight generation, if any.
 */
export function cancel() {
	if ( inFlight && inFlight.controller ) {
		inFlight.controller.abort();
	}
	inFlight = null;
}

export const isBusy = () => !! inFlight;

/** Test helper. */
export function __resetThrottle() {
	inFlight = null;
	lastSubmit = 0;
}
