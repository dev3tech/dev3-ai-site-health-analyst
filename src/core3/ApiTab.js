import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from './api';
import { Button } from './ui/Button';
import { Card, Columns, Stack } from './ui/layout';
import { ErrorNotice, Notice } from './ui/feedback';
import { NumberField, Select, TextField, Toggle } from './ui/fields';
import { UsageMeter } from './ui/UsageMeter';
import { useToast } from './ui/Toast';

const CUSTOM = '__custom';

function ModelPicker( { label, help, value, models, onChange } ) {
	const known = models.some( ( m ) => m.id === value );
	const [ custom, setCustom ] = useState( ! known && models.length > 0 );
	if ( ! models.length || custom ) {
		return (
			<div>
				<TextField label={ label } help={ help } value={ value } onChange={ onChange } placeholder="gemini-2.5-flash" spellCheck={ false } />
				{ models.length > 0 && (
					<Button variant="link" size="sm" onClick={ () => setCustom( false ) }>
						{ __( 'Choose from list', 'dev3-ai-site-health-analyst' ) }
					</Button>
				) }
			</div>
		);
	}
	return (
		<Select
			label={ label }
			help={ help }
			value={ known ? value : CUSTOM }
			options={ [ ...models.map( ( m ) => ( { value: m.id, label: `${ m.name } (${ m.id })` } ) ), { value: CUSTOM, label: __( 'Custom model ID…', 'dev3-ai-site-health-analyst' ) } ] }
			onChange={ ( v ) => ( v === CUSTOM ? setCustom( true ) : onChange( v ) ) }
		/>
	);
}

/**
 * API Config tab: key, models, generation parameters, budget (monthly cap) and AI switches.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Public settings.
 * @param {Function} props.save     ( patch ) => Promise<settings>.
 * @param {*}        props.children Extra cards (plugin-specific AI settings).
 * @return {Element} Tab.
 */
