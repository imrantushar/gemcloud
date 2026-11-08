import React, { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { Link } from 'react-router-dom';
import { __, sprintf } from '@wordpress/i18n';
import { CloudUpload, Clock3, AlertOctagon, ShieldCheck, HardDrive } from 'lucide-react';
import PageLayout from '@GCSComponents/PageLayout';
import StatCard from '@GCSComponents/StatCard';
import StatusPill from '@GCSComponents/StatusPill';
import Section from '@GCSComponents/Section';
import GCSAlert from '@GCSComponents/GCSAlert';
import { getStatus, runTool } from '@GCSRedux/Slices/statusSlice/statusSlice';
import { adminPage, formatBytes, formatNumber } from '@GCSUtils/helper';

const PROVIDERS = {
	r2: __( 'Cloudflare R2', 'gemcloud' ),
	aws: __( 'Amazon S3', 'gemcloud' ),
	custom: __( 'S3-compatible', 'gemcloud' ),
};

const LOCAL_FILES = {
	keep: __( 'Keep local copies', 'gemcloud' ),
	remove_sizes: __( 'Remove generated sizes', 'gemcloud' ),
	remove_all: __( 'Remove all local files', 'gemcloud' ),
};

const Row = ( { label, children } ) => (
	<div className="flex items-start justify-between gap-4 py-2.5 text-[13px]">
		<span className="shrink-0 text-[var(--gcs-font-secondary-color)]">{ label }</span>
		<span className="min-w-0 text-right font-medium text-[var(--gcs-font-color)]" style={ { overflowWrap: 'anywhere' } }>{ children }</span>
	</div>
);

export const usePolling = ( active, ms = 8000 ) => {
	const dispatch = useDispatch();
	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		const id = setInterval( () => dispatch( getStatus() ), ms );
		return () => clearInterval( id );
	}, [ active, ms ] );
};

