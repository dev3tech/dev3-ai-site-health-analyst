import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, JobProgress, Notice, PageHeader, SkeletonCard, Stat, StatGrid, useToast } from '../core3/ui';
import { SEVERITY, SOURCE, collectorStatus, scoreTone, timeAgo } from './util';

const D = 'dev3-ai-site-health-analyst';

/**
 * Score ring (SVG).
 *
 * @param {Object} props       Props.
 * @param {number} props.score Score 0-100.
 * @return {Element} Ring.
 */
export function ScoreRing( { score } ) {
	const r = 46;
	const c = 2 * Math.PI * r;
	const tone = scoreTone( score );
	return (
		<div className={ `dev3sh-scorering is-${ tone }` } role="img" aria-label={ sprintf( /* translators: %d: score. */ __( 'Health score %d out of 100', D ), score ) }>
			<svg viewBox="0 0 112 112" width="112" height="112" aria-hidden="true">
				<circle className="dev3sh-scorering__track" cx="56" cy="56" r={ r } />
				<circle className="dev3sh-scorering__value" cx="56" cy="56" r={ r } strokeDasharray={ `${ ( c * score ) / 100 } ${ c }` } transform="rotate(-90 56 56)" />
			</svg>
			<span className="dev3sh-scorering__label" aria-hidden="true">
				<span className="dev3sh-scorering__num">{ score }</span>
				<span className="dev3sh-scorering__of">/100</span>
			</span>
		</div>
	);
}

/**
 * Simple line chart of the score history.
 *
 * @param {Object} props         Props.
 * @param {Array}  props.history [ { date, score } ].
 * @return {Element} Chart.
 */
export function Trend( { history } ) {
	if ( ! history || history.length < 2 ) {
		return <p className="dev3sh-muted">{ __( 'The 30-day trend appears after the second daily check.', D ) }</p>;
	}
	const w = 320;
	const h = 96;
	const pad = 6;
	const step = ( w - pad * 2 ) / ( history.length - 1 );
	const y = ( s ) => pad + ( h - pad * 2 ) * ( 1 - s / 100 );
	const pts = history.map( ( p, i ) => `${ pad + i * step },${ y( p.score ) }` ).join( ' ' );
	const last = history[ history.length - 1 ];
	const first = history[ 0 ];
	return (
		<figure className="dev3sh-trend">
			<svg viewBox={ `0 0 ${ w } ${ h }` } className="dev3sh-trend__svg" role="img" aria-label={ sprintf( /* translators: 1: first score, 2: last score, 3: days. */ __( 'Score went from %1$d to %2$d over %3$d days', D ), first.score, last.score, history.length ) }>
				{ [ 0, 50, 100 ].map( ( g ) => (
					<line key={ g } className="dev3sh-trend__grid" x1={ pad } x2={ w - pad } y1={ y( g ) } y2={ y( g ) } />
				) ) }
				<polyline className="dev3sh-trend__line" points={ pts } />
				<circle className="dev3sh-trend__dot" cx={ pad + ( history.length - 1 ) * step } cy={ y( last.score ) } r="4" />
			</svg>
			<figcaption className="dev3sh-muted">
				{ sprintf( /* translators: 1: date, 2: date. */ __( '%1$s to %2$s', D ), first.date, last.date ) }
			</figcaption>
		</figure>
	);
}

/**
 * Overview screen.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onCounts Badge update.
 * @param {Object}   props.settings Settings.
 * @return {Element} Screen.
 */
