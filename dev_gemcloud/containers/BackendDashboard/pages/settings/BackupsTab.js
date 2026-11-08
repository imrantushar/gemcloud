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
import CodeBlock from '@GCSComponents/CodeBlock';

const KEEP_DAYS = [ 7, 14, 30, 90 ];

const ITEM_STATUS = {
	synced: [ 'ok', __( 'Stored', 'gemcloud' ) ],
	pending: [ 'pending', __( 'Waiting', 'gemcloud' ) ],
	uploading: [ 'pending', __( 'Uploading', 'gemcloud' ) ],
	failed: [ 'failed', __( 'Failed', 'gemcloud' ) ],
	deleting: [ 'warning', __( 'Deleting', 'gemcloud' ) ],
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
			notify( dispatch, __( 'Backup started. You can leave this page.', 'gemcloud' ) );
		} );

	const download = ( id ) =>
		call( `dl-${ id }`, 'backups/download', { id }, ( d ) => {
			window.location.href = d.url;
		} );

	const remove = ( id ) =>
		call( `rm-${ id }`, 'backups/delete', { id }, ( d ) => {
			setData( d );
			setConfirm( null );
			notify( dispatch, __( 'Backup deleted.', 'gemcloud' ) );
		} );

	const set = ( key, value ) => setSetting( [ 'backups', key ], value );
	const blocker = data?.blocker;

	return (
		<div className="flex flex-col gap-8">
			<div className="flex flex-col gap-4">
				<SectionTitle
					title={ __( 'Database backups', 'gemcloud' ) }
					description={ __( 'A compressed copy of this site\'s database: orders, products, license keys, customers and purchase history, posts and settings. Kept only in the private bucket, never on a public address.', 'gemcloud' ) }
				/>

				{ blocker && <GCSAlert type="warning">{ blocker }</GCSAlert> }

				<div className="flex items-start justify-between gap-6 rounded-[8px] border border-[var(--gemcloud-border-color)] p-4">
					<div>
						<div className="text-[14px] font-semibold">{ __( 'Back up automatically', 'gemcloud' ) }</div>
						<p className="mt-1 text-[13px] text-[var(--gemcloud-font-secondary-color)]">
							{ __( 'Runs in the background. Large databases are written a slice at a time, so the site stays fast.', 'gemcloud' ) }
						</p>
					</div>
					<GCSToggle checked={ !! b.enabled } onChange={ ( v ) => set( 'enabled', v ) } label={ __( 'Back up automatically', 'gemcloud' ) } />
				</div>

				<div className={ `flex flex-col gap-6 ${ b.enabled ? '' : 'pointer-events-none opacity-50' }` }>
					<div>
						<div className="gemcloud-label mb-2">{ __( 'How often', 'gemcloud' ) }</div>
						<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
							<ChoiceCard
								name="backup_frequency"
								value="daily"
								checked={ b.frequency === 'daily' }
								onChange={ ( v ) => set( 'frequency', v ) }
								title={ __( 'Every day', 'gemcloud' ) }
								badge={ __( 'Recommended', 'gemcloud' ) }
								description={ __( 'For shops and course sites: at most a day of orders can be lost.', 'gemcloud' ) }
							/>
							<ChoiceCard
								name="backup_frequency"
								value="weekly"
								checked={ b.frequency === 'weekly' }
								onChange={ ( v ) => set( 'frequency', v ) }
								title={ __( 'Every week', 'gemcloud' ) }
								description={ __( 'For sites that rarely change.', 'gemcloud' ) }
							/>
						</div>
					</div>

					<div>
						<div className="gemcloud-label mb-2">{ __( 'Keep backups for', 'gemcloud' ) }</div>
						<div className="flex flex-wrap gap-2">
							{ KEEP_DAYS.map( ( days ) => (
								<button
									key={ days }
									type="button"
									className={ `gemcloud-btn gemcloud-btn--sm ${ Number( b.keep_days ) === days ? 'gemcloud-btn--soft' : 'gemcloud-btn--outline' }` }
									onClick={ () => set( 'keep_days', days ) }
								>
									{ sprintf( /* translators: %d: number of days */ __( '%d days', 'gemcloud' ), days ) }
								</button>
							) ) }
						</div>
						<p className="gemcloud-help mt-2">{ __( 'Older backups are deleted from the bucket. The newest one is always kept.', 'gemcloud' ) }</p>
					</div>
				</div>
			</div>

			<div className="flex flex-col gap-4">
				<div className="flex flex-wrap items-end justify-between gap-3">
					<SectionTitle
						title={ __( 'Stored backups', 'gemcloud' ) }
						description={
							data?.next_due && b.enabled
								? sprintf( /* translators: %s: date and time */ __( 'Next automatic backup: around %s.', 'gemcloud' ), formatDateTime( data.next_due ) )
								: __( 'Back up before updating plugins, importing data or making big changes.', 'gemcloud' )
						}
					/>
					<button type="button" className="gemcloud-btn gemcloud-btn--primary mb-4" onClick={ backUpNow } disabled={ !! blocker || !! running || 'run' === busy }>
						{ 'run' === busy ? <Loader2 size={ 16 } className="gemcloud-spin" /> : <DatabaseBackup size={ 16 } /> }
						{ __( 'Back up now', 'gemcloud' ) }
					</button>
				</div>

				{ running && (
					<GCSAlert type="info" title={ 'exporting' === running.status ? __( 'Writing the backup…', 'gemcloud' ) : __( 'Uploading the backup…', 'gemcloud' ) }>
						{ 'exporting' === running.status
							? sprintf(
								/* translators: 1: table number, 2: table count, 3: table name, 4: rows written */
								__( 'Table %1$d of %2$d (%3$s), %4$s rows so far.', 'gemcloud' ),
								running.table_index,
								running.tables,
								running.table,
								formatNumber( running.rows )
							)
							: __( 'Every table is written. Sending the file to the private bucket and checking it arrived intact.', 'gemcloud' ) }
					</GCSAlert>
				) }

				{ ! running && 'failed' === data?.last?.status && (
					<GCSAlert type="error" title={ __( 'The last backup failed', 'gemcloud' ) }>
						{ data.last.message }
					</GCSAlert>
				) }

				<div className="overflow-x-auto rounded-[8px] border border-[var(--gemcloud-border-color)]">
					<table className="gemcloud-table">
						<thead>
							<tr>
								<th>{ __( 'Created', 'gemcloud' ) }</th>
								<th>{ __( 'Size', 'gemcloud' ) }</th>
								<th>{ __( 'Status', 'gemcloud' ) }</th>
								<th className="text-right">{ __( 'Actions', 'gemcloud' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ ! data && (
								<tr>
									<td colSpan="4" className="text-[var(--gemcloud-text-muted)]">
										{ __( 'Loading…', 'gemcloud' ) }
									</td>
								</tr>
							) }
							{ data && data.backups.length === 0 && (
								<tr>
									<td colSpan="4" className="text-[var(--gemcloud-text-muted)]">
										{ __( 'No backups yet.', 'gemcloud' ) }
									</td>
								</tr>
							) }
							{ data?.backups.map( ( row ) => {
								const [ tone, label ] = ITEM_STATUS[ row.status ] || [ 'pending', row.status ];
								return (
									<tr key={ row.id }>
										<td>
											<div className="font-medium">{ formatDateTime( row.created_at ) }</div>
											<div className="text-[12px] text-[var(--gemcloud-text-muted)]">
												{ row.filename } · ID { row.id }
											</div>
										</td>
										<td>{ row.size ? formatBytes( row.size ) : '—' }</td>
										<td title={ row.error }>
											<StatusPill status={ tone } label={ label } />
										</td>
										<td>
											<div className="flex justify-end gap-2">
												<button type="button" className="gemcloud-btn gemcloud-btn--sm gemcloud-btn--outline" onClick={ () => download( row.id ) } disabled={ 'synced' !== row.status || `dl-${ row.id }` === busy }>
													<Download size={ 14 } />
													{ __( 'Download', 'gemcloud' ) }
												</button>
												<button type="button" className="gemcloud-btn gemcloud-btn--sm gemcloud-btn--outline" onClick={ () => setConfirm( row ) } disabled={ 'deleting' === row.status } aria-label={ __( 'Delete', 'gemcloud' ) }>
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
					title={ __( 'Restoring', 'gemcloud' ) }
					description={ __( 'A restore replaces every table, so anything written since the backup is lost. It runs from WP-CLI, not from a button, so it can\'t be clicked by accident. The file is plain SQL: phpMyAdmin or any MySQL tool can import a downloaded backup too.', 'gemcloud' ) }
				/>
				<CodeBlock title={ __( 'Terminal', 'gemcloud' ) } code={ 'wp gemcloud backup list\nwp gemcloud backup restore <ID>' } />
			</div>

			<ConfirmModal
				open={ !! confirm }
				danger
				title={ __( 'Delete this backup?', 'gemcloud' ) }
				confirmLabel={ __( 'Delete backup', 'gemcloud' ) }
				onConfirm={ () => remove( confirm.id ) }
				onClose={ () => setConfirm( null ) }
			>
				{ confirm && sprintf( /* translators: %s: date and time */ __( 'The backup from %s is removed from the bucket. This can\'t be undone.', 'gemcloud' ), formatDateTime( confirm.created_at ) ) }
			</ConfirmModal>
		</div>
	);
};

export default BackupsTab;
