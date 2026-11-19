/**
 * Appointments page entry (Admin\Menu: admin.php?page=slotiva).
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import ErrorBoundary from '../../../vendor/mhm/ui-core/src-react/components/ErrorBoundary';
import App from './App';
import '../shared/admin.scss';

const root = document.getElementById( 'slotiva-admin-root' );
if ( root ) {
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
			<App />
		</ErrorBoundary>
	);
}
