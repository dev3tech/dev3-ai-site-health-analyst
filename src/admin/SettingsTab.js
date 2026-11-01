import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, Checkbox, Columns, FormGrid, Modal, Notice, NumberField, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';

const D = 'dev3-ai-site-health-analyst';

const KEYS = [ 'collectors', 'log_path', 'log_max_kb', 'log_notices', 'log_deprecated', 'site_health_async', 'security_logger', 'anonymize_ip', 'not_found_threshold', 'brute_force_threshold', 'log_admin_logins', 'retention_days', 'slow_queries', 'slow_query_ms', 'integrity_schedule', 'stale_days', 'allow_fixes', 'block_days', 'dashboard_widget', 'delete_on_uninstall' ];

const COLLECTORS = [
	[ 'php', __( 'PHP error log', D ) ],
	[ 'site_health', __( 'Site Health tests', D ) ],
	[ 'security', __( 'Security log', D ) ],
	[ 'changes', __( 'Plugin & theme changes', D ) ],
	[ 'updates', __( 'Update status', D ) ],
	[ 'cron', __( 'Cron health', D ) ],
	[ 'query', __( 'Slow queries', D ) ],
	[ 'integrity', __( 'File integrity', D ) ],
];

/**
 * Settings: collectors, log, security logger, retention and the read-only switch.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Screen.
 */
