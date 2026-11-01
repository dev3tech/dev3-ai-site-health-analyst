import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, EmptyState, ErrorNotice, Inline, Notice, PageHeader, Select, SkeletonCard } from '../core3/ui';
import { LEVEL, bytes, dateTime, qs, timeAgo } from './util';

const D = 'dev3-ai-site-health-analyst';

/**
 * Error log viewer: file status, rotation info and the latest parsed entries.
 *
 * @return {Element} Screen.
 */
export default function LogTab() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ level, setLevel ] = useState( '' );
	const [ lines, setLines ] = useState( '150' );
	const [ loading, setLoading ] = useState( false );
	const [ expanded, setExpanded ] = useState( {} );

	const load = () => {
		setLoading( true );
		setError( null );
		return request( `log${ qs( { level, lines } ) }` )
			.then( setData )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( () => {
		load();
	}, [ level, lines ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return (
			<>
				<PageHeader title={ __( 'Error log', D ) } />
				<SkeletonCard lines={ 6 } />
			</>
		);
	}
	const info = data.info;
	const sourceLabel = { wp_debug_log: 'WP_DEBUG_LOG', ini: 'php.ini error_log', custom: __( 'Custom path (Settings)', D ), none: __( 'Not configured', D ) };

	return (
		<>
			<PageHeader
				title={ __( 'Error log', D ) }
				description={ __( 'The newest PHP and WordPress log entries. The collector reads only new lines each hour and notices when the file is rotated or emptied.', D ) }
				actions={
					<Button icon="refresh" loading={ loading } onClick={ load }>
						{ __( 'Refresh', D ) }
					</Button>
				}
			/>
			{ info.public && info.readable && (
				<Notice tone="warning" title={ __( 'Your log file may be public', D ) }>
					{ __( 'The log sits inside the web folder. Anyone who guesses the address can read it, including file paths and errors. Move it outside the web root with WP_DEBUG_LOG set to a path, or block access to .log files in your server configuration.', D ) }
				</Notice>
			) }
			{ info.message && ! info.readable && (
				<Notice tone={ info.enabled ? 'warning' : 'info' } title={ info.enabled ? __( 'Log not readable yet', D ) : __( 'Logging is off', D ) }>
					<p className="dev3sh-notice-p">{ info.message }</p>
					{ ! info.enabled && <pre className="dev3sh-snippet">{ "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );" }</pre> }
				</Notice>
			) }
			<Card title={ __( 'Log file', D ) }>
				<dl className="dev3sh-facts dev3sh-facts--grid">
					<div>
						<dt>{ __( 'Source', D ) }</dt>
						<dd>{ sourceLabel[ info.source ] || info.source }</dd>
					</div>
					<div>
						<dt>{ __( 'Path', D ) }</dt>
						<dd>
							<code className="dev3sh-code-inline">{ info.path || '—' }</code>
						</dd>
					</div>
					<div>
						<dt>{ __( 'Size', D ) }</dt>
						<dd>{ info.exists ? bytes( info.size ) : '—' }</dd>
					</div>
					<div>
						<dt>{ __( 'Modified', D ) }</dt>
						<dd>{ dateTime( info.modified ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Last read', D ) }</dt>
						<dd>{ info.read_at ? timeAgo( info.read_at ) : __( 'not yet', D ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Rotation', D ) }</dt>
						<dd>{ info.rotated_at ? sprintf( /* translators: %s: time. */ __( 'Rotated or emptied %s', D ), timeAgo( info.rotated_at ) ) : __( 'No rotation seen', D ) }</dd>
					</div>
				</dl>
			</Card>
			<Card
				title={ __( 'Latest entries', D ) }
				description={ data.entries.length ? sprintf( /* translators: %d: count. */ _n( '%d entry, newest first', '%d entries, newest first', data.entries.length, D ), data.entries.length ) : '' }
				actions={
					<div className="dev3sh-inline-fields">
						<Select
							label={ __( 'Level', D ) }
							value={ level }
							onChange={ setLevel }
							options={ [ { value: '', label: __( 'All levels', D ) }, ...Object.keys( LEVEL ).map( ( k ) => ( { value: k, label: LEVEL[ k ].label } ) ) ] }
						/>
						<Select
							label={ __( 'Show', D ) }
							value={ lines }
							onChange={ setLines }
							options={ [
								{ value: '50', label: '50' },
								{ value: '150', label: '150' },
								{ value: '500', label: '500' },
							] }
						/>
					</div>
				}
			>
				{ data.entries.length === 0 ? (
					<EmptyState compact icon="success" title={ __( 'No entries', D ) } description={ info.readable ? __( 'The log has no entries for this filter.', D ) : __( 'Nothing to show until a log file exists.', D ) } />
				) : (
					<ol className="dev3sh-loglist">
						{ data.entries.map( ( e, i ) => {
							const lv = LEVEL[ e.level ] || LEVEL.other;
							return (
								<li key={ i } className="dev3sh-loglist__item">
									<div className="dev3sh-loglist__head">
										<Badge tone={ lv.tone } dot>
											{ lv.label }
										</Badge>
										<span className="dev3sh-muted">{ e.time ? dateTime( e.time ) : '' }</span>
										{ e.issue > 0 && (
											<a className="dev3sh-link" href={ `#/issues/${ e.issue }` }>
												{ __( 'Open issue', D ) }
											</a>
										) }
									</div>
									<p className="dev3sh-loglist__msg">{ e.message }</p>
									{ e.file && (
										<code className="dev3sh-code-inline">
											{ e.file }
											{ e.line ? `:${ e.line }` : '' }
										</code>
									) }
									{ e.trace.length > 0 && (
										<Inline gap={ 8 }>
											<Button size="sm" variant="link" aria-expanded={ !! expanded[ i ] } onClick={ () => setExpanded( ( x ) => ( { ...x, [ i ]: ! x[ i ] } ) ) }>
												{ expanded[ i ] ? __( 'Hide stack trace', D ) : __( 'Show stack trace', D ) }
											</Button>
										</Inline>
									) }
									{ expanded[ i ] && <pre className="dev3sh-snippet">{ e.trace.join( '\n' ) }</pre> }
								</li>
							);
						} ) }
					</ol>
				) }
			</Card>
		</>
	);
}
