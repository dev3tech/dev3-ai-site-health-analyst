import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Icon from './icons';
import { Button } from './Button';
import { Badge, Notice } from './feedback';
import { Tabs } from './Tabs';
import { Textarea, TextField } from './fields';
import { diffLines, diffWords, toJson } from './diff';
import { cx, formatNumber } from './utils';

/**
 * Renders diff parts with <ins>/<del> (screen readers announce insertions and deletions).
 *
 * @param {Object} props       Props.
 * @param {Array}  props.parts Parts from diffWords/diffLines.
 * @param {boolean} props.block Pre-formatted (JSON/lines).
 * @return {Element} Diff.
 */
export function DiffView( { parts, block = false } ) {
	return (
		<div className={ cx( 'dev3sh-ui-diff', block && 'is-block' ) }>
			{ parts.map( ( p, i ) => {
				if ( p.type === 'add' ) {
					return <ins key={ i }>{ p.value }</ins>;
				}
				if ( p.type === 'del' ) {
					return <del key={ i }>{ p.value }</del>;
				}
				return <span key={ i }>{ p.value }</span>;
			} ) }
		</div>
	);
}

const asText = ( value, format ) => ( format === 'json' ? toJson( value ) : String( value === null || value === undefined ? '' : value ) );

/**
 * Review-before-apply for AI output. Nothing is saved until the admin clicks Accept.
 *
 * Modes:
 * - Text/JSON: `draft` (+ optional `original`) with format 'text' | 'json'. Views: Changes (diff), Side by side, Draft.
 * - Fields: `fields=[ { key, label, multiline, maxLength } ]` with `draft`/`original` objects: per-field diff + inline edit.
 *
 * @param {Object}   props              Props.
 * @param {string}   props.title        Title (default "DEV3 AI draft").
 * @param {*}        props.draft        Draft value (string, object for json/fields).
 * @param {*}        props.original     Current value to compare against (optional).
 * @param {string}   props.format       text | json.
 * @param {Array}    props.fields       Field definitions (fields mode).
 * @param {Function} props.onAccept     ( value ) => void|Promise. Receives the edited value.
 * @param {Function} props.onReject     () => void.
 * @param {Function} props.onRegenerate () => void.
 * @param {boolean}  props.regenerating Busy state for Regenerate.
 * @param {boolean}  props.accepting    Busy state for Accept.
 * @param {Object}   props.meta         { model, tokens, cached } footer info.
 * @param {string}   props.acceptLabel  Accept button text.
 * @param {boolean}  props.editable     Allow editing (default true).
 * @return {Element} Review panel.
 */
