import { __ } from '@wordpress/i18n';
import { cx, useUid } from './utils';

/**
 * Label + help + error wrapper. Field components use it; use it directly for custom controls:
 * `<Field label="…" id={ id }>{ ( a11y ) => <input id={ id } { ...a11y } /> }</Field>`.
 *
 * @param {Object}   props           Props.
 * @param {string}   props.id        Control id.
 * @param {string}   props.label     Label.
 * @param {*}        props.help      Help text.
 * @param {string}   props.error     Error text (sets aria-invalid).
 * @param {boolean}  props.required  Required marker.
 * @param {boolean}  props.hideLabel Visually hide the label (still read by screen readers).
 * @param {*}        props.after     Node after the label (e.g. a counter).
 * @param {Function} props.children  ( a11yProps ) => control, or a node.
 * @return {Element} Field.
 */
export function Field( { id, label, help, error, required, hideLabel, after, className, children } ) {
	const helpId = help ? `${ id }-help` : undefined;
	const errorId = error ? `${ id }-error` : undefined;
	const a11y = {
		'aria-describedby': [ errorId, helpId ].filter( Boolean ).join( ' ' ) || undefined,
		'aria-invalid': error ? true : undefined,
		'aria-required': required || undefined,
	};
	return (
		<div className={ cx( 'dev3sh-ui-field', error && 'has-error', className ) }>
			{ label && (
				<div className={ cx( 'dev3sh-ui-field__labelrow', hideLabel && 'dev3sh-ui-sr-only' ) }>
					<label className="dev3sh-ui-field__label" htmlFor={ id }>
						{ label }
						{ required && (
							<span className="dev3sh-ui-field__req" aria-hidden="true">
								{ ' ' }*
							</span>
						) }
					</label>
					{ after }
				</div>
			) }
			{ typeof children === 'function' ? children( a11y ) : children }
			{ error && (
				<p className="dev3sh-ui-field__error" id={ errorId }>
					{ error }
				</p>
			) }
			{ help && (
				<p className="dev3sh-ui-field__help" id={ helpId }>
					{ help }
				</p>
			) }
		</div>
	);
}

/**
 * Text input. onChange receives the string value.
 *
 * @param {Object} props Props: label, value, onChange, help, error, type, prefix, suffix, required, hideLabel, id, …input attributes.
 * @return {Element} Field.
 */
export function TextField( { label, value, onChange, help, error, required, hideLabel, id: givenId, type = 'text', prefix, suffix, className, ...rest } ) {
	const id = useUid( 'dev3sh-ui-text', givenId );
	return (
		<Field id={ id } label={ label } help={ help } error={ error } required={ required } hideLabel={ hideLabel } className={ className }>
			{ ( a11y ) => (
				<div className={ cx( 'dev3sh-ui-inputwrap', prefix && 'has-prefix', suffix && 'has-suffix' ) }>
					{ prefix && <span className="dev3sh-ui-inputwrap__affix">{ prefix }</span> }
					<input
						id={ id }
						type={ type }
						className="dev3sh-ui-input"
						value={ value === null || value === undefined ? '' : value }
						onChange={ ( e ) => onChange && onChange( e.target.value ) }
						required={ required }
						{ ...a11y }
						{ ...rest }
					/>
					{ suffix && <span className="dev3sh-ui-inputwrap__affix">{ suffix }</span> }
				</div>
			) }
		</Field>
	);
}

/**
 * Number input. onChange receives a number, or '' while the field is empty.
 *
 * @param {Object} props Props: label, value, onChange, min, max, step, suffix, help, error.
 * @return {Element} Field.
 */
export function NumberField( { onChange, min, max, step = 1, ...rest } ) {
	return (
		<TextField
			type="number"
			inputMode="decimal"
			min={ min }
			max={ max }
			step={ step }
			onChange={ ( v ) => {
				if ( ! onChange ) {
					return;
				}
				if ( v === '' ) {
					onChange( '' );
					return;
				}
				const n = Number( v );
				onChange( Number.isNaN( n ) ? '' : n );
			} }
			{ ...rest }
		/>
	);
}

/**
 * Multi-line input with an optional character counter (when maxLength is set).
 *
 * @param {Object} props Props: label, value, onChange, rows, maxLength, help, error.
 * @return {Element} Field.
 */
export function Textarea( { label, value, onChange, help, error, required, hideLabel, id: givenId, rows = 4, maxLength, className, ...rest } ) {
	const id = useUid( 'dev3sh-ui-textarea', givenId );
	const length = String( value || '' ).length;
	return (
		<Field
			id={ id }
			label={ label }
			help={ help }
			error={ error }
			required={ required }
			hideLabel={ hideLabel }
			className={ className }
			after={ maxLength ? <span className={ cx( 'dev3sh-ui-field__count', length > maxLength * 0.9 && 'is-near' ) }>{ `${ length }/${ maxLength }` }</span> : null }
		>
			{ ( a11y ) => (
				<textarea
					id={ id }
					className="dev3sh-ui-input dev3sh-ui-textarea"
					rows={ rows }
					value={ value || '' }
					maxLength={ maxLength }
					onChange={ ( e ) => onChange && onChange( e.target.value ) }
					required={ required }
					{ ...a11y }
					{ ...rest }
				/>
			) }
		</Field>
	);
}

/**
 * Native select. options: [ { value, label, disabled } ] or [ { label, options: [...] } ] for groups.
 *
 * @param {Object} props Props: label, value, onChange, options, placeholder, help, error.
 * @return {Element} Field.
 */
