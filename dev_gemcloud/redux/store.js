import { configureStore } from '@reduxjs/toolkit';
import notificationReducer from './Slices/notificationSlice/notificationSlice';
import menuReducer from './Slices/menuSlice/menuSlice';
import settingReducer from './Slices/settingSlice/settingSlice';
import statusReducer from './Slices/statusSlice/statusSlice';
import itemsReducer from './Slices/itemsSlice/itemsSlice';

export const store = configureStore( {
	reducer: {
		notification: notificationReducer,
		adminmenu: menuReducer,
		setting: settingReducer,
		status: statusReducer,
		items: itemsReducer,
	},
} );
