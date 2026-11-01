import apiFetch from '@wordpress/api-fetch';
import { act } from 'react';
import { restUrl } from '../../src/core3/config';
import { AiButton, AiDraftReview, AppShell, Button, ErrorNotice, JobProgress, Modal, ResponsiveTable, Tabs, TextField, Toggle, UsageMeter, diffLines, diffWords, formatMoney, sortRows, splitName, useToast } from '../../src/core3/ui';
import { change, click, key, render } from './render';

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	jest.useRealTimers();
} );

describe( 'helpers', () => {
	it( 'builds REST urls for pretty and plain permalinks', () => {
		expect( restUrl( 'jobs/5?x=1', '/wp-json/dev3sh/v1/' ) ).toBe( '/wp-json/dev3sh/v1/jobs/5?x=1' );
		expect( restUrl( 'jobs/5?x=1', '/index.php?rest_route=/dev3sh/v1/' ) ).toBe( '/index.php?rest_route=/dev3sh/v1/jobs/5&x=1' );
	} );

	it( 'splits long product names for the header', () => {
		expect( splitName( 'DEV3 AI Review Summarizer & Sentiment Analyzer for WooCommerce' ) ).toEqual( { brand: 'DEV3 AI', title: 'Review Summarizer & Sentiment Analyzer', platform: 'WooCommerce' } );
		expect( splitName( 'Plain Name' ) ).toEqual( { brand: '', title: 'Plain Name', platform: '' } );
	} );

	it( 'diffs words and lines', () => {
		const parts = diffWords( 'The quick fox', 'The slow fox' );
		expect( parts.filter( ( p ) => p.type === 'del' ).map( ( p ) => p.value.trim() ) ).toEqual( [ 'quick' ] );
		expect( parts.filter( ( p ) => p.type === 'add' ).map( ( p ) => p.value.trim() ) ).toEqual( [ 'slow' ] );
		expect( diffLines( 'a\nb\n', 'a\nc\n' ) ).toEqual( [ { type: 'same', value: 'a\n' }, { type: 'del', value: 'b\n' }, { type: 'add', value: 'c\n' } ] );
	} );

	it( 'sorts rows numerically and by text, empties last', () => {
		const rows = [ { n: 10, s: 'b' }, { n: 2, s: '' }, { n: 33, s: 'a' } ];
		expect( sortRows( rows, { key: 'n' }, 'asc' ).map( ( r ) => r.n ) ).toEqual( [ 2, 10, 33 ] );
		expect( sortRows( rows, { key: 's' }, 'desc' ).map( ( r ) => r.s ) ).toEqual( [ 'b', 'a', '' ] );
	} );

	it( 'formats money', () => {
		expect( formatMoney( 12.5 ) ).toMatch( /12\.50/ );
		expect( formatMoney( 0.0042 ) ).toMatch( /0\.004/ );
	} );
} );

