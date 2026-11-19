/**
 * Appointment detail entry (Admin\Menu: admin.php?page=slotiva&view=detail&id={id}).
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import ErrorBoundary from '../../../vendor/mhm/ui-core/src-react/components/ErrorBoundary';
import App from './App';
import '../shared/admin.scss';

const root = document.getElementById( 'slotiva-admin-root' );
if ( root ) {
	const id = Number(
		new URLSearchParams( window.location.search ).get( 'id' ) || 0
	);
	createRoot( root ).render(
		<ErrorBoundary
			fallback={
				<p>
					{ __(
						'This screen could not be shown. Reload the page.',
						'mhm-slotiva'
					) }
				</p>
			}
		>
			<App id={ id } />
		</ErrorBoundary>
	);
}
