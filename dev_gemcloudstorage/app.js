import { createPortal } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { flushSync } from 'react-dom';
import { Provider } from 'react-redux';
import { BrowserRouter as Router } from 'react-router-dom';
import BackendDashboard from '@GCSContainers/BackendDashboard';
import AdminMenu from '@GCSContainers/BackendDashboard/AdminMenu';
import { store } from '@GCSRedux/store';
import { initThemeMode } from '@GCSUtils/theme';

import '../assets/scss/backend.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'gemcloudstorage-app' );
	if ( ! container ) {
		return;
	}

	container.classList.add( 'gemcloudstorage-scope' );
	// Stamp the theme mode before the first paint, so there is no flash.
	initThemeMode();

	// The WP sidebar entry is re-rendered by React so switching pages
	// highlights the right submenu item without a reload.
	const menuPage = document.getElementById( 'toplevel_page_gemcloudstorage' );
	if ( menuPage ) {
		menuPage.innerHTML = '';
	}
	const MenuPortal = ( { children } ) => ( menuPage ? createPortal( children, menuPage ) : null );

	const root = createRoot( container );
	flushSync( () => {
		root.render(
			<Provider store={ store }>
				<Router>
					<MenuPortal>
						<AdminMenu />
					</MenuPortal>
					<BackendDashboard />
				</Router>
			</Provider>
		);
	} );
} );