export default function Dashboard() {
	const dispatch = useDispatch();
	const { data, loading } = useSelector( ( state ) => state.status );

	useEffect( () => {
		dispatch( getStatus() );
	}, [ dispatch ] );

	const stats = data?.stats;
	const waiting = ( stats?.by_status?.pending || 0 ) + ( stats?.by_status?.uploading || 0 );
	usePolling( !! data && ( waiting > 0 || data?.migration?.status === 'running' ) );

	const migration = data?.migration;
	const migrationPct = migration?.total ? Math.min( 100, Math.round( ( migration.processed / migration.total ) * 100 ) ) : 0;

	const actions = data?.available ? (
		<button
			type="button"
			className="gcs-btn gcs-btn--primary"
			disabled={ migration?.status === 'running' }
			onClick={ () => dispatch( runTool( { path: 'migration/start' } ) ) }
		>
			<CloudUpload size={ 16 } />
			{ migration?.status === 'running' ? __( 'Offloading…', 'gemcloud' ) : __( 'Offload existing media', 'gemcloud' ) }
		</button>
	) : (
		<Link className="gcs-btn gcs-btn--primary" to={ adminPage( 'gemcloud-settings' ) }>
			{ __( 'Connect storage', 'gemcloud' ) }
		</Link>
	);

	return (
		<PageLayout
			title={ __( 'Dashboard', 'gemcloud' ) }
			description={ __( 'Media and protected files offloaded to Amazon S3 or Cloudflare R2.', 'gemcloud' ) }
			actions={ actions }
		>
			<div className="flex flex-col gap-6">
				{ data && ! data.configured && (
					<GCSAlert
						type="info"
						title={ __( 'Connect a bucket to get started', 'gemcloud' ) }
						action={ <Link className="gcs-btn gcs-btn--soft gcs-btn--sm" to={ adminPage( 'gemcloud-settings' ) }>{ __( 'Open settings', 'gemcloud' ) }</Link> }
					>
						{ __( 'Choose Cloudflare R2 or Amazon S3, enter an access key and a bucket, and test the connection. Files keep being served from this server until each upload is confirmed.', 'gemcloud' ) }
					</GCSAlert>
				) }

				{ data?.configured && ! data.enabled && (
					<GCSAlert type="warning" title={ __( 'Cloud storage is switched off', 'gemcloud' ) }>
						{ __( 'The connection is set up but switched off, so nothing uploads and every URL points at this server. Switch it on in Settings → Connection.', 'gemcloud' ) }
					</GCSAlert>
				) }

				{ data?.queue?.paused && (
					<GCSAlert
						type="error"
						title={ data.queue.reason === 'clock' ? __( 'Uploads paused: server clock is wrong', 'gemcloud' ) : __( 'Uploads paused', 'gemcloud' ) }
						action={ <button type="button" className="gcs-btn gcs-btn--outline gcs-btn--sm" onClick={ () => dispatch( runTool( { path: 'queue/resume' } ) ) }>{ __( 'Resume', 'gemcloud' ) }</button> }
					>
						{ data.queue.message || __( 'Uploads stopped after repeated errors. Fix the connection, test it, then resume.', 'gemcloud' ) }
					</GCSAlert>
				) }

				{ data?.needs_reentry && (
					<GCSAlert type="warning" title={ __( 'Re-enter your secret key', 'gemcloud' ) }>
						{ __( 'The stored secret can no longer be decrypted because the WordPress salts changed. Define GEMCLOUD_KEY in wp-config.php to avoid this.', 'gemcloud' ) }
					</GCSAlert>
				) }

				{ data?.conflicts?.length > 0 && (
					<GCSAlert type="warning" title={ __( 'Media offload is off', 'gemcloud' ) }>
						{ sprintf(
							/* translators: %s: plugin names */
							__( '%s also rewrites media library URLs, so ours stays off to avoid broken links. Plugin file contexts keep working.', 'gemcloud' ),
							data.conflicts.join( ', ' )
						) }
					</GCSAlert>
				) }

				{ ( data?.warnings || [] ).map( ( w, i ) => (
					<GCSAlert key={ i } type={ w.type }>{ w.message }</GCSAlert>
				) ) }

				<div className="flex flex-wrap gap-6">
					<StatCard
						title={ __( 'In the cloud', 'gemcloud' ) }
						value={ formatNumber( stats?.by_status?.synced ) }
						hint={ formatBytes( stats?.bytes ) }
						icon={ CloudUpload }
						tone="success"
						isLoading={ loading }
					/>
					<StatCard title={ __( 'Waiting', 'gemcloud' ) } value={ formatNumber( waiting ) } icon={ Clock3 } tone="warning" isLoading={ loading } />
					<StatCard title={ __( 'Failed', 'gemcloud' ) } value={ formatNumber( stats?.by_status?.failed ) } icon={ AlertOctagon } tone="danger" isLoading={ loading } />
					<StatCard title={ __( 'Private files', 'gemcloud' ) } value={ formatNumber( stats?.private ) } icon={ ShieldCheck } isLoading={ loading } />
				</div>

				<div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
					<Section
						title={ __( 'Connection', 'gemcloud' ) }
						actions={
							data && (
								<StatusPill
									status={ data.available ? 'ok' : data.configured ? 'warning' : 'off' }
									label={ data.available ? __( 'Active', 'gemcloud' ) : data.configured ? __( 'Switched off', 'gemcloud' ) : __( 'Not connected', 'gemcloud' ) }
								/>
							)
						}
						bodyClassName="px-6 py-3 divide-y divide-[var(--gcs-border-color)]"
					>
						<Row label={ __( 'Provider', 'gemcloud' ) }>{ PROVIDERS[ data?.provider ] || '—' }</Row>
						<Row label={ __( 'Public bucket', 'gemcloud' ) }>{ data?.buckets?.public?.name || '—' }</Row>
						<Row label={ __( 'Public URL', 'gemcloud' ) }>{ data?.buckets?.public?.public_url || __( 'Provider default', 'gemcloud' ) }</Row>
						<Row label={ __( 'Private bucket', 'gemcloud' ) }>{ data?.buckets?.private?.name || __( 'Shares the public bucket', 'gemcloud' ) }</Row>
						<Row label={ __( 'New media uploads', 'gemcloud' ) }>{ data?.media?.offload ? __( 'Offloaded', 'gemcloud' ) : __( 'Stay local', 'gemcloud' ) }</Row>
						<Row label={ __( 'Local files', 'gemcloud' ) }>{ LOCAL_FILES[ data?.media?.local_files ] || '—' }</Row>
					</Section>

					<Section
						title={ __( 'Media library', 'gemcloud' ) }
						actions={ migration && <StatusPill status={ migration.status === 'running' ? 'uploading' : migration.status === 'done' ? 'ok' : 'off' } label={ { running: __( 'Running', 'gemcloud' ), done: __( 'Finished', 'gemcloud' ), stopped: __( 'Stopped', 'gemcloud' ), idle: __( 'Not started', 'gemcloud' ) }[ migration.status ] } /> }
					>
						<div className="flex flex-col gap-4">
							<div className="flex items-baseline justify-between text-[13px]">
								<span className="text-[var(--gcs-font-secondary-color)]">{ __( 'Existing media checked', 'gemcloud' ) }</span>
								<span className="font-medium">
									{ /* translators: 1: processed, 2: total */ }
									{ sprintf( __( '%1$s of %2$s attachments', 'gemcloud' ), formatNumber( migration?.processed ), formatNumber( migration?.total || data?.attachments ) ) }
								</span>
							</div>
							<div className="gcs-progress">
								<div className="gcs-progress__bar" style={ { width: `${ migrationPct }%` } } />
							</div>
							<div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
								{ [ 'synced', 'pending', 'uploading', 'failed' ].map( ( s ) => (
									<Link key={ s } to={ adminPage( 'gemcloud-files', { status: s } ) } className="rounded-[6px] border border-[var(--gcs-border-color)] p-3 transition-colors hover:bg-[var(--gcs-secondary-color)]">
										<StatusPill status={ s } />
										<div className="mt-2 text-[20px] font-semibold">{ formatNumber( stats?.by_status?.[ s ] ) }</div>
									</Link>
								) ) }
							</div>
						</div>
					</Section>
				</div>

				<Section title={ __( 'Storage by context', 'gemcloud' ) } description={ __( 'Each plugin that stores files registers a context: the media library, paid downloads, course materials, chat attachments.', 'gemcloud' ) } bodyClassName="">
					{ stats?.by_context?.filter( ( c ) => ! c.source_type.startsWith( '~' ) ).length ? (
						<table className="gcs-table">
							<thead>
								<tr>
									<th>{ __( 'Context', 'gemcloud' ) }</th>
									<th>{ __( 'Objects', 'gemcloud' ) }</th>
									<th>{ __( 'Synced size', 'gemcloud' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ stats.by_context
									.filter( ( c ) => ! c.source_type.startsWith( '~' ) )
									.map( ( c ) => (
										<tr key={ c.source_type }>
											<td>
												<span className="inline-flex items-center gap-2">
													<HardDrive size={ 14 } className="text-[var(--gcs-text-muted)]" />
													<code className="gcs-code">{ c.source_type === 'attachment' ? 'media' : c.source_type }</code>
												</span>
											</td>
											<td>{ formatNumber( c.objects ) }</td>
											<td>{ formatBytes( c.bytes ) }</td>
										</tr>
									) ) }
							</tbody>
						</table>
					) : (
						<p className="px-6 py-8 text-center text-[13px] text-[var(--gcs-font-secondary-color)]">
							{ __( 'Nothing stored yet. New uploads go to the cloud once storage is on; use “Offload existing media” for the files already here.', 'gemcloud' ) }
						</p>
					) }
				</Section>
			</div>
		</PageLayout>
	);
}
