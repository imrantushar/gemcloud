import React from 'react';
import Select from 'react-select';

/**
 * react-select with ZenCommunity's look (styles: .gcs-rs in _select.scss):
 * a rounded control, a floating menu, a light hover tint and a check on the
 * chosen option. Takes and returns plain values, like a native <select>.
 *
 * The menu is rendered on <body> so cards with overflow:hidden can't clip it.
 */
const GCSSelect = ( {
	value,
	onChange,
	options,
	width,
	placeholder,
	isSearchable = false,
	isDisabled = false,
	className = '',
	...rest
} ) => {
	const selected = options.find( ( o ) => String( o.value ) === String( value ) ) ?? null;

	return (
		<div className={ className } style={ width ? { width } : undefined }>
			<Select
				className="gcs-rs"
				classNamePrefix="gcs-rs"
				value={ selected }
				options={ options }
				placeholder={ placeholder }
				isSearchable={ isSearchable }
				isDisabled={ isDisabled }
				onChange={ ( opt ) => onChange( opt ? opt.value : '' ) }
				menuPortalTarget={ typeof document !== 'undefined' ? document.body : null }
				menuPosition="fixed"
				styles={ { menuPortal: ( base ) => ( { ...base, zIndex: 100002 } ) } }
				{ ...rest }
			/>
		</div>
	);
};

export default GCSSelect;
