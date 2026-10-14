import React from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ChevronLeft, ChevronRight } from 'lucide-react';

const Pagination = ( { page, perPage, total, onChange } ) => {
	const pages = Math.max( 1, Math.ceil( total / perPage ) );
	const from = total ? ( page - 1 ) * perPage + 1 : 0;
	const to = Math.min( total, page * perPage );

	return (
		<div className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--gcs-border-color)] px-4 py-3 text-[13px] text-[var(--gcs-font-secondary-color)]">
			{ /* translators: 1: first row, 2: last row, 3: total rows */ }
			<span>{ sprintf( __( '%1$d–%2$d of %3$d', 'gemcloudstorage' ), from, to, total ) }</span>
			<div className="flex items-center gap-2">
				<button type="button" className="gcs-btn gcs-btn--outline gcs-btn--sm" disabled={ page <= 1 } onClick={ () => onChange( page - 1 ) } aria-label={ __( 'Previous page', 'gemcloudstorage' ) }>
					<ChevronLeft size={ 14 } />
				</button>
				{ /* translators: 1: current page, 2: page count */ }
				<span>{ sprintf( __( 'Page %1$d of %2$d', 'gemcloudstorage' ), page, pages ) }</span>
				<button type="button" className="gcs-btn gcs-btn--outline gcs-btn--sm" disabled={ page >= pages } onClick={ () => onChange( page + 1 ) } aria-label={ __( 'Next page', 'gemcloudstorage' ) }>
					<ChevronRight size={ 14 } />
				</button>
			</div>
		</div>
	);
};

export default Pagination;
