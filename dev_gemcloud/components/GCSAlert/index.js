import React from 'react';
import { AlertCircle, AlertTriangle, CheckCircle, Info } from 'lucide-react';

const TONES = {
	info: { Icon: Info, color: 'var(--gcs-info)', background: 'var(--gcs-info-bg)' },
	success: { Icon: CheckCircle, color: 'var(--gcs-success)', background: 'var(--gcs-success-bg)' },
	warning: { Icon: AlertTriangle, color: 'var(--gcs-warning)', background: 'var(--gcs-warning-bg)' },
	error: { Icon: AlertCircle, color: 'var(--gcs-danger)', background: 'var(--gcs-danger-bg)' },
};

const GCSAlert = ( { type = 'info', title, children, action } ) => {
	const tone = TONES[ type ] || TONES.info;
	const { Icon } = tone;
	return (
		<div
			className="flex items-start gap-3 rounded-[8px] border px-4 py-3 text-[13px]"
			style={ { background: tone.background, borderColor: tone.color, color: 'var(--gcs-font-color)' } }
			role={ type === 'error' ? 'alert' : 'status' }
		>
			<Icon size={ 18 } className="mt-[1px] shrink-0" style={ { color: tone.color } } />
			<div className="min-w-0 flex-1">
				{ title && <div className="mb-0.5 font-semibold">{ title }</div> }
				<div className="leading-[1.55]">{ children }</div>
			</div>
			{ action && <div className="shrink-0">{ action }</div> }
		</div>
	);
};

export default GCSAlert;
