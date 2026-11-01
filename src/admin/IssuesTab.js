import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, ErrorNotice, Inline, JobProgress, PageHeader, ResponsiveTable, Select, Tabs, TextField, useToast } from '../core3/ui';
import IssueDrawer from './IssueDrawer';
import { SEVERITY, SEVERITY_ORDER, SOURCE, qs, timeAgo } from './util';

const D = 'dev3-ai-site-health-analyst';
const PER_PAGE = 20;

/**
 * Route params: a numeric segment opens an issue; key:value segments are filters.
 *
 * @param {string[]} params Segments.
 * @return {Object} { id, filters }.
 */
export function parseIssueParams( params = [] ) {
	const out = { id: 0, filters: {} };
	params.forEach( ( seg ) => {
		if ( /^\d+$/.test( seg ) ) {
			out.id = Number( seg );
			return;
		}
		const i = seg.indexOf( ':' );
		if ( i > 0 ) {
			out.filters[ seg.slice( 0, i ) ] = decodeURIComponent( seg.slice( i + 1 ) );
		}
	} );
	return out;
}

/**
 * Issues list with filters, bulk actions and the detail drawer.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.params   Route params.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onCounts Badge update.
 * @param {Function} props.onUsage  After AI calls.
 * @param {Object}   props.settings Settings.
 * @return {Element} Screen.
 */
