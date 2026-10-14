import React, { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { BookOpen, Check, ChevronDown, CornerDownRight, ExternalLink } from 'lucide-react';
import CodeBlock from '@GCSComponents/CodeBlock';

const json = ( value ) => JSON.stringify( value, null, 2 );

/** A field a step fills: chip that jumps to it, and the value that marks the step done. */
const fill = ( id, label, value, optional = false ) => ( { id, label, value, optional } );

const isFilled = ( f ) => String( f.value || '' ).trim() !== '';

/** Done once every field it must fill has a value (optional ones don't count). */
const isDone = ( fills ) => {
	const required = fills.filter( ( f ) => ! f.optional );
	return required.length > 0 && required.every( isFilled );
};

const jumpTo = ( id ) => {
	const el = document.getElementById( id );
	if ( el ) {
		el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		el.focus( { preventScroll: true } );
	}
};

// ── Provider guides ──────────────────────────────────────────────────────

const r2Steps = ( s, c, origin ) => [
	{
		title: __( 'Open R2 in your Cloudflare dashboard', 'gemcloudstorage' ),
		body: [
			__( 'Log in to Cloudflare and choose R2 Object Storage in the sidebar. The first time, Cloudflare asks you to turn R2 on and add a payment method. The free tier includes 10 GB of storage a month, and downloads are always free.', 'gemcloudstorage' ),
		],
		links: [ { label: __( 'Open R2', 'gemcloudstorage' ), url: 'https://dash.cloudflare.com/?to=/:account/r2/overview' } ],
	},
	{
		title: __( 'Copy your Account ID', 'gemcloudstorage' ),
		body: [ __( 'On the R2 Overview page, the Account ID is in the Account Details panel on the right. Copy it into Account ID.', 'gemcloudstorage' ) ],
		fills: [ fill( 'gcs-f-account-id', __( 'Account ID', 'gemcloudstorage' ), s.endpoint.account_id ) ],
	},
	{
		title: __( 'Create the public bucket', 'gemcloudstorage' ),
		body: [
			__( 'Select Create bucket and give it a name such as mysite-media (lowercase letters, numbers and hyphens). Leave Location on Automatic and the storage class on Standard, then create it.', 'gemcloudstorage' ),
			__( 'If you choose a jurisdiction (EU or FedRAMP) here, choose the same under Jurisdiction below. Otherwise leave it on Default.', 'gemcloudstorage' ),
		],
		fills: [ fill( 'gcs-f-public-bucket', __( 'Public bucket', 'gemcloudstorage' ), s.buckets.public.name ) ],
	},
	{
		title: __( 'Give the public bucket an address', 'gemcloudstorage' ),
		body: [
			__( 'Open the bucket, then Settings → Public access. Under Custom Domains, select Connect Domain and enter a subdomain of a domain that is already on Cloudflare, such as media.example.com. This is the recommended choice: it is cached by Cloudflare\'s CDN.', 'gemcloudstorage' ),
			__( 'To try things out first, select Allow under R2.dev subdomain instead. That address is rate-limited, so switch to a custom domain before going live.', 'gemcloudstorage' ),
			__( 'Copy the address it shows (https://media.example.com or https://pub-….r2.dev) into Public URL.', 'gemcloudstorage' ),
		],
		fills: [ fill( 'gcs-f-public-url', __( 'Public URL', 'gemcloudstorage' ), s.buckets.public.public_url ) ],
	},
	{
		title: __( 'Create the private bucket', 'gemcloudstorage' ),
		body: [ __( 'Create a second bucket, such as mysite-private, for course videos, downloads and backups. Leave its public access exactly as it is: no custom domain and the R2.dev subdomain off.', 'gemcloudstorage' ) ],
		fills: [ fill( 'gcs-f-private-bucket', __( 'Private bucket', 'gemcloudstorage' ), s.buckets.private.name ) ],
	},
	{
		title: __( 'Create an API token', 'gemcloudstorage' ),
		body: [
			__( 'Back on the R2 Overview, select Manage API tokens, then Create Account API token. An account token keeps working if the person who made it leaves the team.', 'gemcloudstorage' ),
			__( 'Permissions: Object Read & Write. Specify bucket(s): Apply to specific buckets only, and pick both buckets. TTL: Forever. Then create the token.', 'gemcloudstorage' ),
			__( 'Copy the Access Key ID and the Secret Access Key now: Cloudflare shows the secret only once. You don\'t need the token value or the S3 endpoint it also shows.', 'gemcloudstorage' ),
		],
		links: [ { label: __( 'Open API tokens', 'gemcloudstorage' ), url: 'https://dash.cloudflare.com/?to=/:account/r2/api-tokens' } ],
		fills: [
			fill( 'gcs-f-access-key', __( 'Access key ID', 'gemcloudstorage' ), c.access_key_id ),
			fill( 'gcs-f-secret-key', __( 'Secret access key', 'gemcloudstorage' ), c.secret_access_key ),
		],
	},
	{
		title: __( 'Let your site load files from the bucket (CORS)', 'gemcloudstorage' ),
		optional: true,
		body: [
			__( 'Needed for video captions, poster images captured from a video, and web fonts. Open the public bucket, then Settings → CORS Policy → Add CORS policy, paste this and save.', 'gemcloudstorage' ),
			__( 'The Set up CORS button under Tools does the same, but only with a token that has Admin Read & Write permission.', 'gemcloudstorage' ),
		],
		copy: {
			label: __( 'CORS policy', 'gemcloudstorage' ),
			text: json( [ { AllowedOrigins: [ origin ], AllowedMethods: [ 'GET', 'HEAD' ], AllowedHeaders: [ '*' ], ExposeHeaders: [ 'ETag' ], MaxAgeSeconds: 3600 } ] ),
		},
	},
];

const awsSteps = ( s, c ) => {
	const pub = s.buckets.public.name || 'mysite-media';
	const priv = s.buckets.private.name || 'mysite-private';
	const buckets = [ pub, ...( s.buckets.private.name ? [ priv ] : [] ) ];
	return [
		{
			title: __( 'Open S3 and choose a region', 'gemcloudstorage' ),
			body: [ __( 'Sign in to the AWS console and open S3. Pick the region closest to your visitors in the region menu at the top right, and choose the same region under Region below. Both buckets must be in this region.', 'gemcloudstorage' ) ],
			links: [ { label: __( 'Open S3', 'gemcloudstorage' ), url: 'https://s3.console.aws.amazon.com/s3/home' } ],
			fills: [ fill( 'gcs-f-region', __( 'Region', 'gemcloudstorage' ), s.endpoint.region ) ],
		},
		{
			title: __( 'Create the public bucket', 'gemcloudstorage' ),
			body: [
				__( 'Select Create bucket. Bucket type: General purpose. Give it a name such as mysite-media. Object Ownership: ACLs disabled.', 'gemcloudstorage' ),
				__( 'Under Block Public Access settings, clear Block all public access and tick the acknowledgement. Leave everything else as it is and create the bucket.', 'gemcloudstorage' ),
			],
			fills: [ fill( 'gcs-f-public-bucket', __( 'Public bucket', 'gemcloudstorage' ), s.buckets.public.name ) ],
		},
		{
			title: __( 'Allow anyone to read the public bucket', 'gemcloudstorage' ),
			body: [
				__( 'Open the bucket, then Permissions → Bucket policy → Edit. Paste this and save.', 'gemcloudstorage' ),
				__( 'Leave Public URL empty to use the bucket\'s own address, or put a CloudFront distribution in front of the bucket for faster, cheaper delivery and enter its domain (https://d123….cloudfront.net or your own).', 'gemcloudstorage' ),
			],
			copy: {
				label: __( 'Bucket policy', 'gemcloudstorage' ),
				text: json( { Version: '2012-10-17', Statement: [ { Sid: 'PublicRead', Effect: 'Allow', Principal: '*', Action: 's3:GetObject', Resource: `arn:aws:s3:::${ pub }/*` } ] } ),
			},
			fills: [ fill( 'gcs-f-public-url', __( 'Public URL (optional)', 'gemcloudstorage' ), s.buckets.public.public_url, true ) ],
		},
		{
			title: __( 'Create the private bucket', 'gemcloudstorage' ),
			body: [ __( 'Create a second bucket, such as mysite-private, in the same region. Keep Block all public access switched on.', 'gemcloudstorage' ) ],
			fills: [ fill( 'gcs-f-private-bucket', __( 'Private bucket', 'gemcloudstorage' ), s.buckets.private.name ) ],
		},
		{
			title: __( 'Create a user that can only reach these buckets', 'gemcloudstorage' ),
			body: [
				__( 'In IAM, create a user named gemcloudstorage without console access. On the permissions step choose Attach policies directly → Create policy → JSON, paste this policy, name it GemCloudStorage and create it. Then attach it to the user and finish.', 'gemcloudstorage' ),
				__( 'The policy below already has your bucket names. Fill in both bucket fields first, then copy it.', 'gemcloudstorage' ),
			],
			links: [ { label: __( 'Open IAM users', 'gemcloudstorage' ), url: 'https://console.aws.amazon.com/iam/home#/users' } ],
			copy: {
				label: __( 'Permissions policy', 'gemcloudstorage' ),
				text: json( {
					Version: '2012-10-17',
					Statement: [
						{ Effect: 'Allow', Action: [ 's3:ListBucket', 's3:GetBucketLocation', 's3:PutBucketCORS' ], Resource: buckets.map( ( b ) => `arn:aws:s3:::${ b }` ) },
						{ Effect: 'Allow', Action: [ 's3:GetObject', 's3:PutObject', 's3:DeleteObject', 's3:AbortMultipartUpload', 's3:ListMultipartUploadParts' ], Resource: buckets.map( ( b ) => `arn:aws:s3:::${ b }/*` ) },
					],
				} ),
			},
		},
		{
			title: __( 'Create an access key', 'gemcloudstorage' ),
			body: [
				__( 'Open the user, then Security credentials → Create access key. Choose Application running outside AWS and create it.', 'gemcloudstorage' ),
				__( 'Copy the Access key and the Secret access key now: AWS shows the secret only once. You can also download the .csv file.', 'gemcloudstorage' ),
			],
			fills: [
				fill( 'gcs-f-access-key', __( 'Access key ID', 'gemcloudstorage' ), c.access_key_id ),
				fill( 'gcs-f-secret-key', __( 'Secret access key', 'gemcloudstorage' ), c.secret_access_key ),
			],
		},
		{
			title: __( 'Let your site load files from the bucket (CORS)', 'gemcloudstorage' ),
			optional: true,
			body: [ __( 'After saving, select Set up CORS under Tools. It is needed for video captions, poster images captured from a video, and web fonts.', 'gemcloudstorage' ) ],
		},
	];
};

const SERVICES = [
	{
		name: 'Wasabi',
		endpoint: 'https://s3.<region>.wasabisys.com',
		region: 'eu-central-1',
		keys: __( 'Access Keys → Create new access key', 'gemcloudstorage' ),
		url: 'https://console.wasabisys.com/',
	},
	{
		name: 'Backblaze B2',
		endpoint: __( 'The Endpoint shown on the Buckets page, with https:// in front (https://s3.us-west-004.backblazeb2.com)', 'gemcloudstorage' ),
		region: 'us-west-004',
		keys: __( 'Application Keys → Add a New Application Key, for all buckets. keyID is the Access key ID, applicationKey the secret.', 'gemcloudstorage' ),
		url: 'https://secure.backblaze.com/b2_buckets.htm',
	},
	{
		name: 'DigitalOcean Spaces',
		endpoint: 'https://<region>.digitaloceanspaces.com',
		region: 'nyc3',
		keys: __( 'API → Spaces Keys → Generate New Key', 'gemcloudstorage' ),
		url: 'https://cloud.digitalocean.com/spaces',
	},
	{
		name: 'MinIO',
		endpoint: __( 'Your MinIO server address, such as https://minio.example.com:9000. Switch on path-style addressing.', 'gemcloudstorage' ),
		region: 'us-east-1',
		keys: __( 'Access Keys → Create access key', 'gemcloudstorage' ),
		url: null,
	},
];

const customSteps = ( s, c ) => [
	{
		title: __( 'Find your service\'s endpoint and region', 'gemcloudstorage' ),
		body: [ __( 'Every S3-compatible service lists an S3 endpoint for each region. Enter it under Endpoint URL, and the region code under Region.', 'gemcloudstorage' ) ],
		services: true,
		fills: [
			fill( 'gcs-f-endpoint', __( 'Endpoint URL', 'gemcloudstorage' ), s.endpoint.url ),
			fill( 'gcs-f-region', __( 'Region', 'gemcloudstorage' ), s.endpoint.region ),
		],
	},
	{
		title: __( 'Create the public bucket', 'gemcloudstorage' ),
		body: [
			__( 'Create a bucket such as mysite-media and make its files publicly readable. Most services have a Public option when you create the bucket. Others use a bucket policy that allows s3:GetObject for everyone.', 'gemcloudstorage' ),
			__( 'Enter its public address, or your CDN in front of it, under Public URL. Leave Public URL empty to use the endpoint address.', 'gemcloudstorage' ),
		],
		fills: [
			fill( 'gcs-f-public-bucket', __( 'Public bucket', 'gemcloudstorage' ), s.buckets.public.name ),
			fill( 'gcs-f-public-url', __( 'Public URL (optional)', 'gemcloudstorage' ), s.buckets.public.public_url, true ),
		],
	},
	{
		title: __( 'Create the private bucket', 'gemcloudstorage' ),
		body: [ __( 'Create a second bucket, such as mysite-private, and keep it private (the default on every service).', 'gemcloudstorage' ) ],
		fills: [ fill( 'gcs-f-private-bucket', __( 'Private bucket', 'gemcloudstorage' ), s.buckets.private.name ) ],
	},
	{
		title: __( 'Create an access key', 'gemcloudstorage' ),
		body: [ __( 'Create a key that can read, write, list and delete objects in both buckets (where each service keeps them is listed in step 1). Copy both parts: most services show the secret only once.', 'gemcloudstorage' ) ],
		fills: [
			fill( 'gcs-f-access-key', __( 'Access key ID', 'gemcloudstorage' ), c.access_key_id ),
			fill( 'gcs-f-secret-key', __( 'Secret access key', 'gemcloudstorage' ), c.secret_access_key ),
		],
	},
];

// ── Guide ────────────────────────────────────────────────────────────────

const Step = ( { step, index } ) => {
	const fills = ( step.fills || [] ).filter( Boolean );
	const done = isDone( fills );
	return (
		<li className="grid grid-cols-[28px_minmax(0,1fr)] gap-3">
			<span
				className="flex h-7 w-7 items-center justify-center rounded-full text-[12px] font-semibold"
				style={ done ? { background: 'var(--gcs-success-bg)', color: 'var(--gcs-success)' } : { background: 'var(--gcs-second-primary)', color: 'var(--gcs-primary)' } }
				aria-label={ done ? __( 'Done', 'gemcloudstorage' ) : undefined }
			>
				{ done ? <Check size={ 14 } /> : index + 1 }
			</span>
			<div className="min-w-0 pb-1 pt-1">
				<div className="flex flex-wrap items-center gap-2 text-[14px] font-semibold">
					{ step.title }
					{ step.optional && <span className="rounded-full bg-[var(--gcs-secondary-color)] px-2 py-0.5 text-[11px] font-medium text-[var(--gcs-font-secondary-color)]">{ __( 'Recommended', 'gemcloudstorage' ) }</span> }
				</div>
				{ step.body.map( ( p, i ) => (
					<p key={ i } className="mt-1.5 text-[13px] leading-[1.6] text-[var(--gcs-font-secondary-color)]">{ p }</p>
				) ) }

				{ step.services && (
					<div className="mt-2 overflow-x-auto rounded-[6px] border border-[var(--gcs-border-color)]">
						<table className="gcs-table">
							<thead>
								<tr>
									<th>{ __( 'Service', 'gemcloudstorage' ) }</th>
									<th>{ __( 'Endpoint URL', 'gemcloudstorage' ) }</th>
									<th>{ __( 'Region, e.g.', 'gemcloudstorage' ) }</th>
									<th>{ __( 'Access keys', 'gemcloudstorage' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ SERVICES.map( ( svc ) => (
									<tr key={ svc.name }>
										<td className="whitespace-nowrap font-medium">
											{ svc.url ? <a href={ svc.url } target="_blank" rel="noreferrer" className="text-[var(--gcs-primary)]">{ svc.name }</a> : svc.name }
										</td>
										<td className="text-[12px]">{ svc.endpoint }</td>
										<td><code className="gcs-code">{ svc.region }</code></td>
										<td className="text-[12px]">{ svc.keys }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }

				{ step.copy && (
					<div className="mt-2">
						<CodeBlock title={ step.copy.label } code={ step.copy.text } maxHeight={ 260 } />
					</div>
				) }

				{ ( step.links?.length > 0 || fills.length > 0 ) && (
					<div className="mt-2.5 flex flex-wrap items-center gap-2">
						{ ( step.links || [] ).map( ( l ) => (
							<a key={ l.url } href={ l.url } target="_blank" rel="noreferrer" className="gcs-btn gcs-btn--outline gcs-btn--sm">
								<ExternalLink size={ 13 } />
								{ l.label }
							</a>
						) ) }
						{ fills.map( ( f ) => (
							<button
								key={ f.id }
								type="button"
								onClick={ () => jumpTo( f.id ) }
								className="inline-flex items-center gap-1 rounded-full border border-dashed border-[var(--gcs-border-color)] px-2.5 py-1 text-[12px] text-[var(--gcs-font-secondary-color)] hover:border-[var(--gcs-primary)] hover:text-[var(--gcs-primary)]"
								title={ __( 'Go to this field', 'gemcloudstorage' ) }
							>
								<CornerDownRight size={ 12 } />
								{ f.label }
							</button>
						) ) }
					</div>
				) }
			</div>
		</li>
	);
};

/**
 * Where each Connection field comes from, step by step, for the provider
 * chosen above. Open until the connection is filled in.
 */
const SetupGuide = ( { provider, providerLabel, settings, credentials } ) => {
	const origin = window.location.origin;
	const steps = provider === 'r2' ? r2Steps( settings, credentials, origin ) : provider === 'aws' ? awsSteps( settings, credentials ) : customSteps( settings, credentials );
	const filled = steps.flatMap( ( st ) => st.fills || [] );
	const complete = isDone( filled );
	const [ open, setOpen ] = useState( ! complete );

	return (
		<div className="rounded-[8px] border border-[var(--gcs-border-color)] bg-[var(--gcs-secondary-color)]">
			<button
				type="button"
				className="flex w-full items-center justify-between gap-4 bg-transparent px-4 py-3 text-left"
				onClick={ () => setOpen( ( v ) => ! v ) }
				aria-expanded={ open }
			>
				<span className="flex items-center gap-3">
					<span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-[6px] bg-[var(--gcs-second-primary)] text-[var(--gcs-primary)]">
						<BookOpen size={ 16 } />
					</span>
					<span>
						{ /* translators: %s: provider name */ }
						<span className="block text-[14px] font-semibold">{ sprintf( __( 'How to get these details from %s', 'gemcloudstorage' ), providerLabel ) }</span>
						<span className="block text-[12px] text-[var(--gcs-font-secondary-color)]">
							{ complete
								? __( 'Every field is filled in. Open the guide to check a step again.', 'gemcloudstorage' )
								: sprintf(
									/* translators: %d: number of steps */
									__( '%d steps, about 10 minutes. Each step shows which field it fills.', 'gemcloudstorage' ),
									steps.length
								) }
						</span>
					</span>
				</span>
				<ChevronDown size={ 18 } className="shrink-0 text-[var(--gcs-text-muted)] transition-transform" style={ { transform: open ? 'rotate(180deg)' : 'none' } } />
			</button>
			{ open && (
				<ol className="m-0 flex list-none flex-col gap-5 border-t border-[var(--gcs-border-color)] bg-[var(--gcs-background)] px-4 py-5">
					{ steps.map( ( step, i ) => (
						<Step key={ step.title } step={ step } index={ i } />
					) ) }
					<li className="grid grid-cols-[28px_minmax(0,1fr)] gap-3">
						<span className="flex h-7 w-7 items-center justify-center rounded-full bg-[var(--gcs-second-primary)] text-[12px] font-semibold text-[var(--gcs-primary)]">{ steps.length + 1 }</span>
						<div className="pt-1">
							<div className="text-[14px] font-semibold">{ __( 'Test, then save', 'gemcloudstorage' ) }</div>
							<p className="mt-1.5 text-[13px] leading-[1.6] text-[var(--gcs-font-secondary-color)]">
								{ __( 'Select Test connection at the bottom. When both buckets show Works, select Save changes and switch Cloud storage on at the top.', 'gemcloudstorage' ) }
							</p>
						</div>
					</li>
				</ol>
			) }
		</div>
	);
};

export default SetupGuide;
