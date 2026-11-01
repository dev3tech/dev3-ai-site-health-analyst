import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Checkbox, ErrorNotice, Modal, Notice, Skeleton, useToast } from '../core3/ui';

const D = 'dev3-ai-site-health-analyst';

/**
 * Confirm-before-apply dialog for one-click fixes: preview (server token) → explicit checkbox → apply.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.fix      { type, target, label } or null.
 * @param {number}   props.issueId  Issue id (for the log).
 * @param {Function} props.onClose  Close.
 * @param {Function} props.onDone   After a successful apply.
 * @return {Element} Modal.
 */
export default function FixModal( { fix, issueId = 0, onClose, onDone } ) {
	const toast = useToast();
	const [ plan, setPlan ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ agree, setAgree ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		setPlan( null );
		setError( null );
		setAgree( false );
		if ( ! fix ) {
			return;
		}
		request( 'fixes/preview', { method: 'POST', data: { type: fix.type, target: fix.target, issue_id: issueId } } )
			.then( setPlan )
			.catch( setError );
	}, [ fix && fix.type, fix && fix.target ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const apply = () => {
		setBusy( true );
		request( 'fixes/apply', { method: 'POST', data: { token: plan.token, confirm: true } } )
			.then( ( r ) => {
				toast.success( r.message || __( 'Fix applied.', D ) );
				onDone( r );
				onClose();
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};

	const disabled = error && error.code === 'dev3sh_fixes_disabled';

	return (
		<Modal
			open={ !! fix }
			onClose={ onClose }
			size="md"
			title={ plan ? plan.title : ( fix && fix.label ) || __( 'One-click fix', D ) }
			description={ __( 'Nothing changes until you confirm. Every fix is logged and can be undone under Reports & alerts → Fix history.', D ) }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', D ) }
					</Button>
					{ ! disabled && (
						<Button variant="danger" disabled={ ! plan || ! agree } loading={ busy } onClick={ apply } data-testid="dev3sh-apply-fix">
							{ __( 'Apply fix', D ) }
						</Button>
					) }
				</>
			}
		>
			{ disabled && (
				<Notice
					tone="info"
					title={ __( 'Read-only mode is on', D ) }
					actions={
						<Button size="sm" href="#/settings" onClick={ onClose }>
							{ __( 'Open Settings', D ) }
						</Button>
					}
				>
					{ __( 'This plugin never changes your site unless you allow it. Turn on "Allow one-click fixes" in Settings to use fixes, or apply the steps by hand.', D ) }
				</Notice>
			) }
			{ error && ! disabled && <ErrorNotice error={ error } /> }
			{ ! plan && ! error && <Skeleton lines={ 3 } /> }
			{ plan && (
				<div className="dev3sh-fixplan">
					<p>{ plan.description }</p>
					{ plan.effects && plan.effects.length > 0 && (
						<>
							<p className="dev3sh-label">{ __( 'What to expect', D ) }</p>
							<ul className="dev3sh-bullets">
								{ plan.effects.map( ( e, i ) => (
									<li key={ i }>{ e }</li>
								) ) }
							</ul>
						</>
					) }
					<Checkbox label={ __( 'I understand that this changes my site, and I want to apply it now.', D ) } checked={ agree } onChange={ setAgree } />
				</div>
			) }
		</Modal>
	);
}
