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
		case 'gemcloudstorage':
			return <Dashboard />;
		case 'gemcloudstorage-files':
			return <Files />;
		case 'gemcloudstorage-tools':
			return <Tools />;
		case 'gemcloudstorage-settings':
			return <Settings />;
		default:
			return <p className="p-6">{ __( 'No page found', 'gemcloudstorage' ) }</p>;
	}
};

export default function BackendDashboard() {
	const page = useQuery().get( 'page' );

	return (
		<div className="gemcloudstorage-admin-content">
			<Notification />
			{ /* Keyed by page so switching menu items replays the enter animation. */ }
			<div key={ page } className="gemcloudstorage-route">
				{ renderPage( page ) }
			</div>
		</div>
	);
}
