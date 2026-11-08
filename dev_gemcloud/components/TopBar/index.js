import React from 'react';
import { __ } from '@wordpress/i18n';
import { HelpCircle, Moon, Sun } from 'lucide-react';
import { useThemeMode } from '@GCSUtils/theme';
import './styles.scss';

const ThemeToggle = () => {
	const { isDark, toggle } = useThemeMode();
	const label = isDark ? __( 'Switch to light mode', 'gemcloud' ) : __( 'Switch to dark mode', 'gemcloud' );
	return (
		<button
			type="button"
			onClick={ toggle }
			title={ label }
			aria-label={ label }
			className="flex h-9 w-9 items-center justify-center rounded-[6px] border border-[var(--gemcloud-border-color)] bg-[var(--gemcloud-body-background)] text-[var(--gemcloud-font-color)] transition-colors"
		>
			{ isDark ? <Sun size={ 16 } /> : <Moon size={ 16 } /> }
		</button>
	);
};

const TopBar = ( { leftContent = () => null, middleContent = () => null } ) => (
	<div className="gemcloud-topbar">
		<div className="gemcloud-topbar__left">{ leftContent() }</div>
		<div className="gemcloud-topbar__middle">{ middleContent() }</div>
		<div className="gemcloud-topbar__right">
			<ThemeToggle />
			<a
				className="gemcloud-btn gemcloud-btn--outline gemcloud-help-button"
				href="https://kodezen.com/docs/"
				target="_blank"
				rel="noreferrer"
			>
				<HelpCircle size={ 16 } />
				<span className="gemcloud-help-button__label">{ __( 'Help', 'gemcloud' ) }</span>
			</a>
		</div>
	</div>
);

export default TopBar;
