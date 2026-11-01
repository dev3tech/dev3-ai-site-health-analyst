import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, ErrorNotice, Notice, PageHeader, ResponsiveTable, Select, SkeletonCard, Stat, StatGrid, TextField, useToast } from '../core3/ui';
import FixModal from './FixModal';
import { EVENT_TYPE, dateTime, qs, timeAgo } from './util';

const D = 'dev3-ai-site-health-analyst';
const PER_PAGE = 25;

/**
 * Small bar chart (14 days).
 *
 * @param {Object} props        Props.
 * @param {Array}  props.values Values (oldest first).
 * @param {string} props.label  Accessible label.
 * @return {Element} Chart.
 */
export function Bars( { values, label } ) {
	const max = Math.max( 1, ...values );
	const total = values.reduce( ( a, b ) => a + b, 0 );
	return (
		<div className="dev3sh-daybars" role="img" aria-label={ sprintf( /* translators: 1: label, 2: total. */ __( '%1$s: %2$d in 14 days', D ), label, total ) }>
			{ values.map( ( v, i ) => (
				<span key={ i } className="dev3sh-daybars__bar" style={ { height: `${ Math.max( 3, ( v / max ) * 100 ) }%` } } title={ String( v ) } />
			) ) }
		</div>
	);
}

/**
 * Security screen: KPIs, top addresses, blocklist and the event log.
 *
 * @return {Element} Screen.
 */