export default function ApiTab( { settings, save, children } ) {
	const toast = useToast();
	const [ key, setKey ] = useState( settings.api_key_masked || '' );
	const [ form, setForm ] = useState( settings );
	const [ models, setModels ] = useState( [] );
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ meterKey, setMeterKey ] = useState( 0 );

	useEffect( () => {
		if ( settings.api_key_set ) {
			request( 'models' ).then( setModels ).catch( () => {} );
		}
	}, [ settings.api_key_set ] );

	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );

	const run = ( id, promise, message ) => {
		setBusy( id );
		setError( null );
		return promise
			.then( ( res ) => {
				if ( message ) {
					toast.success( message );
				}
				return res;
			} )
			.catch( ( err ) => {
				setError( err );
				toast.error( err );
			} )
			.finally( () => setBusy( '' ) );
	};

	const validateAndSave = () => {
		setBusy( 'validate' );
		setStatus( null );
		request( 'settings/validate', { method: 'POST', data: { api_key: key } } )
			.then( ( res ) => {
				setModels( res.models );
				setStatus( { ok: true, message: sprintf( /* translators: %d: number of models */ __( 'Connected — %d models available to this key.', 'dev3-ai-site-health-analyst' ), res.models.length ) } );
				return save( { api_key: key } ).then( ( next ) => setKey( next.api_key_masked ) );
			} )
			.catch( ( err ) => setStatus( { ok: false, message: err.message, hint: err.hint } ) )
			.finally( () => setBusy( '' ) );
	};

	const saveEngine = () =>
		run(
			'engine',
			save( {
				default_model: form.default_model,
				pro_model: form.pro_model,
				image_model: form.image_model,
				embedding_model: form.embedding_model,
				temperature: form.temperature,
				max_tokens: parseInt( form.max_tokens, 10 ) || 4096,
				reasoning: form.reasoning,
			} ),
			__( 'Engine settings saved.', 'dev3-ai-site-health-analyst' )
		);

	const saveBudget = () =>
		run(
			'budget',
			save( {
				monthly_cap: form.monthly_cap === '' ? 0 : form.monthly_cap,
				ai_enabled: !! form.ai_enabled,
				public_ai: !! form.public_ai,
				cache_enabled: !! form.cache_enabled,
				pii_masking: !! form.pii_masking,
			} ).then( ( next ) => {
				setMeterKey( ( n ) => n + 1 );
				return next;
			} ),
			__( 'Budget and safety settings saved.', 'dev3-ai-site-health-analyst' )
		);

	const refresh = () => run( 'models', request( 'models?refresh=1' ).then( setModels ) );

	return (
		<Stack gap={ 16 }>
			<Card
				title={ __( 'Google Gemini API key', 'dev3-ai-site-health-analyst' ) }
				description={
					<>
						{ __( 'Create a free key in Google AI Studio, paste it here and click “Validate & save”. The key is encrypted in your database and never sent to the browser.', 'dev3-ai-site-health-analyst' ) }{ ' ' }
						<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">
							{ __( 'Get an API key', 'dev3-ai-site-health-analyst' ) }
							<span className="dev3sh-ui-sr-only">{ __( '(opens in a new tab)', 'dev3-ai-site-health-analyst' ) }</span>
						</a>
					</>
				}
			>
				{ settings.api_key_constant ? (
					<Notice tone="info">{ __( 'The key is defined by the DEV3SH_GEMINI_API_KEY constant in wp-config.php.', 'dev3-ai-site-health-analyst' ) }</Notice>
				) : (
					<div className="dev3sh-ui-inline" style={ { gap: 8, alignItems: 'flex-end' } }>
						<div style={ { flex: '1 1 320px', minWidth: 0 } }>
							<TextField
								label={ __( 'API key', 'dev3-ai-site-health-analyst' ) }
								value={ key }
								onChange={ setKey }
								onFocus={ () => key.includes( '•' ) && setKey( '' ) }
								autoComplete="off"
								spellCheck={ false }
								placeholder="AIza…"
							/>
						</div>
						<Button variant="primary" onClick={ validateAndSave } loading={ busy === 'validate' } disabled={ !! busy || ! key }>
							{ __( 'Validate & save', 'dev3-ai-site-health-analyst' ) }
						</Button>
						{ settings.api_key_set && (
							<Button
								variant="ghost"
								onClick={ () =>
									run(
										'remove',
										save( { api_key: '' } ).then( () => {
											setKey( '' );
											setModels( [] );
										} ),
										__( 'API key removed.', 'dev3-ai-site-health-analyst' )
									)
								}
								disabled={ !! busy }
							>
								{ __( 'Remove key', 'dev3-ai-site-health-analyst' ) }
							</Button>
						) }
					</div>
				) }
				{ ! settings.encryption_available && <Notice tone="warning">{ __( 'OpenSSL is not available on this server, so the key is only obfuscated. Consider defining DEV3SH_GEMINI_API_KEY in wp-config.php instead.', 'dev3-ai-site-health-analyst' ) }</Notice> }
				{ status && (
					<Notice tone={ status.ok ? 'success' : 'error' } title={ status.message }>
						{ status.hint }
					</Notice>
				) }
			</Card>

			<Card
				title={ __( 'Models & generation', 'dev3-ai-site-health-analyst' ) }
				description={ __( 'Fast is used for most tasks; Pro for complex, long-form or structured work.', 'dev3-ai-site-health-analyst' ) }
				actions={
					<Button size="sm" icon="refresh" onClick={ refresh } loading={ busy === 'models' } disabled={ ! settings.api_key_set || !! busy }>
						{ __( 'Refresh models', 'dev3-ai-site-health-analyst' ) }
					</Button>
				}
				footer={
					<Button variant="primary" onClick={ saveEngine } loading={ busy === 'engine' } disabled={ !! busy }>
						{ __( 'Save engine settings', 'dev3-ai-site-health-analyst' ) }
					</Button>
				}
			>
				<Columns min={ 340 }>
					<ModelPicker label={ __( 'Fast model', 'dev3-ai-site-health-analyst' ) } help={ __( 'Recommended: gemini-2.5-flash', 'dev3-ai-site-health-analyst' ) } value={ form.default_model } models={ models } onChange={ set( 'default_model' ) } />
					<ModelPicker label={ __( 'Pro model', 'dev3-ai-site-health-analyst' ) } help={ __( 'Recommended: gemini-2.5-pro', 'dev3-ai-site-health-analyst' ) } value={ form.pro_model } models={ models } onChange={ set( 'pro_model' ) } />
					<TextField label={ __( 'Image model', 'dev3-ai-site-health-analyst' ) } help={ __( 'Used for image generation. Default: gemini-2.5-flash-image', 'dev3-ai-site-health-analyst' ) } value={ form.image_model || '' } onChange={ set( 'image_model' ) } spellCheck={ false } />
					<TextField label={ __( 'Embedding model', 'dev3-ai-site-health-analyst' ) } help={ __( 'Used for semantic search. Default: gemini-embedding-001', 'dev3-ai-site-health-analyst' ) } value={ form.embedding_model || '' } onChange={ set( 'embedding_model' ) } spellCheck={ false } />
				</Columns>
				<Columns min={ 200 }>
					<NumberField label={ __( 'Temperature', 'dev3-ai-site-health-analyst' ) } help={ __( 'Lower = focused and consistent, higher = more creative (0–1).', 'dev3-ai-site-health-analyst' ) } value={ form.temperature } onChange={ set( 'temperature' ) } min={ 0 } max={ 1 } step={ 0.05 } />
					<NumberField label={ __( 'Max output tokens', 'dev3-ai-site-health-analyst' ) } help={ __( 'Upper limit per answer.', 'dev3-ai-site-health-analyst' ) } value={ form.max_tokens } onChange={ set( 'max_tokens' ) } min={ 256 } max={ 65536 } step={ 256 } />
					<Select
						label={ __( 'Reasoning', 'dev3-ai-site-health-analyst' ) }
						help={ __( '“Low” keeps generations fast and cheap; “Auto” lets the model think longer.', 'dev3-ai-site-health-analyst' ) }
						value={ form.reasoning }
						options={ [
							{ value: 'low', label: __( 'Low (fast)', 'dev3-ai-site-health-analyst' ) },
							{ value: 'auto', label: __( 'Auto (model decides)', 'dev3-ai-site-health-analyst' ) },
						] }
						onChange={ set( 'reasoning' ) }
					/>
				</Columns>
			</Card>

			<Card
				title={ __( 'Budget & safety', 'dev3-ai-site-health-analyst' ) }
				description={ __( 'Costs are estimated from token counts and Google’s list prices. Check exact billing in Google AI Studio.', 'dev3-ai-site-health-analyst' ) }
				footer={
					<Button variant="primary" onClick={ saveBudget } loading={ busy === 'budget' } disabled={ !! busy }>
						{ __( 'Save budget & safety', 'dev3-ai-site-health-analyst' ) }
					</Button>
				}
			>
				<UsageMeter refreshKey={ meterKey } />
				<Columns min={ 260 }>
					<NumberField
						label={ __( 'Monthly AI budget cap', 'dev3-ai-site-health-analyst' ) }
						help={ __( 'AI pauses when the estimated spend this month reaches the cap. 0 = no cap.', 'dev3-ai-site-health-analyst' ) }
						prefix="$"
						value={ form.monthly_cap }
						onChange={ set( 'monthly_cap' ) }
						min={ 0 }
						step={ 1 }
					/>
					<Stack gap={ 12 }>
						<Toggle label={ __( 'AI features', 'dev3-ai-site-health-analyst' ) } help={ __( 'Turn off to stop every AI call. The plugin keeps working without AI.', 'dev3-ai-site-health-analyst' ) } checked={ form.ai_enabled !== false } onChange={ set( 'ai_enabled' ) } />
						<Toggle label={ __( 'Storefront AI', 'dev3-ai-site-health-analyst' ) } help={ __( 'Kill switch for visitor-facing AI. Visitors get the non-AI fallback.', 'dev3-ai-site-health-analyst' ) } checked={ form.public_ai !== false } onChange={ set( 'public_ai' ) } />
						<Toggle label={ __( 'Cache AI results', 'dev3-ai-site-health-analyst' ) } help={ __( 'Reuse answers for identical requests to save tokens.', 'dev3-ai-site-health-analyst' ) } checked={ form.cache_enabled !== false } onChange={ set( 'cache_enabled' ) } />
						<Toggle label={ __( 'Mask personal data', 'dev3-ai-site-health-analyst' ) } help={ __( 'Replace emails, phone and card numbers before text is sent to Google Gemini.', 'dev3-ai-site-health-analyst' ) } checked={ !! form.pii_masking } onChange={ set( 'pii_masking' ) } />
					</Stack>
				</Columns>
				<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
			</Card>
			{ children }
		</Stack>
	);
}
