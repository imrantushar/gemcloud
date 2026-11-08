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
	const container = document.getElementById( 'gemcloud-app' );
	if ( ! container ) {
		return;
	}

	container.classList.add( 'gemcloud-scope' );
	// Stamp the theme mode before the first paint, so there is no flash.
	initThemeMode();

	const root = createRoot( container );
	flushSync( () => {
		root.render(
			<Provider store={ store }>
				<Router>
					{ /* WordPress's own sidebar entry, kept in step with the router. */ }
					<AdminMenu />
					<BackendDashboard />
				</Router>
			</Provider>
		);
	} );
} );