export default function SecurityTab() {
	const toast = useToast();
	const [ sum, setSum ] = useState( null );
	const [ events, setEvents ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ type, setType ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ fix, setFix ] = useState( null );

	const loadSummary = () =>
		request( 'security' )
			.then( setSum )
			.catch( setError );
	const loadEvents = () => {
		setLoading( true );
		return request( `events${ qs( { type, search: query, page, per_page: PER_PAGE } ) }` )
			.then( setEvents )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( () => {
		loadSummary();
	}, [] );
	useEffect( () => {
		loadEvents();
	}, [ type, query, page ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		const t = setTimeout( () => {
			setPage( 1 );
			setQuery( search );
		}, 350 );
		return () => clearTimeout( t );
	}, [ search ] );

	const unblock = ( ip ) =>
		request( 'security/unblock', { method: 'POST', data: { ip } } )
			.then( () => {
				/* translators: %s: IP. */
				toast.success( sprintf( __( '%s unblocked.', D ), ip ) );
				loadSummary();
			} )
			.catch( ( e ) => toast.error( e ) );

	const blockFix = ( ip ) =>
		setFix( {
			type: 'block_ip',
			target: ip,
			/* translators: %s: IP. */
			label: sprintf( __( 'Block %s', D ), ip ),
		} );

	if ( error && ! sum ) {
		return <ErrorNotice error={ error } onRetry={ loadSummary } />;
	}
	if ( ! sum ) {
		return (
			<>
				<PageHeader title={ __( 'Security', D ) } />
				<SkeletonCard lines={ 5 } />
			</>
		);
	}

	const ipColumns = [
		{ key: 'value', label: __( 'Address', D ), primary: true, render: ( r ) => <code className="dev3sh-code-inline">{ r.value }</code> },
		{ key: 'count', label: __( 'Events (7 d)', D ), align: 'end' },
		{ key: 'last', label: __( 'Last', D ), nowrap: true, render: ( r ) => timeAgo( r.last ) },
	];
	const blockColumns = [
		{ key: 'ip', label: __( 'Address', D ), primary: true, render: ( r ) => <code className="dev3sh-code-inline">{ r.ip }</code> },
		{ key: 'added', label: __( 'Blocked', D ), render: ( r ) => timeAgo( r.added ) },
		{ key: 'expires', label: __( 'Until', D ), render: ( r ) => dateTime( r.expires ) },
	];
	const eventColumns = [
		{
			key: 'message',
			label: __( 'Event', D ),
			primary: true,
			width: '40%',
			render: ( e ) => (
				<span>
					<span className="dev3sh-issue__title">{ e.message }</span>
					{ e.path && <span className="dev3sh-issue__meta">{ e.path }</span> }
				</span>
			),
		},
		{
			key: 'type',
			label: __( 'Type', D ),
			nowrap: true,
			render: ( e ) => {
				const t = EVENT_TYPE[ e.type ] || { label: e.type, tone: 'neutral' };
				return <Badge tone={ t.tone }>{ t.label }</Badge>;
			},
		},
		{ key: 'ip', label: __( 'Address', D ), render: ( e ) => ( e.ip ? <code className="dev3sh-code-inline">{ e.ip }</code> : '—' ) },
		{ key: 'user_login', label: __( 'User', D ), hideOnMobile: true, render: ( e ) => e.user_login || '—' },
		{ key: 'created_at', label: __( 'When', D ), nowrap: true, render: ( e ) => <span title={ dateTime( e.created_at ) }>{ timeAgo( e.created_at ) }</span> },
	];
	const typeOptions = [ { value: '', label: __( 'All events', D ) }, ...Object.keys( EVENT_TYPE ).map( ( k ) => ( { value: k, label: EVENT_TYPE[ k ].label } ) ) ];

	return (
		<>
			<PageHeader title={ __( 'Security', D ) } description={ __( 'Failed logins, suspicious requests and changes, recorded by a lightweight logger. Only matching requests are written to the database.', D ) } />
			{ ! sum.logger && (
				<Notice tone="warning" title={ __( 'The security logger is off', D ) }>
					{ __( 'Turn it on in Settings to record failed logins and suspicious requests.', D ) }
				</Notice>
			) }
			<StatGrid>
				<Stat label={ __( 'Failed logins (24 h)', D ) } value={ String( sum.failed_24h ) } icon="users" tone={ sum.failed_24h > 20 ? 'warning' : 'neutral' } hint={ sprintf( /* translators: %d: count. */ __( '%d in 7 days', D ), sum.failed_7d ) } />
				<Stat label={ __( 'Suspicious requests (24 h)', D ) } value={ String( sum.suspicious_24h ) } icon="alert" tone={ sum.suspicious_24h ? 'danger' : 'neutral' } hint={ sprintf( /* translators: %d: count. */ __( '%d in 7 days', D ), sum.suspicious_7d ) } />
				<Stat label={ __( 'Blocked addresses', D ) } value={ String( sum.blocklist.length ) } icon="x" tone="brand" hint={ sum.fixes_enabled ? __( 'Block from the table below', D ) : __( 'Read-only mode', D ) } />
				<Stat label={ __( 'XML-RPC', D ) } value={ sum.xmlrpc_disabled ? __( 'Disabled', D ) : __( 'Enabled', D ) } icon="key" tone={ sum.xmlrpc_disabled ? 'success' : 'neutral' } hint={ __( 'Used by bots for password guessing', D ) } />
			</StatGrid>

			<Columns min={ 320 }>
				<Card title={ __( 'Failed logins, last 14 days', D ) }>
					<Bars values={ sum.daily_failed } label={ __( 'Failed logins', D ) } />
				</Card>
				<Card title={ __( 'Suspicious requests, last 14 days', D ) }>
					<Bars values={ sum.daily_suspect } label={ __( 'Suspicious requests', D ) } />
					{ sum.top_rules.length > 0 && (
						<ul className="dev3sh-rules">
							{ sum.top_rules.map( ( r ) => (
								<li key={ r.value }>
									<span>{ sum.rules[ r.value ] || r.value }</span>
									<Badge tone="neutral">{ r.count }</Badge>
								</li>
							) ) }
						</ul>
					) }
				</Card>
			</Columns>

			<Columns min={ 320 }>
				<Card flush title={ __( 'Most active addresses (7 days)', D ) }>
					<ResponsiveTable
						columns={ ipColumns }
						rows={ sum.top_ips }
						rowKey="value"
						caption={ __( 'Most active addresses', D ) }
						rowActions={ ( r ) =>
							sum.blocklist.some( ( b ) => b.ip === r.value ) ? (
								<Badge tone="neutral">{ __( 'Blocked', D ) }</Badge>
							) : r.value === sum.your_ip ? (
								<Badge tone="info">{ __( 'You', D ) }</Badge>
							) : (
								<Button size="sm" variant="ghost" onClick={ () => blockFix( r.value ) }>
									{ __( 'Block…', D ) }
								</Button>
							)
						}
						empty={ { icon: 'success', title: __( 'No activity', D ), description: __( 'No failed logins or suspicious requests in the last 7 days.', D ) } }
					/>
				</Card>
				<Card
					flush
					title={ __( 'Blocklist', D ) }
					actions={
						! sum.xmlrpc_disabled && (
							<Button size="sm" variant="ghost" onClick={ () => setFix( { type: 'disable_xmlrpc', target: 'xmlrpc', label: __( 'Disable XML-RPC', D ) } ) }>
								{ __( 'Disable XML-RPC…', D ) }
							</Button>
						)
					}
				>
					<ResponsiveTable
						columns={ blockColumns }
						rows={ sum.blocklist }
						rowKey="ip"
						caption={ __( 'Blocked addresses', D ) }
						rowActions={ ( r ) => (
							<Button size="sm" variant="ghost" onClick={ () => unblock( r.ip ) }>
								{ __( 'Unblock', D ) }
							</Button>
						) }
						empty={ { icon: 'info', title: __( 'Nobody is blocked', D ), description: __( 'Blocking is optional and always needs your confirmation.', D ) } }
					/>
				</Card>
			</Columns>

			<Card className="dev3sh-filters">
				<div className="dev3sh-filters__row">
					<TextField label={ __( 'Search events', D ) } value={ search } onChange={ setSearch } placeholder={ __( 'Address, user, path or message', D ) } />
					<Select
						label={ __( 'Type', D ) }
						value={ type }
						onChange={ ( v ) => {
							setType( v );
							setPage( 1 );
						} }
						options={ typeOptions }
					/>
				</div>
			</Card>
			<Card flush title={ __( 'Event log', D ) }>
				<ResponsiveTable
					columns={ eventColumns }
					rows={ events ? events.items : [] }
					loading={ loading && ! events }
					caption={ __( 'Event log', D ) }
					pagination={ events ? { page, perPage: PER_PAGE, total: events.total, onPageChange: setPage } : undefined }
					rowActions={ ( e ) =>
						e.ip && [ 'login_failed', 'suspicious', 'not_found_flood' ].includes( e.type ) && e.ip !== sum.your_ip && ! sum.blocklist.some( ( b ) => b.ip === e.ip ) ? (
							<Button size="sm" variant="ghost" onClick={ () => blockFix( e.ip ) }>
								{ __( 'Block…', D ) }
							</Button>
						) : null
					}
					empty={ { icon: 'inbox', title: __( 'No events yet', D ), description: __( 'Failed logins, suspicious requests, plugin/theme changes, fixes and alerts appear here.', D ) } }
				/>
			</Card>
			<FixModal
				fix={ fix }
				onClose={ () => setFix( null ) }
				onDone={ () => {
					loadSummary();
					loadEvents();
				} }
			/>
		</>
	);
}
