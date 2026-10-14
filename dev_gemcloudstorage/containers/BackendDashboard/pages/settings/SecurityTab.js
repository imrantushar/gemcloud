import React from 'react';
import { __ } from '@wordpress/i18n';
import Field from '@GCSComponents/Field';
import GCSAlert from '@GCSComponents/GCSAlert';
import { ChoiceCard, SectionTitle } from './common';

const TTL_PRESETS = [
	[ 60, __( '1 minute', 'gemcloudstorage' ) ],
	[ 300, __( '5 minutes', 'gemcloudstorage' ) ],
	[ 900, __( '15 minutes', 'gemcloudstorage' ) ],
	[ 3600, __( '1 hour', 'gemcloudstorage' ) ],
	[ 86400, __( '1 day', 'gemcloudstorage' ) ],
];

const SecurityTab = ( { form, meta, setSetting } ) => {
	const s = form.settings;

	return (
		<div className="flex flex-col gap-8">
			<div>
				<SectionTitle
					title={ __( 'Signed URL lifetime', 'gemcloudstorage' ) }
					description={ __( 'A signed URL is created at the moment of the click, after the access check, and is never printed into a page. The signature is checked when the download starts, so a long download that starts in time still finishes.', 'gemcloudstorage' ) }
				/>
				<div className="flex flex-wrap items-end gap-3">
					<Field label={ __( 'Seconds', 'gemcloudstorage' ) } help={ __( '1 to 604,800 (7 days). Default 300.', 'gemcloudstorage' ) } className="w-[180px]">
						<input
							className="gcs-input"
							type="number"
							min="1"
							max="604800"
							value={ s.signed_url_ttl }
							onChange={ ( e ) => setSetting( [ 'signed_url_ttl' ], Math.max( 1, Math.min( 604800, parseInt( e.target.value || '0', 10 ) || 1 ) ) ) }
						/>
					</Field>
					<div className="flex flex-wrap gap-2 pb-[26px]">
						{ TTL_PRESETS.map( ( [ value, label ] ) => (
							<button
								key={ value }
								type="button"
								className={ `gcs-btn gcs-btn--sm ${ s.signed_url_ttl === value ? 'gcs-btn--soft' : 'gcs-btn--outline' }` }
								onClick={ () => setSetting( [ 'signed_url_ttl' ], value ) }
							>
								{ label }
							</button>
						) ) }
					</div>
				</div>
			</div>

			<div>
				<SectionTitle title={ __( 'Private file delivery', 'gemcloudstorage' ) } />
				<div className="flex flex-col gap-3">
					<ChoiceCard
						name="private_delivery"
						value="redirect"
						checked={ s.private_delivery === 'redirect' }
						onChange={ ( v ) => setSetting( [ 'private_delivery' ], v ) }
						title={ __( 'Redirect to a signed URL', 'gemcloudstorage' ) }
						badge={ __( 'Recommended', 'gemcloudstorage' ) }
						description={ __( 'The browser downloads straight from the bucket. No load on this server.', 'gemcloudstorage' ) }
					/>
					<ChoiceCard
						name="private_delivery"
						value="stream"
						checked={ s.private_delivery === 'stream' }
						onChange={ ( v ) => setSetting( [ 'private_delivery' ], v ) }
						title={ __( 'Stream through this server', 'gemcloudstorage' ) }
						description={ __( 'For networks that block redirects to the storage provider. Supports resumable downloads, but every byte passes through PHP.', 'gemcloudstorage' ) }
					/>
				</div>
			</div>

			<div className="flex flex-col gap-3">
				<SectionTitle title={ __( 'How secrets are kept', 'gemcloudstorage' ) } />
				<GCSAlert type={ meta.constants?.encryption_key ? 'success' : 'info' }>
					{ meta.constants?.encryption_key
						? __( 'The secret key is encrypted with GEMCLOUDSTORAGE_KEY from wp-config.php.', 'gemcloudstorage' )
						: __( 'The secret key is encrypted with a key derived from your WordPress salts. Rotating the salts makes it unreadable; define GEMCLOUDSTORAGE_KEY in wp-config.php to avoid that.', 'gemcloudstorage' ) }
				</GCSAlert>
				<pre className="m-0 overflow-x-auto rounded-[6px] bg-[var(--gcs-secondary-color)] p-4 text-[12px] leading-[1.7] text-[var(--gcs-font-color)]">
					<code>{ `// wp-config.php: keep credentials out of the database entirely.
define( 'GEMCLOUDSTORAGE_PROVIDER', 'r2' );          // r2 | aws | custom
define( 'GEMCLOUDSTORAGE_ACCOUNT_ID', '…' );         // R2 only
define( 'GEMCLOUDSTORAGE_ACCESS_KEY_ID', '…' );
define( 'GEMCLOUDSTORAGE_SECRET_ACCESS_KEY', '…' );
define( 'GEMCLOUDSTORAGE_KEY', '…' );                // encryption key for stored secrets` }</code>
				</pre>
			</div>
		</div>
	);
};

export default SecurityTab;
