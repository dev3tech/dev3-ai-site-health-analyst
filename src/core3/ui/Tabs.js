import { useRef } from '@wordpress/element';
import { cx, useUid } from './utils';

/**
 * Accessible tabs (roving focus with arrow keys, Home/End). Scrolls horizontally on narrow screens.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.tabs     [ { id, label, badge, disabled } ].
 * @param {string}   props.value    Active tab id.
 * @param {Function} props.onChange ( id ) => void.
 * @param {string}   props.variant  line | pills.
 * @param {string}   props.label    aria-label for the tab list.
 * @param {*}        props.children Panel content for the active tab (optional).
 * @return {Element} Tabs.
 */
export function Tabs( { tabs, value, onChange, variant = 'line', label, className, children } ) {
	const base = useUid( 'dev3sh-ui-tabs' );
	const refs = useRef( {} );
	const enabled = tabs.filter( ( t ) => ! t.disabled );
	const move = ( e, current ) => {
		const i = enabled.findIndex( ( t ) => t.id === current );
		let next = null;
		if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
			next = enabled[ ( i + 1 ) % enabled.length ];
		} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
			next = enabled[ ( i - 1 + enabled.length ) % enabled.length ];
		} else if ( e.key === 'Home' ) {
			next = enabled[ 0 ];
		} else if ( e.key === 'End' ) {
			next = enabled[ enabled.length - 1 ];
		}
		if ( next ) {
			e.preventDefault();
			onChange( next.id );
			if ( refs.current[ next.id ] ) {
				refs.current[ next.id ].focus();
			}
		}
	};
	return (
		<div className={ cx( 'dev3sh-ui-tabs', `is-${ variant }`, className ) }>
			<div className="dev3sh-ui-tabs__list" role="tablist" aria-label={ label }>
				{ tabs.map( ( t ) => (
					<button
						key={ t.id }
						ref={ ( el ) => ( refs.current[ t.id ] = el ) }
						type="button"
						role="tab"
						id={ `${ base }-tab-${ t.id }` }
						aria-selected={ value === t.id }
						aria-controls={ children ? `${ base }-panel` : undefined }
						tabIndex={ value === t.id ? 0 : -1 }
						disabled={ t.disabled }
						className={ cx( 'dev3sh-ui-tabs__tab', value === t.id && 'is-active' ) }
						onClick={ () => onChange( t.id ) }
						onKeyDown={ ( e ) => move( e, t.id ) }
					>
						{ t.label }
						{ t.badge !== undefined && t.badge !== null && <span className="dev3sh-ui-tabs__badge">{ t.badge }</span> }
					</button>
				) ) }
			</div>
			{ children && (
				<div className="dev3sh-ui-tabs__panel" role="tabpanel" id={ `${ base }-panel` } aria-labelledby={ `${ base }-tab-${ value }` } tabIndex={ 0 }>
					{ children }
				</div>
			) }
		</div>
	);
}

export default Tabs;