export function AiDraftReview( {
	title = __( 'DEV3 AI draft', 'dev3-ai-site-health-analyst' ),
	draft,
	original,
	format = 'text',
	fields,
	onAccept,
	onReject,
	onRegenerate,
	regenerating = false,
	accepting = false,
	meta,
	acceptLabel = __( 'Accept', 'dev3-ai-site-health-analyst' ),
	editable = true,
	className,
} ) {
	const hasOriginal = original !== undefined && original !== null && ( fields ? true : asText( original, format ) !== '' );
	const [ view, setView ] = useState( hasOriginal ? 'diff' : 'draft' );
	const [ editing, setEditing ] = useState( false );
	const [ text, setText ] = useState( () => asText( draft, format ) );
	const [ values, setValues ] = useState( () => ( fields ? { ...( draft || {} ) } : null ) );
	const [ error, setError ] = useState( '' );

	// A regenerated draft replaces local edits.
	useEffect( () => {
		setText( asText( draft, format ) );
		setValues( fields ? { ...( draft || {} ) } : null );
		setEditing( false );
		setError( '' );
	}, [ draft ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const parts = useMemo( () => {
		if ( fields || ! hasOriginal ) {
			return [];
		}
		return format === 'json' ? diffLines( asText( original, format ), text ) : diffWords( asText( original, format ), text );
	}, [ fields, hasOriginal, original, text, format ] );

	const accept = () => {
		setError( '' );
		if ( fields ) {
			onAccept( values );
			return;
		}
		if ( format === 'json' ) {
			try {
				onAccept( JSON.parse( text ) );
			} catch ( e ) {
				setEditing( true );
				setError( __( 'The JSON is not valid. Fix it or regenerate.', 'dev3-ai-site-health-analyst' ) );
			}
			return;
		}
		onAccept( text );
	};

	const busy = regenerating || accepting;
	const views = [
		{ id: 'diff', label: __( 'Changes', 'dev3-ai-site-health-analyst' ), disabled: ! hasOriginal },
		{ id: 'side', label: __( 'Side by side', 'dev3-ai-site-health-analyst' ), disabled: ! hasOriginal },
		{ id: 'draft', label: __( 'Draft', 'dev3-ai-site-health-analyst' ) },
	];

	return (
		<section className={ cx( 'dev3sh-ui-draft', regenerating && 'is-busy', className ) } aria-label={ title } aria-busy={ regenerating || undefined }>
			<header className="dev3sh-ui-draft__head">
				<div className="dev3sh-ui-draft__title">
					<span className="dev3sh-ui-draft__spark" aria-hidden="true">
						<Icon name="sparkles" size={ 16 } />
					</span>
					<h3>{ title }</h3>
					<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-site-health-analyst' ) }</Badge>
				</div>
				{ ! fields && hasOriginal && ! editing && <Tabs tabs={ views } value={ view } onChange={ setView } variant="pills" label={ __( 'Draft view', 'dev3-ai-site-health-analyst' ) } /> }
			</header>

			<div className="dev3sh-ui-draft__body">
				{ error && <Notice tone="error">{ error }</Notice> }
				{ fields && (
					<div className="dev3sh-ui-draft__fields">
						{ fields.map( ( f ) => {
							const before = original ? String( original[ f.key ] ?? '' ) : '';
							const after = String( values[ f.key ] ?? '' );
							const Control = f.multiline ? Textarea : TextField;
							return (
								<div key={ f.key } className="dev3sh-ui-draft__field">
									{ editing ? (
										<Control label={ f.label } value={ after } maxLength={ f.maxLength } rows={ 4 } onChange={ ( v ) => setValues( { ...values, [ f.key ]: v } ) } />
									) : (
										<>
											<p className="dev3sh-ui-draft__label">{ f.label }</p>
											{ hasOriginal && before !== '' && before !== after ? <DiffView parts={ diffWords( before, after ) } /> : <div className="dev3sh-ui-draft__value">{ after || '—' }</div> }
										</>
									) }
								</div>
							);
						} ) }
					</div>
				) }
				{ ! fields && editing && (
					<Textarea
						label={ format === 'json' ? __( 'Edit JSON', 'dev3-ai-site-health-analyst' ) : __( 'Edit draft', 'dev3-ai-site-health-analyst' ) }
						value={ text }
						onChange={ setText }
						rows={ Math.min( 18, Math.max( 6, text.split( '\n' ).length + 1 ) ) }
						className={ format === 'json' ? 'is-mono' : '' }
						spellCheck={ format !== 'json' }
					/>
				) }
				{ ! fields && ! editing && view === 'diff' && hasOriginal && <DiffView parts={ parts } block={ format === 'json' } /> }
				{ ! fields && ! editing && view === 'side' && hasOriginal && (
					<div className="dev3sh-ui-draft__side">
						<div>
							<p className="dev3sh-ui-draft__label">{ __( 'Current', 'dev3-ai-site-health-analyst' ) }</p>
							<div className={ cx( 'dev3sh-ui-draft__value', format === 'json' && 'is-mono' ) }>{ asText( original, format ) }</div>
						</div>
						<div>
							<p className="dev3sh-ui-draft__label">{ __( 'DEV3 AI draft', 'dev3-ai-site-health-analyst' ) }</p>
							<div className={ cx( 'dev3sh-ui-draft__value is-draft', format === 'json' && 'is-mono' ) }>{ text }</div>
						</div>
					</div>
				) }
				{ ! fields && ! editing && ( view === 'draft' || ! hasOriginal ) && <div className={ cx( 'dev3sh-ui-draft__value is-draft', format === 'json' && 'is-mono' ) }>{ text }</div> }
			</div>

			<footer className="dev3sh-ui-draft__foot">
				<div className="dev3sh-ui-draft__meta">
					{ meta && meta.model && <span>{ meta.model }</span> }
					{ meta && meta.tokens > 0 && <span>{ sprintf( /* translators: %s: token count */ __( '%s tokens', 'dev3-ai-site-health-analyst' ), formatNumber( meta.tokens ) ) }</span> }
					{ meta && meta.cached && <Badge tone="info">{ __( 'Cached', 'dev3-ai-site-health-analyst' ) }</Badge> }
				</div>
				<div className="dev3sh-ui-draft__actions">
					{ onReject && (
						<Button variant="ghost" icon="x" onClick={ onReject } disabled={ busy }>
							{ __( 'Reject', 'dev3-ai-site-health-analyst' ) }
						</Button>
					) }
					{ onRegenerate && (
						<Button icon="refresh" onClick={ onRegenerate } loading={ regenerating } disabled={ busy }>
							{ __( 'Regenerate', 'dev3-ai-site-health-analyst' ) }
						</Button>
					) }
					{ editable && (
						<Button icon={ editing ? 'check' : 'edit' } onClick={ () => setEditing( ! editing ) } disabled={ busy } aria-pressed={ editing }>
							{ editing ? __( 'Done editing', 'dev3-ai-site-health-analyst' ) : __( 'Edit', 'dev3-ai-site-health-analyst' ) }
						</Button>
					) }
					<Button variant="primary" icon="check" onClick={ accept } loading={ accepting } disabled={ busy }>
						{ acceptLabel }
					</Button>
				</div>
			</footer>
		</section>
	);
}

export default AiDraftReview;
