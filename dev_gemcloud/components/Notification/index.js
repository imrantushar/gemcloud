import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { useDispatch, useSelector } from 'react-redux';
import { __ } from '@wordpress/i18n';
import { AlertCircle, AlertTriangle, CheckCircle, Info, X } from 'lucide-react';
import { showNotification } from '@GCSRedux/Slices/notificationSlice/notificationSlice';

const ICONS = {
	error: AlertCircle,
	warning: AlertTriangle,
	info: Info,
	success: CheckCircle,
};

const Notification = () => {
	const notification = useSelector( ( state ) => state.notification );
	const dispatch = useDispatch();
	const close = () => dispatch( showNotification( { message: '', isShow: false, type: '' } ) );

	useEffect( () => {
		if ( ! notification.isShow ) {
			return undefined;
		}
		const id = setTimeout( close, 6000 );
		return () => clearTimeout( id );
	}, [ notification.isShow, notification.message ] );

	if ( ! notification.isShow ) {
		return null;
	}

	const type = notification.type || 'success';
	const Icon = ICONS[ type ] || CheckCircle;

	return createPortal(
		<div className={ `gemcloud-notification gemcloud-notification--${ type }` } role="status">
			<div className="gemcloud-notification__message">
				<Icon size={ 18 } />
				<span>{ notification.message }</span>
			</div>
			<button type="button" className="gemcloud-notification__close" onClick={ close } aria-label={ __( 'Close notification', 'gemcloud' ) }>
				<X size={ 16 } />
			</button>
		</div>,
		document.body
	);
};

export default Notification;
