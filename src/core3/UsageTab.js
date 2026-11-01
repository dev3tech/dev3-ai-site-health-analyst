import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from './api';
import { Card, Columns, Stack, Stat, StatGrid } from './ui/layout';
import { Badge, EmptyState, ErrorNotice, SkeletonCard } from './ui/feedback';
import { Select } from './ui/fields';
import { ResponsiveTable } from './ui/ResponsiveTable';
import { UsageMeter } from './ui/UsageMeter';
import { formatMoney, formatNumber as fmt } from './ui/utils';

const PER_PAGE = 50;

function BreakdownTable( { title, rows, keyName, label, withCost = false } ) {
	const columns = [
		{ key: keyName, label, primary: true, sortable: true, render: ( r ) => r[ keyName ] || '—' },
		{ key: 'requests', label: __( 'Requests', 'dev3-ai-site-health-analyst' ), align: 'end', sortable: true, render: ( r ) => fmt( r.requests ) },
		{ key: 'tokens', label: __( 'Tokens', 'dev3-ai-site-health-analyst' ), align: 'end', sortable: true, render: ( r ) => fmt( r.tokens ) },
	];
	if ( withCost ) {
		columns.push( { key: 'cost', label: __( 'Est. cost', 'dev3-ai-site-health-analyst' ), align: 'end', sortable: true, render: ( r ) => formatMoney( r.cost ) } );
	}
	return (
		<Card title={ title } flush>
			<ResponsiveTable columns={ columns } rows={ rows } rowKey={ keyName } caption={ title } empty={ { title: __( 'No requests yet', 'dev3-ai-site-health-analyst' ) } } />
		</Card>
	);
}

/**
 * Usage Logs tab: monthly budget, KPIs, requests per day, breakdowns and recent requests.
 *
 * @param {Object} props          Props.
 * @param {*}      props.children Extra content under the KPIs.
 * @return {Element} Tab.
 */
