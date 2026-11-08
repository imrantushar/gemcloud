import React from 'react';
import { __, sprintf } from '@wordpress/i18n';
import Field from '@GCSComponents/Field';
import GCSToggle from '@GCSComponents/GCSToggle';
import GCSAlert from '@GCSComponents/GCSAlert';
import { Lock } from 'lucide-react';
import { ChoiceCard, SectionTitle } from './common';

const MediaTab = ( { form, meta, setSetting } ) => {
	const s = form.settings;

	return (
		<div className="flex flex-col gap-8">
			{ meta.conflicts?.length > 0 && (
				<GCSAlert type="warning" title={ __( 'Media offload is off', 'gemcloud' ) }>
					{ sprintf(
						/* translators: %s: plugin names */
						__( '%s is active and rewrites the same URLs. Deactivate it to offload the media library here.', 'gemcloud' ),
						meta.conflicts.join( ', ' )
					) }
				</GCSAlert>
			) }

			<div className="flex items-start justify-between gap-6">
				<div>
					<SectionTitle
						title={ __( 'Offload new uploads', 'gemcloud' ) }
						description={ __( 'Every new media library file, and every image size WordPress generates, is copied to the cloud in the background. URLs switch to the cloud only once the upload is verified, so a failed upload never shows a broken image.', 'gemcloud' ) }
					/>
				</div>
				<GCSToggle checked={ !! s.media.offload } onChange={ ( v ) => setSetting( [ 'media', 'offload' ], v ) } label={ __( 'Offload new uploads', 'gemcloud' ) } />
			</div>

			<div>
				<SectionTitle
					title={ __( 'Local files', 'gemcloud' ) }
					description={ __( 'What happens to the copy on this server after a verified upload.', 'gemcloud' ) }
				/>
				<div className="flex flex-col gap-3">
					<ChoiceCard
						name="local_files"
						value="keep"
						checked={ s.media.local_files === 'keep' }
						onChange={ ( v ) => setSetting( [ 'media', 'local_files' ], v ) }
						title={ __( 'Keep local copies (Sync mode)', 'gemcloud' ) }
						badge={ __( 'Recommended', 'gemcloud' ) }
						description={ __( 'The cloud gets a copy, nothing is deleted. No disk space is freed, but every plugin that reads files from disk keeps working.', 'gemcloud' ) }
					/>
					<ChoiceCard
						name="local_files"
						value="remove_sizes"
						checked={ s.media.local_files === 'remove_sizes' }
						onChange={ ( v ) => setSetting( [ 'media', 'local_files' ], v ) }
						title={ __( 'Remove generated sizes', 'gemcloud' ) }
						description={ __( 'Deletes thumbnails and other generated sizes; originals stay as a backup. Most of the disk saving, very little risk.', 'gemcloud' ) }
					/>
					<ChoiceCard
						name="local_files"
						value="remove_all"
						checked={ s.media.local_files === 'remove_all' }
						onChange={ ( v ) => setSetting( [ 'media', 'local_files' ], v ) }
						title={ __( 'Remove all local files (Offload mode)', 'gemcloud' ) }
						description={ __( 'Deletes every local copy. When the image editor, thumbnail regeneration or another plugin needs a file, it is downloaded back automatically for a day.', 'gemcloud' ) }
					/>
				</div>
			</div>

			<div className="grid grid-cols-1 gap-5">
				<Field
					label={
						<>
							{ __( 'Path prefix', 'gemcloud' ) }
							<span className="inline-flex items-center gap-1 rounded-full bg-[var(--gemcloud-secondary-color)] px-2 py-0.5 text-[11px] font-medium text-[var(--gemcloud-font-secondary-color)]">
								<Lock size={ 11 } />
								{ __( 'Automatic', 'gemcloud' ) }
							</span>
						</>
					}
					help={
						<>
							{ __( 'Set automatically from where WordPress keeps uploads, so a file\'s cloud address mirrors its local one:', 'gemcloud' ) }{ ' ' }
							<code className="gemcloud-code">{ `${ s.path_prefix }/2026/10/photo.jpg` }</code>
						</>
					}
				>
					<div className="relative">
						<input id="gemcloud-f-path-prefix" className="gemcloud-input !pr-10" value={ s.path_prefix } readOnly aria-readonly="true" />
						<Lock size={ 15 } className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[var(--gemcloud-text-muted)]" aria-hidden="true" />
					</div>
				</Field>
			</div>

			<GCSAlert type="info" title={ __( 'Private media', 'gemcloud' ) }>
				{ __( 'Any media library file can be marked “Private” in its attachment details. It then moves to the private bucket and is served through a signed URL to logged-in users who can read it.', 'gemcloud' ) }
			</GCSAlert>
		</div>
	);
};

export default MediaTab;
