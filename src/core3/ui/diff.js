/**
 * Small LCS diff for AI draft review. Returns [ { type: 'same'|'add'|'del', value } ].
 */

const MAX_CELLS = 2000000;

function lcs( a, b ) {
	const n = a.length;
	const m = b.length;
	const table = new Uint32Array( ( n + 1 ) * ( m + 1 ) );
	for ( let i = n - 1; i >= 0; i-- ) {
		for ( let j = m - 1; j >= 0; j-- ) {
			table[ i * ( m + 1 ) + j ] = a[ i ] === b[ j ] ? table[ ( i + 1 ) * ( m + 1 ) + j + 1 ] + 1 : Math.max( table[ ( i + 1 ) * ( m + 1 ) + j ], table[ i * ( m + 1 ) + j + 1 ] );
		}
	}
	const out = [];
	let i = 0;
	let j = 0;
	const push = ( type, value ) => {
		const last = out[ out.length - 1 ];
		if ( last && last.type === type ) {
			last.value += value;
		} else {
			out.push( { type, value } );
		}
	};
	while ( i < n && j < m ) {
		if ( a[ i ] === b[ j ] ) {
			push( 'same', a[ i ] );
			i++;
			j++;
		} else if ( table[ ( i + 1 ) * ( m + 1 ) + j ] >= table[ i * ( m + 1 ) + j + 1 ] ) {
			push( 'del', a[ i++ ] );
		} else {
			push( 'add', b[ j++ ] );
		}
	}
	while ( i < n ) {
		push( 'del', a[ i++ ] );
	}
	while ( j < m ) {
		push( 'add', b[ j++ ] );
	}
	return out;
}

function diffTokens( a, b ) {
	// Trim the common head and tail first: drafts usually change a part of the text.
	let start = 0;
	while ( start < a.length && start < b.length && a[ start ] === b[ start ] ) {
		start++;
	}
	let endA = a.length;
	let endB = b.length;
	while ( endA > start && endB > start && a[ endA - 1 ] === b[ endB - 1 ] ) {
		endA--;
		endB--;
	}
	const midA = a.slice( start, endA );
	const midB = b.slice( start, endB );
	let middle;
	if ( ( midA.length + 1 ) * ( midB.length + 1 ) > MAX_CELLS ) {
		middle = [ ...( midA.length ? [ { type: 'del', value: midA.join( '' ) } ] : [] ), ...( midB.length ? [ { type: 'add', value: midB.join( '' ) } ] : [] ) ];
	} else {
		middle = lcs( midA, midB );
	}
	const head = start ? [ { type: 'same', value: a.slice( 0, start ).join( '' ) } ] : [];
	const tail = endA < a.length ? [ { type: 'same', value: a.slice( endA ).join( '' ) } ] : [];
	return [ ...head, ...middle, ...tail ];
}

/**
 * Word-level diff (whitespace kept as separate tokens).
 *
 * @param {string} before Original.
 * @param {string} after  Draft.
 * @return {Array} Parts.
 */
export function diffWords( before, after ) {
	const tok = ( s ) => String( s || '' ).match( /\s+|[^\s]+/g ) || [];
	return diffTokens( tok( before ), tok( after ) );
}

/**
 * Line-level diff (JSON, code, long text).
 *
 * @param {string} before Original.
 * @param {string} after  Draft.
 * @return {Array} Parts (values keep their trailing newline).
 */
export function diffLines( before, after ) {
	const tok = ( s ) => String( s || '' ).match( /[^\n]*\n|[^\n]+$/g ) || [];
	return diffTokens( tok( before ), tok( after ) );
}

/**
 * Pretty JSON (stable 2-space indent) for diffs and editing.
 *
 * @param {*} value Value.
 * @return {string} JSON.
 */
export function toJson( value ) {
	if ( typeof value === 'string' ) {
		try {
			return JSON.stringify( JSON.parse( value ), null, 2 );
		} catch ( e ) {
			return value;
		}
	}
	return JSON.stringify( value === undefined ? null : value, null, 2 );
}

/**
 * Counts changed tokens.
 *
 * @param {Array} parts Diff parts.
 * @return {Object} { added, removed } (characters).
 */
export function diffStats( parts ) {
	return parts.reduce(
		( acc, p ) => {
			if ( p.type === 'add' ) {
				acc.added += p.value.trim().length;
			} else if ( p.type === 'del' ) {
				acc.removed += p.value.trim().length;
			}
			return acc;
		},
		{ added: 0, removed: 0 }
	);
}
