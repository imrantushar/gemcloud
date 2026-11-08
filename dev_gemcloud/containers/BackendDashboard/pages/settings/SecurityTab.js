import React, { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { RefreshCw } from 'lucide-react';
import GCSAlert from '@GCSComponents/GCSAlert';
import CodeBlock from '@GCSComponents/CodeBlock';
import { ChoiceCard, SectionTitle } from './common';

const TTL_PRESETS = [
	[ 60, __( '1 minute', 'gemcloud' ) ],
	[ 300, __( '5 minutes', 'gemcloud' ) ],
	[ 900, __( '15 minutes', 'gemcloud' ) ],
	[ 3600, __( '1 hour', 'gemcloud' ) ],
	[ 86400, __( '1 day', 'gemcloud' ) ],
];

/** 32 random bytes, base64: a key for GEMCLOUD_KEY, made in the browser. */
const randomKey = () => {
	const bytes = new Uint8Array( 32 );
	window.crypto.getRandomValues( bytes );
	return window.btoa( String.fromCharCode( ...bytes ) );
};

/** define() lines with their comments lined up. */
const defines = ( rows ) => {
	const width = Math.max( ...rows.map( ( [ name, value ] ) => `define( '${ name }', '${ value }' );`.length ) ) + 2;
	return rows
		.map( ( [ name, value, comment ] ) => {
			const line = `define( '${ name }', '${ value }' );`;
			return comment ? `${ line.padEnd( width ) }// ${ comment }` : line;
		} )
		.join( '\n' );
};

const SecurityTab = ( { form, meta, setSetting } ) => {
	const s = form.settings;
	const [ key, setKey ] = useState( randomKey );

	const keySnippet = `// Encryption key for secrets stored in the database. Keep it private.\n${ defines( [ [ 'GEMCLOUD_KEY', key ] ] ) }`;

	const connectionSnippet = `// GemCloud connection, kept out of the database.\n${ defines( [
		[ 'GEMCLOUD_PROVIDER', s.provider, 'r2, aws or custom' ],
		...( s.provider === 'r2' ? [ [ 'GEMCLOUD_ACCOUNT_ID', s.endpoint.account_id || 'your-account-id', 'Cloudflare R2 only' ] ] : [] ),
		[ 'GEMCLOUD_ACCESS_KEY_ID', form.credentials.access_key_id || 'your-access-key-id' ],
		[ 'GEMCLOUD_SECRET_ACCESS_KEY', 'your-secret-access-key', 'shown only once by your provider' ],
	] ) }`;

	return (
		<div className="flex flex-col gap-8">
			<div>
				<SectionTitle
					title={ __( 'Signed URL lifetime', 'gemcloud' ) }
					description={ __( 'A signed URL is created at the moment of the click, after the access check, and is never printed into a page. The signature is checked when the download starts, so a long download that starts in time still finishes.', 'gemcloud' ) }
				/>
				<label className="gemcloud-label" htmlFor="gemcloud-f-signed-ttl">{ __( 'Lifetime', 'gemcloud' ) }</label>
				<div className="flex flex-wrap items-center gap-3">
					<div className="relative w-[180px]">
						<input
							id="gemcloud-f-signed-ttl"
							className="gemcloud-input !pr-[72px]"
							type="number"
							min="1"
							max="604800"
							value={ s.signed_url_ttl }
							onChange={ ( e ) => setSetting( [ 'signed_url_ttl' ], Math.max( 1, Math.min( 604800, parseInt( e.target.value || '0', 10 ) || 1 ) ) ) }
						/>
						<span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[13px] text-[var(--gemcloud-text-muted)]">
							{ __( 'seconds', 'gemcloud' ) }
						</span>
					</div>
					<div className="gemcloud-segmented" role="group" aria-label={ __( 'Common lifetimes', 'gemcloud' ) }>
						{ TTL_PRESETS.map( ( [ value, label ] ) => (
							<button
								key={ value }
								type="button"
								aria-pressed={ s.signed_url_ttl === value }
								className={ s.signed_url_ttl === value ? 'is-active' : '' }
								onClick={ () => setSetting( [ 'signed_url_ttl' ], value ) }
							>
								{ label }
							</button>
						) ) }
					</div>
				</div>
				<p className="gemcloud-help">{ __( '1 to 604,800 seconds (7 days). Default 300 (5 minutes). Video and audio links always last at least 4 hours, so a long lesson keeps playing.', 'gemcloud' ) }</p>
			</div>

			<div>
				<SectionTitle title={ __( 'Private file delivery', 'gemcloud' ) } />
				<div className="flex flex-col gap-3">
					<ChoiceCard
						name="private_delivery"
						value="redirect"
						checked={ s.private_delivery === 'redirect' }
						onChange={ ( v ) => setSetting( [ 'private_delivery' ], v ) }
						title={ __( 'Redirect to a signed URL', 'gemcloud' ) }
						badge={ __( 'Recommended', 'gemcloud' ) }
						description={ __( 'The browser downloads straight from the bucket. No load on this server.', 'gemcloud' ) }
					/>
					<ChoiceCard
						name="private_delivery"
						value="stream"
						checked={ s.private_delivery === 'stream' }
						onChange={ ( v ) => setSetting( [ 'private_delivery' ], v ) }
						title={ __( 'Stream through this server', 'gemcloud' ) }
						description={ __( 'For networks that block redirects to the storage provider. Supports resumable downloads, but every byte passes through PHP.', 'gemcloud' ) }
					/>
				</div>
			</div>

			<div className="flex flex-col gap-3">
				<SectionTitle
					title={ __( 'How secrets are kept', 'gemcloud' ) }
					description={ __( 'Add these lines to wp-config.php, above the line /* That\'s all, stop editing! */.', 'gemcloud' ) }
				/>
				<div className="flex flex-col gap-5">
					{ meta.constants?.encryption_key ? (
						<GCSAlert type="success">{ __( 'The secret key is encrypted with GEMCLOUD_KEY from wp-config.php.', 'gemcloud' ) }</GCSAlert>
					) : (
						<div className="flex flex-col gap-3">
							<GCSAlert type="info">
								{ __( 'The secret key is encrypted with a key made from your WordPress salts, so changing the salts makes it unreadable. Your own key in wp-config.php avoids that. One was generated for you below, in this browser; it is never sent anywhere.', 'gemcloud' ) }
							</GCSAlert>
							<CodeBlock
								title="wp-config.php"
								code={ keySnippet }
								actions={
									<button type="button" className="gemcloud-btn gemcloud-btn--outline gemcloud-btn--sm" onClick={ () => setKey( randomKey() ) }>
										<RefreshCw size={ 13 } />
										{ __( 'New key', 'gemcloud' ) }
									</button>
								}
							/>
							<GCSAlert type="warning">
								{ __( 'After adding the key, enter each provider\'s secret access key again under Connection and save: secrets stored before were encrypted with the old key.', 'gemcloud' ) }
							</GCSAlert>
						</div>
					) }

					<div className="flex flex-col gap-2">
						<div className="text-[13px] font-semibold">{ __( 'Keep the connection out of the database (optional)', 'gemcloud' ) }</div>
						<p className="gemcloud-help !mt-0">
							{ __( 'Filled in from your current connection; paste your secret access key in place of the placeholder. These values then win over the Connection tab, which shows them locked.', 'gemcloud' ) }
						</p>
						<CodeBlock title="wp-config.php" code={ connectionSnippet } />
					</div>
				</div>
			</div>
		</div>
	);
};

export default SecurityTab;
