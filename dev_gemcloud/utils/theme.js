import { useEffect, useState } from 'react';
import { theme_mode } from '@GCSUtils/helper';

// Per-browser light/dark preference, the same mechanism Zaplane uses.
export const THEME_STORAGE_KEY = 'gemcloud-theme-mode';

const normalize = ( mode ) => ( 'dark' === mode ? 'dark' : 'light' );

export const getStoredMode = () => {
	try {
		const v = window.localStorage.getItem( THEME_STORAGE_KEY );
		if ( v === 'light' || v === 'dark' ) {
			return v;
		}
	} catch ( e ) {
		// localStorage unavailable: fall back to the saved default.
	}
	return normalize( theme_mode );
};

export const applyThemeMode = ( mode ) => {
	const value = normalize( mode );
	document.documentElement.setAttribute( 'data-theme', value );
	const root = document.getElementById( 'gemcloud-app' );
	if ( root ) {
		root.setAttribute( 'data-theme', value );
	}
	try {
		window.localStorage.setItem( THEME_STORAGE_KEY, value );
	} catch ( e ) {
		// Ignore: the attribute still applies.
	}
	window.dispatchEvent( new CustomEvent( 'gemcloud-theme-change', { detail: value } ) );
	return value;
};

export const initThemeMode = () => applyThemeMode( getStoredMode() );

export const useThemeMode = () => {
	const [ mode, setMode ] = useState( () => getStoredMode() );
	useEffect( () => {
		const handler = ( e ) => setMode( normalize( e.detail ) );
		window.addEventListener( 'gemcloud-theme-change', handler );
		return () => window.removeEventListener( 'gemcloud-theme-change', handler );
	}, [] );
	return {
		mode,
		isDark: mode === 'dark',
		toggle: () => setMode( applyThemeMode( mode === 'dark' ? 'light' : 'dark' ) ),
		set: ( m ) => setMode( applyThemeMode( m ) ),
	};
};
