import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Overview from './Overview';
import IssuesTab from './IssuesTab';
import SecurityTab from './SecurityTab';
import LogTab from './LogTab';
import IntegrityTab from './IntegrityTab';
import SystemTab from './SystemTab';
import ReportsTab from './ReportsTab';
import SettingsTab from './SettingsTab';
import './admin.css';

const D = 'dev3-ai-site-health-analyst';

export default function App() {
	const [ badges, setBadges ] = useState( {} );
	const NAV = [
		{ id: 'overview', label: __( 'Overview', D ), icon: 'dashboard' },
		{ id: 'issues', label: __( 'Issues', D ), icon: 'list', badge: badges.issues },
		{ id: 'security', label: __( 'Security', D ), icon: 'alert' },
		{ id: 'log', label: __( 'Error log', D ), icon: 'file' },
		{ id: 'integrity', label: __( 'Integrity', D ), icon: 'check' },
		{ id: 'system', label: __( 'System', D ), icon: 'clock' },
		{ id: 'reports', label: __( 'Reports & alerts', D ), icon: 'inbox' },
		{ id: 'settings', label: __( 'Settings', D ), icon: 'settings' },
		{ id: 'api', label: __( 'API Config', D ), icon: 'key' },
		{ id: 'usage', label: __( 'Usage Logs', D ), icon: 'chart' },
	];
	const [ route, navigate ] = useHashRoute(
		NAV.map( ( n ) => n.id ),
		'overview'
	);
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );

	const onCounts = ( counts ) => {
		if ( counts && counts.severity ) {
			const urgent = ( counts.severity.critical || 0 ) + ( counts.severity.high || 0 );
			setBadges( { issues: urgent > 0 ? String( urgent ) : undefined } );
		}
	};
	const onUsage = () => setMeter( ( n ) => n + 1 );

	const props = { settings, save, navigate, params: route.params, onCounts, onUsage };
	const screens = {
		overview: () => <Overview { ...props } />,
		issues: () => <IssuesTab { ...props } />,
		security: () => <SecurityTab { ...props } />,
		log: () => <LogTab { ...props } />,
		integrity: () => <IntegrityTab { ...props } />,
		system: () => <SystemTab { ...props } />,
		reports: () => <ReportsTab { ...props } />,
		settings: () => <SettingsTab { ...props } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', D ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget. Everything else works without a key.', D ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', D ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI explanation and summary.', D ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Site Health & Security Log Analyst for WordPress' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ NAV }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } settingsHref="#/api" /> }
			footer={ __( 'Made by DEV3 Technologies · Read-only by default · AI by Google Gemini, using your own API key, with secrets masked.', D ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 6 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
