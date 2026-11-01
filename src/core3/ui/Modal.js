import { createPortal, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Icon from './icons';
import { cx, useUid } from './utils';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Portal target inside a `.dev3sh-root` element so scoped styles apply.
 *
 * @return {HTMLElement|null} Node.
 */
function usePortalNode() {
	const [ node, setNode ] = useState( null );
	useEffect( () => {
		const el = document.createElement( 'div' );
		el.className = 'dev3sh-root dev3sh-ui-portal';
		document.body.appendChild( el );
		setNode( el );
		return () => el.remove();
	}, [] );
	return node;
}

/**
 * Traps Tab inside `ref` while active, closes on Escape, restores focus on close and locks body scroll.
 *
 * @param {Object}   ref     Container ref.
 * @param {boolean}  active  Active.
 * @param {Function} onClose Escape handler.
 * @param {Object}   initial Ref of the element to focus first.
 */
export function useFocusTrap( ref, active, onClose, initial ) {
	const closeRef = useRef( onClose );
	closeRef.current = onClose;
	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		const previous = document.activeElement;
		const overflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		const focusFirst = () => {
			const root = ref.current;
			if ( ! root ) {
				return;
			}
			const target = ( initial && initial.current ) || root.querySelector( '[data-autofocus]' ) || root.querySelector( FOCUSABLE ) || root;
			target.focus();
		};
		const t = setTimeout( focusFirst, 0 );
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				e.stopPropagation();
				if ( closeRef.current ) {
					closeRef.current();
				}
				return;
			}
			if ( e.key !== 'Tab' || ! ref.current ) {
				return;
			}
			const items = Array.from( ref.current.querySelectorAll( FOCUSABLE ) ).filter( ( el ) => el.offsetParent !== null || el === document.activeElement );
			if ( ! items.length ) {
				e.preventDefault();
				return;
			}
			const first = items[ 0 ];
			const last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		};
		document.addEventListener( 'keydown', onKey, true );
		return () => {
			clearTimeout( t );
			document.removeEventListener( 'keydown', onKey, true );
			document.body.style.overflow = overflow;
			if ( previous && previous.focus ) {
				previous.focus();
			}
		};
	}, [ active ] ); // eslint-disable-line react-hooks/exhaustive-deps
}

function Dialog( { open, onClose, title, description, footer, children, size = 'md', kind, closeOnOverlay = true, initialFocus, className, hideClose = false } ) {
	const node = usePortalNode();
	const ref = useRef( null );
	const titleId = useUid( 'dev3sh-ui-dialog-title' );
	const descId = useUid( 'dev3sh-ui-dialog-desc' );
	useFocusTrap( ref, open && !! node, onClose, initialFocus );
	if ( ! open || ! node ) {
		return null;
	}
	return createPortal(
		<div className={ cx( 'dev3sh-ui-overlay', `is-${ kind }` ) } onMouseDown={ ( e ) => closeOnOverlay && e.target === e.currentTarget && onClose && onClose() }>
			<div ref={ ref } className={ cx( `dev3sh-ui-${ kind }`, `is-${ size }`, className ) } role="dialog" aria-modal="true" aria-labelledby={ title ? titleId : undefined } aria-describedby={ description ? descId : undefined } tabIndex={ -1 }>
				<header className="dev3sh-ui-dialog__head">
					<div>
						{ title && (
							<h2 className="dev3sh-ui-dialog__title" id={ titleId }>
								{ title }
							</h2>
						) }
						{ description && (
							<p className="dev3sh-ui-dialog__desc" id={ descId }>
								{ description }
							</p>
						) }
					</div>
					{ ! hideClose && (
						<button type="button" className="dev3sh-ui-dialog__close" onClick={ onClose } aria-label={ __( 'Close', 'dev3-ai-site-health-analyst' ) }>
							<Icon name="x" size={ 18 } />
						</button>
					) }
				</header>
				<div className="dev3sh-ui-dialog__body">{ children }</div>
				{ footer && <footer className="dev3sh-ui-dialog__foot">{ footer }</footer> }
			</div>
		</div>,
		node
	);
}

/**
 * Modal dialog (bottom sheet on phones). Focus trap, Escape, overlay click, focus restore.
 *
 * @param {Object}   props               Props.
 * @param {boolean}  props.open          Open.
 * @param {Function} props.onClose       Close handler.
 * @param {string}   props.title         Title (labels the dialog).
 * @param {*}        props.description   Text under the title.
 * @param {*}        props.footer        Footer actions.
 * @param {string}   props.size          sm | md | lg | xl.
 * @param {boolean}  props.closeOnOverlay Close when the backdrop is clicked.
 * @param {Object}   props.initialFocus  Ref to focus first (else [data-autofocus] or the first control).
 * @return {Element|null} Modal.
 */
export function Modal( props ) {
	return <Dialog { ...props } kind="modal" />;
}

/**
 * Side drawer (full width on phones). Same props as Modal; size sm | md | lg sets the width.
 *
 * @param {Object} props Props.
 * @return {Element|null} Drawer.
 */
export function Drawer( props ) {
	return <Dialog { ...props } kind="drawer" />;
}

export default Modal;
