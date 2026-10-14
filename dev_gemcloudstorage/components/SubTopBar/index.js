import React from 'react';

export default function SubTopBar( { heading, description, children } ) {
	return (
		<div className="gemcloudstorage-page-content mb-[24px] flex flex-wrap items-center justify-between gap-4">
			<div className="min-w-0">
				<h1 className="text-[20px] font-[500] leading-[30px] text-[var(--gcs-font-color)]">{ heading }</h1>
				{ description && (
					<p className="mt-1 text-[13px] text-[var(--gcs-font-secondary-color)]">{ description }</p>
				) }
			</div>
			{ children && <div className="flex flex-wrap items-center gap-3">{ children }</div> }
		</div>
	);
}