export default function UsageTab( { children } ) {
	const [ days, setDays ] = useState( '30' );
	const [ summary, setSummary ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ recent, setRecent ] = useState( { items: [], total: 0 } );
	const [ recentLoading, setRecentLoading ] = useState( true );
	const [ page, setPage ] = useState( 1 );

	useEffect( () => {
		setSummary( null );
		setError( null );
		request( `usage?days=${ days }` )
			.then( setSummary )
			.catch( ( err ) => {
				setError( err );
				setSummary( false );
			} );
	}, [ days ] );
	useEffect( () => {
		setRecentLoading( true );
		request( `usage/recent?page=${ page }` )
			.then( setRecent )
			.catch( () => {} )
			.finally( () => setRecentLoading( false ) );
	}, [ page ] );

	const t = summary ? summary.totals : {};
	const max = summary ? Math.max( 1, ...summary.by_day.map( ( d ) => d.requests ) ) : 1;

	return (
		<Stack gap={ 16 }>
			<div className="dev3sh-ui-inline is-between" style={ { gap: 12 } }>
				<p className="dev3sh-ui-pagehead__desc" style={ { margin: 0, flex: '1 1 320px' } }>
					{ __( 'Token counts come from the AI provider’s usage metadata; costs are estimates. Check exact billing in Google AI Studio / Cloud Console.', 'dev3-ai-site-health-analyst' ) }
				</p>
				<div style={ { width: 180 } }>
					<Select
						hideLabel
						label={ __( 'Period', 'dev3-ai-site-health-analyst' ) }
						value={ days }
						onChange={ setDays }
						options={ [
							{ value: '7', label: __( 'Last 7 days', 'dev3-ai-site-health-analyst' ) },
							{ value: '30', label: __( 'Last 30 days', 'dev3-ai-site-health-analyst' ) },
							{ value: '90', label: __( 'Last 90 days', 'dev3-ai-site-health-analyst' ) },
						] }
					/>
				</div>
			</div>

			<ErrorNotice error={ error } />

			<Card>
				<UsageMeter status={ summary && summary.month ? { enabled: true, month: summary.month } : undefined } />
			</Card>

			<StatGrid min={ 150 }>
				<Stat label={ __( 'Requests', 'dev3-ai-site-health-analyst' ) } icon="bolt" tone="brand" value={ fmt( t.requests ) } loading={ ! summary } />
				<Stat label={ __( 'Total tokens', 'dev3-ai-site-health-analyst' ) } icon="chart" value={ fmt( t.total_tokens ) } loading={ ! summary } hint={ summary ? sprintf( /* translators: 1: input, 2: output */ __( '%1$s in · %2$s out', 'dev3-ai-site-health-analyst' ), fmt( t.prompt_tokens ), fmt( t.output_tokens ) ) : null } />
				<Stat label={ __( 'Est. cost', 'dev3-ai-site-health-analyst' ) } icon="chart" tone="success" value={ formatMoney( summary ? summary.cost : 0 ) } loading={ ! summary } />
				<Stat label={ __( 'Avg. response', 'dev3-ai-site-health-analyst' ) } icon="clock" value={ `${ ( ( t.avg_ms || 0 ) / 1000 ).toFixed( 1 ) }s` } loading={ ! summary } />
				<Stat label={ __( 'Errors', 'dev3-ai-site-health-analyst' ) } icon="alert" tone={ t.errors ? 'danger' : 'neutral' } value={ fmt( t.errors ) } loading={ ! summary } />
			</StatGrid>

			{ children }

			{ summary === null && <SkeletonCard lines={ 4 } /> }
			{ summary && (
				<Card title={ __( 'Requests per day', 'dev3-ai-site-health-analyst' ) }>
					{ summary.by_day.length ? (
						<>
							<div className="dev3sh-ui-bars" role="img" aria-label={ __( 'Requests per day chart', 'dev3-ai-site-health-analyst' ) }>
								{ summary.by_day.map( ( d ) => (
									<div key={ d.day } style={ { height: `${ ( d.requests / max ) * 100 }%` } } title={ sprintf( /* translators: 1: date, 2: requests, 3: tokens */ __( '%1$s — %2$s requests, %3$s tokens', 'dev3-ai-site-health-analyst' ), d.day, fmt( d.requests ), fmt( d.tokens ) ) } />
								) ) }
							</div>
							<div className="dev3sh-ui-bars__axis" aria-hidden="true">
								<span>{ summary.by_day[ 0 ].day }</span>
								<span>{ summary.by_day[ summary.by_day.length - 1 ].day }</span>
							</div>
						</>
					) : (
						<EmptyState compact icon="chart" title={ __( 'No requests in this period yet', 'dev3-ai-site-health-analyst' ) } description={ __( 'Usage appears here as soon as an AI feature runs.', 'dev3-ai-site-health-analyst' ) } />
					) }
				</Card>
			) }

			{ summary && (
				<Columns min={ 400 }>
					<BreakdownTable title={ __( 'By task', 'dev3-ai-site-health-analyst' ) } rows={ summary.by_task } keyName="task" label={ __( 'Task', 'dev3-ai-site-health-analyst' ) } />
					<BreakdownTable title={ __( 'By model', 'dev3-ai-site-health-analyst' ) } rows={ summary.by_model } keyName="model" label={ __( 'Model', 'dev3-ai-site-health-analyst' ) } withCost />
					<BreakdownTable title={ __( 'By user', 'dev3-ai-site-health-analyst' ) } rows={ summary.by_user } keyName="name" label={ __( 'User', 'dev3-ai-site-health-analyst' ) } />
				</Columns>
			) }

			<Card title={ __( 'Recent requests', 'dev3-ai-site-health-analyst' ) } flush>
				<ResponsiveTable
					caption={ __( 'Recent requests', 'dev3-ai-site-health-analyst' ) }
					loading={ recentLoading && ! recent.items.length }
					rows={ recent.items }
					empty={ { icon: 'list', title: __( 'No requests logged yet', 'dev3-ai-site-health-analyst' ) } }
					pagination={ { page, perPage: PER_PAGE, total: recent.total, onPageChange: setPage } }
					columns={ [
						{ key: 'task', label: __( 'Task', 'dev3-ai-site-health-analyst' ), primary: true },
						{ key: 'created_at', label: __( 'Time', 'dev3-ai-site-health-analyst' ), nowrap: true, render: ( r ) => new Date( r.created_at ).toLocaleString() },
						{ key: 'user', label: __( 'User', 'dev3-ai-site-health-analyst' ) },
						{ key: 'context', label: __( 'Where', 'dev3-ai-site-health-analyst' ), hideOnMobile: true },
						{ key: 'model', label: __( 'Model', 'dev3-ai-site-health-analyst' ) },
						{ key: 'total_tokens', label: __( 'Tokens', 'dev3-ai-site-health-analyst' ), align: 'end', render: ( r ) => fmt( r.total_tokens ) },
						{ key: 'duration_ms', label: __( 'Time (s)', 'dev3-ai-site-health-analyst' ), align: 'end', hideOnMobile: true, render: ( r ) => ( r.duration_ms / 1000 ).toFixed( 1 ) },
						{
							key: 'status',
							label: __( 'Status', 'dev3-ai-site-health-analyst' ),
							render: ( r ) =>
								r.status === 'ok' ? (
									<Badge tone="success">OK</Badge>
								) : (
									<Badge tone="danger" title={ r.error_code }>
										{ r.error_code || r.status }
									</Badge>
								),
						},
					] }
				/>
			</Card>
		</Stack>
	);
}