export default function SettingsTab( { settings, save } ) {
	const toast = useToast();
	const pick = ( s ) => KEYS.reduce( ( o, k ) => ( { ...o, [ k ]: s[ k ] } ), {} );
	const [ form, setForm ] = useState( pick( settings ) );
	const [ saving, setSaving ] = useState( false );
	const [ confirmFixes, setConfirmFixes ] = useState( false );
	const [ understood, setUnderstood ] = useState( false );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const setCollector = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, collectors: { ...f.collectors, [ k ]: v } } ) );
	const dirty = JSON.stringify( form ) !== JSON.stringify( pick( settings ) );

	const submit = ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setSaving( true );
		save( form )
			.then( ( next ) => {
				setForm( pick( next ) );
				toast.success( __( 'Settings saved.', D ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSaving( false ) );
	};

	const footer = (
		<Button type="submit" variant="primary" loading={ saving } disabled={ ! dirty }>
			{ __( 'Save settings', D ) }
		</Button>
	);

	return (
		<>
			<PageHeader title={ __( 'Settings', D ) } description={ __( 'Choose what is collected, how long it is kept, and whether one-click fixes are allowed.', D ) } />
			<form onSubmit={ submit }>
				<Stack gap={ 16 }>
					<Card title={ __( 'Read-only mode', D ) } description={ __( 'By default this plugin only reads and reports. It never changes your site on its own, and AI suggestions are never applied automatically.', D ) } footer={ footer }>
						<Stack gap={ 16 }>
							<Toggle
								label={ __( 'Allow one-click fixes (with confirmation)', D ) }
								help={ __( 'Lets you deactivate a broken plugin, restore a changed core file, block an IP, remove an orphan cron task or disable XML-RPC. Each fix shows a preview, needs a confirmation and can be undone.', D ) }
								checked={ !! form.allow_fixes }
								onChange={ ( v ) => {
									if ( v ) {
										setUnderstood( false );
										setConfirmFixes( true );
									} else {
										set( 'allow_fixes' )( false );
									}
								} }
							/>
							{ form.allow_fixes ? (
								<Notice tone="warning">{ __( 'Fixes are allowed. They still need your confirmation one by one.', D ) }</Notice>
							) : (
								<Notice tone="success">{ __( 'Read-only: nothing on the site can be changed from this plugin.', D ) }</Notice>
							) }
							<NumberField label={ __( 'Block IP addresses for (days)', D ) } value={ form.block_days } onChange={ set( 'block_days' ) } min={ 1 } max={ 365 } />
						</Stack>
					</Card>

					<Card title={ __( 'Collectors', D ) } description={ __( 'Sources that feed the issue list. Checks run hourly in the background.', D ) } footer={ footer }>
						<div className="dev3sh-toggles">
							{ COLLECTORS.map( ( [ k, label ] ) => (
								<Toggle key={ k } label={ label } checked={ ! form.collectors || form.collectors[ k ] !== false } onChange={ setCollector( k ) } />
							) ) }
						</div>
					</Card>

					<Columns min={ 340 }>
						<Card title={ __( 'PHP error log', D ) } footer={ footer }>
							<Stack gap={ 16 }>
								<TextField label={ __( 'Custom log path', D ) } value={ form.log_path } onChange={ set( 'log_path' ) } placeholder="/home/site/logs/php-error.log" help={ __( 'Leave empty to use WP_DEBUG_LOG or php.ini error_log. Must end in .log or .txt.', D ) } />
								<NumberField label={ __( 'Read at most (KB per run)', D ) } value={ form.log_max_kb } onChange={ set( 'log_max_kb' ) } min={ 64 } max={ 20480 } />
								<Toggle label={ __( 'Collect notices', D ) } checked={ !! form.log_notices } onChange={ set( 'log_notices' ) } />
								<Toggle label={ __( 'Collect deprecation messages', D ) } checked={ !! form.log_deprecated } onChange={ set( 'log_deprecated' ) } />
								<NumberField label={ __( 'Auto-resolve PHP issues not seen for (days)', D ) } value={ form.stale_days } onChange={ set( 'stale_days' ) } min={ 0 } max={ 365 } help={ __( '0 = never', D ) } />
							</Stack>
						</Card>
						<Card title={ __( 'Security logger', D ) } footer={ footer }>
							<Stack gap={ 16 }>
								<Toggle label={ __( 'Log failed logins and suspicious requests', D ) } checked={ !! form.security_logger } onChange={ set( 'security_logger' ) } />
								<Toggle label={ __( 'Log administrator logins', D ) } checked={ !! form.log_admin_logins } onChange={ set( 'log_admin_logins' ) } />
								<Toggle label={ __( 'Anonymise IP addresses', D ) } help={ __( 'Stores 192.0.2.0 instead of 192.0.2.17. Blocking single addresses is then less precise.', D ) } checked={ !! form.anonymize_ip } onChange={ set( 'anonymize_ip' ) } />
								<FormGrid min={ 150 }>
									<NumberField label={ __( 'Brute-force threshold (fails/hour/IP)', D ) } value={ form.brute_force_threshold } onChange={ set( 'brute_force_threshold' ) } min={ 3 } max={ 500 } />
									<NumberField label={ __( '404 flood threshold (per 5 min)', D ) } value={ form.not_found_threshold } onChange={ set( 'not_found_threshold' ) } min={ 5 } max={ 500 } />
									<NumberField label={ __( 'Keep events for (days)', D ) } value={ form.retention_days } onChange={ set( 'retention_days' ) } min={ 1 } max={ 365 } />
								</FormGrid>
							</Stack>
						</Card>
					</Columns>

					<Columns min={ 340 }>
						<Card title={ __( 'Checks', D ) } footer={ footer }>
							<Stack gap={ 16 }>
								<Select
									label={ __( 'File integrity check', D ) }
									value={ form.integrity_schedule }
									onChange={ set( 'integrity_schedule' ) }
									options={ [
										{ value: 'daily', label: __( 'Daily', D ) },
										{ value: 'weekly', label: __( 'Weekly', D ) },
										{ value: 'off', label: __( 'Only when I run it', D ) },
									] }
								/>
								<Toggle label={ __( 'Include slow Site Health tests (loopback, wordpress.org, HTTPS)', D ) } checked={ !! form.site_health_async } onChange={ set( 'site_health_async' ) } />
								<Toggle label={ __( 'Capture slow queries when SAVEQUERIES is on', D ) } checked={ !! form.slow_queries } onChange={ set( 'slow_queries' ) } />
								<NumberField label={ __( 'Slow query threshold (ms)', D ) } value={ form.slow_query_ms } onChange={ set( 'slow_query_ms' ) } min={ 10 } max={ 60000 } />
							</Stack>
						</Card>
						<Card title={ __( 'Other', D ) } footer={ footer }>
							<Stack gap={ 16 }>
								<Toggle label={ __( 'Show the widget on the WordPress dashboard', D ) } checked={ !! form.dashboard_widget } onChange={ set( 'dashboard_widget' ) } />
								<Toggle label={ __( 'Delete all data when the plugin is uninstalled', D ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
							</Stack>
						</Card>
					</Columns>
				</Stack>
			</form>

			<Modal
				open={ confirmFixes }
				onClose={ () => setConfirmFixes( false ) }
				size="sm"
				title={ __( 'Allow one-click fixes?', D ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirmFixes( false ) }>
							{ __( 'Keep read-only', D ) }
						</Button>
						<Button
							variant="primary"
							disabled={ ! understood }
							onClick={ () => {
								set( 'allow_fixes' )( true );
								setConfirmFixes( false );
							} }
						>
							{ __( 'Allow fixes', D ) }
						</Button>
					</>
				}
			>
				<p>{ __( 'Fixes change your live site (for example, deactivating a plugin turns off its features). Make sure you have a recent backup. Each fix still needs its own confirmation and can be undone.', D ) }</p>
				<Checkbox label={ __( 'I have a backup and understand the risk.', D ) } checked={ understood } onChange={ setUnderstood } />
				<p className="dev3sh-muted">{ __( 'Remember to click "Save settings" afterwards.', D ) }</p>
			</Modal>
		</>
	);
}
