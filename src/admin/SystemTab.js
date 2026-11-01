import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Button, Card, EmptyState, ErrorNotice, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid, Tabs } from '../core3/ui';
import { dateTime, timeAgo } from './util';

const D = 'dev3-ai-site-health-analyst';

const HEALTH = {
	critical: { label: __( 'Critical', D ), tone: 'danger' },
	recommended: { label: __( 'Recommended', D ), tone: 'warning' },
	good: { label: __( 'Passed', D ), tone: 'success' },
};

/**
 * System screen: updates, cron health, slow queries, Site Health and recent changes.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Router.
 * @return {Element} Screen.
 */
export default function SystemTab( { navigate } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ tab, setTab ] = useState( 'updates' );

	const load = () => request( 'system' ).then( setData ).catch( setError );
	useEffect( () => {
		load();
	}, [] );

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return (
			<>
				<PageHeader title={ __( 'System', D ) } />
				<SkeletonCard lines={ 6 } />
			</>
		);
	}
	const u = data.updates;
	const c = data.cron;
	const pending = ( u.core.available ? 1 : 0 ) + u.plugins.length + u.themes.length;
	const failing = data.site_health.results.filter( ( r ) => r.status !== 'good' ).length;

	const updateRows = [
		...( u.core.available ? [ { id: 'core', name: 'WordPress', current: u.core.current, next: u.core.available, kind: __( 'Core', D ), active: true } ] : [] ),
		...u.plugins.map( ( p ) => ( { id: p.file, name: p.name, current: p.current, next: p.new, kind: __( 'Plugin', D ), active: p.active } ) ),
		...u.themes.map( ( t ) => ( { id: `theme-${ t.slug }`, name: t.name, current: t.current, next: t.new, kind: __( 'Theme', D ), active: t.active } ) ),
	];

	const panels = {
		updates: (
			<>
				<Card
					flush
					title={ __( 'Pending updates', D ) }
					actions={
						pending > 0 && (
							<Button size="sm" href={ `${ config.adminUrl || '' }update-core.php` } iconRight="external">
								{ __( 'Open Updates', D ) }
							</Button>
						)
					}
				>
					<ResponsiveTable
						columns={ [
							{ key: 'name', label: __( 'Name', D ), primary: true },
							{ key: 'kind', label: __( 'Type', D ) },
							{ key: 'version', label: __( 'Version', D ), nowrap: true, render: ( r ) => `${ r.current } → ${ r.next }` },
							{ key: 'active', label: __( 'Status', D ), render: ( r ) => <Badge tone={ r.active ? 'warning' : 'neutral' }>{ r.active ? __( 'Active', D ) : __( 'Inactive', D ) }</Badge> },
						] }
						rows={ updateRows }
						caption={ __( 'Pending updates', D ) }
						empty={ { icon: 'success', title: __( 'Everything is up to date', D ), description: u.checked_at ? sprintf( /* translators: %s: time. */ __( 'Checked with wordpress.org %s.', D ), timeAgo( u.checked_at ) ) : '' } }
					/>
				</Card>
				<Card title={ __( 'Unused code', D ) } description={ __( 'Inactive plugins and themes can still be attacked. Delete what you do not use.', D ) }>
					<p>
						{ sprintf( /* translators: 1: plugins, 2: themes. */ __( '%1$d inactive plugins, %2$d inactive themes.', D ), u.inactive_plugins.length, u.inactive_themes.length ) }
					</p>
					{ u.inactive_plugins.length > 0 && <p className="dev3sh-muted">{ u.inactive_plugins.map( ( p ) => p.name ).join( ', ' ) }</p> }
				</Card>
			</>
		),
		cron: (
			<>
				{ c.disabled && (
					<Notice tone="info" title={ __( 'WP-Cron is disabled', D ) }>
						{ __( 'DISABLE_WP_CRON is set, so a server cron job must call wp-cron.php regularly.', D ) }
					</Notice>
				) }
				<StatGrid>
					<Stat label={ __( 'Scheduled tasks', D ) } value={ String( c.total ) } icon="clock" />
					<Stat label={ __( 'Overdue', D ) } value={ String( c.overdue_count ) } icon="alert" tone={ c.overdue_count ? 'warning' : 'success' } />
					<Stat label={ __( 'Without code', D ) } value={ String( c.orphans.length ) } icon="x" tone={ c.orphans.length ? 'warning' : 'success' } />
					<Stat label={ __( 'Our hourly check', D ) } value={ c.heartbeat ? timeAgo( c.heartbeat ) : __( 'not yet', D ) } icon="refresh" tone={ c.heartbeat_late ? 'danger' : 'neutral' } />
				</StatGrid>
				<Card flush title={ __( 'Overdue tasks', D ) }>
					<ResponsiveTable
						columns={ [
							{ key: 'hook', label: __( 'Task (hook)', D ), primary: true, render: ( r ) => <code className="dev3sh-code-inline">{ r.hook }</code> },
							{ key: 'late_min', label: __( 'Late', D ), align: 'end', render: ( r ) => sprintf( /* translators: %d: minutes. */ __( '%d min', D ), r.late_min ) },
							{ key: 'schedule', label: __( 'Repeats', D ), render: ( r ) => r.schedule || __( 'once', D ) },
							{ key: 'has_cb', label: __( 'Code', D ), render: ( r ) => ( r.has_cb ? <Badge tone="success">{ __( 'Yes', D ) }</Badge> : <Badge tone="warning">{ __( 'Missing', D ) }</Badge> ) },
						] }
						rows={ c.overdue }
						rowKey={ ( r ) => `${ r.hook }-${ r.time }-${ r.key }` }
						caption={ __( 'Overdue tasks', D ) }
						empty={ { icon: 'success', title: __( 'Nothing is late', D ) } }
					/>
				</Card>
				<Card flush title={ __( 'Next runs', D ) }>
					<ResponsiveTable
						columns={ [
							{ key: 'hook', label: __( 'Task (hook)', D ), primary: true, render: ( r ) => <code className="dev3sh-code-inline">{ r.hook }</code> },
							{ key: 'time', label: __( 'Next run', D ), nowrap: true, render: ( r ) => dateTime( r.time ) },
							{ key: 'schedule', label: __( 'Repeats', D ), render: ( r ) => r.schedule || __( 'once', D ) },
						] }
						rows={ c.next }
						rowKey={ ( r ) => `${ r.hook }-${ r.time }-${ r.key }` }
						caption={ __( 'Next runs', D ) }
						pageSize={ 10 }
					/>
				</Card>
			</>
		),
		queries: (
			<Card flush title={ __( 'Slow database queries', D ) } description={ sprintf( /* translators: %d: ms. */ __( 'Queries slower than %d ms, grouped by shape.', D ), data.slow.threshold ) }>
				{ ! data.slow.available ? (
					<div className="dev3sh-pad">
						<EmptyState
							compact
							icon="info"
							title={ __( 'SAVEQUERIES is off', D ) }
							description={ __( "Add define( 'SAVEQUERIES', true ); to wp-config.php on a staging site or for a short time to capture slow queries. It uses extra memory on every request.", D ) }
						/>
					</div>
				) : (
					<ResponsiveTable
						columns={ [
							{ key: 'title', label: __( 'Query', D ), primary: true, render: ( q ) => <span><span className="dev3sh-issue__title">{ q.title }</span><code className="dev3sh-code-inline dev3sh-sql">{ q.message }</code></span> },
							{ key: 'component_label', label: __( 'Caller', D ), render: ( q ) => q.component_label || '—' },
							{ key: 'count', label: __( 'Seen', D ), align: 'end' },
						] }
						rows={ data.slow.items }
						caption={ __( 'Slow queries', D ) }
						onRowClick={ ( q ) => navigate( `issues/${ q.id }` ) }
						empty={ { icon: 'success', title: __( 'No slow queries captured', D ) } }
					/>
				) }
			</Card>
		),
		health: (
			<Card flush title={ __( 'Site Health results', D ) } description={ data.site_health.at ? sprintf( /* translators: %s: time. */ __( 'Ran %s. Refreshed daily.', D ), timeAgo( data.site_health.at ) ) : __( 'Runs with the next scan.', D ) }>
				<ResponsiveTable
					columns={ [
						{ key: 'label', label: __( 'Test', D ), primary: true },
						{ key: 'badge', label: __( 'Area', D ) },
						{
							key: 'status',
							label: __( 'Result', D ),
							render: ( r ) => {
								const s = HEALTH[ r.status ] || HEALTH.recommended;
								return (
									<Badge tone={ s.tone } dot>
										{ s.label }
									</Badge>
								);
							},
						},
					] }
					rows={ [ ...data.site_health.results ].sort( ( a, b ) => [ 'critical', 'recommended', 'good' ].indexOf( a.status ) - [ 'critical', 'recommended', 'good' ].indexOf( b.status ) ) }
					rowKey="test"
					caption={ __( 'Site Health results', D ) }
					pageSize={ 15 }
					empty={ { icon: 'info', title: __( 'No results yet', D ), description: __( 'Run a scan from the Overview.', D ) } }
				/>
			</Card>
		),
		changes: (
			<Card flush title={ __( 'Recent plugin, theme and core changes', D ) }>
				<ResponsiveTable
					columns={ [
						{ key: 'message', label: __( 'Change', D ), primary: true },
						{ key: 'user_login', label: __( 'By', D ), render: ( e ) => ( e.meta && e.meta.outside ? <Badge tone="warning">{ __( 'Outside WordPress', D ) }</Badge> : e.user_login || __( 'System', D ) ) },
						{ key: 'created_at', label: __( 'When', D ), nowrap: true, render: ( e ) => timeAgo( e.created_at ) },
					] }
					rows={ data.changes }
					caption={ __( 'Recent changes', D ) }
					empty={ { icon: 'inbox', title: __( 'No changes logged yet', D ) } }
				/>
			</Card>
		),
		env: (
			<Card title={ __( 'Environment', D ) } description={ __( 'This summary (without paths or secrets) is what DEV3 AI sees about your setup.', D ) }>
				<dl className="dev3sh-facts dev3sh-facts--grid">
					<div>
						<dt>WordPress</dt>
						<dd>{ data.environment.wordpress }</dd>
					</div>
					<div>
						<dt>PHP</dt>
						<dd>{ data.environment.php }</dd>
					</div>
					<div>
						<dt>{ __( 'Database', D ) }</dt>
						<dd>{ u.db }</dd>
					</div>
					<div>
						<dt>{ __( 'Theme', D ) }</dt>
						<dd>{ data.environment.theme }</dd>
					</div>
					<div>
						<dt>{ __( 'Object cache', D ) }</dt>
						<dd>{ data.environment.object_cache ? __( 'Persistent', D ) : __( 'None', D ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Active plugins', D ) }</dt>
						<dd>{ data.environment.active_plugins.length }</dd>
					</div>
				</dl>
			</Card>
		),
	};

	const tabs = [
		{ id: 'updates', label: __( 'Updates', D ), badge: pending ? String( pending ) : undefined },
		{ id: 'cron', label: __( 'Cron', D ), badge: c.overdue_count ? String( c.overdue_count ) : undefined },
		{ id: 'queries', label: __( 'Slow queries', D ) },
		{ id: 'health', label: __( 'Site Health', D ), badge: failing ? String( failing ) : undefined },
		{ id: 'changes', label: __( 'Changes', D ) },
		{ id: 'env', label: __( 'Environment', D ) },
	];

	return (
		<>
			<PageHeader title={ __( 'System', D ) } description={ __( 'Updates, scheduled tasks, slow queries, Site Health results and the change log in one place.', D ) } />
			<div className="dev3sh-tabs-scroll">
				<Tabs label={ __( 'System sections', D ) } variant="pills" value={ tab } onChange={ setTab } tabs={ tabs } />
			</div>
			{ panels[ tab ] }
		</>
	);
}
