import React, { useEffect, useMemo, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { __, sprintf } from '@wordpress/i18n';
import { ExternalLink, FileText, Lock, RotateCcw, Search, Pencil } from 'lucide-react';
import PageLayout from '@GCSComponents/PageLayout';
import StatusPill from '@GCSComponents/StatusPill';
import Pagination from '@GCSComponents/Pagination';
import ConfirmModal from '@GCSComponents/ConfirmModal';
import { getItems, retryItems } from '@GCSRedux/Slices/itemsSlice/itemsSlice';
import { formatDateTime, useQuery } from '@GCSUtils/helper';

const STATUS_TABS = [
	{ value: '', label: __( 'All', 'gemcloudstorage' ) },
	{ value: 'synced', label: __( 'Synced', 'gemcloudstorage' ) },
	{ value: 'pending', label: __( 'Pending', 'gemcloudstorage' ) },
	{ value: 'uploading', label: __( 'Uploading', 'gemcloudstorage' ) },
	{ value: 'failed', label: __( 'Failed', 'gemcloudstorage' ) },
];

const PER_PAGE = 20;

const Thumb = ( { row } ) =>
	row.thumb ? (
		<img src={ row.thumb } alt="" width="40" height="40" className="h-10 w-10 shrink-0 rounded-[4px] border border-[var(--gcs-border-color)] object-cover" />
	) : (
		<span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-[4px] border border-[var(--gcs-border-color)] bg-[var(--gcs-secondary-color)] text-[var(--gcs-text-muted)]">
			<FileText size={ 18 } />
		</span>
	);

export default function Files() {
	const dispatch = useDispatch();
	const query = useQuery();
	const { data, total, loading } = useSelector( ( state ) => state.items );

	const [ status, setStatus ] = useState( query.get( 'status' ) || '' );
	const [ visibility, setVisibility ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ term, setTerm ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ selected, setSelected ] = useState( [] );
	const [ confirmAll, setConfirmAll ] = useState( false );

	// Debounce the search box.
	useEffect( () => {
		const id = setTimeout( () => {
			setTerm( search.trim() );
			setPage( 1 );
		}, 350 );
		return () => clearTimeout( id );
	}, [ search ] );

	const params = useMemo( () => ( { status, visibility, search: term, page, per_page: PER_PAGE } ), [ status, visibility, term, page ] );

	const load = () => dispatch( getItems( params ) );

	useEffect( () => {
		load();
		setSelected( [] );
	}, [ params ] );

	const failedIds = data.filter( ( r ) => r.status === 'failed' ).map( ( r ) => r.id );
	const allChecked = data.length > 0 && selected.length === data.length;

	const retry = async ( body ) => {
		await dispatch( retryItems( body ) );
		setSelected( [] );
		load();
	};

	return (
		<PageLayout
			title={ __( 'Files', 'gemcloudstorage' ) }
			description={ __( 'Every object in your buckets, one row per file and image size.', 'gemcloudstorage' ) }
			actions={
				<button type="button" className="gcs-btn gcs-btn--outline" onClick={ () => setConfirmAll( true ) }>
					<RotateCcw size={ 15 } />
					{ __( 'Retry all failed', 'gemcloudstorage' ) }
				</button>
			}
		>
			<div className="gcs-card overflow-hidden">
				<div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--gcs-border-color)] px-4 py-3">
					<div className="gcs-tabs">
						{ STATUS_TABS.map( ( t ) => (
							<span
								key={ t.value || 'all' }
								role="button"
								tabIndex={ 0 }
								className={ `tab ${ status === t.value ? 'is-active' : '' }` }
								onClick={ () => {
									setStatus( t.value );
									setPage( 1 );
								} }
								onKeyDown={ ( e ) => e.key === 'Enter' && setStatus( t.value ) }
							>
								{ t.label }
							</span>
						) ) }
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<select className="gcs-select !w-[150px]" value={ visibility } onChange={ ( e ) => { setVisibility( e.target.value ); setPage( 1 ); } } aria-label={ __( 'Visibility', 'gemcloudstorage' ) }>
							<option value="">{ __( 'Public and private', 'gemcloudstorage' ) }</option>
							<option value="public">{ __( 'Public', 'gemcloudstorage' ) }</option>
							<option value="private">{ __( 'Private', 'gemcloudstorage' ) }</option>
						</select>
						<div className="relative">
							<Search size={ 15 } className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--gcs-text-muted)]" />
							<input
								type="search"
								className="gcs-input !w-[240px] !pl-9"
								placeholder={ __( 'Search object keys', 'gemcloudstorage' ) }
								value={ search }
								onChange={ ( e ) => setSearch( e.target.value ) }
							/>
						</div>
					</div>
				</div>

				{ selected.length > 0 && (
					<div className="flex items-center justify-between gap-3 border-b border-[var(--gcs-border-color)] bg-[var(--gcs-second-primary)] px-4 py-2 text-[13px]">
						{ /* translators: %d: number of selected files */ }
						<span className="font-medium text-[var(--gcs-primary)]">{ sprintf( __( '%d selected', 'gemcloudstorage' ), selected.length ) }</span>
						<div className="flex gap-2">
							<button type="button" className="gcs-btn gcs-btn--primary gcs-btn--sm" onClick={ () => retry( { ids: selected } ) }>
								<RotateCcw size={ 14 } />
								{ __( 'Upload again', 'gemcloudstorage' ) }
							</button>
							<button type="button" className="gcs-btn gcs-btn--outline gcs-btn--sm" onClick={ () => setSelected( [] ) }>
								{ __( 'Clear', 'gemcloudstorage' ) }
							</button>
						</div>
					</div>
				) }

				<div className="overflow-x-auto">
					<table className="gcs-table">
						<thead>
							<tr>
								<th className="w-[36px]">
									<input
										type="checkbox"
										checked={ allChecked }
										onChange={ () => setSelected( allChecked ? [] : data.map( ( r ) => r.id ) ) }
										aria-label={ __( 'Select all', 'gemcloudstorage' ) }
									/>
								</th>
								<th>{ __( 'File', 'gemcloudstorage' ) }</th>
								<th>{ __( 'Object key', 'gemcloudstorage' ) }</th>
								<th>{ __( 'Size', 'gemcloudstorage' ) }</th>
								<th>{ __( 'Status', 'gemcloudstorage' ) }</th>
								<th>{ __( 'Updated', 'gemcloudstorage' ) }</th>
								<th className="text-right">{ __( 'Actions', 'gemcloudstorage' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ loading && ! data.length &&
								[ ...Array( 6 ) ].map( ( _, i ) => (
									<tr key={ i }>
										<td colSpan={ 7 }>
											<div className="gcs-skeleton h-8 w-full" />
										</td>
									</tr>
								) ) }

							{ ! loading && ! data.length && (
								<tr>
									<td colSpan={ 7 } className="py-12 text-center text-[var(--gcs-font-secondary-color)]">
										{ __( 'No files found.', 'gemcloudstorage' ) }
									</td>
								</tr>
							) }

							{ data.map( ( row ) => (
								<tr key={ row.id }>
									<td>
										<input
											type="checkbox"
											checked={ selected.includes( row.id ) }
											onChange={ () => setSelected( ( s ) => ( s.includes( row.id ) ? s.filter( ( id ) => id !== row.id ) : [ ...s, row.id ] ) ) }
											aria-label={ __( 'Select file', 'gemcloudstorage' ) }
										/>
									</td>
									<td>
										<div className="flex min-w-[220px] items-center gap-3">
											<Thumb row={ row } />
											<div className="min-w-0">
												<div className="truncate font-medium">{ row.title || row.filename }</div>
												<div className="mt-0.5 flex items-center gap-1.5 text-[12px] text-[var(--gcs-font-secondary-color)]">
													{ row.visibility === 'private' && <Lock size={ 11 } /> }
													<span>{ row.source_type === 'attachment' ? __( 'Media', 'gemcloudstorage' ) : row.source_type }</span>
													<span>·</span>
													<span>{ row.variant }</span>
												</div>
											</div>
										</div>
									</td>
									<td>
										<code className="gcs-code inline-block max-w-[320px]" title={ `${ row.bucket }/${ row.object_key }` }>
											{ row.object_key }
										</code>
									</td>
									<td className="whitespace-nowrap">{ row.size_label || '—' }</td>
									<td>
										<div className="flex flex-col items-start gap-1">
											<StatusPill status={ row.status } />
											{ row.local_state === 'removed' && (
												<span className="text-[11px] text-[var(--gcs-text-muted)]">{ __( 'not on this server', 'gemcloudstorage' ) }</span>
											) }
											{ row.last_error && row.status !== 'synced' && (
												<span className="max-w-[260px] text-[11px] leading-[1.4] text-[var(--gcs-danger)]" title={ row.last_error }>
													{ row.last_error.length > 90 ? row.last_error.slice( 0, 90 ) + '…' : row.last_error }
												</span>
											) }
										</div>
									</td>
									<td className="whitespace-nowrap text-[var(--gcs-font-secondary-color)]">{ formatDateTime( row.updated_at ) }</td>
									<td>
										<div className="flex justify-end gap-1">
											{ row.url && (
												<a className="gcs-btn gcs-btn--outline gcs-btn--sm" href={ row.url } target="_blank" rel="noreferrer" title={ __( 'Open public URL', 'gemcloudstorage' ) }>
													<ExternalLink size={ 14 } />
												</a>
											) }
											{ row.edit_url && (
												<a className="gcs-btn gcs-btn--outline gcs-btn--sm" href={ row.edit_url } title={ __( 'Edit attachment', 'gemcloudstorage' ) }>
													<Pencil size={ 14 } />
												</a>
											) }
											{ ( row.status === 'failed' || row.status === 'pending' ) && (
												<button type="button" className="gcs-btn gcs-btn--outline gcs-btn--sm" onClick={ () => retry( { ids: [ row.id ] } ) } title={ __( 'Upload again', 'gemcloudstorage' ) }>
													<RotateCcw size={ 14 } />
												</button>
											) }
										</div>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>

				{ total > 0 && <Pagination page={ page } perPage={ PER_PAGE } total={ total } onChange={ setPage } /> }
			</div>

			<ConfirmModal
				open={ confirmAll }
				title={ __( 'Retry every failed upload?', 'gemcloudstorage' ) }
				confirmLabel={ __( 'Retry all', 'gemcloudstorage' ) }
				onConfirm={ () => retry( { all: true } ) }
				onClose={ () => setConfirmAll( false ) }
			>
				{ failedIds.length
					? __( 'Failed files are queued again with a fresh attempt count. Fix the cause first (shown under each file), or they fail again.', 'gemcloudstorage' )
					: __( 'Every failed file in every page is queued again with a fresh attempt count.', 'gemcloudstorage' ) }
			</ConfirmModal>
		</PageLayout>
	);
}