export function Select( { label, value, onChange, options = [], placeholder, help, error, required, hideLabel, id: givenId, className, ...rest } ) {
	const id = useUid( 'dev3sh-ui-select', givenId );
	const renderOption = ( o ) => (
		<option key={ String( o.value ) } value={ o.value } disabled={ o.disabled }>
			{ o.label }
		</option>
	);
	return (
		<Field id={ id } label={ label } help={ help } error={ error } required={ required } hideLabel={ hideLabel } className={ className }>
			{ ( a11y ) => (
				<div className="dev3sh-ui-selectwrap">
					<select id={ id } className="dev3sh-ui-input dev3sh-ui-select" value={ value === null || value === undefined ? '' : value } onChange={ ( e ) => onChange && onChange( e.target.value ) } required={ required } { ...a11y } { ...rest }>
						{ placeholder !== undefined && (
							<option value="" disabled={ required }>
								{ placeholder }
							</option>
						) }
						{ options.map( ( o, i ) =>
							o.options ? (
								<optgroup key={ `g${ i }` } label={ o.label }>
									{ o.options.map( renderOption ) }
								</optgroup>
							) : (
								renderOption( o )
							)
						) }
					</select>
				</div>
			) }
		</Field>
	);
}

/**
 * Switch (role="switch"). onChange receives a boolean.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.label    Label (clickable).
 * @param {boolean} props.checked  State.
 * @param {*}       props.help     Help text.
 * @param {boolean} props.disabled Disabled.
 * @return {Element} Toggle.
 */
export function Toggle( { label, checked, onChange, help, disabled, id: givenId, className } ) {
	const id = useUid( 'dev3sh-ui-toggle', givenId );
	const helpId = help ? `${ id }-help` : undefined;
	return (
		<div className={ cx( 'dev3sh-ui-toggle', disabled && 'is-disabled', className ) }>
			<button
				type="button"
				id={ id }
				role="switch"
				aria-checked={ !! checked }
				aria-labelledby={ `${ id }-label` }
				aria-describedby={ helpId }
				disabled={ disabled }
				className={ cx( 'dev3sh-ui-toggle__track', checked && 'is-on' ) }
				onClick={ () => onChange && onChange( ! checked ) }
			>
				<span className="dev3sh-ui-toggle__thumb" />
			</button>
			<div className="dev3sh-ui-toggle__text">
				{ /* A <label> for the button, so clicking the text toggles too. */ }
				<label htmlFor={ id } id={ `${ id }-label` } className="dev3sh-ui-toggle__label">
					{ label }
				</label>
				{ help && (
					<p className="dev3sh-ui-field__help" id={ helpId }>
						{ help }
					</p>
				) }
			</div>
		</div>
	);
}

/**
 * Checkbox. onChange receives a boolean.
 *
 * @param {Object} props Props: label, checked, onChange, help, disabled.
 * @return {Element} Checkbox.
 */
export function Checkbox( { label, checked, onChange, help, disabled, id: givenId, className } ) {
	const id = useUid( 'dev3sh-ui-check', givenId );
	return (
		<div className={ cx( 'dev3sh-ui-check', className ) }>
			<input id={ id } type="checkbox" className="dev3sh-ui-check__input" checked={ !! checked } disabled={ disabled } onChange={ ( e ) => onChange && onChange( e.target.checked ) } aria-describedby={ help ? `${ id }-help` : undefined } />
			<div>
				<label htmlFor={ id } className="dev3sh-ui-check__label">
					{ label }
				</label>
				{ help && (
					<p className="dev3sh-ui-field__help" id={ `${ id }-help` }>
						{ help }
					</p>
				) }
			</div>
		</div>
	);
}

const HEX = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;

/**
 * Colour picker + hex input. onChange receives "#rrggbb" (or the raw text while typing).
 *
 * @param {Object} props Props: label, value, onChange, help, error.
 * @return {Element} Field.
 */
export function ColorField( { label, value, onChange, help, error, id: givenId, className } ) {
	const id = useUid( 'dev3sh-ui-color', givenId );
	const valid = HEX.test( value || '' );
	return (
		<Field id={ id } label={ label } help={ help } error={ error || ( value && ! valid ? __( 'Use a hex colour like #4f46e5.', 'dev3-ai-site-health-analyst' ) : undefined ) } className={ className }>
			{ ( a11y ) => (
				<div className="dev3sh-ui-color">
					<input type="color" className="dev3sh-ui-color__swatch" value={ valid ? expandHex( value ) : '#000000' } onChange={ ( e ) => onChange && onChange( e.target.value ) } aria-label={ `${ label } – ${ __( 'picker', 'dev3-ai-site-health-analyst' ) }` } />
					<input id={ id } type="text" className="dev3sh-ui-input" value={ value || '' } onChange={ ( e ) => onChange && onChange( e.target.value.trim() ) } placeholder="#4f46e5" maxLength={ 7 } spellCheck={ false } { ...a11y } />
				</div>
			) }
		</Field>
	);
}

/**
 * #abc → #aabbcc.
 *
 * @param {string} hex Hex.
 * @return {string} Six-digit hex.
 */
export function expandHex( hex ) {
	const h = String( hex || '' );
	return h.length === 4 ? `#${ h[ 1 ] }${ h[ 1 ] }${ h[ 2 ] }${ h[ 2 ] }${ h[ 3 ] }${ h[ 3 ] }`.toLowerCase() : h.toLowerCase();
}

/**
 * Two-column form grid on wide screens, one column on narrow ones.
 *
 * @param {Object} props Props.
 * @return {Element} Grid.
 */
export function FormGrid( { className, children } ) {
	return <div className={ cx( 'dev3sh-ui-formgrid', className ) }>{ children }</div>;
}
