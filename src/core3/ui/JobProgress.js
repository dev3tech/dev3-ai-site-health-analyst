import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../api';
import { Button } from './Button';
import { Badge, ErrorNotice } from './feedback';
import { cx, formatNumber, useInterval } from './utils';

const FINAL = [ 'done', 'failed', 'cancelled' ];

export const JOB_STATUS = {
	queued: { tone: 'neutral', label: __( 'Queued', 'dev3-ai-site-health-analyst' ) },
	running: { tone: 'brand', label: __( 'Running', 'dev3-ai-site-health-analyst' ) },
	done: { tone: 'success', label: __( 'Done', 'dev3-ai-site-health-analyst' ) },
	failed: { tone: 'danger', label: __( 'Failed', 'dev3-ai-site-health-analyst' ) },
	cancelled: { tone: 'neutral', label: __( 'Cancelled', 'dev3-ai-site-health-analyst' ) },
};

/**
 * Progress bar.
 *
 * @param {Object} props       Props.
 * @param {number} props.value 0–100.
 * @param {string} props.tone  brand | success | warning | danger.
 * @param {string} props.label Accessible label.
 * @return {Element} Bar.
 */
export function ProgressBar( { value = 0, tone = 'brand', label, indeterminate = false } ) {
	return (
		<div
			className={ cx( 'dev3sh-ui-progress', `is-${ tone }`, indeterminate && 'is-indeterminate' ) }
			role="progressbar"
			aria-label={ label }
			aria-valuemin={ 0 }
			aria-valuemax={ 100 }
			aria-valuenow={ indeterminate ? undefined : Math.round( value ) }
		>
			<span style={ { width: indeterminate ? undefined : `${ Math.max( 0, Math.min( 100, value ) ) }%` } } />
		</div>
	);
}

/**
 * Polls GET jobs/<id> and shows progress with Cancel / Retry failed.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.jobId    Job id (null renders nothing).
 * @param {Object}   props.job      Initial job object (optional; e.g. the create response).
 * @param {string}   props.title    Title.
 * @param {number}   props.interval Poll interval ms (default 2000).
 * @param {Function} props.onDone   ( job ) => void when the job reaches done/failed/cancelled.
 * @param {Function} props.onChange ( job ) => void on every update.
 * @param {boolean}  props.showErrors List failed items (default true).
 * @return {Element|null} Progress.
 */
export function JobProgress( { jobId, job: initial, title = __( 'Background job', 'dev3-ai-site-health-analyst' ), interval = 2000, onDone, onChange, showErrors = true, className } ) {
	const [ job, setJob ] = useState( initial || null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const doneFor = useRef( null );

	const apply = ( next ) => {
		setJob( next );
		setError( null );
		if ( onChange ) {
			onChange( next );
		}
		if ( next && FINAL.includes( next.status ) && doneFor.current !== `${ next.id }:${ next.status }:${ next.processed }` ) {
			doneFor.current = `${ next.id }:${ next.status }:${ next.processed }`;
			if ( onDone ) {
				onDone( next );
			}
		}
	};
	const load = () => {
		if ( ! jobId ) {
			return;
		}
		request( `jobs/${ jobId }` ).then( apply ).catch( setError );
	};
	useEffect( load, [ jobId ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useInterval( load, interval, !! jobId && ( ! job || ! FINAL.includes( job.status ) ) && ! error );

	if ( ! jobId ) {
		return null;
	}
	const act = ( action ) => {
		setBusy( action );
		request( `jobs/${ jobId }/${ action }`, { method: 'POST' } )
			.then( apply )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};
	const status = job ? JOB_STATUS[ job.status ] || JOB_STATUS.queued : JOB_STATUS.queued;
	const running = job && ! FINAL.includes( job.status );
	const percent = job ? job.percent : 0;
	const tone = job && job.status === 'failed' ? 'danger' : job && job.status === 'done' ? ( job.failed ? 'warning' : 'success' ) : 'brand';

	return (
		<div className={ cx( 'dev3sh-ui-job', className ) } aria-live="polite">
			<div className="dev3sh-ui-job__head">
				<p className="dev3sh-ui-job__title">{ title }</p>
				<Badge tone={ status.tone } dot={ running }>
					{ status.label }
				</Badge>
			</div>
			<ProgressBar value={ percent } tone={ tone } label={ title } indeterminate={ ! job || ( job.status === 'queued' && job.processed === 0 ) } />
			<div className="dev3sh-ui-job__foot">
				<span className="dev3sh-ui-job__counts">
					{ job
						? sprintf( /* translators: 1: processed, 2: total, 3: percent */ __( '%1$s of %2$s processed (%3$s%%)', 'dev3-ai-site-health-analyst' ), formatNumber( job.processed ), formatNumber( job.total ), percent )
						: __( 'Starting…', 'dev3-ai-site-health-analyst' ) }
					{ job && job.failed > 0 && <span className="dev3sh-ui-job__failed">{ sprintf( /* translators: %s: failed count */ __( '%s failed', 'dev3-ai-site-health-analyst' ), formatNumber( job.failed ) ) }</span> }
				</span>
				<span className="dev3sh-ui-job__actions">
					{ running && (
						<Button size="sm" variant="ghost" onClick={ () => act( 'cancel' ) } loading={ busy === 'cancel' }>
							{ __( 'Cancel', 'dev3-ai-site-health-analyst' ) }
						</Button>
					) }
					{ job && ! running && ( job.failed > 0 || job.status === 'cancelled' ) && (
						<Button size="sm" icon="refresh" onClick={ () => act( 'retry' ) } loading={ busy === 'retry' }>
							{ job.status === 'cancelled' ? __( 'Resume', 'dev3-ai-site-health-analyst' ) : __( 'Retry failed', 'dev3-ai-site-health-analyst' ) }
						</Button>
					) }
				</span>
			</div>
			<ErrorNotice error={ error } onRetry={ load } />
			{ showErrors && job && job.errors && job.errors.length > 0 && (
				<details className="dev3sh-ui-job__errors">
					<summary>{ __( 'Show failed items', 'dev3-ai-site-health-analyst' ) }</summary>
					<ul>
						{ job.errors.map( ( e, i ) => (
							<li key={ i }>
								<code>{ typeof e.item === 'object' ? JSON.stringify( e.item ) : String( e.item ) }</code> { e.message }
							</li>
						) ) }
					</ul>
				</details>
			) }
		</div>
	);
}

export default JobProgress;
