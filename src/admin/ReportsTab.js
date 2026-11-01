import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, ErrorNotice, FormGrid, NumberField, PageHeader, ResponsiveTable, Select, Skeleton, Stack, TextField, Toggle, useToast } from '../core3/ui';
import { dateTime, timeAgo } from './util';

const D = 'dev3-ai-site-health-analyst';

const KEYS = [ 'alerts_enabled', 'alert_emails', 'alert_fatal', 'alert_critical', 'alert_integrity', 'alert_failed_logins', 'alert_suspicious', 'alert_score_below', 'alert_cooldown_hours', 'weekly_report', 'report_emails', 'report_day', 'report_hour', 'report_ai_summary' ];

const FIX_LABEL = {
	deactivate_plugin: __( 'Deactivate plugin', D ),
	restore_core_file: __( 'Restore core file', D ),
	block_ip: __( 'Block IP', D ),
	unblock_ip: __( 'Unblock IP', D ),
	unschedule_cron: __( 'Remove scheduled task', D ),
	disable_xmlrpc: __( 'Disable XML-RPC', D ),
};

/**
 * Reports & alerts: thresholds, weekly email (preview + send) and the fix history with undo.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save settings.
 * @param {Function} props.onUsage  After AI calls.
 * @return {Element} Screen.
 */
