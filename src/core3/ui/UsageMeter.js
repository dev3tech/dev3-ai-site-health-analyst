import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../api';
import { Badge, Skeleton } from './feedback';
import { ProgressBar } from './JobProgress';
import { cx, formatMoney, formatNumber } from './utils';

/**
 * Loads GET usage/month: { enabled, public, key_set, cap_reached, month: { cost, cap, percent, reached, requests, tokens, resets_at, currency } }.
 *
 * @param {*} refreshKey Change it to reload.
 * @return {Object|null|false} Status, null while loading, false on error.
 */
export function useAiStatus( refreshKey ) {
	const [ status, setStatus ] = useState( null );
	useEffect( () => {
		let live = true;
		request( 'usage/month' )
			.then( ( s ) => live && setStatus( s ) )
			.catch( () => live && setStatus( false ) );
		return () => {
			live = false;
		};
	}, [ refreshKey ] );
	return status;
}

/**
 * Estimated AI spend this month against the admin's cap ("AI paused" when reached).
 *
 * @param {Object}  props            Props.
 * @param {boolean} props.compact    Pill for headers.
 * @param {*}       props.refreshKey Reload trigger.
 * @param {Object}  props.status     Pre-loaded status (skips the request).
 * @param {string}  props.settingsHref Link to the cap setting.
 * @return {Element|null} Meter.
 */
export function UsageMeter( { compact = false, refreshKey, status: given, settingsHref = '#/api', className } ) {
	const loaded = useAiStatus( given ? 'given' : refreshKey );
	const status = given || loaded;
	if ( status === false ) {
		return null;
	}
	if ( ! status ) {
		return compact ? <Skeleton width={ 120 } height={ 24 } /> : <Skeleton lines={ 2 } />;
	}
	const m = status.month || {};
	const capped = m.cap > 0;
	const tone = m.reached ? 'danger' : m.percent >= 80 ? 'warning' : 'brand';
	const paused = ! status.enabled || m.reached;

	if ( compact ) {
		return (
			<a className={ cx( 'dev3sh-ui-usagepill', paused && 'is-paused', className ) } href={ settingsHref } title={ __( 'Estimated AI spend this month', 'dev3-ai-site-health-analyst' ) }>
				<span className={ cx( 'dev3sh-ui-usagepill__dot', `is-${ paused ? 'danger' : tone }` ) } aria-hidden="true" />
				{ paused ? (
					<span>{ status.enabled ? __( 'AI paused', 'dev3-ai-site-health-analyst' ) : __( 'AI off', 'dev3-ai-site-health-analyst' ) }</span>
				) : (
					<span>{ capped ? `${ formatMoney( m.cost, m.currency ) } / ${ formatMoney( m.cap, m.currency ) }` : formatMoney( m.cost, m.currency ) }</span>
				) }
			</a>
		);
	}

	const resets = m.resets_at ? new Date( m.resets_at ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } ) : '';
	return (
		<div className={ cx( 'dev3sh-ui-usage', className ) }>
			<div className="dev3sh-ui-usage__head">
				<div>
					<p className="dev3sh-ui-usage__label">{ __( 'Estimated AI spend this month', 'dev3-ai-site-health-analyst' ) }</p>
					<p className="dev3sh-ui-usage__value">
						{ formatMoney( m.cost, m.currency ) }
						{ capped && <span className="dev3sh-ui-usage__cap"> { sprintf( /* translators: %s: cap */ __( 'of %s cap', 'dev3-ai-site-health-analyst' ), formatMoney( m.cap, m.currency ) ) }</span> }
					</p>
				</div>
				{ ! status.enabled && <Badge tone="neutral">{ __( 'AI turned off', 'dev3-ai-site-health-analyst' ) }</Badge> }
				{ status.enabled && m.reached && <Badge tone="danger">{ __( 'AI paused · cap reached', 'dev3-ai-site-health-analyst' ) }</Badge> }
				{ status.enabled && ! m.reached && capped && m.percent >= 80 && <Badge tone="warning">{ __( 'Near cap', 'dev3-ai-site-health-analyst' ) }</Badge> }
			</div>
			{ capped && <ProgressBar value={ m.percent } tone={ tone } label={ __( 'Monthly AI budget used', 'dev3-ai-site-health-analyst' ) } /> }
			<p className="dev3sh-ui-usage__foot">
				{ sprintf( /* translators: 1: requests, 2: tokens */ __( '%1$s requests · %2$s tokens', 'dev3-ai-site-health-analyst' ), formatNumber( m.requests ), formatNumber( m.tokens ) ) }
				{ capped && resets && ` · ${ sprintf( /* translators: %s: date */ __( 'resets %s', 'dev3-ai-site-health-analyst' ), resets ) }` }
				{ ! capped && (
					<>
						{ ' · ' }
						<a href={ settingsHref }>{ __( 'Set a monthly cap', 'dev3-ai-site-health-analyst' ) }</a>
					</>
				) }
			</p>
		</div>
	);
}

export default UsageMeter;
