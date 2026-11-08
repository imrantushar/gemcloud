import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { useDispatch } from 'react-redux';
import { __ } from '@wordpress/i18n';
import { Check, Copy, Download, ExternalLink, FileText, Loader2, Lock, X } from 'lucide-react';
import GCSToggle from '@GCSComponents/GCSToggle';
import StatusPill from '@GCSComponents/StatusPill';
import Tooltip from '@GCSComponents/Tooltip';
import { API, errorMessage, formatDateTime, namespace, notify } from '@GCSUtils/helper';

const FIELDS = [ 'title', 'alt', 'caption', 'description', 'private' ];

const valuesOf = ( file ) => ( {
	title: file.title,
	alt: file.alt,
	caption: file.caption,
	description: file.description,
	private: file.cloud.private,
} );

const Preview = ( { file } ) => {
	const box = 'flex aspect-video w-full items-center justify-center overflow-hidden rounded-[6px] border border-[var(--gemcloud-border-color)] bg-[var(--gemcloud-secondary-color)]';
	if ( file.kind === 'image' ) {
		return (
			<div className={ box }>
				<img src={ file.preview } alt={ file.alt } className="max-h-full max-w-full object-contain" />
			</div>
		);
	}
	if ( file.kind === 'video' ) {
		return (
			<div className={ box }>
				{ /* eslint-disable-next-line jsx-a11y/media-has-caption */ }
				<video src={ file.preview } controls preload="metadata" className="max-h-full max-w-full" />
			</div>
		);
	}
	if ( file.kind === 'audio' ) {
		return (
			<div className={ `${ box } !aspect-auto px-4 py-8` }>
				{ /* eslint-disable-next-line jsx-a11y/media-has-caption */ }
				<audio src={ file.preview } controls preload="metadata" className="w-full" />
			</div>
		);
	}
	return (
		<div className={ `${ box } flex-col gap-2 text-[var(--gemcloud-text-muted)]` }>
			<FileText size={ 40 } />
			<span className="text-[13px]">{ file.file_type }</span>
		</div>
	);
};

const Info = ( { label, children } ) =>
	children ? (
		<div className="flex justify-between gap-4 py-1.5 text-[13px]">
			<span className="shrink-0 text-[var(--gemcloud-font-secondary-color)]">{ label }</span>
			<span className="min-w-0 text-right font-medium [overflow-wrap:anywhere]">{ children }</span>
		</div>
	) : null;

/**
 * A media library file, edited without leaving the plugin: its details,
 * where its copies are stored, and whether it is private.
 */
