import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import FixModal from '../../src/admin/FixModal';
import IssueDrawer from '../../src/admin/IssueDrawer';
import Overview, { ScoreRing } from '../../src/admin/Overview';
import { parseIssueParams } from '../../src/admin/IssuesTab';
import SettingsTab from '../../src/admin/SettingsTab';
import { Bars } from '../../src/admin/SecurityTab';
import { bytes, collectorStatus, qs, scoreTone, timeAgo } from '../../src/admin/util';
import { change, click, render } from './render';

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

const ISSUE = {
	id: 7,
	source: 'php',
	kind: 'fatal',
	severity: 'critical',
	title: 'Fatal error: Uncaught Error: Call to undefined function acme_fn()',
	message: 'Uncaught Error: Call to undefined function acme_fn()',
	file: 'wp-content/plugins/acme/acme.php',
	line: 12,
	component: 'plugin:acme',
	component_label: 'Plugin: Acme',
	count: 3,
	first_seen: '2026-10-20T10:00:00+00:00',
	last_seen: '2026-10-25T10:00:00+00:00',
	status: 'open',
	regressed: false,
	context: { samples: [ '[25-Oct-2026] PHP Fatal error ...' ] },
	ai: null,
	builtin: { summary: 'Code tried to use a function that does not exist.', cause: 'Missing dependency.', steps: [ 'Re-install Acme.' ], who: 'owner', urgency: 'now', fix_action: 'deactivate_plugin', fix_target: 'acme', source: 'built-in' },
	fixes: [ { type: 'deactivate_plugin', target: 'acme', label: 'Deactivate Acme' } ],
	fixes_enabled: false,
};

describe( 'helpers', () => {
	it( 'builds query strings without empty values', () => {
		expect( qs( { a: 1, b: '', c: 'x y' } ) ).toBe( '?a=1&c=x%20y' );
		expect( qs( {} ) ).toBe( '' );
	} );
	it( 'formats sizes, tones and relative times', () => {
		expect( bytes( 512 ) ).toBe( '512 B' );
		expect( bytes( 2048 ) ).toBe( '2.0 KB' );
		expect( bytes( 3 * 1048576 ) ).toBe( '3.0 MB' );
		expect( scoreTone( 92 ) ).toBe( 'success' );
		expect( scoreTone( 60 ) ).toBe( 'warning' );
		expect( scoreTone( 10 ) ).toBe( 'danger' );
		expect( timeAgo( new Date( Date.now() - 5 * 60000 ).toISOString() ) ).toBe( '5 min ago' );
		expect( timeAgo( '' ) ).toBe( 'never' );
		expect( collectorStatus( 'off' ).tone ).toBe( 'neutral' );
		expect( collectorStatus( 'nope' ).label ).toBe( 'Not run yet' );
	} );
	it( 'parses issue route params', () => {
		expect( parseIssueParams( [ '12' ] ) ).toEqual( { id: 12, filters: {} } );
		expect( parseIssueParams( [ 'severity:critical', 'source:php' ] ) ).toEqual( { id: 0, filters: { severity: 'critical', source: 'php' } } );
	} );
} );

describe( 'charts', () => {
	it( 'score ring has an accessible label', async () => {
		const view = await render( <ScoreRing score={ 73 } /> );
		expect( view.$( '[role="img"]' ).getAttribute( 'aria-label' ) ).toBe( 'Health score 73 out of 100' );
		expect( view.$( '.dev3sh-scorering' ).className ).toContain( 'is-warning' );
	} );
	it( 'bars summarise the total', async () => {
		const view = await render( <Bars values={ [ 1, 2, 3 ] } label="Failed logins" /> );
		expect( view.$( '[role="img"]' ).getAttribute( 'aria-label' ) ).toBe( 'Failed logins: 6 in 14 days' );
		expect( view.$$( '.dev3sh-daybars__bar' ) ).toHaveLength( 3 );
	} );
} );

