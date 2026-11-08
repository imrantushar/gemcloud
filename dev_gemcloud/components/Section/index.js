import React from 'react';

/**
 * A titled card. Settings tabs and dashboard panels are built from these.
 */
const Section = ( { title, description, actions, children, className = '', bodyClassName = 'p-6' } ) => (
	<div className={ `gemcloud-card ${ className }` }>
		{ ( title || actions ) && (
			<div className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--gemcloud-border-color)] px-6 py-4">
				<div className="min-w-0">
					<h3 className="text-[15px] font-semibold text-[var(--gemcloud-font-color)]">{ title }</h3>
					{ description && <p className="mt-1 text-[13px] text-[var(--gemcloud-font-secondary-color)]">{ description }</p> }
				</div>
				{ actions && <div className="flex items-center gap-2">{ actions }</div> }
			</div>
		) }
		<div className={ bodyClassName }>{ children }</div>
	</div>
);

export default Section;
