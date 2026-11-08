import React from 'react';
import { __ } from '@wordpress/i18n';

const STYLES = {
	synced: { color: 'var(--gcs-success)', background: 'var(--gcs-success-bg)' },
	pending: { color: 'var(--gcs-warning)', background: 'var(--gcs-warning-bg)' },
	uploading: { color: 'var(--gcs-info)', background: 'var(--gcs-info-bg)' },
	failed: { color: 'var(--gcs-danger)', background: 'var(--gcs-danger-bg)' },
	deleting: { color: 'var(--gcs-text-muted)', background: 'var(--gcs-secondary-color)' },
	ok: { color: 'var(--gcs-success)', background: 'var(--gcs-success-bg)' },
	off: { color: 'var(--gcs-text-muted)', background: 'var(--gcs-secondary-color)' },
	warning: { color: 'var(--gcs-warning)', background: 'var(--gcs-warning-bg)' },
	error: { color: 'var(--gcs-danger)', background: 'var(--gcs-danger-bg)' },
};

export const STATUS_LABELS = {
	synced: __( 'Synced', 'gemcloud' ),
	pending: __( 'Pending', 'gemcloud' ),
	uploading: __( 'Uploading', 'gemcloud' ),
	failed: __( 'Failed', 'gemcloud' ),
	deleting: __( 'Removing', 'gemcloud' ),
};

/**
 * Rounded status label with a leading dot, as in Zaplane's logs table.
 */
const StatusPill = ( { status, label } ) => {
	const style = STYLES[ status ] || STYLES.off;
	return (
		<span className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[12px] font-medium" style={ style }>
			<span className="h-[6px] w-[6px] rounded-full" style={ { background: style.color } } />
			{ label || STATUS_LABELS[ status ] || status }
		</span>
	);
};

export default StatusPill;
