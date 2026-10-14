import React from 'react';
import { __ } from '@wordpress/i18n';
import { HelpCircle, Moon, Sun } from 'lucide-react';
import { useThemeMode } from '@GCSUtils/theme';
import './styles.scss';

const ThemeToggle = () => {
	const { isDark, toggle } = useThemeMode();
	const label = isDark ? __( 'Switch to light mode', 'gemcloudstorage' ) : __( 'Switch to dark mode', 'gemcloudstorage' );
	return (
		<button
			type="button"
			onClick={ toggle }
			title={ label }
			aria-label={ label }
			className="flex h-9 w-9 items-center justify-center rounded-full border border-[var(--gcs-border-color)] bg-[var(--gcs-body-background)] text-[var(--gcs-font-color)] transition-colors"
		>
			{ isDark ? <Sun size={ 16 } /> : <Moon size={ 16 } /> }
		</button>
	);
};

const TopBar = ( { leftContent = () => null, middleContent = () => null } ) => (
	<div className="gemcloudstorage-topbar">
		<div className="gemcloudstorage-topbar__left">{ leftContent() }</div>
		<div className="gemcloudstorage-topbar__middle">{ middleContent() }</div>
		<div className="gemcloudstorage-topbar__right">
			<ThemeToggle />
			<a
				className="gcs-btn gcs-btn--outline gemcloudstorage-help-button"
				href="https://kodezen.com/docs/"
				target="_blank"
				rel="noreferrer"
			>
				<HelpCircle size={ 16 } />
				<span className="gemcloudstorage-help-button__label">{ __( 'Help', 'gemcloudstorage' ) }</span>
			</a>
		</div>
	</div>
);

export default TopBar;
