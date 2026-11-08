import React from 'react';
import { __ } from '@wordpress/i18n';
import GCSToggle from '@GCSComponents/GCSToggle';
import StatusPill from '@GCSComponents/StatusPill';
import { SectionTitle } from './common';
import GCSSelect from '@GCSComponents/GCSSelect';

/**
 * Each plugin registers the kinds of files it stores. The admin can switch a
 * context off or change where it goes, without touching the others.
 */
const ContextsTab = ( { form, meta, setSetting } ) => {
	const overrides = form.settings.contexts || {};

	// PHP sends an empty map as [].
	const kindsOf = ( o ) => ( o?.kinds && ! Array.isArray( o.kinds ) ? o.kinds : {} );

	const setContext = ( key, patch ) => {
		const context = meta.contexts.find( ( c ) => c.key === key );
		const current = overrides[ key ] || { enabled: context.enabled, visibility: context.visibility };
		setSetting( [ 'contexts', key ], { ...current, kinds: kindsOf( current ), ...patch } );
	};

	const setKind = ( key, kind, value ) => {
		setContext( key, { kinds: { ...kindsOf( overrides[ key ] ), [ kind ]: value } } );
	};

	return (
		<div className="flex flex-col gap-6">
			<SectionTitle
				title={ __( 'Contexts', 'gemcloud' ) }
				description={ __( 'A context is a kind of file a plugin stores: the media library, paid downloads, course materials, chat attachments. Academy LMS, StoreEngine and ZenCommunity add theirs when they are active.', 'gemcloud' ) }
			/>
			<div className="flex flex-col divide-y divide-[var(--gcs-border-color)] rounded-[8px] border border-[var(--gcs-border-color)]">
				{ meta.contexts.map( ( context ) => {
					const o = overrides[ context.key ] || {};
					const enabled = o.enabled ?? context.enabled;
					const visibility = context.locked?.includes( 'visibility' ) ? context.visibility : o.visibility ?? context.visibility;
					const kinds = Object.entries( Array.isArray( context.kinds ) ? {} : context.kinds || {} );
					return (
						<div key={ context.key } className="flex flex-col gap-3 p-4">
						<div className="flex flex-wrap items-center justify-between gap-4">
							<div className="min-w-0 max-w-[520px]">
								<div className="flex flex-wrap items-center gap-2">
									<span className="text-[14px] font-medium">{ context.label }</span>
									<code className="gcs-code">{ context.key }</code>
								</div>
								{ context.description && <p className="mt-1 text-[13px] text-[var(--gcs-font-secondary-color)]">{ context.description }</p> }
								{ context.source && <p className="mt-1 text-[12px] text-[var(--gcs-text-muted)]">{ context.source }</p> }
							</div>
							<div className="flex items-center gap-4">
								{ context.locked?.includes( 'visibility' ) ? (
									<StatusPill status={ visibility === 'private' ? 'warning' : 'ok' } label={ visibility === 'private' ? __( 'Private bucket', 'gemcloud' ) : __( 'Public bucket', 'gemcloud' ) } />
								) : (
									<GCSSelect
										width={ 160 }
										value={ visibility }
										onChange={ ( v ) => setContext( context.key, { visibility: v } ) }
										options={ [
											{ value: 'public', label: __( 'Public bucket', 'gemcloud' ) },
											{ value: 'private', label: __( 'Private bucket', 'gemcloud' ) },
										] }
										aria-label={ __( 'Bucket', 'gemcloud' ) }
									/>
								) }
								<GCSToggle checked={ !! enabled } onChange={ ( v ) => setContext( context.key, { enabled: v } ) } label={ context.label } />
							</div>
						</div>
						{ kinds.length > 0 && (
							<div className={ `flex flex-col divide-y divide-[var(--gcs-border-color)] rounded-[6px] bg-[var(--gcs-secondary-color)] ${ enabled ? '' : 'opacity-60' }` }>
								{ kinds.map( ( [ kind, info ] ) => {
									const on = kindsOf( o )[ kind ] ?? info.enabled ?? true;
									return (
										<div key={ kind } className="flex items-center justify-between gap-4 px-4 py-3">
											<div className="min-w-0 max-w-[520px]">
												<div className="text-[13px] font-medium">{ info.label }</div>
												{ info.description && <p className="mt-0.5 text-[12px] text-[var(--gcs-font-secondary-color)]">{ info.description }</p> }
											</div>
											<GCSToggle size="sm" checked={ !! on && !! enabled } disabled={ ! enabled } onChange={ ( v ) => setKind( context.key, kind, v ) } label={ info.label } />
										</div>
									);
								} ) }
								<p className="px-4 py-2.5 text-[12px] text-[var(--gcs-text-muted)]">
									{ __( 'Switching a kind off keeps new files of that kind public. Files already private stay private, and the people allowed to open them still can.', 'gemcloud' ) }
								</p>
							</div>
						) }
						</div>
					);
				} ) }
			</div>
		</div>
	);
};

export default ContextsTab;
