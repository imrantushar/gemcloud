import React from 'react';
import { Lock } from 'lucide-react';
import { __ } from '@wordpress/i18n';

/**
 * Label, control and help text. `locked` marks a value set in wp-config.php.
 */
const Field = ( { label, help, required, locked, children, className = '' } ) => (
	<div className={ className }>
		{ label && (
			<span className="gcs-label flex items-center gap-1.5">
				{ label }
				{ required && <span className="text-[var(--gcs-danger)]">*</span> }
				{ locked && (
					<span
						className="inline-flex items-center gap-1 rounded-full bg-[var(--gcs-secondary-color)] px-2 py-0.5 text-[11px] font-medium text-[var(--gcs-font-secondary-color)]"
						title={ __( 'Set by a constant in wp-config.php', 'gemcloudstorage' ) }
					>
						<Lock size={ 11 } />
						wp-config.php
					</span>
				) }
			</span>
		) }
		{ children }
		{ help && <p className="gcs-help">{ help }</p> }
	</div>
);

export default Field;
