import { createSlice } from '@reduxjs/toolkit';
import { menu } from '@GCSUtils/helper';

const parse = () => {
	try {
		return menu ? JSON.parse( menu ) : {};
	} catch ( e ) {
		return {};
	}
};

const menuSlice = createSlice( {
	name: 'adminmenu',
	initialState: {
		data: parse(),
	},
	reducers: {
		setMenu: ( state, action ) => {
			state.data = action.payload;
		},
	},
} );

export const { setMenu } = menuSlice.actions;
export default menuSlice.reducer;
