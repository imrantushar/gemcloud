import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { __ } from '@wordpress/i18n';
import { API, namespace, handleSliceError, notify } from '@GCSUtils/helper';

export const getSettings = createAsyncThunk( 'gemcloud/getSettings', async ( _, thunkAPI ) => {
	try {
		const res = await API.get( `${ namespace }settings` );
		return res.data;
	} catch ( error ) {
		return handleSliceError( thunkAPI, error );
	}
} );

export const saveSettings = createAsyncThunk( 'gemcloud/saveSettings', async ( payload, thunkAPI ) => {
	try {
		const res = await API.post( `${ namespace }settings`, payload );
		notify( thunkAPI.dispatch, __( 'Settings saved.', 'gemcloud' ) );
		return res.data;
	} catch ( error ) {
		return handleSliceError( thunkAPI, error );
	}
} );

const settingSlice = createSlice( {
	name: 'setting',
	initialState: {
		data: null,
		loading: false,
		saving: false,
	},
	reducers: {},
	extraReducers: ( builder ) => {
		builder
			.addCase( getSettings.pending, ( state ) => {
				state.loading = true;
			} )
			.addCase( getSettings.fulfilled, ( state, action ) => {
				state.loading = false;
				state.data = action.payload;
			} )
			.addCase( getSettings.rejected, ( state ) => {
				state.loading = false;
			} )
			.addCase( saveSettings.pending, ( state ) => {
				state.saving = true;
			} )
			.addCase( saveSettings.fulfilled, ( state, action ) => {
				state.saving = false;
				state.data = action.payload;
			} )
			.addCase( saveSettings.rejected, ( state ) => {
				state.saving = false;
			} );
	},
} );

export default settingSlice.reducer;
