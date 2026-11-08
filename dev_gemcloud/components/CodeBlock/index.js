import React, { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Check, Copy } from 'lucide-react';

/**
 * Code to copy: a title bar (file name or language) with a Copy button, and
 * the code below it. Styled by .gemcloud-codeblock so WordPress's own <code>
 * background can't paint each line.
 */
const CodeBlock = ( { title, code, actions = null, maxHeight } ) => {
	const [ copied, setCopied ] = useState( false );

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( code );
			setCopied( true );
			setTimeout( () => setCopied( false ), 1600 );
		} catch ( e ) {
			// Clipboard refused (insecure origin): select the code instead.
			const pre = document.activeElement?.closest( '.gemcloud-codeblock' )?.querySelector( 'pre' );
			if ( pre ) {
				window.getSelection().selectAllChildren( pre );
			}
		}
	};

	return (
		<div className="gemcloud-codeblock">
			<div className="gemcloud-codeblock__bar">
				<span className="gemcloud-codeblock__title">{ title }</span>
				<div className="flex items-center gap-2">
					{ actions }
					<button type="button" className="gemcloud-btn gemcloud-btn--outline gemcloud-btn--sm" onClick={ copy } aria-live="polite">
						{ copied ? <Check size={ 13 } /> : <Copy size={ 13 } /> }
						{ copied ? __( 'Copied', 'gemcloud' ) : __( 'Copy', 'gemcloud' ) }
					</button>
				</div>
			</div>
			<pre style={ maxHeight ? { maxHeight } : undefined }>
				<code>{ code }</code>
			</pre>
		</div>
	);
};

export default CodeBlock;