describe( 'buttons and fields', () => {
	it( 'AiButton shows DEV3 branding and a busy state', async () => {
		const view = await render( <AiButton onClick={ () => {} } /> );
		expect( view.getByText( 'Generate with DEV3 AI' ) ).toBeTruthy();
		await view.rerender( <AiButton loading /> );
		const btn = view.$( 'button' );
		expect( btn.disabled ).toBe( true );
		expect( btn.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( view.getByText( 'Generating…' ) ).toBeTruthy();
	} );

	it( 'Button renders a link with href and calls onClick', async () => {
		const onClick = jest.fn();
		const view = await render(
			<>
				<Button href="#/api">Go</Button>
				<Button variant="danger" onClick={ onClick }>
					Delete
				</Button>
			</>
		);
		expect( view.$( 'a.dev3sh-ui-btn' ).getAttribute( 'href' ) ).toBe( '#/api' );
		await click( view.$( 'button.is-danger' ) );
		expect( onClick ).toHaveBeenCalled();
	} );

	it( 'TextField wires label, help and error for screen readers', async () => {
		const onChange = jest.fn();
		const view = await render( <TextField label="Title" help="Shown in search" error="Required" value="" onChange={ onChange } /> );
		const input = view.$( 'input' );
		expect( view.$( `label[for="${ input.id }"]` ).textContent ).toContain( 'Title' );
		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe( `${ input.id }-error ${ input.id }-help` );
		await change( input, 'Hello' );
		expect( onChange ).toHaveBeenCalledWith( 'Hello' );
	} );

	it( 'Toggle is a labelled switch', async () => {
		const onChange = jest.fn();
		const view = await render( <Toggle label="AI features" checked={ false } onChange={ onChange } /> );
		const sw = view.$( '[role="switch"]' );
		expect( sw.getAttribute( 'aria-checked' ) ).toBe( 'false' );
		expect( document.getElementById( sw.getAttribute( 'aria-labelledby' ) ).textContent ).toBe( 'AI features' );
		await click( sw );
		expect( onChange ).toHaveBeenCalledWith( true );
	} );
} );

describe( 'ResponsiveTable', () => {
	const columns = [
		{ key: 'name', label: 'Name', primary: true, sortable: true },
		{ key: 'count', label: 'Count', sortable: true, align: 'end' },
	];
	const rows = [
		{ id: 1, name: 'Beta', count: 5 },
		{ id: 2, name: 'Alpha', count: 50 },
		{ id: 3, name: 'Gamma', count: 7 },
	];

	it( 'sorts on header click and labels cells for the stacked mobile view', async () => {
		const view = await render( <ResponsiveTable columns={ columns } rows={ rows } /> );
		const names = () => view.$$( 'tbody td.is-primary' ).map( ( td ) => td.textContent );
		expect( names() ).toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );
		await click( view.$$( 'th button' )[ 1 ] );
		expect( names() ).toEqual( [ 'Beta', 'Gamma', 'Alpha' ] );
		expect( view.$$( 'th' )[ 1 ].getAttribute( 'aria-sort' ) ).toBe( 'ascending' );
		await click( view.$$( 'th button' )[ 1 ] );
		expect( names() ).toEqual( [ 'Alpha', 'Gamma', 'Beta' ] );
		expect( view.$( 'tbody td:not(.is-primary)' ).getAttribute( 'data-label' ) ).toBe( 'Count' );
	} );

	it( 'paginates client-side and shows the empty state', async () => {
		const view = await render( <ResponsiveTable columns={ columns } rows={ rows } pageSize={ 2 } /> );
		expect( view.$$( 'tbody tr' ) ).toHaveLength( 2 );
		expect( view.getByText( '1–2 of 3' ) ).toBeTruthy();
		await click( view.$( 'button[aria-label="Next page"]' ) );
		expect( view.$$( 'tbody tr' ) ).toHaveLength( 1 );
		await view.rerender( <ResponsiveTable columns={ columns } rows={ [] } empty={ { title: 'No coupons yet' } } /> );
		expect( view.getByText( 'No coupons yet' ) ).toBeTruthy();
	} );
} );