describe( 'Overview', () => {
	it( 'shows the score, KPIs, collectors and the logging hint', async () => {
		apiFetch.mockResolvedValueOnce( {
			score: 64,
			grade: 'fair',
			previous: 70,
			history: [ { date: '2026-10-24', score: 70 }, { date: '2026-10-25', score: 64 } ],
			counts: { severity: { critical: 1, high: 2, medium: 0, low: 0, info: 0 }, source: { updates: 3 }, total: 3 },
			collectors: [ { id: 'php', label: 'PHP error log', status: 'ok', message: '', at: new Date().toISOString() } ],
			top: [ { ...ISSUE } ],
			security: { failed_24h: 9, suspicious_24h: 4, daily_failed: [] },
			updates: 3,
			integrity: null,
			integrity_job: null,
			log: { enabled: false },
			last_scan: new Date().toISOString(),
			ai: {},
			fixes_enabled: false,
		} );
		const onCounts = jest.fn();
		const view = await render( wrap( <Overview settings={ { api_key_set: false } } onCounts={ onCounts } /> ) );
		await view.flush();
		expect( view.getByText( '64' ) ).toBeTruthy();
		expect( view.getByText( '-6 vs a week ago' ) ).toBeTruthy();
		expect( view.getByText( 'PHP error logging is off' ) ).toBeTruthy();
		expect( view.getByText( 'Connect DEV3 AI for plain-words explanations' ) ).toBeTruthy();
		expect( view.getByText( ISSUE.title ) ).toBeTruthy();
		expect( view.getByText( 'Working' ) ).toBeTruthy();
		expect( onCounts ).toHaveBeenCalledWith( expect.objectContaining( { total: 3 } ) );
	} );
} );

