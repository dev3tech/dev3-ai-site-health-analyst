import { createContext, createPortal, useCallback, useContext, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Icon from './icons';
import { cx } from './utils';

const ToastContext = createContext( null );
const ICONS = { success: 'success', error: 'error', warning: 'alert', info: 'info' };

let seq = 0;

/**
 * Provides useToast(). AppShell includes it; wrap other roots yourself.
 *
 * @param {Object} props Props.
 * @return {Element} Provider.
 */
export function ToastProvider( { children } ) {
	const [ toasts, setToasts ] = useState( [] );
	const [ node, setNode ] = useState( null );
	const timers = useRef( {} );

	useEffect( () => {
		const el = document.createElement( 'div' );
		el.className = 'dev3sh-root dev3sh-ui-portal';
		document.body.appendChild( el );
		setNode( el );
		const all = timers.current;
		return () => {
			Object.values( all ).forEach( clearTimeout );
			el.remove();
		};
	}, [] );

	const dismiss = useCallback( ( id ) => {
		clearTimeout( timers.current[ id ] );
		delete timers.current[ id ];
		setToasts( ( list ) => list.filter( ( t ) => t.id !== id ) );
	}, [] );

	const show = useCallback(
		( toast ) => {
			const id = ++seq;
			const item = { tone: 'info', duration: toast && toast.tone === 'error' ? 8000 : 5000, ...toast, id };
			setToasts( ( list ) => [ ...list.slice( -3 ), item ] );
			if ( item.duration > 0 ) {
				timers.current[ id ] = setTimeout( () => dismiss( id ), item.duration );
			}
			return id;
		},
		[ dismiss ]
	);

	const api = useMemo(
		() => ( {
			show,
			dismiss,
			success: ( message, opts = {} ) => show( { ...opts, tone: 'success', message } ),
			info: ( message, opts = {} ) => show( { ...opts, tone: 'info', message } ),
			warning: ( message, opts = {} ) => show( { ...opts, tone: 'warning', message } ),
			/* Accepts a string or a REST error envelope { message, hint }. */
			error: ( error, opts = {} ) =>
				show( {
					...opts,
					tone: 'error',
					message: typeof error === 'string' ? error : ( error && error.message ) || __( 'Something went wrong.', 'dev3-ai-site-health-analyst' ),
					hint: typeof error === 'string' ? opts.hint : error && error.hint,
				} ),
		} ),
		[ show, dismiss ]
	);

	return (
		<ToastContext.Provider value={ api }>
			{ children }
			{ node &&
				createPortal(
					<div className="dev3sh-ui-toasts" aria-live="polite" aria-relevant="additions">
						{ toasts.map( ( t ) => (
							<div key={ t.id } className={ cx( 'dev3sh-ui-toast', `is-${ t.tone }` ) } role={ t.tone === 'error' ? 'alert' : 'status' }>
								<Icon name={ ICONS[ t.tone ] || 'info' } size={ 18 } className="dev3sh-ui-toast__icon" />
								<div className="dev3sh-ui-toast__body">
									{ t.title && <p className="dev3sh-ui-toast__title">{ t.title }</p> }
									<p className="dev3sh-ui-toast__msg">{ t.message }</p>
									{ t.hint && <p className="dev3sh-ui-toast__hint">{ t.hint }</p> }
									{ t.action && (
										<button
											type="button"
											className="dev3sh-ui-toast__action"
											onClick={ () => {
												t.action.onClick();
												dismiss( t.id );
											} }
										>
											{ t.action.label }
										</button>
									) }
								</div>
								<button type="button" className="dev3sh-ui-toast__close" onClick={ () => dismiss( t.id ) } aria-label={ __( 'Dismiss notification', 'dev3-ai-site-health-analyst' ) }>
									<Icon name="x" size={ 14 } />
								</button>
							</div>
						) ) }
					</div>,
					node
				) }
		</ToastContext.Provider>
	);
}

const NOOP = { show: () => 0, dismiss: () => {}, success: () => 0, info: () => 0, warning: () => 0, error: () => 0 };

/**
 * Toast API: { show({ tone, title, message, hint, action:{label,onClick}, duration }), success(msg), info(msg), warning(msg), error(msgOrEnvelope), dismiss(id) }.
 *
 * @return {Object} API (no-ops outside a provider).
 */
export function useToast() {
	return useContext( ToastContext ) || NOOP;
}
