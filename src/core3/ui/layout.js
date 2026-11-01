import Icon from './icons';
import { Skeleton } from './feedback';
import { cx } from './utils';

/**
 * Section header: title, description, actions (wrap under the title on narrow screens).
 *
 * @param {Object} props             Props.
 * @param {string} props.title       Title (h2: the product name is the page's h1).
 * @param {*}      props.description Text under the title.
 * @param {*}      props.actions     Buttons.
 * @param {string} props.eyebrow     Small label above the title.
 * @param {Object} props.back        { href, label } back link.
 * @return {Element} Header.
 */
export function PageHeader( { title, description, actions, eyebrow, back, className } ) {
	return (
		<div className={ cx( 'dev3sh-ui-pagehead', className ) }>
			<div className="dev3sh-ui-pagehead__text">
				{ back && (
					<a className="dev3sh-ui-pagehead__back" href={ back.href }>
						<Icon name="chevronLeft" size={ 14 } />
						{ back.label }
					</a>
				) }
				{ eyebrow && <p className="dev3sh-ui-pagehead__eyebrow">{ eyebrow }</p> }
				<h2 className="dev3sh-ui-pagehead__title">{ title }</h2>
				{ description && <p className="dev3sh-ui-pagehead__desc">{ description }</p> }
			</div>
			{ actions && <div className="dev3sh-ui-pagehead__actions">{ actions }</div> }
		</div>
	);
}

/**
 * Card.
 *
 * @param {Object}  props             Props.
 * @param {string}  props.title       Title (h3).
 * @param {*}       props.description Text under the title.
 * @param {*}       props.actions     Header actions.
 * @param {*}       props.footer      Footer (e.g. Save button).
 * @param {boolean} props.flush       No body padding (tables).
 * @param {string}  props.tone        '' | 'ai' (subtle AI accent).
 * @return {Element} Card.
 */
export function Card( { title, description, actions, footer, flush = false, tone, className, children, ...rest } ) {
	return (
		<section className={ cx( 'dev3sh-ui-card', tone && `is-${ tone }`, className ) } { ...rest }>
			{ ( title || actions ) && (
				<header className="dev3sh-ui-card__head">
					<div className="dev3sh-ui-card__heading">
						{ title && <h3 className="dev3sh-ui-card__title">{ title }</h3> }
						{ description && <p className="dev3sh-ui-card__desc">{ description }</p> }
					</div>
					{ actions && <div className="dev3sh-ui-card__actions">{ actions }</div> }
				</header>
			) }
			<div className={ cx( 'dev3sh-ui-card__body', flush && 'is-flush' ) }>{ children }</div>
			{ footer && <footer className="dev3sh-ui-card__foot">{ footer }</footer> }
		</section>
	);
}

/**
 * KPI tile.
 *
 * @param {Object}  props         Props.
 * @param {string}  props.label   Label.
 * @param {*}       props.value   Value.
 * @param {*}       props.hint    Small text under the value.
 * @param {string}  props.icon    Icon name.
 * @param {string}  props.tone    neutral | brand | success | warning | danger.
 * @param {Object}  props.trend   { value: '+12%', direction: 'up'|'down'|'flat', good: bool }.
 * @param {boolean} props.loading Skeleton.
 * @return {Element} Stat.
 */
export function Stat( { label, value, hint, icon, tone = 'neutral', trend, loading = false, className } ) {
	return (
		<div className={ cx( 'dev3sh-ui-stat', `is-${ tone }`, className ) }>
			<div className="dev3sh-ui-stat__top">
				<span className="dev3sh-ui-stat__label">{ label }</span>
				{ icon && (
					<span className="dev3sh-ui-stat__icon" aria-hidden="true">
						<Icon name={ icon } size={ 16 } />
					</span>
				) }
			</div>
			<div className="dev3sh-ui-stat__value">{ loading ? <Skeleton width="60%" height={ 26 } /> : value }</div>
			{ ( hint || trend ) && (
				<div className="dev3sh-ui-stat__hint">
					{ trend && (
						<span className={ cx( 'dev3sh-ui-stat__trend', trend.good === false ? 'is-bad' : trend.good ? 'is-good' : '' ) }>
							{ trend.direction === 'down' ? '↓' : trend.direction === 'flat' ? '→' : '↑' } { trend.value }
						</span>
					) }
					{ hint }
				</div>
			) }
		</div>
	);
}

/**
 * Responsive grid of Stats (auto-fit columns, 1 column on phones).
 *
 * @param {Object} props     Props.
 * @param {number} props.min Minimum tile width in px (default 180).
 * @return {Element} Grid.
 */
export function StatGrid( { min = 180, className, children } ) {
	return (
		<div className={ cx( 'dev3sh-ui-statgrid', className ) } style={ { '--dev3sh-ui-min': `${ min }px` } }>
			{ children }
		</div>
	);
}

/**
 * Vertical stack with consistent gaps.
 *
 * @param {Object} props     Props.
 * @param {number} props.gap Gap in px (4/8/12/16/24/32).
 * @return {Element} Stack.
 */
export function Stack( { gap = 16, className, children, as: Tag = 'div' } ) {
	return (
		<Tag className={ cx( 'dev3sh-ui-stack', className ) } style={ { gap } }>
			{ children }
		</Tag>
	);
}

/**
 * Responsive columns: auto-fit at a minimum width, single column on phones.
 *
 * @param {Object} props     Props.
 * @param {number} props.min Minimum column width (default 280).
 * @param {number} props.gap Gap (default 16).
 * @return {Element} Grid.
 */
export function Columns( { min = 280, gap = 16, className, children } ) {
	return (
		<div className={ cx( 'dev3sh-ui-columns', className ) } style={ { '--dev3sh-ui-min': `${ min }px`, gap } }>
			{ children }
		</div>
	);
}

/**
 * Horizontal row of actions/buttons that wraps.
 *
 * @param {Object} props       Props.
 * @param {string} props.align start | end | between.
 * @return {Element} Row.
 */
export function Inline( { align = 'start', gap = 8, className, children } ) {
	return (
		<div className={ cx( 'dev3sh-ui-inline', `is-${ align }`, className ) } style={ { gap } }>
			{ children }
		</div>
	);
}