const FileDetailsModal = ( { attachmentId, onClose, onSaved } ) => {
	const dispatch = useDispatch();
	const [ file, setFile ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ copied, setCopied ] = useState( false );

	useEffect( () => {
		let live = true;
		setFile( null );
		setError( '' );
		API.get( `${ namespace }attachment`, { params: { id: attachmentId } } )
			.then( ( res ) => {
				if ( live ) {
					setFile( res.data );
					setForm( valuesOf( res.data ) );
				}
			} )
			.catch( ( e ) => live && setError( errorMessage( e ) ) );
		return () => {
			live = false;
		};
	}, [ attachmentId ] );

	const dirty = !! file && FIELDS.some( ( key ) => form[ key ] !== valuesOf( file )[ key ] );

	const close = () => {
		// eslint-disable-next-line no-alert
		if ( ! dirty || window.confirm( __( 'Discard your changes to this file?', 'gemcloud' ) ) ) {
			onClose();
		}
	};

	useEffect( () => {
		const onKey = ( e ) => e.key === 'Escape' && close();
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	} );

	const set = ( key ) => ( e ) => setForm( { ...form, [ key ]: e.target.value } );

	const save = async () => {
		setSaving( true );
		try {
			const body = { id: attachmentId };
			FIELDS.forEach( ( key ) => {
				if ( form[ key ] !== valuesOf( file )[ key ] ) {
					body[ key ] = form[ key ];
				}
			} );
			const res = await API.post( `${ namespace }attachment/save`, body );
			setFile( res.data );
			setForm( valuesOf( res.data ) );
			notify( dispatch, __( 'File updated.', 'gemcloud' ) );
			onSaved?.();
		} catch ( e ) {
			notify( dispatch, errorMessage( e ), 'error' );
		} finally {
			setSaving( false );
		}
	};

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( file.url );
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} catch ( e ) {
			notify( dispatch, __( 'Couldn\'t copy. Select the address and copy it instead.', 'gemcloud' ), 'error' );
		}
	};

	const cloud = file?.cloud;

	return createPortal(
		<div className="gemcloud-scope">
			<div
				className="fixed inset-0 z-[100000] flex items-center justify-center p-4"
				style={ { background: 'rgba(14,17,22,0.55)', fontFamily: 'var(--gemcloud-font)' } }
				onMouseDown={ ( e ) => e.target === e.currentTarget && close() }
			>
				<div
					role="dialog"
					aria-modal="true"
					aria-labelledby="gemcloud-file-details-title"
					className="flex max-h-[calc(100vh-48px)] w-full max-w-[1040px] flex-col rounded-[8px] border border-[var(--gemcloud-border-color)] bg-[var(--gemcloud-background)]"
					style={ { boxShadow: 'var(--gemcloud-shadow-menu)', color: 'var(--gemcloud-font-color)' } }
				>
					<div className="flex items-center justify-between gap-4 border-b border-[var(--gemcloud-border-color)] px-5 py-4">
						<h3 id="gemcloud-file-details-title" className="m-0 min-w-0 text-[16px] font-semibold">
							<Tooltip content={ file?.file_name } className="gemcloud-truncate">
								{ file ? file.file_name : __( 'File details', 'gemcloud' ) }
							</Tooltip>
						</h3>
						<button type="button" onClick={ close } aria-label={ __( 'Close', 'gemcloud' ) } className="flex border-0 bg-transparent p-1 text-[var(--gemcloud-text-muted)]">
							<X size={ 18 } />
						</button>
					</div>

					<div className="min-h-0 flex-1 overflow-y-auto">
						{ ! file && ! error && (
							<div className="flex items-center justify-center gap-2 py-24 text-[var(--gemcloud-text-muted)]">
								<Loader2 size={ 18 } className="gemcloud-spin" />
								{ __( 'Loading…', 'gemcloud' ) }
							</div>
						) }
						{ error && <div className="px-5 py-16 text-center text-[var(--gemcloud-danger)]">{ error }</div> }

						{ file && (
							<div className="grid gap-6 p-5 md:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
								<div className="flex min-w-0 flex-col gap-5">
									<Preview file={ file } />

									<div>
										<div className="gemcloud-label">{ __( 'File URL', 'gemcloud' ) }</div>
										<div className="flex gap-2">
											<input className="gemcloud-input" readOnly value={ file.url } onFocus={ ( e ) => e.target.select() } aria-label={ __( 'File URL', 'gemcloud' ) } />
											<button type="button" className="gemcloud-btn gemcloud-btn--outline shrink-0" onClick={ copy }>
												{ copied ? <Check size={ 15 } /> : <Copy size={ 15 } /> }
												{ copied ? __( 'Copied', 'gemcloud' ) : __( 'Copy', 'gemcloud' ) }
											</button>
										</div>
										{ cloud.private && <p className="gemcloud-help">{ __( 'A private link: it opens only for people allowed to see the file, through a short-lived signed address.', 'gemcloud' ) }</p> }
									</div>

									<div className="rounded-[6px] border border-[var(--gemcloud-border-color)] px-4 py-2">
										<Info label={ __( 'File type', 'gemcloud' ) }>{ file.file_type || file.mime_type }</Info>
										<Info label={ __( 'File size', 'gemcloud' ) }>{ file.size_label }</Info>
										<Info label={ __( 'Dimensions', 'gemcloud' ) }>{ file.width ? `${ file.width } × ${ file.height }` : '' }</Info>
										<Info label={ __( 'Length', 'gemcloud' ) }>{ file.length }</Info>
										<Info label={ __( 'Uploaded on', 'gemcloud' ) }>{ formatDateTime( file.uploaded_on ) }</Info>
										<Info label={ __( 'Uploaded by', 'gemcloud' ) }>
											{ file.author && ( file.author_url ? <a href={ file.author_url } className="text-[var(--gemcloud-primary)]">{ file.author }</a> : file.author ) }
										</Info>
									</div>
								</div>

								<div className="flex min-w-0 flex-col gap-5">
									<div className="rounded-[6px] border border-[var(--gemcloud-border-color)] p-4">
										<div className="flex items-start justify-between gap-4">
											<div>
												<div className="flex items-center gap-1.5 text-[14px] font-semibold">
													{ form.private && <Lock size={ 14 } /> }
													{ __( 'Private', 'gemcloud' ) }
												</div>
												<p className="gemcloud-help !mt-1">
													{ __( 'Kept in the private bucket and opened through a signed link after an access check.', 'gemcloud' ) }
												</p>
											</div>
											<GCSToggle
												checked={ !! form.private }
												disabled={ cloud.locked || ! cloud.available }
												onChange={ ( v ) => setForm( { ...form, private: v } ) }
												label={ __( 'Private', 'gemcloud' ) }
											/>
										</div>
										{ cloud.locked && (
											<div className="mt-3 text-[12px] leading-[1.5] text-[var(--gemcloud-font-secondary-color)]">
												{ __( 'Kept private because it is used by:', 'gemcloud' ) }
												<ul className="ml-4 mt-1 list-disc">
													{ cloud.uses.map( ( u ) => (
														<li key={ u }>{ u }</li>
													) ) }
												</ul>
											</div>
										) }
										{ cloud.orphaned && (
											<p className="gemcloud-help">{ __( 'Made private by a product that no longer uses it. It stays private until you switch this off.', 'gemcloud' ) }</p>
										) }

										<div className="mt-4 border-t border-[var(--gemcloud-border-color)] pt-3">
											<div className="mb-2 text-[12px] font-medium text-[var(--gemcloud-font-secondary-color)]">{ cloud.label }</div>
											{ cloud.variants.length === 0 && <p className="gemcloud-help !mt-0">{ __( 'Not in the cloud yet. Only this server has the file.', 'gemcloud' ) }</p> }
											<div className="flex flex-col gap-2">
												{ cloud.variants.map( ( v ) => (
													<div key={ v.variant } className="flex items-center gap-3 text-[12px]">
														<span className="w-[92px] shrink-0 text-[var(--gemcloud-font-secondary-color)]">{ v.variant }</span>
														<Tooltip content={ `${ v.bucket }/${ v.object_key }` } className="gemcloud-code gemcloud-truncate min-w-0 flex-1">
															{ v.object_key }
														</Tooltip>
														<span className="shrink-0">{ v.size_label }</span>
														<StatusPill status={ v.status } />
													</div>
												) ) }
											</div>
										</div>
									</div>

									<div>
										<label className="gemcloud-label" htmlFor="gemcloud-file-title">{ __( 'Title', 'gemcloud' ) }</label>
										<input id="gemcloud-file-title" className="gemcloud-input" value={ form.title } onChange={ set( 'title' ) } />
									</div>
									{ file.kind === 'image' && (
										<div>
											<label className="gemcloud-label" htmlFor="gemcloud-file-alt">{ __( 'Alternative text', 'gemcloud' ) }</label>
											<input id="gemcloud-file-alt" className="gemcloud-input" value={ form.alt } onChange={ set( 'alt' ) } />
											<p className="gemcloud-help">{ __( 'Describe the image for people who can\'t see it. Leave empty if it is only decoration.', 'gemcloud' ) }</p>
										</div>
									) }
									<div>
										<label className="gemcloud-label" htmlFor="gemcloud-file-caption">{ __( 'Caption', 'gemcloud' ) }</label>
										<textarea id="gemcloud-file-caption" className="gemcloud-input" rows={ 2 } value={ form.caption } onChange={ set( 'caption' ) } />
									</div>
									<div>
										<label className="gemcloud-label" htmlFor="gemcloud-file-description">{ __( 'Description', 'gemcloud' ) }</label>
										<textarea id="gemcloud-file-description" className="gemcloud-input" rows={ 4 } value={ form.description } onChange={ set( 'description' ) } />
										<p className="gemcloud-help">{ __( 'Shown on the file\'s attachment page.', 'gemcloud' ) }</p>
									</div>
								</div>
							</div>
						) }
					</div>

					<div className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--gemcloud-border-color)] px-5 py-3">
						<div className="flex flex-wrap gap-2">
							{ file && (
								<>
									<a className="gemcloud-btn gemcloud-btn--outline gemcloud-btn--sm" href={ file.url } target="_blank" rel="noreferrer" download={ file.file_name }>
										<Download size={ 14 } />
										{ __( 'Download', 'gemcloud' ) }
									</a>
									<a className="gemcloud-btn gemcloud-btn--outline gemcloud-btn--sm" href={ file.edit_url } target="_blank" rel="noreferrer">
										<ExternalLink size={ 14 } />
										{ __( 'Open in WordPress', 'gemcloud' ) }
									</a>
								</>
							) }
						</div>
						<div className="flex gap-2">
							<button type="button" className="gemcloud-btn gemcloud-btn--outline" onClick={ close }>
								{ dirty ? __( 'Cancel', 'gemcloud' ) : __( 'Close', 'gemcloud' ) }
							</button>
							<button type="button" className="gemcloud-btn gemcloud-btn--primary" onClick={ save } disabled={ ! dirty || saving }>
								{ saving && <Loader2 size={ 15 } className="gemcloud-spin" /> }
								{ saving ? __( 'Saving…', 'gemcloud' ) : __( 'Save changes', 'gemcloud' ) }
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>,
		document.body
	);
};

export default FileDetailsModal;
