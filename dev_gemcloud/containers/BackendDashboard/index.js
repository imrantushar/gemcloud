import React from 'react';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@GCSUtils/helper';
import Notification from '@GCSComponents/Notification';
import Dashboard from './pages/dashboard';
import Files from './pages/files';
import Tools from './pages/tools';
import Settings from './pages/settings';

const renderPage = ( page ) => {
	switch ( page ) {
		case 'gemcloud':
			return <Dashboard />;
		case 'gemcloud-files':
			return <Files />;
		case 'gemcloud-tools':
			return <Tools />;
		case 'gemcloud-settings':
			return <Settings />;
		default:
			return <p className="p-6">{ __( 'No page found', 'gemcloud' ) }</p>;
	}
};

export default function BackendDashboard() {
	const page = useQuery().get( 'page' );

	return (
		<div className="gemcloud-admin-content">
			<Notification />
			{ /* Keyed by page so switching menu items replays the enter animation. */ }
			<div key={ page } className="gemcloud-route">
				{ renderPage( page ) }
			</div>
		</div>
	);
}
