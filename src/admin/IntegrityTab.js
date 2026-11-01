import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, EmptyState, ErrorNotice, JobProgress, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid, useToast } from '../core3/ui';
import FixModal from './FixModal';
import { dateTime, timeAgo } from './util';

const D = 'dev3-ai-site-health-analyst';

const PLUGIN_STATUS = {
	ok: { label: __( 'Matches wordpress.org', D ), tone: 'success' },
	modified: { label: __( 'Files differ', D ), tone: 'danger' },
	unverifiable: { label: __( 'Not verifiable', D ), tone: 'neutral' },
	error: { label: __( 'Check failed', D ), tone: 'warning' },
};

/**
 * File list with an optional restore action.
 *
 * @param {Object}   props           Props.
 * @param {string}   props.title     Title.
 * @param {Array}    props.files     Files.
 * @param {Function} props.onRestore Restore callback (core only).
 * @return {Element|null} List.
 */
function FileList( { title, files, onRestore } ) {
	if ( ! files || ! files.length ) {
		return null;
	}
	return (
		<div className="dev3sh-filegroup">
			<p className="dev3sh-label">
				{ title } ({ files.length })
			</p>
			<ul className="dev3sh-filelist">
				{ files.slice( 0, 50 ).map( ( f ) => (
					<li key={ f }>
						<code className="dev3sh-code-inline">{ f }</code>
						{ onRestore && (
							<Button size="sm" variant="ghost" onClick={ () => onRestore( f ) }>
								{ __( 'Restore…', D ) }
							</Button>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Integrity screen.
 *
 * @return {Element} Screen.
 */
export default function IntegrityTab() {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const [ fix, setFix ] = useState( null );
	const [ expanded, setExpanded ] = useState( null );

	const load = () =>
		request( 'integrity' )
			.then( ( d ) => {
				setData( d );
				setJob( d.job );
			} )
			.catch( setError );
	useEffect( () => {
		load();
	}, [] );

	const run = () => {
		setStarting( true );
		request( 'integrity/run', { method: 'POST' } )
			.then( setJob )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setStarting( false ) );
	};

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return (
			<>
				<PageHeader title={ __( 'File integrity', D ) } />
				<SkeletonCard lines={ 5 } />
			</>
		);
	}
	const res = data.result;
	const core = res && res.core;
	const running = job && ( job.status === 'queued' || job.status === 'running' );
	const restore = ( file ) =>
		setFix( {
			type: 'restore_core_file',
			target: file,
			/* translators: %s: file. */
			label: sprintf( __( 'Restore %s', D ), file ),
		} );

	const columns = [
		{
			key: 'name',
			label: __( 'Plugin', D ),
			primary: true,
			render: ( p ) => (
				<span>
					<span className="dev3sh-issue__title">{ p.name }</span>
					<span className="dev3sh-issue__meta">
						{ p.slug } { p.version }
					</span>
				</span>
			),
		},
		{
			key: 'status',
			label: __( 'Result', D ),
			render: ( p ) => {
				const s = PLUGIN_STATUS[ p.status ] || PLUGIN_STATUS.error;
				return (
					<Badge tone={ s.tone } dot>
						{ s.label }
					</Badge>
				);
			},
		},
		{ key: 'changes', label: __( 'Files', D ), align: 'end', render: ( p ) => String( p.modified.length + p.missing.length + p.added.length ) },
	];

	return (
		<>
			<PageHeader
				title={ __( 'File integrity', D ) }
				description={ __( 'Compares WordPress core and wordpress.org plugins with the official checksums. Premium and custom plugins cannot be verified and are never reported as changed.', D ) }
				actions={
					<Button variant="primary" icon="refresh" loading={ starting } disabled={ running } onClick={ run }>
						{ __( 'Run check now', D ) }
					</Button>
				}
			/>
			{ running && (
				<Card>
					<JobProgress
						jobId={ job.id }
						job={ job }
						title={ __( 'Comparing files with wordpress.org', D ) }
						onDone={ () => {
							setJob( null );
							load();
						} }
					/>
				</Card>
			) }
			{ ! res && ! running && (
				<Card>
					<EmptyState
						icon="check"
						title={ __( 'No check has run yet', D ) }
						description={ sprintf( /* translators: %s: schedule. */ __( 'Scheduled checks: %s. Run the first one now; it takes a minute in the background.', D ), data.schedule ) }
						action={
							<Button variant="primary" onClick={ run } loading={ starting }>
								{ __( 'Run check now', D ) }
							</Button>
						}
					/>
				</Card>
			) }
			{ res && (
				<>
					<StatGrid>
						<Stat label={ __( 'Core files changed', D ) } value={ String( res.summary.core_modified + res.summary.core_missing ) } icon="file" tone={ res.summary.core_modified + res.summary.core_missing ? 'danger' : 'success' } hint={ core ? sprintf( /* translators: 1: files, 2: version. */ __( '%1$d files of WordPress %2$s checked', D ), core.checked, core.version ) : '' } />
						<Stat label={ __( 'Unknown core PHP files', D ) } value={ String( res.summary.core_unknown ) } icon="alert" tone={ res.summary.core_unknown ? 'danger' : 'success' } hint={ __( 'Inside wp-admin and wp-includes', D ) } />
						<Stat label={ __( 'Plugins verified', D ) } value={ String( res.summary.plugins_ok ) } icon="check" tone="success" hint={ sprintf( /* translators: %d: count. */ __( '%d not verifiable', D ), res.summary.plugins_unknown ) } />
						<Stat label={ __( 'Plugins with changes', D ) } value={ String( res.summary.plugins_changed ) } icon="box" tone={ res.summary.plugins_changed ? 'warning' : 'success' } hint={ sprintf( /* translators: %s: time. */ __( 'Checked %s', D ), timeAgo( new Date( res.at * 1000 ).toISOString() ) ) } />
					</StatGrid>
					<Card title={ __( 'WordPress core', D ) } description={ sprintf( /* translators: %s: date. */ __( 'Last check: %s', D ), dateTime( new Date( res.at * 1000 ).toISOString() ) ) }>
						{ core && core.status === 'error' && <Notice tone="warning">{ core.message }</Notice> }
						{ core && core.status === 'ok' && (
							<Notice tone="success" title={ __( 'All core files match wordpress.org', D ) }>
								{ __( 'This only proves core files are unchanged. Keep plugins and themes updated too.', D ) }
							</Notice>
						) }
						{ core && core.status === 'modified' && (
							<>
								<FileList title={ __( 'Changed files', D ) } files={ core.modified } onRestore={ restore } />
								<FileList title={ __( 'Missing files', D ) } files={ core.missing } onRestore={ restore } />
								<FileList title={ __( 'Unknown PHP files', D ) } files={ core.unknown } />
							</>
						) }
					</Card>
					<Card flush title={ __( 'Plugins', D ) }>
						<ResponsiveTable
							columns={ columns }
							rows={ res.plugins }
							rowKey="file"
							caption={ __( 'Plugin integrity', D ) }
							onRowClick={ ( p ) => setExpanded( expanded === p.file ? null : p.file ) }
							empty={ { icon: 'box', title: __( 'No plugins checked', D ) } }
						/>
					</Card>
					{ expanded &&
						res.plugins
							.filter( ( p ) => p.file === expanded )
							.map( ( p ) => (
								<Card key={ p.file } title={ p.name } description={ p.message }>
									{ p.status === 'ok' && <p>{ __( 'Every file matches the official release.', D ) }</p> }
									<FileList title={ __( 'Changed files', D ) } files={ p.modified } />
									<FileList title={ __( 'Missing files', D ) } files={ p.missing } />
									<FileList title={ __( 'Extra PHP files', D ) } files={ p.added } />
								</Card>
							) ) }
				</>
			) }
			<FixModal fix={ fix } onClose={ () => setFix( null ) } onDone={ run } />
		</>
	);
}
