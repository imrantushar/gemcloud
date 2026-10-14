import React, { useCallback, useEffect, useState } from 'react';
import { useDispatch } from 'react-redux';
import { __, sprintf } from '@wordpress/i18n';
import { DatabaseBackup, Download, Loader2, Trash2 } from 'lucide-react';
import GCSToggle from '@GCSComponents/GCSToggle';
import GCSAlert from '@GCSComponents/GCSAlert';
import StatusPill from '@GCSComponents/StatusPill';
import ConfirmModal from '@GCSComponents/ConfirmModal';
import { API, namespace, errorMessage, formatBytes, formatDateTime, formatNumber, notify } from '@GCSUtils/helper';
import { ChoiceCard, SectionTitle } from './common';

const KEEP_DAYS = [ 7, 14, 30, 90 ];

const ITEM_STATUS = {
	synced: [ 'ok', __( 'Stored', 'gemcloudstorage' ) ],
	pending: [ 'pending', __( 'Waiting', 'gemcloudstorage' ) ],
	uploading: [ 'pending', __( 'Uploading', 'gemcloudstorage' ) ],
	failed: [ 'failed', __( 'Failed', 'gemcloudstorage' ) ],
	deleting: [ 'warning', __( 'Deleting', 'gemcloudstorage' ) ],
};

/**
 * Database backups: schedule (saved with the form) and the stored backups
 * (live, from the backups endpoint).
 */
