import React from 'react';
import { Link } from 'react-router-dom';
import { useSelector } from 'react-redux';
import { __ } from '@wordpress/i18n';
import { LayoutDashboard, Files, Wrench, Settings as SettingsIcon } from 'lucide-react';
import { route_path, useQuery } from '@GCSUtils/helper';
import './styles.scss';

/**
 * Pill links to every section, the same quick-nav strip as Zaplane's topbar.
 */
const ICONS = {
	gemcloud: LayoutDashboard,
	'gemcloud-files': Files,
	'gemcloud-tools': Wrench,
	'gemcloud-settings': SettingsIcon,
};

const TopBarNav = () => {
	const adminmenu = useSelector( ( state ) => state.adminmenu.data ) || {};
	const current = useQuery().get( 'page' ) || 'gemcloud';

	return (
		<nav className="gemcloud-topbar-nav" aria-label={ __( 'GemCloud sections', 'gemcloud' ) }>
			{ Object.entries( adminmenu ).map( ( [ key, item ] ) => {
				const Icon = ICONS[ key ] || LayoutDashboard;
				const active = current === key;
				return (
					<Link
						key={ key }
						to={ `${ route_path }admin.php?page=${ key }` }
						className={ 'gemcloud-topbar-nav__link' + ( active ? ' gemcloud-topbar-nav__link--active' : '' ) }
						aria-current={ active ? 'page' : undefined }
					>
						<Icon size={ 16 } strokeWidth={ 1.75 } aria-hidden="true" />
						<span>{ item.title }</span>
					</Link>
				);
			} ) }
		</nav>
	);
};

export default TopBarNav;
