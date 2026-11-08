import React, { useEffect, useRef, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { __ } from '@wordpress/i18n';
import { Cloud, Image, ShieldCheck, Layers, Palette, DatabaseBackup } from 'lucide-react';
import PageLayout from '@GCSComponents/PageLayout';
import NavigationBlocker from '@GCSComponents/NavigationBlocker';
import { getSettings, saveSettings } from '@GCSRedux/Slices/settingSlice/settingSlice';
import { getStatus } from '@GCSRedux/Slices/statusSlice/statusSlice';
import { useQuery } from '@GCSUtils/helper';
import { useThemeMode } from '@GCSUtils/theme';
import ConnectionTab from './ConnectionTab';
import MediaTab from './MediaTab';
import SecurityTab from './SecurityTab';
import ContextsTab from './ContextsTab';
import BackupsTab from './BackupsTab';
import { ChoiceCard, SectionTitle } from './common';

const TABS = [
	{ key: 'connection', label: __( 'Connection', 'gemcloud' ), icon: Cloud },
	{ key: 'media', label: __( 'Media library', 'gemcloud' ), icon: Image },
	{ key: 'security', label: __( 'Security', 'gemcloud' ), icon: ShieldCheck },
	{ key: 'contexts', label: __( 'Contexts', 'gemcloud' ), icon: Layers },
	{ key: 'backups', label: __( 'Backups', 'gemcloud' ), icon: DatabaseBackup },
	{ key: 'appearance', label: __( 'Appearance', 'gemcloud' ), icon: Palette },
];

// PHP sends an empty map as [], and an array drops string keys when saved.
const asMap = ( value ) => ( value && ! Array.isArray( value ) ? value : {} );

const normalize = ( settings ) => {
	const copy = JSON.parse( JSON.stringify( settings ) );
	copy.contexts = asMap( copy.contexts );
	// The active provider's profile is the top-level endpoint and buckets.
	copy.profiles = { ...asMap( copy.profiles ), [ copy.provider ]: { endpoint: copy.endpoint, buckets: copy.buckets } };
	return copy;
};

const toForm = ( data ) => {
	const settings = normalize( data.settings );
	const active = {
		access_key_id: data.credentials.access_key_id || '',
		secret_access_key: data.credentials.secret_access_key || '',
	};
	return {
		settings,
		credentials: { ...active, profiles: { ...asMap( data.credentials.profiles ), [ settings.provider ]: active } },
	};
};

// A provider chosen for the first time starts empty.
const blankConnection = ( provider ) => ( {
	endpoint: { account_id: '', jurisdiction: '', region: provider === 'r2' ? 'auto' : 'us-east-1', url: '', path_style: true },
	buckets: { public: { name: '', public_url: '' }, private: { name: '' } },
} );

/**
 * The form with the active provider's profile updated from the fields being
 * edited, so typing then switching away and back compares as one change.
 */
const withActiveProfile = ( form ) => {
	const { settings, credentials } = form;
	return {
		settings: { ...settings, profiles: { ...settings.profiles, [ settings.provider ]: { endpoint: settings.endpoint, buckets: settings.buckets } } },
		credentials: {
			...credentials,
			profiles: {
				...credentials.profiles,
				[ settings.provider ]: { access_key_id: credentials.access_key_id, secret_access_key: credentials.secret_access_key },
			},
		},
	};
};

/**
 * Each provider keeps its own endpoint, buckets and keys: the current ones
 * are put aside and the chosen provider's are brought back.
 */
const switchProvider = ( form, next ) => {
	const { settings, credentials } = form;
	const current = settings.provider;
	if ( current === next ) {
		return form;
	}
	const profiles = { ...settings.profiles, [ current ]: { endpoint: settings.endpoint, buckets: settings.buckets } };
	const keys = {
		...credentials.profiles,
		[ current ]: { access_key_id: credentials.access_key_id, secret_access_key: credentials.secret_access_key },
	};
	const connection = profiles[ next ] || blankConnection( next );
	const nextKeys = keys[ next ] || { access_key_id: '', secret_access_key: '' };

	return {
		settings: { ...settings, provider: next, endpoint: connection.endpoint, buckets: connection.buckets, profiles },
		credentials: { ...nextKeys, profiles: keys },
	};
};

const setIn = ( obj, path, value ) => {
	const next = Array.isArray( obj ) ? [ ...obj ] : { ...obj };
	if ( path.length === 1 ) {
		next[ path[ 0 ] ] = value;
		return next;
	}
	next[ path[ 0 ] ] = setIn( obj?.[ path[ 0 ] ] ?? {}, path.slice( 1 ), value );
	return next;
};

const AppearanceTab = ( { form, setSetting } ) => {
	const { set } = useThemeMode();
	const mode = form.settings.theme?.default_mode || 'light';
	const choose = ( value ) => {
		setSetting( [ 'theme', 'default_mode' ], value );
		set( value );
	};
	return (
		<div className="flex flex-col gap-4">
			<SectionTitle title={ __( 'Default theme', 'gemcloud' ) } description={ __( 'The mode these screens open in. Each person can still switch with the button in the top bar.', 'gemcloud' ) } />
			<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
				<ChoiceCard name="theme" value="light" checked={ mode === 'light' } onChange={ choose } title={ __( 'Light', 'gemcloud' ) } description={ __( 'White surfaces, the WordPress admin look.', 'gemcloud' ) } />
				<ChoiceCard name="theme" value="dark" checked={ mode === 'dark' } onChange={ choose } title={ __( 'Dark', 'gemcloud' ) } description={ __( 'Dark surfaces, easier at night.', 'gemcloud' ) } />
			</div>
		</div>
	);
};

export default function Settings() {
	const dispatch = useDispatch();
	const { data, saving } = useSelector( ( state ) => state.setting );
	const query = useQuery();
	const [ activeTab, setActiveTab ] = useState( query.get( 'tab' ) || 'connection' );
	const [ form, setForm ] = useState( data ? toForm( data ) : null );
	const saved = useRef( data ? JSON.stringify( toForm( data ) ) : '' );

	useEffect( () => {
		dispatch( getSettings() );
	}, [ dispatch ] );

	useEffect( () => {
		if ( data ) {
			const next = toForm( data );
			setForm( next );
			saved.current = JSON.stringify( next );
		}
	}, [ data ] );

	const setSetting = ( path, value ) => setForm( ( f ) => ( { ...f, settings: setIn( f.settings, path, value ) } ) );
	const setCredential = ( key, value ) => setForm( ( f ) => ( { ...f, credentials: { ...f.credentials, [ key ]: value } } ) );

	const setProvider = ( next ) => setForm( ( f ) => switchProvider( f, next ) );

	const isDirty = !! form && JSON.stringify( withActiveProfile( form ) ) !== JSON.stringify( withActiveProfile( JSON.parse( saved.current ) ) );

	const handleSave = async () => {
		const res = await dispatch( saveSettings( withActiveProfile( form ) ) );
		if ( ! res.error ) {
			dispatch( getStatus() );
		}
	};

	// Always visible so it can be found; disabled until something changes.
	const saveButton = (
		<button type="button" className="gemcloud-btn gemcloud-btn--primary" onClick={ handleSave } disabled={ ! isDirty || saving }>
			{ saving ? __( 'Saving…', 'gemcloud' ) : __( 'Save changes', 'gemcloud' ) }
		</button>
	);

	const tabProps = { form, meta: data, setSetting, setCredential, setProvider, isDirty, saving, onSave: handleSave };

	return (
		<PageLayout title={ __( 'Settings', 'gemcloud' ) } actions={ saveButton }>
			<NavigationBlocker when={ isDirty && ! saving } />
			{ ! form ? (
				<div className="flex flex-col gap-6 md:flex-row">
					<div className="gemcloud-skeleton h-[240px] w-full md:w-[220px]" />
					<div className="gemcloud-skeleton h-[480px] flex-1" />
				</div>
			) : (
				<div className="flex flex-col gap-6 md:flex-row">
					<nav className="w-full shrink-0 self-start rounded-lg border border-[var(--gemcloud-border-color)] bg-[var(--gemcloud-background)] p-2 md:sticky md:top-[120px] md:w-[220px]">
						<div className="flex flex-row gap-1 overflow-x-auto md:flex-col">
							{ TABS.map( ( tab ) => {
								const Icon = tab.icon;
								const active = activeTab === tab.key;
								return (
									<button
										key={ tab.key }
										type="button"
										onClick={ () => setActiveTab( tab.key ) }
										className={ `flex items-center gap-2.5 whitespace-nowrap rounded-[6px] px-3 py-2.5 text-left text-[14px] font-medium transition-colors ${
											active ? 'bg-[var(--gemcloud-second-primary)] text-[var(--gemcloud-primary)]' : 'text-[var(--gemcloud-font-secondary-color)] hover:bg-[var(--gemcloud-secondary-color)]'
										}` }
									>
										<Icon size={ 16 } />
										{ tab.label }
									</button>
								);
							} ) }
						</div>
					</nav>

					<div className="min-w-0 max-w-[880px] flex-1 rounded-lg border border-[var(--gemcloud-border-color)] bg-[var(--gemcloud-background)] p-6">
						{ activeTab === 'connection' && <ConnectionTab { ...tabProps } /> }
						{ activeTab === 'media' && <MediaTab { ...tabProps } /> }
						{ activeTab === 'security' && <SecurityTab { ...tabProps } /> }
						{ activeTab === 'contexts' && <ContextsTab { ...tabProps } /> }
					{ activeTab === 'backups' && <BackupsTab { ...tabProps } /> }
						{ activeTab === 'appearance' && <AppearanceTab { ...tabProps } /> }
					</div>
				</div>
			) }
		</PageLayout>
	);
}
