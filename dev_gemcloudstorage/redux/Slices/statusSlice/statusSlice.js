import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { API, namespace, handleSliceError } from '@GCSUtils/helper';

export const getStatus = createAsyncThunk( 'gemcloudstorage/getStatus', async ( _, thunkAPI ) => {
	try {
		const res = await API.get( `${ namespace }status` );
		return res.data;
	} catch ( error ) {
		return handleSliceError( thunkAPI, error );
	}
} );

/**
 * Tools actions (migration, download back, queue) all answer with a fresh
 * state, then the status is refetched.
 */
export const runTool = createAsyncThunk( 'gemcloudstorage/runTool', async ( { path, body = {} }, thunkAPI ) => {
	try {
		const res = await API.post( `${ namespace }${ path }`, body );
		thunkAPI.dispatch( getStatus() );
		return res.data;
	} catch ( error ) {
		return handleSliceError( thunkAPI, error );
	}
} );

const statusSlice = createSlice( {
	name: 'status',
	initialState: {
		data: null,
		loading: false,
		busy: null,
	},
	reducers: {},
	extraReducers: ( builder ) => {
		builder
			.addCase( getStatus.pending, ( state ) => {
				state.loading = ! state.data;
			} )
			.addCase( getStatus.fulfilled, ( state, action ) => {
				state.loading = false;
				state.data = action.payload;
			} )
			.addCase( getStatus.rejected, ( state ) => {
				state.loading = false;
			} )
			.addCase( runTool.pending, ( state, action ) => {
				state.busy = action.meta.arg.path;
			} )
			.addCase( runTool.fulfilled, ( state ) => {
				state.busy = null;
			} )
			.addCase( runTool.rejected, ( state ) => {
				state.busy = null;
			} );
	},
} );

export default statusSlice.reducer;
