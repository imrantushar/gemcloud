import { useLocation } from 'react-router-dom';
import axios from 'axios';
import { __ } from '@wordpress/i18n';
import { showNotification } from '@GCSRedux/Slices/notificationSlice/notificationSlice';

export const {
	plugin_root_url,
	nonce,
	menu,
	route_path,
	rest_url,
	admin_url,
	home_url,
	namespace,
	is_plain_permalink,
	version,
	theme_mode,
} = window?.GemCloudStorageGlobal ?? {};

export const useQuery = () => new URLSearchParams( useLocation().search );

export const API = axios.create( {
	baseURL: rest_url,
	headers: {
		'content-type': 'application/json',
		'X-WP-Nonce': nonce,
		'Cache-Control': 'no-cache',
	},
} );

export const errorMessage = ( error ) =>
	error?.response?.data?.message ?? error?.message ?? __( 'Something went wrong.', 'gemcloudstorage' );

export const handleSliceError = ( thunkAPI, error ) => {
	thunkAPI.dispatch(
		showNotification( { message: errorMessage( error ), isShow: true, type: 'error' } )
	);
	return thunkAPI.rejectWithValue( errorMessage( error ) );
};

export const notify = ( dispatch, message, type = 'success' ) =>
	dispatch( showNotification( { message, isShow: true, type } ) );

export const formatBytes = ( bytes = 0 ) => {
	if ( ! bytes ) {
		return '0 B';
	}
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	const i = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
	return `${ ( bytes / Math.pow( 1024, i ) ).toFixed( i ? 1 : 0 ) } ${ units[ i ] }`;
};

export const formatNumber = ( n = 0 ) => new Intl.NumberFormat().format( n || 0 );

// Stored times are UTC; a value without a zone is read as UTC.
const toDate = ( value ) => {
	if ( ! value ) {
		return null;
	}
	const iso = String( value ).replace( ' ', 'T' );
	const date = new Date( /Z|[+-]\d\d:?\d\d$/.test( iso ) ? iso : iso + 'Z' );
	return isNaN( date ) ? null : date;
};

/**
 * [ "Oct 14", "04:13 PM" ], for cells that show the date over the time.
 */
export const formatDateParts = ( value ) => {
	const date = toDate( value );
	if ( ! date ) {
		return [ '', '' ];
	}
	return [
		date.toLocaleDateString( undefined, { month: 'short', day: '2-digit' } ),
		date.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } ),
	];
};

export const formatDateTime = ( value ) => {
	const date = toDate( value );
	if ( ! date ) {
		return '';
	}
	return date.toLocaleString( undefined, {
		month: 'short',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	} );
};

export const adminPage = ( page, params = {} ) => {
	const query = new URLSearchParams( { page, ...params } ).toString();
	return `${ route_path }admin.php?${ query }`;
};
