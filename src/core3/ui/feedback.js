import { __ } from '@wordpress/i18n';
import Icon from './icons';
import { Button } from './Button';
import { cx } from './utils';

const TONE_ICON = { info: 'info', success: 'success', warning: 'alert', error: 'error', ai: 'sparkles' };

/**
 * Small status pill.
 *
 * @param {Object}  props      Props.
 * @param {string}  props.tone neutral | brand | success | warning | danger | info.
 * @param {boolean} props.dot  Leading dot.
 * @return {Element} Badge.
 */
export function Badge( { tone = 'neutral', dot = false, className, children, ...rest } ) {
	return (
		<span className={ cx( 'dev3sh-ui-badge', `is-${ tone }`, className ) } { ...rest }>
			{ dot && <span className="dev3sh-ui-badge__dot" aria-hidden="true" /> }
			{ children }
		</span>
	);
}

/**
 * Inline notice.
 *
 * @param {Object}   props           Props.
 * @param {string}   props.tone      info | success | warning | error | ai.
 * @param {string}   props.title     Bold first line.
 * @param {Function} props.onDismiss Shows a close button.
 * @param {*}        props.actions   Buttons.
 * @return {Element} Notice.
 */
export function Notice( { tone = 'info', title, children, onDismiss, actions, className } ) {
	return (
		<div className={ cx( 'dev3sh-ui-notice', `is-${ tone }`, className ) } role={ tone === 'error' ? 'alert' : 'status' }>
			<Icon name={ TONE_ICON[ tone ] || 'info' } size={ 18 } className="dev3sh-ui-notice__icon" />
			<div className="dev3sh-ui-notice__body">
				{ title && <p className="dev3sh-ui-notice__title">{ title }</p> }
				{ children && <div className="dev3sh-ui-notice__text">{ children }</div> }
				{ actions && <div className="dev3sh-ui-notice__actions">{ actions }</div> }
			</div>
			{ onDismiss && (
				<button type="button" className="dev3sh-ui-notice__close" onClick={ onDismiss } aria-label={ __( 'Dismiss', 'dev3-ai-site-health-analyst' ) }>
					<Icon name="x" size={ 16 } />
				</button>
			) }
		</div>
	);
}

/**
 * True when an error envelope means "monthly AI cap reached".
 *
 * @param {Object} error Error envelope.
 * @return {boolean} Cap error.
 */
export const isCapError = ( error ) => !! error && /_cap_reached$/.test( error.code || '' );

/**
 * Renders a REST error envelope { code, message, hint, retryable } as a notice, with the
 * "AI paused" state for the monthly cap and a Retry button for retryable errors.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.error     Envelope (or null to render nothing).
 * @param {Function} props.onRetry   Retry handler.
 * @param {Function} props.onDismiss Dismiss handler.
 * @param {string}   props.settingsHref Link to the API settings (cap errors).
 * @return {Element|null} Notice.
 */
export function ErrorNotice( { error, onRetry, onDismiss, settingsHref = '#/api' } ) {
	if ( ! error || error.silent ) {
		return null;
	}
	if ( isCapError( error ) ) {
		return (
			<Notice
				tone="warning"
				title={ __( 'AI paused: monthly budget reached', 'dev3-ai-site-health-analyst' ) }
				onDismiss={ onDismiss }
				actions={ <Button size="sm" href={ settingsHref }>{ __( 'Adjust the cap', 'dev3-ai-site-health-analyst' ) }</Button> }
			>
				{ error.hint || error.message }
			</Notice>
		);
	}
	return (
		<Notice
			tone="error"
			title={ error.message }
			onDismiss={ onDismiss }
			actions={ onRetry && error.retryable !== false ? <Button size="sm" icon="refresh" onClick={ onRetry }>{ __( 'Try again', 'dev3-ai-site-health-analyst' ) }</Button> : null }
		>
			{ error.hint }
		</Notice>
	);
}

/**
 * Empty state.
 *
 * @param {Object}  props             Props.
 * @param {string}  props.icon        Icon name.
 * @param {string}  props.title       Title.
 * @param {*}       props.description Text.
 * @param {*}       props.action      Primary action (a Button).
 * @param {*}       props.secondary   Secondary action.
 * @param {boolean} props.compact     Less padding (inside tables/cards).
 * @return {Element} Empty state.
 */
export function EmptyState( { icon = 'inbox', title, description, action, secondary, compact = false, className, children } ) {
	return (
		<div className={ cx( 'dev3sh-ui-empty', compact && 'is-compact', className ) }>
			<div className="dev3sh-ui-empty__icon" aria-hidden="true">
				<Icon name={ icon } size={ compact ? 20 : 24 } />
			</div>
			{ title && <p className="dev3sh-ui-empty__title">{ title }</p> }
			{ description && <p className="dev3sh-ui-empty__text">{ description }</p> }
			{ children }
			{ ( action || secondary ) && (
				<div className="dev3sh-ui-empty__actions">
					{ action }
					{ secondary }
				</div>
			) }
		</div>
	);
}

/**
 * Loading placeholder.
 *
 * @param {Object} props        Props.
 * @param {number} props.lines  Text lines (renders a block of lines when > 0).
 * @param {*}      props.width  CSS width (single block).
 * @param {*}      props.height CSS height (single block).
 * @param {boolean} props.circle Round.
 * @return {Element} Skeleton.
 */
export function Skeleton( { lines = 0, width = '100%', height = 14, circle = false, className } ) {
	if ( lines > 0 ) {
		return (
			<div className={ cx( 'dev3sh-ui-skeleton-lines', className ) } aria-hidden="true">
				{ Array.from( { length: lines } ).map( ( _, i ) => (
					<span key={ i } className="dev3sh-ui-skeleton" style={ { width: i === lines - 1 && lines > 1 ? '60%' : '100%', height } } />
				) ) }
			</div>
		);
	}
	return <span className={ cx( 'dev3sh-ui-skeleton', circle && 'is-circle', className ) } style={ { width, height } } aria-hidden="true" />;
}

/**
 * Card-shaped skeleton with a title and lines.
 *
 * @param {Object} props       Props.
 * @param {number} props.lines Lines.
 * @return {Element} Skeleton card.
 */
export function SkeletonCard( { lines = 3 } ) {
	return (
		<div className="dev3sh-ui-card" aria-busy="true" aria-label={ __( 'Loading', 'dev3-ai-site-health-analyst' ) }>
			<div className="dev3sh-ui-card__body">
				<Skeleton width="40%" height={ 18 } />
				<div style={ { height: 16 } } />
				<Skeleton lines={ lines } />
			</div>
		</div>
	);
}

/**
 * Visually hidden text for screen readers.
 *
 * @param {Object} props Props.
 * @return {Element} Span.
 */
export function VisuallyHidden( { children, as: Tag = 'span' } ) {
	return <Tag className="dev3sh-ui-sr-only">{ children }</Tag>;
}