export default function Overview( { onCounts, settings } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ scanning, setScanning ] = useState( false );
	const [ job, setJob ] = useState( null );

	const load = () => {
		setError( null );
		return request( 'overview' )
			.then( ( d ) => {
				setData( d );
				setJob( d.integrity_job );
				onCounts( d.counts );
			} )
			.catch( setError );
	};
	useEffect( () => {
		load();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const scan = () => {
		setScanning( true );
		request( 'scan', { method: 'POST', data: { force: true } } )
			.then( ( r ) => {
				/* translators: 1: score, 2: new issues. */
				toast.success( sprintf( _n( 'Scan finished. Score %1$d, %2$d new issue.', 'Scan finished. Score %1$d, %2$d new issues.', r.created, D ), r.score, r.created ) );
				if ( r.integrity_job ) {
					setJob( r.integrity_job );
				}
				return load();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setScanning( false ) );
	};

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return (
			<>
				<PageHeader title={ __( 'Overview', D ) } />
				<SkeletonCard lines={ 4 } />
				<SkeletonCard lines={ 4 } />
			</>
		);
	}

	const sev = data.counts.severity;
	const diff = data.previous === null || data.previous === undefined ? null : data.score - data.previous;
	const neverScanned = ! data.last_scan;

	return (
		<>
			<PageHeader
				title={ __( 'Overview', D ) }
				description={ data.last_scan ? sprintf( /* translators: %s: time. */ __( 'Last full check %s. Checks run every hour in the background.', D ), timeAgo( data.last_scan ) ) : __( 'Run the first check to collect logs, Site Health results, updates and more.', D ) }
				actions={
					<Button variant="primary" icon="refresh" loading={ scanning } onClick={ scan }>
						{ scanning ? __( 'Checking…', D ) : __( 'Scan now', D ) }
					</Button>
				}
			/>

			{ ! settings.api_key_set && (
				<Notice
					tone="ai"
					title={ __( 'Connect DEV3 AI for plain-words explanations', D ) }
					actions={
						<Button size="sm" variant="primary" href="#/api">
							{ __( 'Add your API key', D ) }
						</Button>
					}
				>
					{ __( 'Every issue already has a built-in explanation. With a Google Gemini key, DEV3 AI explains each issue for your exact setup. Secrets, paths, emails and IPs are masked before anything is sent.', D ) }
				</Notice>
			) }

			{ ! data.log.enabled && (
				<Notice tone="info" title={ __( 'PHP error logging is off', D ) }>
					<p className="dev3sh-notice-p">{ __( 'Add these lines to wp-config.php (above "That\'s all, stop editing") so PHP errors are written to a log this plugin can read. Visitors will not see them.', D ) }</p>
					<pre className="dev3sh-snippet">{ "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', WP_CONTENT_DIR . '/../logs/debug.log' );\ndefine( 'WP_DEBUG_DISPLAY', false );" }</pre>
				</Notice>
			) }

			<div className="dev3sh-hero">
				<Card>
					<div className="dev3sh-hero__inner">
						<ScoreRing score={ data.score } />
						<div>
							<h3 className="dev3sh-hero__title">{ __( 'Health score', D ) }</h3>
							<p className="dev3sh-muted">
								{ data.counts.total === 0
									? __( 'No open issues. Nice work.', D )
									: sprintf( /* translators: %d: open issues. */ _n( '%d open issue, weighted by severity.', '%d open issues, weighted by severity.', data.counts.total, D ), data.counts.total ) }
							</p>
							{ diff !== null && (
								<Badge tone={ diff >= 0 ? 'success' : 'danger' }>
									{ diff === 0 ? __( 'Same as a week ago', D ) : sprintf( /* translators: %s: points. */ __( '%s vs a week ago', D ), ( diff > 0 ? '+' : '' ) + diff ) }
								</Badge>
							) }
						</div>
					</div>
				</Card>
				<Card title={ __( 'Last 30 days', D ) }>
					<Trend history={ data.history } />
				</Card>
			</div>

			<StatGrid>
				<a className="dev3sh-statlink" href="#/issues/severity:critical">
					<Stat label={ __( 'Critical', D ) } value={ String( sev.critical ) } icon="error" tone={ sev.critical ? 'danger' : 'neutral' } hint={ __( 'Fix these first', D ) } />
				</a>
				<a className="dev3sh-statlink" href="#/issues/severity:high">
					<Stat label={ __( 'High', D ) } value={ String( sev.high ) } icon="alert" tone={ sev.high ? 'warning' : 'neutral' } hint={ __( 'Fix this week', D ) } />
				</a>
				<a className="dev3sh-statlink" href="#/security">
					<Stat label={ __( 'Failed logins (24 h)', D ) } value={ String( data.security.failed_24h ) } icon="users" tone="brand" hint={ sprintf( /* translators: %d: count. */ __( '%d suspicious requests', D ), data.security.suspicious_24h ) } />
				</a>
				<a className="dev3sh-statlink" href="#/system">
					<Stat label={ __( 'Pending updates', D ) } value={ String( data.updates ) } icon="refresh" tone={ data.updates ? 'warning' : 'success' } hint={ data.updates ? __( 'Updates fix security holes', D ) : __( 'Everything up to date', D ) } />
				</a>
			</StatGrid>

			{ job && ( job.status === 'queued' || job.status === 'running' ) && (
				<Card title={ __( 'File integrity check', D ) }>
					<JobProgress jobId={ job.id } job={ job } title={ __( 'Comparing files with wordpress.org', D ) } onDone={ () => load() } />
				</Card>
			) }

			<Columns min={ 340 }>
				<Card
					title={ __( 'Top issues', D ) }
					actions={
						<Button size="sm" variant="ghost" href="#/issues" iconRight="chevronRight">
							{ __( 'All issues', D ) }
						</Button>
					}
				>
					{ data.top.length === 0 ? (
						<EmptyState
							compact
							icon="success"
							title={ neverScanned ? __( 'No data yet', D ) : __( 'No open issues', D ) }
							description={ neverScanned ? __( 'Click "Scan now" to run the first check.', D ) : __( 'New problems show up here as soon as they are seen.', D ) }
						/>
					) : (
						<ul className="dev3sh-toplist">
							{ data.top.map( ( i ) => (
								<li key={ i.id }>
									<a href={ `#/issues/${ i.id }` }>
										<span className="dev3sh-toplist__title">{ i.title }</span>
										<span className="dev3sh-toplist__meta">
											<Badge tone={ ( SEVERITY[ i.severity ] || SEVERITY.low ).tone } dot>
												{ ( SEVERITY[ i.severity ] || SEVERITY.low ).label }
											</Badge>
											<span>{ SOURCE[ i.source ] || i.source }</span>
											<span>{ sprintf( /* translators: %d: count. */ _n( '%d time', '%d times', i.count, D ), i.count ) }</span>
											<span>{ timeAgo( i.last_seen ) }</span>
										</span>
									</a>
								</li>
							) ) }
						</ul>
					) }
				</Card>
				<Card title={ __( 'What is being watched', D ) } description={ __( 'Each source feeds the issue list. Nothing on your site is changed.', D ) }>
					<ul className="dev3sh-sources">
						{ data.collectors.map( ( c ) => {
							const st = collectorStatus( c.status );
							return (
								<li key={ c.id }>
									<span>
										<span className="dev3sh-sources__label">{ c.label }</span>
										<span className="dev3sh-sources__meta">{ c.message || ( c.at ? sprintf( /* translators: %s: time. */ __( 'Checked %s', D ), timeAgo( c.at ) ) : __( 'Waiting for the first check', D ) ) }</span>
									</span>
									<Badge tone={ st.tone } dot>
										{ st.label }
									</Badge>
								</li>
							);
						} ) }
					</ul>
				</Card>
			</Columns>
		</>
	);
}
