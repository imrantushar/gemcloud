import { createSlice } from '@reduxjs/toolkit';

const notificationSlice = createSlice( {
	name: 'notification',
	initialState: {
		message: '',
		isShow: false,
		type: '',
	},
	reducers: {
		showNotification: ( state, action ) => {
			state.message = action.payload.message;
			state.isShow = action.payload.isShow;
			state.type = action.payload.type;
		},
	},
} );

export default notificationSlice.reducer;
export const { showNotification } = notificationSlice.actions;
