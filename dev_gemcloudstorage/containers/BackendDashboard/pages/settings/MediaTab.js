import React from 'react';
import { __, sprintf } from '@wordpress/i18n';
import Field from '@GCSComponents/Field';
import GCSToggle from '@GCSComponents/GCSToggle';
import GCSAlert from '@GCSComponents/GCSAlert';
import { ChoiceCard, SectionTitle } from './common';

const MediaTab = ( { form, meta, setSetting } ) => {
	const s = form.settings;

	return (
		<div className="flex flex-col gap-8">
			{ meta.conflicts?.length > 0 && (
				<GCSAlert type="warning" title={ __( 'Media offload is off', 'gemcloudstorage' ) }>
					{ sprintf(
						/* translators: %s: plugin names */
						__( '%s is active and rewrites the same URLs. Deactivate it to offload the media library here.', 'gemcloudstorage' ),
						meta.conflicts.join( ', ' )
					) }
				</GCSAlert>
			) }

			<div className="flex items-start justify-between gap-6">
				<div>
					<SectionTitle
						title={ __( 'Offload new uploads', 'gemcloudstorage' ) }
						description={ __( 'Every new media library file, and every image size WordPress generates, is copied to the cloud in the background. URLs switch to the cloud only once the upload is verified, so a failed upload never shows a broken image.', 'gemcloudstorage' ) }
					/>
				</div>
				<GCSToggle checked={ !! s.media.offload } onChange={ ( v ) => setSetting( [ 'media', 'offload' ], v ) } label={ __( 'Offload new uploads', 'gemcloudstorage' ) } />
			</div>

			<div>
				<SectionTitle
					title={ __( 'Local files', 'gemcloudstorage' ) }
					description={ __( 'What happens to the copy on this server after a verified upload.', 'gemcloudstorage' ) }
				/>
				<div className="flex flex-col gap-3">
					<ChoiceCard
						name="local_files"
						value="keep"
						checked={ s.media.local_files === 'keep' }
						onChange={ ( v ) => setSetting( [ 'media', 'local_files' ], v ) }
						title={ __( 'Keep local copies (Sync mode)', 'gemcloudstorage' ) }
						badge={ __( 'Recommended', 'gemcloudstorage' ) }
						description={ __( 'The cloud gets a copy, nothing is deleted. No disk space is freed, but every plugin that reads files from disk keeps working.', 'gemcloudstorage' ) }
					/>
					<ChoiceCard
						name="local_files"
						value="remove_sizes"
						checked={ s.media.local_files === 'remove_sizes' }
						onChange={ ( v ) => setSetting( [ 'media', 'local_files' ], v ) }
						title={ __( 'Remove generated sizes', 'gemcloudstorage' ) }
						description={ __( 'Deletes thumbnails and other generated sizes; originals stay as a backup. Most of the disk saving, very little risk.', 'gemcloudstorage' ) }
					/>
					<ChoiceCard
						name="local_files"
						value="remove_all"
						checked={ s.media.local_files === 'remove_all' }
						onChange={ ( v ) => setSetting( [ 'media', 'local_files' ], v ) }
						title={ __( 'Remove all local files (Offload mode)', 'gemcloudstorage' ) }
						description={ __( 'Deletes every local copy. When the image editor, thumbnail regeneration or another plugin needs a file, it is downloaded back automatically for a day.', 'gemcloudstorage' ) }
					/>
				</div>
			</div>

			<div className="grid grid-cols-1 gap-5 md:grid-cols-2">
				<Field
					label={ __( 'Path prefix', 'gemcloudstorage' ) }
					help={ __( 'Public media keys mirror the uploads folder under this prefix: wp-content/uploads/2026/10/photo.jpg. Changing it only affects new uploads.', 'gemcloudstorage' ) }
				>
					<input className="gcs-input" value={ s.path_prefix } onChange={ ( e ) => setSetting( [ 'path_prefix' ], e.target.value ) } placeholder="wp-content/uploads" />
				</Field>
			</div>

			<GCSAlert type="info" title={ __( 'Private media', 'gemcloudstorage' ) }>
				{ __( 'Any media library file can be marked “Private” in its attachment details. It then moves to the private bucket and is served through a signed URL to logged-in users who can read it.', 'gemcloudstorage' ) }
			</GCSAlert>
		</div>
	);
};

export default MediaTab;