const BackupsTab = ( { form, setSetting } ) => {
	const dispatch = useDispatch();
	const b = form.settings.backups || { enabled: false, frequency: 'daily', keep_days: 30 };
	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ confirm, setConfirm ] = useState( null );

	const load = useCallback( async () => {
		try {
			const res = await API.get( `${ namespace }backups` );
			setData( res.data );
		} catch ( e ) {
			notify( dispatch, errorMessage( e ), 'error' );
		}
	}, [ dispatch ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Follow a running backup until it is stored.
	const running = data?.running;
	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}
		const id = setInterval( load, 3000 );
		return () => clearInterval( id );
	}, [ running, load ] );

	const call = async ( key, path, body, then ) => {
		setBusy( key );
		try {
			const res = await API.post( `${ namespace }${ path }`, body );
			then( res.data );
		} catch ( e ) {
			notify( dispatch, errorMessage( e ), 'error' );
		} finally {
			setBusy( '' );
		}
	};

	const backUpNow = () =>
		call( 'run', 'backups/run', {}, ( d ) => {
			setData( d );
			notify( dispatch, __( 'Backup started. You can leave this page.', 'gemcloudstorage' ) );
		} );

	const download = ( id ) =>
		call( `dl-${ id }`, 'backups/download', { id }, ( d ) => {
			window.location.href = d.url;
		} );

	const remove = ( id ) =>
		call( `rm-${ id }`, 'backups/delete', { id }, ( d ) => {
			setData( d );
			setConfirm( null );
			notify( dispatch, __( 'Backup deleted.', 'gemcloudstorage' ) );
		} );

	const set = ( key, value ) => setSetting( [ 'backups', key ], value );
	const blocker = data?.blocker;

	return (
		<div className="flex flex-col gap-8">
			<div className="flex flex-col gap-4">
				<SectionTitle
					title={ __( 'Database backups', 'gemcloudstorage' ) }
					description={ __( 'A compressed copy of this site\'s database: orders, products, license keys, customers and purchase history, posts and settings. Kept only in the private bucket, never on a public address.', 'gemcloudstorage' ) }
				/>

				{ blocker && <GCSAlert type="warning">{ blocker }</GCSAlert> }

				<div className="flex items-start justify-between gap-6 rounded-[8px] border border-[var(--gcs-border-color)] p-4">
					<div>
						<div className="text-[14px] font-semibold">{ __( 'Back up automatically', 'gemcloudstorage' ) }</div>
						<p className="mt-1 text-[13px] text-[var(--gcs-font-secondary-color)]">
							{ __( 'Runs in the background. Large databases are written a slice at a time, so the site stays fast.', 'gemcloudstorage' ) }
						</p>
					</div>
					<GCSToggle checked={ !! b.enabled } onChange={ ( v ) => set( 'enabled', v ) } label={ __( 'Back up automatically', 'gemcloudstorage' ) } />
				</div>

				<div className={ `flex flex-col gap-6 ${ b.enabled ? '' : 'pointer-events-none opacity-50' }` }>
					<div>
						<div className="gcs-label mb-2">{ __( 'How often', 'gemcloudstorage' ) }</div>
						<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
							<ChoiceCard
								name="backup_frequency"
								value="daily"
								checked={ b.frequency === 'daily' }
								onChange={ ( v ) => set( 'frequency', v ) }
								title={ __( 'Every day', 'gemcloudstorage' ) }
								badge={ __( 'Recommended', 'gemcloudstorage' ) }
								description={ __( 'For shops and course sites: at most a day of orders can be lost.', 'gemcloudstorage' ) }
							/>
							<ChoiceCard
								name="backup_frequency"
								value="weekly"
								checked={ b.frequency === 'weekly' }
								onChange={ ( v ) => set( 'frequency', v ) }
								title={ __( 'Every week', 'gemcloudstorage' ) }
								description={ __( 'For sites that rarely change.', 'gemcloudstorage' ) }
							/>
						</div>
					</div>

					<div>
						<div className="gcs-label mb-2">{ __( 'Keep backups for', 'gemcloudstorage' ) }</div>
						<div className="flex flex-wrap gap-2">
							{ KEEP_DAYS.map( ( days ) => (
								<button
									key={ days }
									type="button"
									className={ `gcs-btn gcs-btn--sm ${ Number( b.keep_days ) === days ? 'gcs-btn--soft' : 'gcs-btn--outline' }` }
									onClick={ () => set( 'keep_days', days ) }
								>
									{ sprintf( /* translators: %d: number of days */ __( '%d days', 'gemcloudstorage' ), days ) }
								</button>
							) ) }
						</div>
						<p className="gcs-help mt-2">{ __( 'Older backups are deleted from the bucket. The newest one is always kept.', 'gemcloudstorage' ) }</p>
					</div>
				</div>
			</div>

			<div className="flex flex-col gap-4">
				<div className="flex flex-wrap items-end justify-between gap-3">
					<SectionTitle
						title={ __( 'Stored backups', 'gemcloudstorage' ) }
						description={
							data?.next_due && b.enabled
								? sprintf( /* translators: %s: date and time */ __( 'Next automatic backup: around %s.', 'gemcloudstorage' ), formatDateTime( data.next_due ) )
								: __( 'Back up before updating plugins, importing data or making big changes.', 'gemcloudstorage' )
						}
					/>
					<button type="button" className="gcs-btn gcs-btn--primary mb-4" onClick={ backUpNow } disabled={ !! blocker || !! running || 'run' === busy }>
						{ 'run' === busy ? <Loader2 size={ 16 } className="gcs-spin" /> : <DatabaseBackup size={ 16 } /> }
						{ __( 'Back up now', 'gemcloudstorage' ) }
					</button>
				</div>

				{ running && (
					<GCSAlert type="info" title={ 'exporting' === running.status ? __( 'Writing the backup…', 'gemcloudstorage' ) : __( 'Uploading the backup…', 'gemcloudstorage' ) }>
						{ 'exporting' === running.status
							? sprintf(
								/* translators: 1: table number, 2: table count, 3: table name, 4: rows written */
								__( 'Table %1$d of %2$d (%3$s), %4$s rows so far.', 'gemcloudstorage' ),
								running.table_index,
								running.tables,
								running.table,
								formatNumber( running.rows )
							)
							: __( 'Every table is written. Sending the file to the private bucket and checking it arrived intact.', 'gemcloudstorage' ) }
					</GCSAlert>
				) }

				{ ! running && 'failed' === data?.last?.status && (
					<GCSAlert type="error" title={ __( 'The last backup failed', 'gemcloudstorage' ) }>
						{ data.last.message }
					</GCSAlert>
				) }

				<div className="overflow-x-auto rounded-[8px] border border-[var(--gcs-border-color)]">
					<table className="gcs-table">
						<thead>
							<tr>
								<th>{ __( 'Created', 'gemcloudstorage' ) }</th>
								<th>{ __( 'Size', 'gemcloudstorage' ) }</th>
								<th>{ __( 'Status', 'gemcloudstorage' ) }</th>
								<th className="text-right">{ __( 'Actions', 'gemcloudstorage' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ ! data && (
								<tr>
									<td colSpan="4" className="text-[var(--gcs-text-muted)]">
										{ __( 'Loading…', 'gemcloudstorage' ) }
									</td>
								</tr>
							) }
							{ data && data.backups.length === 0 && (
								<tr>
									<td colSpan="4" className="text-[var(--gcs-text-muted)]">
										{ __( 'No backups yet.', 'gemcloudstorage' ) }
									</td>
								</tr>
							) }
							{ data?.backups.map( ( row ) => {
								const [ tone, label ] = ITEM_STATUS[ row.status ] || [ 'pending', row.status ];
								return (
									<tr key={ row.id }>
										<td>
											<div className="font-medium">{ formatDateTime( row.created_at ) }</div>
											<div className="text-[12px] text-[var(--gcs-text-muted)]">
												{ row.filename } · ID { row.id }
											</div>
										</td>
										<td>{ row.size ? formatBytes( row.size ) : '—' }</td>
										<td title={ row.error }>
											<StatusPill status={ tone } label={ label } />
										</td>
										<td>
											<div className="flex justify-end gap-2">
												<button type="button" className="gcs-btn gcs-btn--sm gcs-btn--outline" onClick={ () => download( row.id ) } disabled={ 'synced' !== row.status || `dl-${ row.id }` === busy }>
													<Download size={ 14 } />
													{ __( 'Download', 'gemcloudstorage' ) }
												</button>
												<button type="button" className="gcs-btn gcs-btn--sm gcs-btn--outline" onClick={ () => setConfirm( row ) } disabled={ 'deleting' === row.status } aria-label={ __( 'Delete', 'gemcloudstorage' ) }>
													<Trash2 size={ 14 } />
												</button>
											</div>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			</div>

			<div className="flex flex-col gap-3">
				<SectionTitle
					title={ __( 'Restoring', 'gemcloudstorage' ) }
					description={ __( 'A restore replaces every table, so anything written since the backup is lost. It runs from WP-CLI, not from a button, so it can\'t be clicked by accident. The file is plain SQL: phpMyAdmin or any MySQL tool can import a downloaded backup too.', 'gemcloudstorage' ) }
				/>
				<pre className="overflow-x-auto rounded-[8px] bg-[var(--gcs-secondary-color)] p-4 text-[12px] leading-[1.7]">
					{ 'wp gemcloudstorage backup list\nwp gemcloudstorage backup restore <ID>' }
				</pre>
			</div>

			<ConfirmModal
				open={ !! confirm }
				danger
				title={ __( 'Delete this backup?', 'gemcloudstorage' ) }
				confirmLabel={ __( 'Delete backup', 'gemcloudstorage' ) }
				onConfirm={ () => remove( confirm.id ) }
				onClose={ () => setConfirm( null ) }
			>
				{ confirm && sprintf( /* translators: %s: date and time */ __( 'The backup from %s is removed from the bucket. This can\'t be undone.', 'gemcloudstorage' ), formatDateTime( confirm.created_at ) ) }
			</ConfirmModal>
		</div>
	);
};

export default BackupsTab;
