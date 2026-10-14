import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { __, sprintf } from '@wordpress/i18n';
import { API, namespace, handleSliceError, notify } from '@GCSUtils/helper';

export const getItems = createAsyncThunk( 'gemcloudstorage/getItems', async ( params, thunkAPI ) => {
	try {
		const res = await API.get( `${ namespace }items`, { params } );
		return res.data;
	} catch ( error ) {
		return handleSliceError( thunkAPI, error );
	}
} );

export const retryItems = createAsyncThunk( 'gemcloudstorage/retryItems', async ( body, thunkAPI ) => {
	try {
		const res = await API.post( `${ namespace }items/retry`, body );
		notify(
			thunkAPI.dispatch,
			body.all
				? __( 'Every failed upload is queued again.', 'gemcloudstorage' )
				/* translators: %d: number of files */
				: sprintf( __( '%d files queued again.', 'gemcloudstorage' ), res.data.queued )
		);
		return res.data;
	} catch ( error ) {
		return handleSliceError( thunkAPI, error );
	}
} );

const itemsSlice = createSlice( {
	name: 'items',
	initialState: {
		data: [],
		total: 0,
		loading: false,
	},
	reducers: {},
	extraReducers: ( builder ) => {
		builder
			.addCase( getItems.pending, ( state ) => {
				state.loading = true;
			} )
			.addCase( getItems.fulfilled, ( state, action ) => {
				state.loading = false;
				state.data = action.payload.items;
				state.total = action.payload.total;
			} )
			.addCase( getItems.rejected, ( state ) => {
				state.loading = false;
			} );
	},
} );

export default itemsSlice.reducer;