describe( 'Modal and Tabs', () => {
	it( 'Modal is labelled, closes on Escape and traps focus', async () => {
		const onClose = jest.fn();
		const view = await render(
			<Modal open onClose={ onClose } title="Edit rule" footer={ <button type="button">Save</button> }>
				<input aria-label="Name" />
			</Modal>
		);
		await view.flush();
		const dialog = view.$( '[role="dialog"]' );
		expect( dialog.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		expect( document.getElementById( dialog.getAttribute( 'aria-labelledby' ) ).textContent ).toBe( 'Edit rule' );
		expect( dialog.closest( '.dev3sh-root' ) ).toBeTruthy();
		const focusables = Array.from( dialog.querySelectorAll( 'button, input' ) );
		focusables[ focusables.length - 1 ].focus();
		await key( document.activeElement, 'Tab' );
		await key( document.body, 'Escape' );
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'Tabs support arrow keys', async () => {
		const onChange = jest.fn();
		const view = await render( <Tabs tabs={ [ { id: 'a', label: 'A' }, { id: 'b', label: 'B' } ] } value="a" onChange={ onChange } /> );
		const tabs = view.$$( '[role="tab"]' );
		expect( tabs[ 0 ].getAttribute( 'aria-selected' ) ).toBe( 'true' );
		expect( tabs[ 1 ].tabIndex ).toBe( -1 );
		await key( tabs[ 0 ], 'ArrowRight' );
		expect( onChange ).toHaveBeenCalledWith( 'b' );
	} );
} );

describe( 'AiDraftReview', () => {
	it( 'shows changes and accepts the edited text', async () => {
		const onAccept = jest.fn();
		const onReject = jest.fn();
		const view = await render( <AiDraftReview original="Old title" draft="New title" onAccept={ onAccept } onReject={ onReject } onRegenerate={ () => {} } /> );
		expect( view.$( 'ins' ).textContent.trim() ).toBe( 'New' );
		expect( view.$( 'del' ).textContent.trim() ).toBe( 'Old' );
		await click( view.getByText( 'Edit' ).closest( 'button' ) );
		await change( view.$( 'textarea' ), 'Edited title' );
		await click( view.getByText( 'Accept' ).closest( 'button' ) );
		expect( onAccept ).toHaveBeenCalledWith( 'Edited title' );
		await click( view.getByText( 'Reject' ).closest( 'button' ) );
		expect( onReject ).toHaveBeenCalled();
	} );

	it( 'validates JSON before accepting', async () => {
		const onAccept = jest.fn();
		const view = await render( <AiDraftReview format="json" draft={ { a: 1 } } onAccept={ onAccept } /> );
		await click( view.getByText( 'Edit' ).closest( 'button' ) );
		await change( view.$( 'textarea' ), '{ "a": ' );
		await click( view.getByText( 'Accept' ).closest( 'button' ) );
		expect( onAccept ).not.toHaveBeenCalled();
		expect( view.getByText( 'The JSON is not valid. Fix it or regenerate.' ) ).toBeTruthy();
		await change( view.$( 'textarea' ), '{ "a": 2 }' );
		await click( view.getByText( 'Accept' ).closest( 'button' ) );
		expect( onAccept ).toHaveBeenCalledWith( { a: 2 } );
	} );

	it( 'reviews structured fields', async () => {
		const onAccept = jest.fn();
		const fields = [ { key: 'headline', label: 'Headline' }, { key: 'body', label: 'Body', multiline: true } ];
		const view = await render( <AiDraftReview fields={ fields } original={ { headline: 'Hi', body: 'Same' } } draft={ { headline: 'Hello', body: 'Same' } } onAccept={ onAccept } /> );
		expect( view.$( 'ins' ).textContent ).toBe( 'Hello' );
		await click( view.getByText( 'Accept' ).closest( 'button' ) );
		expect( onAccept ).toHaveBeenCalledWith( { headline: 'Hello', body: 'Same' } );
	} );
} );

describe( 'errors, toasts and AI status', () => {
	it( 'ErrorNotice shows the AI-paused state for the monthly cap', async () => {
		const view = await render( <ErrorNotice error={ { code: 'dev3sh_cap_reached', message: 'AI paused', hint: 'Raise the cap' } } /> );
		expect( view.getByText( 'AI paused: monthly budget reached' ) ).toBeTruthy();
		expect( view.$( 'a[href="#/api"]' ) ).toBeTruthy();
	} );

	it( 'toasts render inside AppShell and announce politely', async () => {
		function Child() {
			const toast = useToast();
			return (
				<button type="button" onClick={ () => toast.success( 'Saved!' ) }>
					save
				</button>
			);
		}
		const view = await render(
			<AppShell name="DEV3 AI Demo for WooCommerce" version="1.0.0" nav={ [ { id: 'a', label: 'Dashboard' }, { id: 'b', label: 'Settings' } ] } active="a">
				<Child />
			</AppShell>
		);
		expect( view.getByText( 'Demo' ) ).toBeTruthy();
		expect( view.getByText( 'for WooCommerce' ) ).toBeTruthy();
		expect( view.$( 'a[aria-current="page"]' ).textContent ).toBe( 'Dashboard' );
		await click( view.getByText( 'save' ) );
		expect( view.$( '.dev3sh-ui-toasts' ).getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( view.getByText( 'Saved!' ) ).toBeTruthy();
	} );

	it( 'UsageMeter shows spend against the cap and the paused badge', async () => {
		const view = await render( <UsageMeter status={ { enabled: true, month: { cost: 10, cap: 10, percent: 100, reached: true, requests: 3, tokens: 900, currency: 'USD', resets_at: '2026-11-01T00:00:00Z' } } } /> );
		expect( view.getByText( 'AI paused · cap reached' ) ).toBeTruthy();
		expect( view.$( '[role="progressbar"]' ).getAttribute( 'aria-valuenow' ) ).toBe( '100' );
	} );
} );

describe( 'JobProgress', () => {
	it( 'polls the jobs endpoint until the job finishes, then offers retry', async () => {
		jest.useFakeTimers();
		const onDone = jest.fn();
		apiFetch
			.mockResolvedValueOnce( { id: 7, status: 'running', total: 10, done: 4, failed: 0, processed: 4, percent: 40, errors: [] } )
			.mockResolvedValueOnce( { id: 7, status: 'done', total: 10, done: 9, failed: 1, processed: 10, percent: 100, errors: [ { item: 3, message: 'Boom' } ] } );
		const view = await render( <JobProgress jobId={ 7 } onDone={ onDone } interval={ 1000 } /> );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe( '/wp-json/dev3sh/v1/jobs/7' );
		expect( view.$( '[role="progressbar"]' ).getAttribute( 'aria-valuenow' ) ).toBe( '40' );
		await act( async () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( onDone ).toHaveBeenCalledWith( expect.objectContaining( { status: 'done' } ) );
		expect( view.getByText( 'Retry failed' ) ).toBeTruthy();
		apiFetch.mockResolvedValueOnce( { id: 7, status: 'queued', total: 1, done: 0, failed: 0, processed: 0, percent: 0, errors: [] } );
		await click( view.getByText( 'Retry failed' ).closest( 'button' ) );
		expect( apiFetch.mock.calls[ 2 ][ 0 ] ).toEqual( expect.objectContaining( { url: '/wp-json/dev3sh/v1/jobs/7/retry', method: 'POST' } ) );
	} );
} );