export default function IssuesTab( { params, navigate, onCounts, onUsage, settings } ) {
	const toast = useToast();
	const parsed = parseIssueParams( params );
	const [ status, setStatus ] = useState( parsed.filters.status || 'open' );
	const [ source, setSource ] = useState( parsed.filters.source || '' );
	const [ severity, setSeverity ] = useState( parsed.filters.severity || '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ sort, setSort ] = useState( { key: 'severity', dir: 'desc' } );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ selected, setSelected ] = useState( [] );
	const [ open, setOpen ] = useState( parsed.id );
	const [ job, setJob ] = useState( null );
	const [ starting, setStarting ] = useState( false );

	useEffect( () => setOpen( parsed.id ), [ parsed.id ] );

	const load = () => {
		setLoading( true );
		setError( null );
		return request( `issues${ qs( { status, source, severity, search: query, page, per_page: PER_PAGE, orderby: sort.key, order: sort.dir } ) }` )
			.then( ( d ) => {
				setData( d );
				onCounts( d.counts );
			} )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( () => {
		load();
	}, [ status, source, severity, query, page, sort.key, sort.dir ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		const t = setTimeout( () => {
			setPage( 1 );
			setQuery( search );
		}, 350 );
		return () => clearTimeout( t );
	}, [ search ] );

	const bulk = ( next ) =>
		request( 'issues/bulk', { method: 'POST', data: { ids: selected, status: next } } )
			.then( ( r ) => {
				/* translators: %d: issues. */
				toast.success( sprintf( _n( '%d issue updated.', '%d issues updated.', r.updated, D ), r.updated ) );
				setSelected( [] );
				load();
			} )
			.catch( ( e ) => toast.error( e ) );

	const explainAll = () => {
		setStarting( true );
		request( 'issues/explain-job', { method: 'POST' } )
			.then( setJob )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setStarting( false ) );
	};

	const items = data ? data.items : [];
	const toggle = ( id ) => setSelected( ( s ) => ( s.includes( id ) ? s.filter( ( x ) => x !== id ) : [ ...s, id ] ) );
	const allSelected = items.length > 0 && items.every( ( i ) => selected.includes( i.id ) );

	const columns = [
		{
			key: 'title',
			label: __( 'Issue', D ),
			primary: true,
			sortable: true,
			width: '46%',
			render: ( i ) => (
				<span className="dev3sh-issue">
					<input
						type="checkbox"
						className="dev3sh-check"
						checked={ selected.includes( i.id ) }
						onClick={ ( e ) => e.stopPropagation() }
						onChange={ () => toggle( i.id ) }
						aria-label={ sprintf( /* translators: %s: issue. */ __( 'Select issue: %s', D ), i.title ) }
					/>
					<span>
						<span className="dev3sh-issue__title">{ i.title }</span>
						<span className="dev3sh-issue__meta">
							{ [ i.component_label, i.file ? `${ i.file }${ i.line ? ':' + i.line : '' }` : '' ].filter( Boolean ).join( ' · ' ) }
							{ i.has_ai && (
								<Badge tone="brand" className="dev3sh-ai-tag">
									{ __( 'AI explained', D ) }
								</Badge>
							) }
							{ i.regressed && <Badge tone="danger">{ __( 'Came back', D ) }</Badge> }
						</span>
					</span>
				</span>
			),
		},
		{
			key: 'severity',
			label: __( 'Severity', D ),
			sortable: true,
			nowrap: true,
			render: ( i ) => (
				<Badge tone={ ( SEVERITY[ i.severity ] || SEVERITY.low ).tone } dot>
					{ ( SEVERITY[ i.severity ] || SEVERITY.low ).label }
				</Badge>
			),
		},
		{ key: 'source', label: __( 'Source', D ), hideOnMobile: true, render: ( i ) => SOURCE[ i.source ] || i.source },
		{ key: 'count', label: __( 'Count', D ), align: 'end', sortable: true, render: ( i ) => i.count.toLocaleString() },
		{ key: 'last_seen', label: __( 'Last seen', D ), sortable: true, nowrap: true, render: ( i ) => timeAgo( i.last_seen ) },
	];

	const counts = data ? data.counts : null;
	const tabs = [
		{ id: 'open', label: __( 'Open', D ), badge: counts ? String( counts.total ) : undefined },
		{ id: 'resolved', label: __( 'Resolved', D ) },
		{ id: 'ignored', label: __( 'Ignored', D ) },
		{ id: 'all', label: __( 'All', D ) },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Issues', D ) }
				description={ __( 'Errors, warnings and findings grouped into issues, with counts and first/last seen. Click an issue for the explanation and fix steps.', D ) }
				actions={
					<AiButton loading={ starting } disabled={ ! settings.api_key_set } onClick={ explainAll }>
						{ __( 'Explain critical & high with DEV3 AI', D ) }
					</AiButton>
				}
			/>
			{ job && (
				<Card>
					<JobProgress
						jobId={ job.id }
						job={ job }
						title={ __( 'DEV3 AI is explaining issues', D ) }
						onDone={ () => {
							load();
							onUsage();
						} }
					/>
				</Card>
			) }
			<div className="dev3sh-tabs-scroll">
				<Tabs
					label={ __( 'Issue status', D ) }
					variant="pills"
					value={ status }
					onChange={ ( v ) => {
						setStatus( v );
						setPage( 1 );
						setSelected( [] );
					} }
					tabs={ tabs }
				/>
			</div>
			<Card className="dev3sh-filters">
				<div className="dev3sh-filters__row">
					<TextField label={ __( 'Search', D ) } value={ search } onChange={ setSearch } placeholder={ __( 'Message, file or plugin', D ) } />
					<Select
						label={ __( 'Severity', D ) }
						value={ severity }
						onChange={ ( v ) => {
							setSeverity( v );
							setPage( 1 );
						} }
						options={ [ { value: '', label: __( 'All severities', D ) }, ...SEVERITY_ORDER.map( ( s ) => ( { value: s, label: `${ SEVERITY[ s ].label }${ counts ? ` (${ counts.severity[ s ] })` : '' }` } ) ) ] }
					/>
					<Select
						label={ __( 'Source', D ) }
						value={ source }
						onChange={ ( v ) => {
							setSource( v );
							setPage( 1 );
						} }
						options={ [ { value: '', label: __( 'All sources', D ) }, ...Object.keys( SOURCE ).map( ( s ) => ( { value: s, label: SOURCE[ s ] } ) ) ] }
					/>
				</div>
			</Card>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ selected.length > 0 && (
				<div className="dev3sh-bulkbar" role="region" aria-label={ __( 'Bulk actions', D ) }>
					<span>{ sprintf( /* translators: %d: count. */ _n( '%d selected', '%d selected', selected.length, D ), selected.length ) }</span>
					<Inline gap={ 8 }>
						<Button size="sm" icon="check" onClick={ () => bulk( 'resolved' ) }>
							{ __( 'Mark resolved', D ) }
						</Button>
						<Button size="sm" variant="ghost" onClick={ () => bulk( 'ignored' ) }>
							{ __( 'Ignore', D ) }
						</Button>
						<Button size="sm" variant="ghost" onClick={ () => setSelected( [] ) }>
							{ __( 'Clear', D ) }
						</Button>
					</Inline>
				</div>
			) }
			<Card
				flush
				title={ data ? sprintf( /* translators: %d: count. */ _n( '%d issue', '%d issues', data.total, D ), data.total ) : __( 'Issues', D ) }
				actions={
					items.length > 0 && (
						<Button size="sm" variant="ghost" onClick={ () => setSelected( allSelected ? [] : items.map( ( i ) => i.id ) ) }>
							{ allSelected ? __( 'Select none', D ) : __( 'Select page', D ) }
						</Button>
					)
				}
			>
				<ResponsiveTable
					columns={ columns }
					rows={ items }
					loading={ loading && ! data }
					caption={ __( 'Issues', D ) }
					sort={ sort }
					onSortChange={ ( s ) => {
						setSort( s );
						setPage( 1 );
					} }
					onRowClick={ ( i ) => navigate( `issues/${ i.id }` ) }
					pagination={ data ? { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } : undefined }
					empty={ {
						icon: 'success',
						title: status === 'open' ? __( 'No open issues', D ) : __( 'Nothing here', D ),
						description: data && data.all === 0 ? __( 'Run a scan from the Overview to collect the first data.', D ) : __( 'Try other filters.', D ),
						action:
							data && data.all === 0 ? (
								<Button variant="primary" href="#/overview">
									{ __( 'Go to Overview', D ) }
								</Button>
							) : undefined,
					} }
				/>
			</Card>
			<IssueDrawer
				id={ open }
				aiReady={ !! settings.api_key_set }
				onUsage={ onUsage }
				onClose={ () => {
					setOpen( 0 );
					navigate( 'issues' );
				} }
				onChange={ load }
			/>
		</>
	);
}
