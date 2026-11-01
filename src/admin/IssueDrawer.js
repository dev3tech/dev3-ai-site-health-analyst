import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Drawer, ErrorNotice, Inline, Notice, Skeleton, Textarea, useToast } from '../core3/ui';
import FixModal from './FixModal';
import { SEVERITY, SOURCE, URGENCY, WHO, dateTime, timeAgo } from './util';

const D = 'dev3-ai-site-health-analyst';

/**
 * Explanation block (built-in or AI).
 *
 * @param {Object}   props       Props.
 * @param {Object}   props.x     Explanation.
 * @param {Array}    props.fixes Applicable fixes.
 * @param {Function} props.onFix Open fix dialog.
 * @return {Element} Block.
 */
function Explanation( { x, fixes, onFix } ) {
	const urgency = URGENCY[ x.urgency ] || URGENCY.normal;
	const proposed = x.fix_action && x.fix_action !== 'none' ? fixes.find( ( f ) => f.type === x.fix_action && ( ! x.fix_target || f.target === x.fix_target ) ) : null;
	return (
		<div className="dev3sh-explain">
			<p className="dev3sh-explain__summary">{ x.summary }</p>
			{ x.cause && (
				<>
					<p className="dev3sh-label">{ __( 'Likely cause', D ) }</p>
					<p>{ x.cause }</p>
				</>
			) }
			{ x.impact && (
				<>
					<p className="dev3sh-label">{ __( 'Impact', D ) }</p>
					<p>{ x.impact }</p>
				</>
			) }
			<p className="dev3sh-label">{ __( 'How to fix it', D ) }</p>
			<ol className="dev3sh-steps">
				{ ( x.steps || [] ).map( ( s, i ) => (
					<li key={ i }>{ s }</li>
				) ) }
			</ol>
			<Inline gap={ 8 }>
				<Badge tone={ urgency.tone }>{ urgency.label }</Badge>
				{ x.who && <Badge tone="neutral">{ sprintf( /* translators: %s: who. */ __( 'Who: %s', D ), WHO[ x.who ] || x.who ) }</Badge> }
				{ x.confidence && x.source === 'ai' && <Badge tone="neutral">{ sprintf( /* translators: %s: confidence. */ __( 'Confidence: %s', D ), x.confidence ) }</Badge> }
			</Inline>
			{ proposed && (
				<div className="dev3sh-fixbox">
					<p className="dev3sh-fixbox__title">{ x.source === 'ai' ? __( 'DEV3 AI suggests a one-click fix', D ) : __( 'Suggested one-click fix', D ) }</p>
					<p className="dev3sh-muted">{ __( 'You will see exactly what changes and must confirm it first.', D ) }</p>
					<div>
						<Button size="sm" variant="secondary" icon="bolt" onClick={ () => onFix( proposed ) }>
							{ proposed.label }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}

/**
 * Issue detail drawer.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.id       Issue id (0 = closed).
 * @param {Function} props.onClose  Close.
 * @param {Function} props.onChange After a change.
 * @param {Function} props.onUsage  After an AI call.
 * @param {boolean}  props.aiReady  API key set.
 * @return {Element} Drawer.
 */
export default function IssueDrawer( { id, onClose, onChange, onUsage, aiReady } ) {
	const toast = useToast();
	const [ issue, setIssue ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ aiBusy, setAiBusy ] = useState( false );
	const [ aiError, setAiError ] = useState( null );
	const [ payload, setPayload ] = useState( null );
	const [ question, setQuestion ] = useState( '' );
	const [ answer, setAnswer ] = useState( null );
	const [ asking, setAsking ] = useState( false );
	const [ fix, setFix ] = useState( null );

	const load = () => {
		setError( null );
		return request( `issues/${ id }` ).then( setIssue ).catch( setError );
	};
	useEffect( () => {
		setIssue( null );
		setPayload( null );
		setAnswer( null );
		setAiError( null );
		setQuestion( '' );
		if ( id ) {
			load();
		}
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const explain = () => {
		setAiBusy( true );
		setAiError( null );
		request( `issues/${ id }/explain`, { method: 'POST' } )
			.then( ( ai ) => setIssue( ( i ) => ( { ...i, ai } ) ) )
			.catch( setAiError )
			.finally( () => {
				setAiBusy( false );
				onUsage();
			} );
	};

	const ask = () => {
		setAsking( true );
		setAiError( null );
		request( `issues/${ id }/ask`, { method: 'POST', data: { question } } )
			.then( setAnswer )
			.catch( setAiError )
			.finally( () => {
				setAsking( false );
				onUsage();
			} );
	};

	const togglePayload = () => {
		if ( payload ) {
			setPayload( null );
			return;
		}
		request( `issues/${ id }/payload` )
			.then( setPayload )
			.catch( ( e ) => toast.error( e ) );
	};

	const setStatus = ( status ) =>
		request( `issues/${ id }/status`, { method: 'POST', data: { status } } )
			.then( ( next ) => {
				setIssue( ( i ) => ( { ...i, ...next } ) );
				toast.success( status === 'resolved' ? __( 'Marked as resolved. It re-opens if it happens again.', D ) : status === 'ignored' ? __( 'Ignored. It no longer counts towards the score.', D ) : __( 'Re-opened.', D ) );
				onChange();
			} )
			.catch( ( e ) => toast.error( e ) );

	const sev = issue ? SEVERITY[ issue.severity ] || SEVERITY.low : null;
	const details = issue && issue.context && issue.context.details ? issue.context.details : null;
	const samples = issue && issue.context && issue.context.samples ? issue.context.samples : [];
	const lists = details && typeof details === 'object' && ! Array.isArray( details ) ? [ 'modified', 'missing', 'added', 'unknown', 'hooks' ].filter( ( k ) => Array.isArray( details[ k ] ) && details[ k ].length ) : [];
	const listLabel = { modified: __( 'Changed files', D ), missing: __( 'Missing files', D ), added: __( 'Extra files', D ), unknown: __( 'Unknown files', D ), hooks: __( 'Late tasks', D ) };

	return (
		<>
			<Drawer
				open={ !! id }
				onClose={ onClose }
				size="xl"
				title={ issue ? issue.title : __( 'Issue', D ) }
				description={ issue ? `${ SOURCE[ issue.source ] || issue.source }${ issue.component_label ? ' · ' + issue.component_label : '' }` : '' }
				footer={
					issue && (
						<>
							{ issue.status === 'open' ? (
								<>
									<Button variant="ghost" onClick={ () => setStatus( 'ignored' ) }>
										{ __( 'Ignore', D ) }
									</Button>
									<Button variant="primary" icon="check" onClick={ () => setStatus( 'resolved' ) }>
										{ __( 'Mark resolved', D ) }
									</Button>
								</>
							) : (
								<Button onClick={ () => setStatus( 'open' ) }>{ __( 'Re-open', D ) }</Button>
							) }
						</>
					)
				}
			>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				{ ! issue && ! error && <Skeleton lines={ 6 } /> }
				{ issue && (
					<div className="dev3sh-drawer">
						<Inline gap={ 8 }>
							<Badge tone={ sev.tone } dot>
								{ sev.label }
							</Badge>
							<Badge tone={ issue.status === 'open' ? 'warning' : 'neutral' }>{ issue.status === 'open' ? __( 'Open', D ) : issue.status === 'resolved' ? __( 'Resolved', D ) : __( 'Ignored', D ) }</Badge>
							{ issue.regressed && <Badge tone="danger">{ __( 'Came back', D ) }</Badge> }
						</Inline>

						<dl className="dev3sh-facts">
							<div>
								<dt>{ __( 'Seen', D ) }</dt>
								<dd>{ sprintf( /* translators: %d: count. */ _n( '%d time', '%d times', issue.count, D ), issue.count ) }</dd>
							</div>
							<div>
								<dt>{ __( 'First seen', D ) }</dt>
								<dd>{ dateTime( issue.first_seen ) }</dd>
							</div>
							<div>
								<dt>{ __( 'Last seen', D ) }</dt>
								<dd>
									{ dateTime( issue.last_seen ) } <span className="dev3sh-muted">({ timeAgo( issue.last_seen ) })</span>
								</dd>
							</div>
							{ issue.file && (
								<div>
									<dt>{ __( 'File', D ) }</dt>
									<dd>
										<code className="dev3sh-code-inline">
											{ issue.file }
											{ issue.line ? `:${ issue.line }` : '' }
										</code>
									</dd>
								</div>
							) }
							{ issue.source !== 'site_health' && issue.message && issue.message !== issue.title && (
								<div>
									<dt>{ __( 'Message', D ) }</dt>
									<dd className="dev3sh-pre">{ issue.message }</dd>
								</div>
							) }
						</dl>

						<Card tone="ai" title={ __( 'Explained by DEV3 AI', D ) } description={ issue.ai ? sprintf( /* translators: 1: model, 2: time. */ __( '%1$s · %2$s', D ), issue.ai.model, timeAgo( issue.ai.at ) ) : __( 'Plain words, likely cause and fix steps for your setup.', D ) }>
							{ issue.ai ? (
								<Explanation x={ issue.ai } fixes={ issue.fixes } onFix={ setFix } />
							) : (
								<p className="dev3sh-muted">{ aiReady ? __( 'Secrets, paths, emails, IP addresses and user names are masked before sending.', D ) : __( 'Add a Google Gemini API key under API Config to use DEV3 AI. The built-in explanation below works without it.', D ) }</p>
							) }
							<ErrorNotice error={ aiError } onRetry={ explain } settingsHref="#/api" />
							<Inline gap={ 8 }>
								<AiButton loading={ aiBusy } loadingText={ __( 'Explaining…', D ) } disabled={ ! aiReady } onClick={ explain }>
									{ issue.ai ? __( 'Explain again with DEV3 AI', D ) : __( 'Explain with DEV3 AI', D ) }
								</AiButton>
								<Button variant="ghost" size="sm" icon="info" onClick={ togglePayload } aria-expanded={ !! payload }>
									{ payload ? __( 'Hide what is sent', D ) : __( 'What is sent?', D ) }
								</Button>
							</Inline>
							{ payload && <pre className="dev3sh-snippet dev3sh-snippet--light" aria-label={ __( 'Masked data sent to DEV3 AI', D ) }>{ JSON.stringify( payload, null, 2 ) }</pre> }
							{ aiReady && (
								<div className="dev3sh-ask">
									<Textarea label={ __( 'Ask a follow-up question', D ) } value={ question } onChange={ setQuestion } rows={ 2 } maxLength={ 500 } placeholder={ __( 'e.g. Is it safe to update this plugin before my sale on Friday?', D ) } />
									<div>
										<Button size="sm" loading={ asking } disabled={ ! question.trim() } onClick={ ask }>
											{ __( 'Ask DEV3 AI', D ) }
										</Button>
									</div>
									{ answer && (
										<Notice tone="ai" title={ __( 'Answer', D ) }>
											{ answer.answer }
										</Notice>
									) }
								</div>
							) }
						</Card>

						<Card title={ __( 'Built-in explanation', D ) } description={ __( 'Rule-based, works without AI.', D ) }>
							<Explanation x={ issue.builtin } fixes={ issue.fixes } onFix={ setFix } />
						</Card>

						{ issue.fixes.length > 0 && (
							<Card title={ __( 'One-click fixes', D ) } description={ issue.fixes_enabled ? __( 'Each fix shows a preview and needs your confirmation.', D ) : __( 'Read-only mode is on. Fixes stay disabled until you allow them in Settings.', D ) }>
								<Inline gap={ 8 }>
									{ issue.fixes.map( ( f ) => (
										<Button key={ `${ f.type }-${ f.target }` } size="sm" icon="bolt" onClick={ () => setFix( f ) }>
											{ f.label }
										</Button>
									) ) }
								</Inline>
							</Card>
						) }

						{ lists.map( ( k ) => (
							<div key={ k }>
								<p className="dev3sh-label">{ listLabel[ k ] }</p>
								<ul className="dev3sh-filelist">
									{ details[ k ].slice( 0, 30 ).map( ( f ) => (
										<li key={ f }>
											<code className="dev3sh-code-inline">{ f }</code>
										</li>
									) ) }
								</ul>
							</div>
						) ) }

						{ samples.length > 0 && (
							<div>
								<p className="dev3sh-label">{ __( 'Latest samples (stay on your site)', D ) }</p>
								{ samples.map( ( s, i ) => (
									<pre key={ i } className="dev3sh-snippet">
										{ s }
									</pre>
								) ) }
							</div>
						) }
					</div>
				) }
			</Drawer>
			<FixModal
				fix={ fix }
				issueId={ id }
				onClose={ () => setFix( null ) }
				onDone={ () => {
					load();
					onChange();
				} }
			/>
		</>
	);
}
