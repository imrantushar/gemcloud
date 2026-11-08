import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { __ } from '@wordpress/i18n';
import { X } from 'lucide-react';

const ConfirmModal = ( { open, title, children, confirmLabel, onConfirm, onClose, danger = false } ) => {
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const onKey = ( e ) => e.key === 'Escape' && onClose();
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ open ] );

	if ( ! open ) {
		return null;
	}

	// Tailwind utilities are scoped as `.gemcloud-scope .x`, so the
	// portal needs the scope class on a wrapper, not on the overlay itself.
	return createPortal(
		<div className="gemcloud-scope"><div
			className="fixed inset-0 z-[100000] flex items-center justify-center p-4"
			style={ { background: 'rgba(14,17,22,0.45)', fontFamily: 'var(--gcs-font)' } }
			onMouseDown={ ( e ) => e.target === e.currentTarget && onClose() }
		>
			<div
				role="dialog"
				aria-modal="true"
				className="w-full max-w-[460px] rounded-[8px] border border-[var(--gcs-border-color)] bg-[var(--gcs-background)]"
				style={ { boxShadow: 'var(--gcs-shadow-menu)', color: 'var(--gcs-font-color)' } }
			>
				<div className="flex items-center justify-between border-b border-[var(--gcs-border-color)] px-5 py-4">
					<h3 className="m-0 text-[16px] font-semibold">{ title }</h3>
					<button type="button" onClick={ onClose } aria-label={ __( 'Close', 'gemcloud' ) } className="flex border-0 bg-transparent p-1 text-[var(--gcs-text-muted)]">
						<X size={ 18 } />
					</button>
				</div>
				<div className="px-5 py-4 text-[14px] leading-[1.6] text-[var(--gcs-font-secondary-color)]">{ children }</div>
				<div className="flex justify-end gap-2 border-t border-[var(--gcs-border-color)] px-5 py-3">
					<button type="button" className="gcs-btn gcs-btn--outline" onClick={ onClose }>
						{ __( 'Cancel', 'gemcloud' ) }
					</button>
					<button
						type="button"
						className={ `gcs-btn ${ danger ? 'gcs-btn--danger' : 'gcs-btn--primary' }` }
						onClick={ () => {
							onConfirm();
							onClose();
						} }
					>
						{ confirmLabel }
					</button>
				</div>
			</div>
		</div></div>,
		document.body
	);
};

export default ConfirmModal;
