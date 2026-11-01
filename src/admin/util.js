import { __, sprintf } from '@wordpress/i18n';

const D = 'dev3-ai-site-health-analyst';

export const SEVERITY = {
	critical: { label: __( 'Critical', D ), tone: 'danger' },
	high: { label: __( 'High', D ), tone: 'warning' },
	medium: { label: __( 'Medium', D ), tone: 'info' },
	low: { label: __( 'Low', D ), tone: 'neutral' },
	info: { label: __( 'Info', D ), tone: 'neutral' },
};

export const SEVERITY_ORDER = [ 'critical', 'high', 'medium', 'low' ];

export const SOURCE = {
	php: __( 'PHP error log', D ),
	site_health: __( 'Site Health', D ),
	security: __( 'Security', D ),
	change: __( 'Changes', D ),
	updates: __( 'Updates', D ),
	cron: __( 'Cron', D ),
	query: __( 'Slow queries', D ),
	integrity: __( 'File integrity', D ),
};

export const WHO = {
	owner: __( 'You (site owner)', D ),
	developer: __( 'Your developer', D ),
	host: __( 'Your hosting provider', D ),
};

export const URGENCY = {
	now: { label: __( 'Fix now', D ), tone: 'danger' },
	soon: { label: __( 'Fix this week', D ), tone: 'warning' },
	normal: { label: __( 'When convenient', D ), tone: 'info' },
	low: { label: __( 'Low priority', D ), tone: 'neutral' },
};

export const EVENT_TYPE = {
	login_failed: { label: __( 'Failed login', D ), tone: 'warning' },
	login_ok: { label: __( 'Login', D ), tone: 'success' },
	suspicious: { label: __( 'Suspicious', D ), tone: 'danger' },
	not_found_flood: { label: __( '404 flood', D ), tone: 'danger' },
	change: { label: __( 'Change', D ), tone: 'info' },
	fix: { label: __( 'Fix', D ), tone: 'brand' },
	alert: { label: __( 'Alert sent', D ), tone: 'neutral' },
	report: { label: __( 'Report sent', D ), tone: 'neutral' },
};

export const LEVEL = {
	fatal: { label: __( 'Fatal', D ), tone: 'danger' },
	parse: { label: __( 'Parse', D ), tone: 'danger' },
	database: { label: __( 'Database', D ), tone: 'warning' },
	warning: { label: __( 'Warning', D ), tone: 'warning' },
	notice: { label: __( 'Notice', D ), tone: 'info' },
	deprecated: { label: __( 'Deprecated', D ), tone: 'neutral' },
	other: { label: __( 'Other', D ), tone: 'neutral' },
};

/**
 * Query string from an object (skips empty values).
 *
 * @param {Object} params Params.
 * @return {string} "?a=1&b=2" or "".
 */
export function qs( params ) {
	const parts = Object.keys( params )
		.filter( ( k ) => params[ k ] !== '' && params[ k ] !== undefined && params[ k ] !== null )
		.map( ( k ) => `${ encodeURIComponent( k ) }=${ encodeURIComponent( params[ k ] ) }` );
	return parts.length ? `?${ parts.join( '&' ) }` : '';
}

/**
 * "3 min ago" style relative time.
 *
 * @param {string} iso ISO date.
 * @return {string} Text.
 */
export function timeAgo( iso ) {
	if ( ! iso ) {
		return __( 'never', D );
	}
	const t = new Date( iso ).getTime();
	if ( Number.isNaN( t ) ) {
		return '';
	}
	const s = Math.round( ( Date.now() - t ) / 1000 );
	if ( s < 0 ) {
		const m = Math.round( -s / 60 );
		/* translators: %d: minutes. */
		return m < 60 ? sprintf( __( 'in %d min', D ), Math.max( 1, m ) ) : sprintf( __( 'in %d h', D ), Math.round( m / 60 ) ); // eslint-disable-line @wordpress/valid-sprintf
	}
	if ( s < 60 ) {
		return __( 'just now', D );
	}
	if ( s < 3600 ) {
		/* translators: %d: minutes. */
		return sprintf( __( '%d min ago', D ), Math.round( s / 60 ) );
	}
	if ( s < 86400 ) {
		/* translators: %d: hours. */
		return sprintf( __( '%d h ago', D ), Math.round( s / 3600 ) );
	}
	/* translators: %d: days. */
	return sprintf( __( '%d d ago', D ), Math.round( s / 86400 ) );
}

/**
 * Local date + time.
 *
 * @param {string} iso ISO.
 * @return {string} Text.
 */
export function dateTime( iso ) {
	if ( ! iso ) {
		return '—';
	}
	const d = new Date( iso );
	return Number.isNaN( d.getTime() ) ? '—' : d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
}

/**
 * Bytes → "1.2 MB".
 *
 * @param {number} n Bytes.
 * @return {string} Text.
 */
export function bytes( n ) {
	const v = Number( n ) || 0;
	if ( v < 1024 ) {
		return `${ v } B`;
	}
	if ( v < 1048576 ) {
		return `${ ( v / 1024 ).toFixed( 1 ) } KB`;
	}
	return `${ ( v / 1048576 ).toFixed( 1 ) } MB`;
}

/**
 * Score tone.
 *
 * @param {number} score Score.
 * @return {string} success|warning|danger.
 */
export function scoreTone( score ) {
	if ( score >= 80 ) {
		return 'success';
	}
	return score >= 50 ? 'warning' : 'danger';
}

/**
 * Collector status → badge.
 *
 * @param {string} status Status.
 * @return {Object} { label, tone }.
 */
export function collectorStatus( status ) {
	const map = {
		ok: { label: __( 'Working', D ), tone: 'success' },
		running: { label: __( 'Running', D ), tone: 'info' },
		warning: { label: __( 'Needs attention', D ), tone: 'warning' },
		error: { label: __( 'Error', D ), tone: 'danger' },
		off: { label: __( 'Off', D ), tone: 'neutral' },
		pending: { label: __( 'Not run yet', D ), tone: 'neutral' },
	};
	return map[ status ] || map.pending;
}
