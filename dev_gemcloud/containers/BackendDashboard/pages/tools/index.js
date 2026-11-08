import React, { useEffect, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { __, sprintf } from '@wordpress/i18n';
import { CloudUpload, Download, Pause, Play, RotateCcw, Square, Globe, Terminal, Code2 } from 'lucide-react';
import PageLayout from '@GCSComponents/PageLayout';
import Section from '@GCSComponents/Section';
import StatusPill from '@GCSComponents/StatusPill';
import GCSAlert from '@GCSComponents/GCSAlert';
import ConfirmModal from '@GCSComponents/ConfirmModal';
import { getStatus, runTool } from '@GCSRedux/Slices/statusSlice/statusSlice';
import { API, namespace, errorMessage, formatNumber, notify } from '@GCSUtils/helper';
import { usePolling } from '../dashboard';
import CodeBlock from '@GCSComponents/CodeBlock';

const RUN_LABELS = {
	running: __( 'Running', 'gemcloud' ),
	done: __( 'Finished', 'gemcloud' ),
	stopped: __( 'Stopped', 'gemcloud' ),
	idle: __( 'Not started', 'gemcloud' ),
};

const runPill = ( state ) => (
	<StatusPill status={ state === 'running' ? 'uploading' : state === 'done' ? 'ok' : 'off' } label={ RUN_LABELS[ state ] || state } />
);

const Progress = ( { done, total } ) => {
	const pct = total ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
	return (
		<div className="flex flex-col gap-2">
			<div className="flex justify-between text-[13px] text-[var(--gemcloud-font-secondary-color)]">
				{ /* translators: 1: done, 2: total */ }
				<span>{ sprintf( __( '%1$s of %2$s', 'gemcloud' ), formatNumber( done ), formatNumber( total ) ) }</span>
				<span>{ pct }%</span>
			</div>
			<div className="gemcloud-progress">
				<div className="gemcloud-progress__bar" style={ { width: `${ pct }%` } } />
			</div>
		</div>
	);
};

const CLI = [
	[ 'wp gemcloud status', __( 'Connection, queue and per-status counts.', 'gemcloud' ) ],
	[ 'wp gemcloud test', __( 'Put, head and delete a probe object in each bucket.', 'gemcloud' ) ],
	[ 'wp gemcloud migrate --sync', __( 'Offload existing media inside the command, without cron.', 'gemcloud' ) ],
	[ 'wp gemcloud run', __( 'Run waiting uploads now.', 'gemcloud' ) ],
	[ 'wp gemcloud retry --failed', __( 'Queue every failed upload again.', 'gemcloud' ) ],
	[ 'wp gemcloud verify --fix', __( 'HEAD every synced object; re-upload missing or wrong-sized ones.', 'gemcloud' ) ],
	[ 'wp gemcloud download-back', __( 'Download every removed local file back to this server.', 'gemcloud' ) ],
];

const API_SNIPPET = `add_action( 'gemcloud/loaded', function () {
	GemCloud\\Manager::register_context( 'storeengine.download', [
		'label'      => 'StoreEngine downloadable files',
		'visibility' => 'private',
		'path'       => 'private/storeengine/{Y}/{m}/{uuid}/{filename}',
		'max_size'   => 5 * GB_IN_BYTES,
	] );
} );

// After your own upload:
GemCloud\\Manager::upload( $path, GemCloud\\Ref::of( 'storeengine.download', $file_id ) );

// At the moment of the click, after your access checks:
$url = GemCloud\\Manager::temporary_url(
	GemCloud\\Ref::of( 'storeengine.download', $file_id ),
	[ 'filename' => 'Course-Guide.pdf', 'ttl' => 300 ]
);
if ( ! is_wp_error( $url ) ) {
	nocache_headers();
	wp_redirect( $url );
	exit;
}`;

export default function Tools() {
	const dispatch = useDispatch();
	const { data, busy } = useSelector( ( state ) => state.status );
	const [ confirm, setConfirm ] = useState( null );
	const [ cors, setCors ] = useState( null );
	const [ corsBusy, setCorsBusy ] = useState( false );

	useEffect( () => {
		dispatch( getStatus() );
	}, [ dispatch ] );

	const migration = data?.migration || {};
	const back = data?.download_back || {};
	const queue = data?.queue || {};
	usePolling( migration.status === 'running' || back.status === 'running', 5000 );

	const run = ( path, body = {} ) => dispatch( runTool( { path, body } ) );
	const unavailable = data && ! data.available;

	const configureCors = async () => {
		setCorsBusy( true );
		try {
			const res = await API.post( `${ namespace }connection/cors`, { bucket: 'both' } );
			setCors( res.data );
			notify( dispatch, __( 'CORS rules written.', 'gemcloud' ) );
		} catch ( e ) {
			notify( dispatch, errorMessage( e ), 'error' );
		} finally {
			setCorsBusy( false );
		}
	};

	return (
		<PageLayout title={ __( 'Tools', 'gemcloud' ) } description={ __( 'Bulk jobs run in the background through Action Scheduler; you can leave this page.', 'gemcloud' ) }>
			<div className="flex flex-col gap-6">
				{ unavailable && (
					<GCSAlert type="info">{ __( 'Connect a bucket and switch cloud storage on in Settings before running these tools.', 'gemcloud' ) }</GCSAlert>
				) }

				<div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
					<Section
						title={ __( 'Offload existing media', 'gemcloud' ) }
						description={ __( 'Walks the media library 50 attachments at a time and queues every file and image size. Safe to stop and resume.', 'gemcloud' ) }
						actions={ runPill( migration.status ) }
					>
						<div className="flex flex-col gap-5">
							<Progress done={ migration.processed || 0 } total={ migration.total || data?.attachments || 0 } />
							<div className="flex flex-wrap gap-2">
								{ migration.status === 'running' ? (
									<button type="button" className="gemcloud-btn gemcloud-btn--outline" disabled={ !! busy } onClick={ () => run( 'migration/stop' ) }>
										<Square size={ 14 } />
										{ __( 'Stop', 'gemcloud' ) }
									</button>
								) : (
									<button type="button" className="gemcloud-btn gemcloud-btn--primary" disabled={ unavailable || !! busy } onClick={ () => run( 'migration/start' ) }>
										<CloudUpload size={ 15 } />
										{ migration.status === 'stopped' ? __( 'Resume', 'gemcloud' ) : __( 'Start offloading', 'gemcloud' ) }
									</button>
								) }
								{ ( migration.status === 'done' || migration.status === 'stopped' ) && (
									<button type="button" className="gemcloud-btn gemcloud-btn--outline" disabled={ unavailable || !! busy } onClick={ () => run( 'migration/start', { restart: true } ) }>
										<RotateCcw size={ 14 } />
										{ __( 'Start over', 'gemcloud' ) }
									</button>
								) }
							</div>
						</div>
					</Section>

					<Section
						title={ __( 'Upload queue', 'gemcloud' ) }
						description={ __( 'Temporary errors retry after 1 min, 5 min, 30 min, 2 h and 6 h. Five connection errors in a row pause the queue.', 'gemcloud' ) }
						actions={ <StatusPill status={ queue.paused ? 'error' : 'ok' } label={ queue.paused ? __( 'Paused', 'gemcloud' ) : __( 'Running', 'gemcloud' ) } /> }
					>
						<div className="flex flex-col gap-5">
							<div className="grid grid-cols-3 gap-3">
								{ [
									[ __( 'Waiting jobs', 'gemcloud' ), queue.pending ],
									[ __( 'Running', 'gemcloud' ), queue.running ],
									[ __( 'Failed jobs', 'gemcloud' ), queue.failed ],
								].map( ( [ label, value ] ) => (
									<div key={ label } className="rounded-[6px] border border-[var(--gemcloud-border-color)] p-3">
										<div className="text-[12px] text-[var(--gemcloud-font-secondary-color)]">{ label }</div>
										<div className="mt-1 text-[20px] font-semibold">{ value === null || value === undefined ? '—' : formatNumber( value ) }</div>
									</div>
								) ) }
							</div>
							{ queue.paused && queue.message && <GCSAlert type="error">{ queue.message }</GCSAlert> }
							<div className="flex flex-wrap gap-2">
								{ queue.paused ? (
									<button type="button" className="gemcloud-btn gemcloud-btn--primary" disabled={ !! busy } onClick={ () => run( 'queue/resume' ) }>
										<Play size={ 14 } />
										{ __( 'Resume uploads', 'gemcloud' ) }
									</button>
								) : (
									<button type="button" className="gemcloud-btn gemcloud-btn--outline" disabled={ !! busy } onClick={ () => run( 'queue/pause' ) }>
										<Pause size={ 14 } />
										{ __( 'Pause uploads', 'gemcloud' ) }
									</button>
								) }
								<span className="self-center text-[12px] text-[var(--gemcloud-text-muted)]">
									{ /* translators: %s: queue engine name */ }
									{ sprintf( __( 'Engine: %s', 'gemcloud' ), queue.engine === 'action-scheduler' ? 'Action Scheduler' : 'WP-Cron' ) }
								</span>
							</div>
						</div>
					</Section>

					<Section
						title={ __( 'Bring files back', 'gemcloud' ) }
						description={ __( 'Downloads every file Offload mode removed from this server. Use it before leaving the cloud or after switching local files back to "Keep".', 'gemcloud' ) }
						actions={ runPill( back.status ) }
					>
						<div className="flex flex-col gap-5">
							<div className="text-[13px] text-[var(--gemcloud-font-secondary-color)]">
								{ /* translators: %s: number of files */ }
								{ sprintf( __( '%s files are only in the cloud right now.', 'gemcloud' ), formatNumber( data?.stats?.local_removed ) ) }
							</div>
							{ back.status !== 'idle' && back.total > 0 && <Progress done={ back.processed + back.failed } total={ back.total } /> }
							<div className="flex flex-wrap gap-2">
								{ back.status === 'running' ? (
									<button type="button" className="gemcloud-btn gemcloud-btn--outline" disabled={ !! busy } onClick={ () => run( 'download-back/stop' ) }>
										<Square size={ 14 } />
										{ __( 'Stop', 'gemcloud' ) }
									</button>
								) : (
									<button
										type="button"
										className="gemcloud-btn gemcloud-btn--outline"
										disabled={ unavailable || !! busy || ! data?.stats?.local_removed }
										onClick={ () => setConfirm( 'download-back' ) }
									>
										<Download size={ 15 } />
										{ __( 'Download files back', 'gemcloud' ) }
									</button>
								) }
							</div>
						</div>
					</Section>

					<Section
						title={ __( 'Bucket CORS', 'gemcloud' ) }
						description={ __( 'Lets this site read files across origins: video subtitles, web fonts and direct browser uploads need it. Writes one rule allowing GET, HEAD and PUT from this site.', 'gemcloud' ) }
					>
						<div className="flex flex-col gap-4">
							<button type="button" className="gemcloud-btn gemcloud-btn--outline self-start" disabled={ unavailable || corsBusy } onClick={ configureCors }>
								<Globe size={ 15 } />
								{ corsBusy ? __( 'Writing…', 'gemcloud' ) : __( 'Configure CORS', 'gemcloud' ) }
							</button>
							{ cors && (
								<GCSAlert type="success">
									{ /* translators: 1: bucket names, 2: origins */ }
									{ sprintf( __( 'Rule written to %1$s for %2$s.', 'gemcloud' ), cors.buckets.join( ', ' ), cors.origins.join( ', ' ) ) }
								</GCSAlert>
							) }
						</div>
					</Section>
				</div>

				<Section title={ __( 'WP-CLI', 'gemcloud' ) } description={ __( 'For large libraries, or hosts where WP-Cron is disabled.', 'gemcloud' ) } actions={ <Terminal size={ 18 } className="text-[var(--gemcloud-text-muted)]" /> } bodyClassName="">
					<table className="gemcloud-table">
						<tbody>
							{ CLI.map( ( [ cmd, desc ] ) => (
								<tr key={ cmd }>
									<td className="w-[340px]"><code className="gemcloud-code">{ cmd }</code></td>
									<td className="text-[var(--gemcloud-font-secondary-color)]">{ desc }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</Section>

				<Section
					title={ __( 'Developer API', 'gemcloud' ) }
					description={ __( 'Academy LMS, StoreEngine, ZenCommunity or any plugin registers a context, hands files over, and asks for URLs. Every call falls back to local behaviour when storage is off.', 'gemcloud' ) }
					actions={ <Code2 size={ 18 } className="text-[var(--gemcloud-text-muted)]" /> }
				>
					<CodeBlock title="PHP" code={ API_SNIPPET } />
				</Section>
			</div>

			<ConfirmModal
				open={ confirm === 'download-back' }
				title={ __( 'Download files back?', 'gemcloud' ) }
				confirmLabel={ __( 'Start downloading', 'gemcloud' ) }
				onConfirm={ () => run( 'download-back/start' ) }
				onClose={ () => setConfirm( null ) }
			>
				{ __( 'Every file that is only in the cloud is downloaded to this server again. Check there is enough free disk space first. Cloud copies stay where they are.', 'gemcloud' ) }
			</ConfirmModal>
		</PageLayout>
	);
}
