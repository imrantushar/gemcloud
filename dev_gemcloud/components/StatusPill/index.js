import React from 'react';
import { __ } from '@wordpress/i18n';

const STYLES = {
	synced: { color: 'var(--gemcloud-success)', background: 'var(--gemcloud-success-bg)' },
	pending: { color: 'var(--gemcloud-warning)', background: 'var(--gemcloud-warning-bg)' },
	uploading: { color: 'var(--gemcloud-info)', background: 'var(--gemcloud-info-bg)' },
	failed: { color: 'var(--gemcloud-danger)', background: 'var(--gemcloud-danger-bg)' },
	deleting: { color: 'var(--gemcloud-text-muted)', background: 'var(--gemcloud-secondary-color)' },
	ok: { color: 'var(--gemcloud-success)', background: 'var(--gemcloud-success-bg)' },
	off: { color: 'var(--gemcloud-text-muted)', background: 'var(--gemcloud-secondary-color)' },
	warning: { color: 'var(--gemcloud-warning)', background: 'var(--gemcloud-warning-bg)' },
	error: { color: 'var(--gemcloud-danger)', background: 'var(--gemcloud-danger-bg)' },
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