describe( 'IssueDrawer', () => {
	it( 'shows the built-in explanation, the masked payload and blocks AI without a key', async () => {
		apiFetch.mockImplementation( ( { url } ) => {
			if ( url.endsWith( 'issues/7' ) ) {
				return Promise.resolve( { ...ISSUE } );
			}
			if ( url.endsWith( 'issues/7/payload' ) ) {
				return Promise.resolve( { issue: { file: 'wp-content/plugins/acme/acme.php', message: 'user [email]' }, environment: {}, actions: [ 'none' ] } );
			}
			return new Promise( () => {} );
		} );
		const view = await render( wrap( <IssueDrawer id={ 7 } aiReady={ false } onClose={ () => {} } onChange={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Code tried to use a function that does not exist.' ) ).toBeTruthy();
		expect( view.getByText( 'Re-install Acme.' ) ).toBeTruthy();
		const ai = view.$$( 'button' ).find( ( b ) => b.textContent.includes( 'Explain with DEV3 AI' ) );
		expect( ai.disabled ).toBe( true );
		await click( view.$$( 'button' ).find( ( b ) => b.textContent.includes( 'What is sent?' ) ) );
		await view.flush();
		expect( view.$( '.dev3sh-snippet--light' ).textContent ).toContain( '[email]' );
	} );

	it( 'runs the AI explanation and renders steps', async () => {
		apiFetch.mockImplementation( ( { url, method } ) => {
			if ( url.endsWith( 'issues/7' ) ) {
				return Promise.resolve( { ...ISSUE } );
			}
			if ( url.endsWith( 'issues/7/explain' ) && method === 'POST' ) {
				return Promise.resolve( { summary: 'The Acme plugin calls a missing function.', cause: 'Acme needs Acme Core.', steps: [ 'Install Acme Core.', 'Update Acme.' ], who: 'owner', urgency: 'now', fix_action: 'none', fix_target: '', source: 'ai', model: 'gemini-2.5-flash', at: new Date().toISOString() } );
			}
			return new Promise( () => {} );
		} );
		const onUsage = jest.fn();
		const view = await render( wrap( <IssueDrawer id={ 7 } aiReady onClose={ () => {} } onChange={ () => {} } onUsage={ onUsage } /> ) );
		await view.flush();
		await click( view.$$( 'button' ).find( ( b ) => b.textContent.includes( 'Explain with DEV3 AI' ) ) );
		await view.flush();
		expect( view.getByText( 'The Acme plugin calls a missing function.' ) ).toBeTruthy();
		expect( view.getByText( 'Install Acme Core.' ) ).toBeTruthy();
		expect( onUsage ).toHaveBeenCalled();
	} );
} );

describe( 'FixModal', () => {
	it( 'needs the checkbox before applying and sends confirm: true with the token', async () => {
		const calls = [];
		apiFetch.mockImplementation( ( opts ) => {
			calls.push( opts );
			if ( opts.url.endsWith( 'fixes/preview' ) ) {
				return Promise.resolve( { type: 'deactivate_plugin', target: 'acme', title: 'Deactivate Acme', description: 'The plugin is switched off.', effects: [ 'Features stop.' ], token: 'tok123' } );
			}
			if ( opts.url.endsWith( 'fixes/apply' ) ) {
				return Promise.resolve( { applied: true, message: 'Acme was deactivated.' } );
			}
			return new Promise( () => {} );
		} );
		const onDone = jest.fn();
		const view = await render( wrap( <FixModal fix={ { type: 'deactivate_plugin', target: 'acme', label: 'Deactivate Acme' } } issueId={ 7 } onClose={ () => {} } onDone={ onDone } /> ) );
		await view.flush();
		expect( view.getByText( 'Features stop.' ) ).toBeTruthy();
		const apply = view.$( '[data-testid="dev3sh-apply-fix"]' );
		expect( apply.disabled ).toBe( true );
		await click( view.$( 'input[type="checkbox"]' ) );
		expect( apply.disabled ).toBe( false );
		await click( apply );
		await view.flush();
		const applyCall = calls.find( ( c ) => c.url.endsWith( 'fixes/apply' ) );
		expect( applyCall.data ).toEqual( { token: 'tok123', confirm: true } );
		expect( onDone ).toHaveBeenCalled();
	} );

	it( 'explains read-only mode when fixes are off', async () => {
		apiFetch.mockImplementation( () => Promise.reject( { code: 'dev3sh_fixes_disabled', message: 'Read-only mode is on.' } ) );
		const view = await render( wrap( <FixModal fix={ { type: 'block_ip', target: '203.0.113.9', label: 'Block' } } onClose={ () => {} } onDone={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Read-only mode is on' ) ).toBeTruthy();
		expect( view.$( '[data-testid="dev3sh-apply-fix"]' ) ).toBeNull();
	} );
} );

describe( 'SettingsTab', () => {
	it( 'asks for confirmation before allowing fixes', async () => {
		const save = jest.fn( ( patch ) => Promise.resolve( { ...patch } ) );
		const settings = { collectors: { php: true }, allow_fixes: false, log_path: '', block_days: 30 };
		const view = await render( wrap( <SettingsTab settings={ settings } save={ save } /> ) );
		const toggle = view.$( '[role="switch"]' );
		await click( toggle );
		expect( view.getByText( 'Allow one-click fixes?' ) ).toBeTruthy();
		const allow = view.$$( 'button' ).find( ( b ) => b.textContent === 'Allow fixes' );
		expect( allow.disabled ).toBe( true );
		const box = view.getByText( 'I have a backup and understand the risk.' ).closest( '.dev3sh-ui-check' ).querySelector( 'input' );
		await click( box );
		await click( allow );
		await click( view.$$( 'button' ).find( ( b ) => b.textContent === 'Save settings' ) );
		await view.flush();
		expect( save ).toHaveBeenCalledWith( expect.objectContaining( { allow_fixes: true } ) );
	} );

	it( 'saves a custom log path', async () => {
		const save = jest.fn( ( patch ) => Promise.resolve( { ...patch } ) );
		const view = await render( wrap( <SettingsTab settings={ { collectors: {}, log_path: '' } } save={ save } /> ) );
		const input = view.$$( 'input' ).find( ( i ) => i.placeholder === '/home/site/logs/php-error.log' );
		await change( input, '/srv/logs/php.log' );
		await click( view.$$( 'button' ).find( ( b ) => b.textContent === 'Save settings' ) );
		await view.flush();
		expect( save ).toHaveBeenCalledWith( expect.objectContaining( { log_path: '/srv/logs/php.log' } ) );
	} );
} );
