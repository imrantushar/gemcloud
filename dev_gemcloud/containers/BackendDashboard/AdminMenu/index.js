import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useSelector } from 'react-redux';
import { __ } from '@wordpress/i18n';
import { route_path, useQuery } from '@GCSUtils/helper';

const MENU_ID = 'toplevel_page_gemcloud';

const pageOf = ( link ) => {
	try {
		return new URL( link.href, window.location.href ).searchParams.get( 'page' );
	} catch ( e ) {
		return null;
	}
};

/**
 * Keeps WordPress's own sidebar entry (the same markup and styles as every
 * other plugin) and only makes it behave like part of the app: its submenu
 * links switch pages without a reload, and the current page is highlighted.
 */
const AdminMenu = () => {
	const adminmenu = useSelector( ( state ) => state.adminmenu.data ) || {};
	const page = useQuery().get( 'page' );
	const navigate = useNavigate();

	// Submenu clicks go through the router. Modifier clicks open a tab as usual.
	useEffect( () => {
		const menu = document.getElementById( MENU_ID );
		if ( ! menu ) {
			return undefined;
		}
		const onClick = ( event ) => {
			const link = event.target.closest( '.wp-submenu a' );
			if ( ! link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			const target = pageOf( link );
			if ( ! target || ! adminmenu[ target ] ) {
				return;
			}
			event.preventDefault();
			navigate( `${ route_path }admin.php?page=${ target }` );
		};
		menu.addEventListener( 'click', onClick );
		return () => menu.removeEventListener( 'click', onClick );
	}, [ adminmenu, navigate ] );

	// Highlight the current page, the way WordPress does on a full load.
	useEffect( () => {
		const menu = document.getElementById( MENU_ID );
		if ( menu ) {
			menu.querySelectorAll( '.wp-submenu li:not(.wp-submenu-head)' ).forEach( ( li ) => {
				const link = li.querySelector( 'a' );
				const current = !! link && pageOf( link ) === page;
				li.classList.toggle( 'current', current );
				link?.classList.toggle( 'current', current );
				if ( current ) {
					link.setAttribute( 'aria-current', 'page' );
				} else {
					link?.removeAttribute( 'aria-current' );
				}
			} );
		}
		if ( adminmenu[ page ]?.title ) {
			document.title = `${ adminmenu[ page ].title } - ${ __( 'GemCloud', 'gemcloud' ) }`;
		}
	}, [ page, adminmenu ] );

	return null;
};

export default AdminMenu;
