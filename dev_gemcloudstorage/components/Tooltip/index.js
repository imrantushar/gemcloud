import React, { useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * Shows the full text of something cut short, on hover and keyboard focus.
 * Rendered on <body> so a scrolling table can't clip it; theme colours come
 * from html[data-theme].
 */
const Tooltip = ( { content, children, className = '' } ) => {
	const ref = useRef( null );
	const id = useId();
	const [ pos, setPos ] = useState( null );

	const show = () => {
		const rect = ref.current?.getBoundingClientRect();
		if ( rect ) {
			setPos( { left: rect.left + rect.width / 2, top: rect.top, bottom: rect.bottom } );
		}
	};
	const hide = () => setPos( null );

	if ( ! content ) {
		return <span className={ className }>{ children }</span>;
	}

	// Above the element, or below it when there is no room at the top.
	const below = pos && pos.top < 64;

	return (
		<>
			<span
				ref={ ref }
				className={ className }
				tabIndex={ 0 }
				aria-describedby={ pos ? id : undefined }
				onMouseEnter={ show }
				onMouseLeave={ hide }
				onFocus={ show }
				onBlur={ hide }
			>
				{ children }
			</span>
			{ pos &&
				createPortal(
					<div
						id={ id }
						role="tooltip"
						className="gcs-tooltip"
						style={ {
							left: Math.min( Math.max( pos.left, 16 ), window.innerWidth - 16 ),
							top: below ? pos.bottom + 8 : pos.top - 8,
							transform: below ? 'translateX(-50%)' : 'translate(-50%, -100%)',
						} }
					>
						{ content }
					</div>,
					document.body
				) }
		</>
	);
};

export default Tooltip;
