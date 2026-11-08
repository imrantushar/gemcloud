import React from 'react';
import { __ } from '@wordpress/i18n';
import { ChevronRight } from 'lucide-react';
import { plugin_root_url } from '@GCSUtils/helper';
import TopBar from '@GCSComponents/TopBar';
import TopBarNav from '@GCSComponents/TopBarNav';
import SubTopBar from '@GCSComponents/SubTopBar';

/**
 * Standard page frame: sticky top bar with breadcrumb and section pills,
 * then the page heading with its actions, then the content.
 */
const PageLayout = ( { title, heading, description, actions, children } ) => (
	<>
		<TopBar
			leftContent={ () => (
				<>
					<img
						src={ `${ plugin_root_url }assets/images/logo.svg` }
						alt={ __( 'GemCloud', 'gemcloud' ) }
						width="32"
						height="32"
						style={ { borderRadius: '6px' } }
					/>
					<ChevronRight size={ 16 } className="shrink-0 text-[var(--gcs-text-muted)]" />
					<span className="gemcloud-topbar__crumb">{ title }</span>
				</>
			) }
			middleContent={ () => <TopBarNav /> }
		/>
		<SubTopBar heading={ heading || title } description={ description }>
			{ actions }
		</SubTopBar>
		<div className="gemcloud-page-content">{ children }</div>
	</>
);

export default PageLayout;
