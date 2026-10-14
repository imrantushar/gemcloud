import React, { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useSelector } from 'react-redux';
import { __ } from '@wordpress/i18n';
import { plugin_root_url, route_path, useQuery } from '@GCSUtils/helper';

const AdminMenu = () => {
	const adminmenu = useSelector( ( state ) => state.adminmenu.data ) || {};
	const page = useQuery().get( 'page' );

	useEffect( () => {
		if ( adminmenu[ page ]?.title ) {
			document.title = `${ adminmenu[ page ].title } - ${ __( 'GemCloudStorage', 'gemcloudstorage' ) }`;
		}
	}, [ page ] );

	return (
		<>
			<Link
				to={ `${ route_path }admin.php?page=gemcloudstorage` }
				className="wp-has-submenu wp-has-current-submenu wp-menu-open menu-top toplevel_page_gemcloudstorage"
				aria-haspopup="false"
			>
				<div className="wp-menu-arrow">
					<div></div>
				</div>
				<div
					className="wp-menu-image svg"
					style={ { backgroundImage: `url(${ plugin_root_url }assets/images/menu-icon.svg)` } }
					aria-hidden="true"
				>
					<br />
				</div>
				<div className="wp-menu-name">{ __( 'GemCloudStorage', 'gemcloudstorage' ) }</div>
			</Link>
			<ul className="wp-submenu wp-submenu-wrap">
				<li className="wp-submenu-head" aria-hidden="true">
					{ __( 'GemCloudStorage', 'gemcloudstorage' ) }
				</li>
				{ Object.entries( adminmenu ).map( ( [ key, item ], index ) => {
					const first = index === 0 ? 'wp-first-item ' : '';
					return (
						<li key={ key } className={ page === key ? `${ first }current` : first }>
							<Link to={ `${ route_path }admin.php?page=${ key }` }>{ item.title }</Link>
						</li>
					);
				} ) }
			</ul>
		</>
	);
};

export default AdminMenu;
