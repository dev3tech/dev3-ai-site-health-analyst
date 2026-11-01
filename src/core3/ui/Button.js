import { forwardRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Icon from './icons';
import { cx } from './utils';

/**
 * Small inline spinner.
 *
 * @param {Object} props       Props.
 * @param {number} props.size  Pixels.
 * @param {string} props.label Screen-reader label ('' = decorative).
 * @return {Element} Spinner.
 */
export function Spinner( { size = 16, label = __( 'Loading', 'dev3-ai-site-health-analyst' ) } ) {
	return (
		<span className="dev3sh-ui-spinner" style={ { width: size, height: size } } role={ label ? 'status' : undefined } aria-label={ label || undefined } aria-hidden={ label ? undefined : true } />
	);
}

/**
 * Button (or link when `href` is set).
 *
 * variant: primary | secondary | ghost | danger | link | ai
 * size: sm | md | lg
 */
export const Button = forwardRef( function Button(
	{ variant = 'secondary', size = 'md', icon, iconRight, loading = false, disabled = false, block = false, href, type = 'button', className, children, label, ...rest },
	ref
) {
	const classes = cx( 'dev3sh-ui-btn', `is-${ variant }`, `is-${ size }`, block && 'is-block', loading && 'is-loading', ! children && icon && 'is-icon-only', className );
	const content = (
		<>
			{ loading ? <Spinner size={ size === 'sm' ? 14 : 16 } label="" /> : icon && <Icon name={ icon } size={ size === 'sm' ? 14 : 16 } /> }
			{ children && <span className="dev3sh-ui-btn__label">{ children }</span> }
			{ iconRight && ! loading && <Icon name={ iconRight } size={ size === 'sm' ? 14 : 16 } /> }
		</>
	);
	if ( href && ! disabled ) {
		return (
			<a ref={ ref } href={ href } className={ classes } aria-label={ label } { ...rest }>
				{ content }
			</a>
		);
	}
	return (
		// eslint-disable-next-line react/button-has-type
		<button ref={ ref } type={ type } className={ classes } disabled={ disabled || loading } aria-busy={ loading || undefined } aria-label={ label } { ...rest }>
			{ content }
		</button>
	);
} );

/**
 * Icon-only button with a required accessible label (close, row actions…).
 *
 * @param {Object} props Props: icon, label, variant (default ghost), size.
 * @return {Element} Button.
 */
export function IconButton( { icon, label, variant = 'ghost', size = 'md', ...rest } ) {
	return <Button variant={ variant } size={ size } icon={ icon } label={ label } title={ label } { ...rest } />;
}

/**
 * The DEV3 AI action button: sparkle icon, "Generate with DEV3 AI", loading state.
 *
 * @param {Object}  props             Props.
 * @param {boolean} props.loading     Busy.
 * @param {string}  props.loadingText Text while busy.
 * @return {Element} Button.
 */
export function AiButton( { loading = false, loadingText = __( 'Generating…', 'dev3-ai-site-health-analyst' ), children, ...rest } ) {
	return (
		<Button variant="ai" icon="sparkles" loading={ loading } { ...rest }>
			{ loading ? loadingText : children || __( 'Generate with DEV3 AI', 'dev3-ai-site-health-analyst' ) }
		</Button>
	);
}

export default Button;
