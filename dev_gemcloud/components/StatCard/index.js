import React from 'react';

/**
 * Metric card, the same layout as Zaplane's dashboard StatCard.
 */
const StatCard = ( { title, value, hint, icon, isLoading, tone } ) => (
	<div className="flex h-[130px] min-w-[220px] flex-[1] rounded-[8px] border border-[var(--gcs-border-color)] bg-[var(--gcs-background)] p-6">
		<div className="flex w-full flex-col justify-between">
			<div className="flex items-start justify-between">
				{ isLoading ? (
					<div className="gcs-skeleton h-4 w-24" />
				) : (
					<span className="text-[15px] font-[500] text-[var(--gcs-font-secondary-color)]">{ title }</span>
				) }
				{ isLoading ? (
					<div className="gcs-skeleton h-6 w-6" />
				) : (
					<span style={ { color: tone ? `var(--gcs-${ tone })` : 'var(--gcs-font-color)' } }>
						{ React.createElement( icon, { size: 22 } ) }
					</span>
				) }
			</div>
			{ isLoading ? (
				<div className="gcs-skeleton h-9 w-20" />
			) : (
				<div className="flex items-baseline gap-2">
					<span className="text-[30px] font-[600] leading-none text-[var(--gcs-font-color)]">{ value ?? 0 }</span>
					{ hint && <span className="text-[13px] text-[var(--gcs-font-secondary-color)]">{ hint }</span> }
				</div>
			) }
		</div>
	</div>
);

export default StatCard;
