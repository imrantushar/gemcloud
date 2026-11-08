import React, { useEffect, useMemo, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { __, sprintf } from '@wordpress/i18n';
import { AlertTriangle, ExternalLink, FileText, Globe, Lock, RotateCcw, Search, Pencil } from 'lucide-react';
import PageLayout from '@GCSComponents/PageLayout';
import StatusPill from '@GCSComponents/StatusPill';
import Pagination from '@GCSComponents/Pagination';
import ConfirmModal from '@GCSComponents/ConfirmModal';
import Tooltip from '@GCSComponents/Tooltip';
import FileDetailsModal from './FileDetailsModal';
import { getItems, retryItems } from '@GCSRedux/Slices/itemsSlice/itemsSlice';
import { formatDateParts, useQuery } from '@GCSUtils/helper';
import GCSSelect from '@GCSComponents/GCSSelect';

const STATUS_TABS = [
	{ value: '', label: __( 'All', 'gemcloud' ) },
	{ value: 'synced', label: __( 'Synced', 'gemcloud' ) },
	{ value: 'pending', label: __( 'Pending', 'gemcloud' ) },
	{ value: 'uploading', label: __( 'Uploading', 'gemcloud' ) },
	{ value: 'failed', label: __( 'Failed', 'gemcloud' ) },
];

const PER_PAGE = 20;

const Thumb = ( { row } ) =>
	row.thumb ? (
		<img src={ row.thumb } alt="" width="40" height="40" className="h-10 w-10 shrink-0 rounded-[4px] border border-[var(--gemcloud-border-color)] object-cover" />
	) : (
		<span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-[4px] border border-[var(--gemcloud-border-color)] bg-[var(--gemcloud-secondary-color)] text-[var(--gemcloud-text-muted)]">
			<FileText size={ 18 } />
		</span>
	);

const AccessPill = ( { row } ) => {
	const priv = row.private ?? row.visibility === 'private';
	const pill = (
		<span
			className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[12px] font-medium"
			style={ priv
				? { color: 'var(--gemcloud-warning)', background: 'var(--gemcloud-warning-bg)' }
				: { color: 'var(--gemcloud-info)', background: 'var(--gemcloud-info-bg)' } }
		>
			{ priv ? <Lock size={ 12 } /> : <Globe size={ 12 } /> }
			{ priv ? __( 'Private', 'gemcloud' ) : __( 'Public', 'gemcloud' ) }
		</span>
	);
	const tip = row.moving
		? __( 'Private. Moving to the private bucket; already served only through signed links.', 'gemcloud' )
		: priv
			? __( 'In the private bucket. Opens only through a short-lived signed link after an access check.', 'gemcloud' )
			: __( 'In the public bucket. Anyone with the address can open it.', 'gemcloud' );
	return <Tooltip content={ tip }>{ pill }</Tooltip>;
};

export default function Files() {
	const dispatch = useDispatch();
	const query = useQuery();
	const { data, total, sources, loading } = useSelector( ( state ) => state.items );

	const [ status, setStatus ] = useState( query.get( 'status' ) || '' );
	const [ visibility, setVisibility ] = useState( '' );
	const [ source, setSource ] = useState( query.get( 'source' ) || '' );
	const [ search, setSearch ] = useState( '' );
	const [ term, setTerm ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ selected, setSelected ] = useState( [] );
	const [ confirmAll, setConfirmAll ] = useState( false );
	const [ editing, setEditing ] = useState( 0 );

	// Debounce the search box.
	useEffect( () => {
		const id = setTimeout( () => {
			setTerm( search.trim() );
			setPage( 1 );
		}, 350 );
		return () => clearTimeout( id );
	}, [ search ] );

	const params = useMemo( () => ( { status, visibility, source, search: term, page, per_page: PER_PAGE } ), [ status, visibility, source, term, page ] );

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
			title={ __( 'Files', 'gemcloud' ) }
			description={ __( 'Every object in your buckets, one row per file and image size.', 'gemcloud' ) }
			actions={
				<button type="button" className="gemcloud-btn gemcloud-btn--outline" onClick={ () => setConfirmAll( true ) }>
					<RotateCcw size={ 15 } />
					{ __( 'Retry all failed', 'gemcloud' ) }
				</button>
			}
		>
			<div className="gemcloud-card overflow-hidden">
				<div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--gemcloud-border-color)] px-4 py-3">
					<div className="gemcloud-tabs">
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
						<GCSSelect
							width={ 170 }
							value={ source }
							onChange={ ( v ) => { setSource( v ); setPage( 1 ); } }
							options={ [ { value: '', label: __( 'All sources', 'gemcloud' ) }, ...sources ] }
							aria-label={ __( 'Source', 'gemcloud' ) }
						/>
						<GCSSelect
							width={ 170 }
							value={ visibility }
							onChange={ ( v ) => { setVisibility( v ); setPage( 1 ); } }
							options={ [
								{ value: '', label: __( 'Public and private', 'gemcloud' ) },
								{ value: 'public', label: __( 'Public', 'gemcloud' ) },
								{ value: 'private', label: __( 'Private', 'gemcloud' ) },
							] }
							aria-label={ __( 'Visibility', 'gemcloud' ) }
						/>
						<div className="relative">
							<Search size={ 15 } className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--gemcloud-text-muted)]" />
							<input
								type="search"
								className="gemcloud-input !w-[240px] !pl-9"
								placeholder={ __( 'Search object keys', 'gemcloud' ) }
								value={ search }
								onChange={ ( e ) => setSearch( e.target.value ) }
							/>
						</div>
					</div>
				</div>

				{ selected.length > 0 && (
					<div className="flex items-center justify-between gap-3 border-b border-[var(--gemcloud-border-color)] bg-[var(--gemcloud-second-primary)] px-4 py-2 text-[13px]">
						{ /* translators: %d: number of selected files */ }
						<span className="font-medium text-[var(--gemcloud-primary)]">{ sprintf( __( '%d selected', 'gemcloud' ), selected.length ) }</span>
						<div className="flex gap-2">
							<button type="button" className="gemcloud-btn gemcloud-btn--primary gemcloud-btn--sm" onClick={ () => retry( { ids: selected } ) }>
								<RotateCcw size={ 14 } />
								{ __( 'Upload again', 'gemcloud' ) }
							</button>
							<button type="button" className="gemcloud-btn gemcloud-btn--outline gemcloud-btn--sm" onClick={ () => setSelected( [] ) }>
								{ __( 'Clear', 'gemcloud' ) }
							</button>
						</div>
					</div>
				) }

				<div className="overflow-x-auto">
					<table className="gemcloud-table">
						<thead>
							<tr>
								<th className="w-[36px]">
									<input
										type="checkbox"
										checked={ allChecked }
										onChange={ () => setSelected( allChecked ? [] : data.map( ( r ) => r.id ) ) }
										aria-label={ __( 'Select all', 'gemcloud' ) }
									/>
								</th>
								<th>{ __( 'File', 'gemcloud' ) }</th>
								<th>{ __( 'Access', 'gemcloud' ) }</th>
								<th>{ __( 'Object key', 'gemcloud' ) }</th>
								<th>{ __( 'Size', 'gemcloud' ) }</th>
								<th>{ __( 'Status', 'gemcloud' ) }</th>
								<th>{ __( 'Updated', 'gemcloud' ) }</th>
								<th className="text-right">{ __( 'Actions', 'gemcloud' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ loading && ! data.length &&
								[ ...Array( 6 ) ].map( ( _, i ) => (
									<tr key={ i }>
										<td colSpan={ 8 }>
											<div className="gemcloud-skeleton h-8 w-full" />
										</td>
									</tr>
								) ) }

							{ ! loading && ! data.length && (
								<tr>
									<td colSpan={ 8 } className="py-12 text-center text-[var(--gemcloud-font-secondary-color)]">
										{ __( 'No files found.', 'gemcloud' ) }
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
											aria-label={ __( 'Select file', 'gemcloud' ) }
										/>
									</td>
									<td>
										<div className="flex w-[240px] max-w-[240px] items-center gap-3">
											<Thumb row={ row } />
											<div className="min-w-0 flex-1">
												<Tooltip content={ row.title || row.filename } className="gemcloud-truncate font-medium">
													{ row.title || row.filename }
												</Tooltip>
												<div className="mt-0.5 flex items-center gap-1.5 text-[12px] text-[var(--gemcloud-font-secondary-color)]">
													<span>{ row.source_type === 'attachment' ? __( 'Media', 'gemcloud' ) : row.source_type }</span>
													<span>·</span>
													<span>{ row.variant }</span>
												</div>
											</div>
										</div>
									</td>
									<td>
										<AccessPill row={ row } />
									</td>
									<td>
										<Tooltip content={ `${ row.bucket }/${ row.object_key }` } className="gemcloud-code gemcloud-truncate max-w-[240px]">
											{ row.object_key }
										</Tooltip>
									</td>
									<td className="whitespace-nowrap">{ row.size_label || '—' }</td>
									<td>
										<div className="flex flex-col items-start gap-1">
											<StatusPill status={ row.status } />
											{ row.local_state === 'removed' && (
												<Tooltip content={ __( 'The cloud has the only copy. Deleting it from the bucket loses the file for good.', 'gemcloud' ) }>
													<span
														className="inline-flex items-center gap-1 whitespace-nowrap rounded-[4px] px-1.5 py-0.5 text-[11px] font-medium"
														style={ { color: 'var(--gemcloud-warning)', background: 'var(--gemcloud-warning-bg)' } }
													>
														<AlertTriangle size={ 11 } />
														{ __( 'Not on this server', 'gemcloud' ) }
													</span>
												</Tooltip>
											) }
											{ row.last_error && row.status !== 'synced' && (
												<span className="max-w-[260px] text-[11px] leading-[1.4] text-[var(--gemcloud-danger)]" title={ row.last_error }>
													{ row.last_error.length > 90 ? row.last_error.slice( 0, 90 ) + '…' : row.last_error }
												</span>
											) }
										</div>
									</td>
									<td className="whitespace-nowrap text-[12px] leading-[1.45] text-[var(--gemcloud-font-secondary-color)]">
										{ formatDateParts( row.updated_at ).map( ( part, i ) => (
											<div key={ i }>{ part }</div>
										) ) }
									</td>
									<td>
										<div className="flex justify-end gap-1">
											{ row.url && (
												<a className="gemcloud-btn gemcloud-btn--outline gemcloud-btn--sm" href={ row.url } target="_blank" rel="noreferrer" title={ __( 'Open public URL', 'gemcloud' ) }>
													<ExternalLink size={ 14 } />
												</a>
											) }
											{ row.source_type === 'attachment' && (
												<button type="button" className="gemcloud-btn gemcloud-btn--outline gemcloud-btn--sm" onClick={ () => setEditing( row.source_id ) } title={ __( 'File details', 'gemcloud' ) } aria-label={ __( 'File details', 'gemcloud' ) }>
													<Pencil size={ 14 } />
												</button>
											) }
											{ ( row.status === 'failed' || row.status === 'pending' ) && (
												<button type="button" className="gemcloud-btn gemcloud-btn--outline gemcloud-btn--sm" onClick={ () => retry( { ids: [ row.id ] } ) } title={ __( 'Upload again', 'gemcloud' ) }>
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

			{ editing > 0 && <FileDetailsModal attachmentId={ editing } onClose={ () => setEditing( 0 ) } onSaved={ load } /> }

			<ConfirmModal
				open={ confirmAll }
				title={ __( 'Retry every failed upload?', 'gemcloud' ) }
				confirmLabel={ __( 'Retry all', 'gemcloud' ) }
				onConfirm={ () => retry( { all: true } ) }
				onClose={ () => setConfirmAll( false ) }
			>
				{ failedIds.length
					? __( 'Failed files are queued again with a fresh attempt count. Fix the cause first (shown under each file), or they fail again.', 'gemcloud' )
					: __( 'Every failed file in every page is queued again with a fresh attempt count.', 'gemcloud' ) }
			</ConfirmModal>
		</PageLayout>
	);
}