export default function ReportsTab( { settings, save, onUsage } ) {
	const toast = useToast();
	const pick = ( s ) => KEYS.reduce( ( o, k ) => ( { ...o, [ k ]: s[ k ] } ), {} );
	const [ form, setForm ] = useState( pick( settings ) );
	const [ saving, setSaving ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const [ previewError, setPreviewError ] = useState( null );
	const [ sending, setSending ] = useState( false );
	const [ withAi, setWithAi ] = useState( false );
	const [ fixes, setFixes ] = useState( null );

	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const dirty = JSON.stringify( form ) !== JSON.stringify( pick( settings ) );

	const loadPreview = ( ai = withAi ) => {
		setPreviewError( null );
		return request( `report/preview${ ai ? '?ai=1' : '' }` )
			.then( ( p ) => {
				setPreview( p );
				if ( ai ) {
					onUsage();
				}
			} )
			.catch( setPreviewError );
	};
	const loadFixes = () => request( 'fixes' ).then( setFixes ).catch( () => setFixes( { items: [], total: 0 } ) );
	useEffect( () => {
		loadPreview( false );
		loadFixes();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const submit = ( e ) => {
		e.preventDefault();
		setSaving( true );
		save( form )
			.then( ( next ) => {
				setForm( pick( next ) );
				toast.success( __( 'Alert and report settings saved.', D ) );
				loadPreview( false );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSaving( false ) );
	};

	const send = () => {
		setSending( true );
		request( 'report/send', { method: 'POST' } )
			.then( ( r ) => {
				/* translators: %s: recipients. */
				toast.success( sprintf( __( 'Report sent to %s.', D ), r.to.join( ', ' ) ) );
				loadPreview( false );
				onUsage();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSending( false ) );
	};

	const undo = ( id ) =>
		request( `fixes/${ id }/undo`, { method: 'POST' } )
			.then( () => {
				toast.success( __( 'Fix undone.', D ) );
				loadFixes();
			} )
			.catch( ( err ) => toast.error( err ) );

	const dayOptions = [
		{ value: 'monday', label: __( 'Monday', D ) },
		{ value: 'tuesday', label: __( 'Tuesday', D ) },
		{ value: 'wednesday', label: __( 'Wednesday', D ) },
		{ value: 'thursday', label: __( 'Thursday', D ) },
		{ value: 'friday', label: __( 'Friday', D ) },
		{ value: 'saturday', label: __( 'Saturday', D ) },
		{ value: 'sunday', label: __( 'Sunday', D ) },
	];
	const hourOptions = Array.from( { length: 24 } ).map( ( _, h ) => ( { value: String( h ), label: `${ String( h ).padStart( 2, '0' ) }:00` } ) );

	return (
		<>
			<PageHeader title={ __( 'Reports & alerts', D ) } description={ __( 'Get an email when something important happens, plus a weekly summary of your site\'s health.', D ) } />
			<form onSubmit={ submit }>
				<Columns min={ 340 }>
					<Card
						title={ __( 'Alerts', D ) }
						description={ __( 'Each alert type is sent at most once per cool-down period.', D ) }
						footer={
							<Button type="submit" variant="primary" loading={ saving } disabled={ ! dirty }>
								{ __( 'Save', D ) }
							</Button>
						}
					>
						<Stack gap={ 16 }>
							<Toggle label={ __( 'Send email alerts', D ) } checked={ !! form.alerts_enabled } onChange={ set( 'alerts_enabled' ) } />
							<TextField label={ __( 'Alert recipients', D ) } value={ form.alert_emails } onChange={ set( 'alert_emails' ) } placeholder={ preview ? preview.alerts_to.join( ', ' ) : '' } help={ __( 'Comma-separated. Empty = the site admin email.', D ) } />
							<Toggle label={ __( 'New fatal PHP error', D ) } checked={ !! form.alert_fatal } onChange={ set( 'alert_fatal' ) } />
							<Toggle label={ __( 'New critical issue', D ) } checked={ !! form.alert_critical } onChange={ set( 'alert_critical' ) } />
							<Toggle label={ __( 'Core or plugin files changed', D ) } checked={ !! form.alert_integrity } onChange={ set( 'alert_integrity' ) } />
							<FormGrid min={ 160 }>
								<NumberField label={ __( 'Failed logins per hour', D ) } value={ form.alert_failed_logins } onChange={ set( 'alert_failed_logins' ) } min={ 0 } help={ __( '0 = off', D ) } />
								<NumberField label={ __( 'Suspicious requests per hour', D ) } value={ form.alert_suspicious } onChange={ set( 'alert_suspicious' ) } min={ 0 } help={ __( '0 = off', D ) } />
								<NumberField label={ __( 'Score below', D ) } value={ form.alert_score_below } onChange={ set( 'alert_score_below' ) } min={ 0 } max={ 100 } help={ __( '0 = off', D ) } />
								<NumberField label={ __( 'Cool-down (hours)', D ) } value={ form.alert_cooldown_hours } onChange={ set( 'alert_cooldown_hours' ) } min={ 1 } max={ 168 } />
							</FormGrid>
						</Stack>
					</Card>
					<Card
						title={ __( 'Weekly report', D ) }
						description={ preview && preview.next ? sprintf( /* translators: %s: date. */ __( 'Next report: %s', D ), dateTime( preview.next ) ) : __( 'A summary email once a week.', D ) }
						footer={
							<>
								<Button variant="secondary" loading={ sending } onClick={ send } icon="inbox">
									{ __( 'Send now', D ) }
								</Button>
								<Button type="submit" variant="primary" loading={ saving } disabled={ ! dirty }>
									{ __( 'Save', D ) }
								</Button>
							</>
						}
					>
						<Stack gap={ 16 }>
							<Toggle label={ __( 'Send the weekly report', D ) } checked={ !! form.weekly_report } onChange={ set( 'weekly_report' ) } />
							<TextField label={ __( 'Report recipients', D ) } value={ form.report_emails } onChange={ set( 'report_emails' ) } help={ __( 'Comma-separated. Empty = the alert recipients.', D ) } />
							<FormGrid min={ 140 }>
								<Select label={ __( 'Day', D ) } value={ form.report_day } onChange={ set( 'report_day' ) } options={ dayOptions } />
								<Select label={ __( 'Time (site time)', D ) } value={ String( form.report_hour ) } onChange={ ( v ) => set( 'report_hour' )( Number( v ) ) } options={ hourOptions } />
							</FormGrid>
							<Toggle label={ __( 'Add a DEV3 AI summary paragraph', D ) } help={ __( 'Uses one short AI request per week with masked figures only. Skipped without an API key.', D ) } checked={ !! form.report_ai_summary } onChange={ set( 'report_ai_summary' ) } />
							{ preview && preview.last && (
								<p className="dev3sh-muted">
									{ sprintf( /* translators: 1: time, 2: status. */ __( 'Last report %1$s (%2$s).', D ), timeAgo( preview.last.at_iso ), preview.last.sent ? __( 'sent', D ) : __( 'not sent', D ) ) }
								</p>
							) }
						</Stack>
					</Card>
				</Columns>
			</form>

			<Card
				title={ __( 'Report preview', D ) }
				description={ __( 'Exactly what recipients receive (figures from the last 7 days).', D ) }
				actions={
					<Toggle
						label={ __( 'With AI summary', D ) }
						checked={ withAi }
						onChange={ ( v ) => {
							setWithAi( v );
							setPreview( null );
							loadPreview( v );
						} }
						disabled={ ! settings.api_key_set }
					/>
				}
			>
				<ErrorNotice error={ previewError } onRetry={ () => loadPreview() } />
				{ ! preview && ! previewError && <Skeleton lines={ 8 } /> }
				{ preview && <iframe className="dev3sh-preview" title={ __( 'Weekly report preview', D ) } srcDoc={ preview.html } sandbox="" /> }
			</Card>

			<Card flush title={ __( 'Fix history', D ) } description={ __( 'Every one-click fix you confirmed, with undo.', D ) }>
				<ResponsiveTable
					columns={ [
						{ key: 'message', label: __( 'Fix', D ), primary: true, render: ( e ) => <span><span className="dev3sh-issue__title">{ e.message }</span><span className="dev3sh-issue__meta">{ FIX_LABEL[ e.rule ] || e.rule }</span></span> },
						{ key: 'user_login', label: __( 'By', D ) },
						{ key: 'created_at', label: __( 'When', D ), nowrap: true, render: ( e ) => timeAgo( e.created_at ) },
						{ key: 'state', label: __( 'State', D ), render: ( e ) => ( e.meta.undone ? <Badge tone="neutral">{ e.rule === 'unblock_ip' ? __( 'Done', D ) : __( 'Undone', D ) }</Badge> : <Badge tone="success">{ __( 'Applied', D ) }</Badge> ) },
					] }
					rows={ fixes ? fixes.items : [] }
					loading={ ! fixes }
					caption={ __( 'Fix history', D ) }
					rowActions={ ( e ) =>
						! e.meta.undone ? (
							<Button size="sm" variant="ghost" onClick={ () => undo( e.id ) }>
								{ __( 'Undo', D ) }
							</Button>
						) : null
					}
					empty={ { icon: 'info', title: __( 'No fixes applied', D ), description: fixes && ! fixes.enabled ? __( 'Read-only mode is on: nothing on your site has been changed by this plugin.', D ) : __( 'Fixes you apply from an issue appear here.', D ) } }
				/>
			</Card>
		</>
	);
}
