import React from 'react';

/**
 * An on/off switch, the same control as Zaplane's ZAPToggle. The track colour
 * is inline so it beats WordPress's button background.
 */
const GCSToggle = ( { checked, onChange, label, disabled = false, size = 'md' } ) => {
	const small = size === 'sm';
	return (
		<button
			type="button"
			role="switch"
			aria-checked={ !! checked }
			aria-label={ label }
			disabled={ disabled }
			onClick={ () => onChange( ! checked ) }
			style={ {
				backgroundColor: checked ? 'var(--gcs-primary)' : 'var(--gcs-border-color)',
				opacity: disabled ? 0.5 : 1,
				cursor: disabled ? 'not-allowed' : 'pointer',
			} }
			className={ `relative inline-flex ${ small ? 'h-5 w-9' : 'h-6 w-11' } shrink-0 items-center rounded-full transition-colors` }
		>
			<span
				className={ `inline-block ${ small ? 'h-[14px] w-[14px]' : 'h-[18px] w-[18px]' } transform rounded-full bg-white transition-transform ${
					checked ? ( small ? 'translate-x-[19px]' : 'translate-x-[23px]' ) : 'translate-x-[3px]'
				}` }
				style={ { boxShadow: '0 1px 3px rgba(0,0,0,0.4)' } }
			/>
		</button>
	);
};

export default GCSToggle;
