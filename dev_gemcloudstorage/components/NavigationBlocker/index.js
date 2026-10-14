import { useEffect } from 'react';
import { __ } from '@wordpress/i18n';

/**
 * Warns before unsaved changes are lost, the same way Zaplane does: the
 * browser's own prompt on reload or close, and a confirm on links that
 * leave the page (WP admin menu, section pills).
 */
const NavigationBlocker = ( { when } ) => {
	useEffect( () => {
		if ( ! when ) {
			return;
		}

		const handleBeforeUnload = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};

		const handleClick = ( event ) => {
			const anchor = event.target.closest( 'a' );
			if ( ! anchor || ! anchor.href || anchor.target === '_blank' ) {
				return;
			}
			const url = new URL( anchor.href );
			const here = window.location;
			if ( url.origin !== here.origin || ( url.pathname === here.pathname && url.search === here.search ) ) {
				return;
			}
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'You have unsaved changes. Are you sure you want to leave?', 'gemcloudstorage' ) ) ) {
				event.preventDefault();
				event.stopPropagation();
			}
		};

		window.addEventListener( 'beforeunload', handleBeforeUnload );
		document.addEventListener( 'click', handleClick, true );

		return () => {
			window.removeEventListener( 'beforeunload', handleBeforeUnload );
			document.removeEventListener( 'click', handleClick, true );
		};
	}, [ when ] );

	return null;
};

export default NavigationBlocker;
