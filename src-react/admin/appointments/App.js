/**
 * Appointments (spec §8, frame 5; frame 6 on mobile via DataTable "stack"): stats, tabs, list with separate status
 * and payment badges, row actions, manual add, CSV export.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import ConfirmButton from '../../../vendor/mhm/ui-core/src-react/components/ConfirmButton';
import DataTable from '../../../vendor/mhm/ui-core/src-react/components/DataTable';
import Notice from '../../../vendor/mhm/ui-core/src-react/components/Notice';
import PageHeader from '../../../vendor/mhm/ui-core/src-react/components/PageHeader';
import Pagination from '../../../vendor/mhm/ui-core/src-react/components/Pagination';
import SortHeader from '../../../vendor/mhm/ui-core/src-react/components/SortHeader';
import StatsGrid from '../../../vendor/mhm/ui-core/src-react/components/StatsGrid';
import StatusBadge from '../../../vendor/mhm/ui-core/src-react/components/StatusBadge';
import Tabs from '../../../vendor/mhm/ui-core/src-react/components/Tabs';
import Button from '../../../vendor/mhm/ui-core/src-react/components/Button';

import {
	ACTIVE,
	dayLabels,
	errorMessage,
	exportUrl,
	listAppointments,
	pageData,
	paymentLabel,
	paymentTone,
	setStatus,
	statusLabel,
	statusTone,
	timeRange,
} from '../shared/api';
import { cellContent, listColumns } from '../../extensions';
import AddDialog from './AddDialog';
import SetupChecklist from './SetupChecklist';

const PER_PAGE = 20;
const TABS = [ 'today', 'upcoming', 'past', 'cancelled' ];
/** Server default per tab (ReservationRepository::listQuery). */
const DEFAULT_ORDER = {
	today: 'asc',
	upcoming: 'asc',
	past: 'desc',
	cancelled: 'desc',
};

function initialTab() {
	const tab =
		typeof window !== 'undefined'
			? new URLSearchParams( window.location.search ).get( 'tab' )
			: null;
	return TABS.includes( tab ) ? tab : 'upcoming';
}

function tabLabel( id ) {
	return {
		today: __( 'Today', 'mhm-slotiva' ),
		upcoming: __( 'Upcoming', 'mhm-slotiva' ),
		past: __( 'Past', 'mhm-slotiva' ),
		cancelled: __( 'Cancelled', 'mhm-slotiva' ),
	}[ id ];
}

export default function App() {
	const data = pageData();
	const extraColumns = listColumns();
	const [ tab, setTab ] = useState( initialTab );
	const [ page, setPage ] = useState( 1 );
	const [ order, setOrder ] = useState( null );
	const [ list, setList ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ adding, setAdding ] = useState( false );
	const [ reloadKey, setReloadKey ] = useState( 0 );

	const reload = useCallback( () => setReloadKey( ( k ) => k + 1 ), [] );

	useEffect( () => {
		let alive = true;
		listAppointments( { tab, page, perPage: PER_PAGE, order } ).then(
			( r ) => {
				if ( alive ) {
					setList( r );
					setLoadError( '' );
				}
			},
			( e ) => {
				if ( alive ) {
					setLoadError( errorMessage( e ) );
				}
			}
		);
		return () => {
			alive = false;
		};
	}, [ tab, page, order, reloadKey ] );

	const tabHref = ( id ) => `${ data.listUrl || '' }&tab=${ id }`;
	const selectTab = ( id ) => {
		setTab( id );
		setPage( 1 );
		setOrder( null );
		setList( null );
		try {
			// Keeps the tab in the address (reload, share); a refused update only loses that convenience.
			window.history.replaceState( null, '', tabHref( id ) );
		} catch ( e ) {}
	};

	const direction = order || DEFAULT_ORDER[ tab ];
	const total = list ? list.total : 0;
	const stats = list ? list.stats : null;

	const cancel = ( appt ) =>
		setStatus( appt.id, 'cancelled', true ).then(
			() => {
				setNotice( {
					tone: 'success',
					text: __( 'Appointment cancelled.', 'mhm-slotiva' ),
				} );
				reload();
			},
			( e ) => setNotice( { tone: 'danger', text: errorMessage( e ) } )
		);

	const detailHref = ( appt ) => `${ data.detailUrl || '' }${ appt.id }`;

	return (
		<div className="slotiva-appointments">
			<PageHeader
				level={ 1 }
				title={ __( 'Appointments', 'mhm-slotiva' ) }
				actions={
					<>
						<Button
							variant="secondary"
							href={ exportUrl(
								data.restRoot,
								data.exportNonce
							) }
						>
							{ __( 'Export CSV', 'mhm-slotiva' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => setAdding( true ) }
						>
							{ __( '+ Add appointment', 'mhm-slotiva' ) }
						</Button>
					</>
				}
			/>

			{ notice && (
				<Notice
					tone={ notice.tone }
					onDismiss={ () => setNotice( null ) }
					dismissLabel={ __( 'Dismiss this notice.', 'mhm-slotiva' ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ loadError && <Notice tone="danger">{ loadError }</Notice> }

			<SetupChecklist />

			<StatsGrid
				columns={ 4 }
				cards={ [
					{
						label: __( 'Today', 'mhm-slotiva' ),
						value: stats ? String( stats.today ) : '–',
						icon: 'calendar',
					},
					{
						label: __( 'This week', 'mhm-slotiva' ),
						value: stats ? String( stats.week ) : '–',
						icon: 'calendar-alt',
					},
					{
						label: __( 'Awaiting payment', 'mhm-slotiva' ),
						value: stats ? String( stats.awaiting_payment ) : '–',
						icon: 'clock',
					},
					{
						label: __( 'Pay on site', 'mhm-slotiva' ),
						value: stats ? String( stats.on_site ) : '–',
						icon: 'store',
					},
				] }
			/>

			<Tabs
				label={ __( 'Appointment filter', 'mhm-slotiva' ) }
				variant="underline"
				current={ tab }
				onSelect={ selectTab }
				items={ TABS.map( ( id ) => ( {
					id,
					label: tabLabel( id ),
					href: tabHref( id ),
					badge: id === tab && list ? total : undefined,
					badgeLabel:
						id === tab && list
							? sprintf(
									/* translators: %d: number of appointments. */
									_n(
										'%d appointment',
										'%d appointments',
										total,
										'mhm-slotiva'
									),
									total
							  )
							: undefined,
				} ) ) }
			/>

			<DataTable
				caption={ tabLabel( tab ) }
				captionHidden
				layout="stack"
				className="slotiva-appointments__table"
				footer={
					<Pagination
						variant="footer"
						page={ page }
						totalPages={ Math.max(
							1,
							Math.ceil( total / PER_PAGE )
						) }
						onChange={ setPage }
						summary={ sprintf(
							/* translators: %d: number of appointments. */
							_n(
								'%d appointment',
								'%d appointments',
								total,
								'mhm-slotiva'
							),
							total
						) }
						labels={ {
							previous: __( 'Previous', 'mhm-slotiva' ),
							next: __( 'Next', 'mhm-slotiva' ),
							of: __( 'of', 'mhm-slotiva' ),
							page: __( 'Page', 'mhm-slotiva' ),
							navigation: __(
								'Appointment pages',
								'mhm-slotiva'
							),
						} }
					/>
				}
			>
				<thead>
					<tr>
						<SortHeader
							label={ __( 'Date / time', 'mhm-slotiva' ) }
							sortKey="starts_at"
							sort={ { key: 'starts_at', direction } }
							onSort={ () => {
								setOrder(
									direction === 'asc' ? 'desc' : 'asc'
								);
								setPage( 1 );
							} }
						/>
						<th scope="col">{ __( 'Customer', 'mhm-slotiva' ) }</th>
						<th scope="col">{ __( 'Service', 'mhm-slotiva' ) }</th>
						<th scope="col">
							{ __( 'Appointment', 'mhm-slotiva' ) }
						</th>
						<th scope="col">{ __( 'Payment', 'mhm-slotiva' ) }</th>
						<th scope="col">{ __( 'Order', 'mhm-slotiva' ) }</th>
						{ extraColumns.map( ( c ) => (
							<th scope="col" key={ c.id }>
								{ c.label }
							</th>
						) ) }
						<th scope="col">
							<span className="screen-reader-text">
								{ __( 'Actions', 'mhm-slotiva' ) }
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ ! list && ! loadError && (
						<tr>
							<td colSpan={ 7 + extraColumns.length }>
								{ __( 'Loading…', 'mhm-slotiva' ) }
							</td>
						</tr>
					) }
					{ list && list.items.length === 0 && (
						<tr>
							<td colSpan={ 7 + extraColumns.length }>
								{ __( 'No appointments here.', 'mhm-slotiva' ) }
							</td>
						</tr>
					) }
					{ list &&
						list.items.map( ( appt ) => (
							<tr key={ appt.id }>
								<td
									data-label={ __(
										'Date / time',
										'mhm-slotiva'
									) }
								>
									<strong>
										{ dayLabels( appt.local_date ).short }
									</strong>
									<br />
									<span className="slotiva-muted">
										{ timeRange( appt ) }
									</span>
								</td>
								<td
									data-label={ __(
										'Customer',
										'mhm-slotiva'
									) }
								>
									{ appt.customer.name || '—' }
									{ appt.customer.phone && (
										<>
											<br />
											<span className="slotiva-muted">
												{ appt.customer.phone }
											</span>
										</>
									) }
								</td>
								<td
									data-label={ __(
										'Service',
										'mhm-slotiva'
									) }
								>
									{ appt.product.name }
								</td>
								<td
									data-label={ __(
										'Appointment',
										'mhm-slotiva'
									) }
								>
									<StatusBadge
										tone={ statusTone( appt.status ) }
									>
										{ statusLabel( appt.status ) }
									</StatusBadge>
								</td>
								<td
									data-label={ __(
										'Payment',
										'mhm-slotiva'
									) }
								>
									<StatusBadge
										tone={ paymentTone(
											appt.payment_state
										) }
									>
										{ paymentLabel( appt.payment_state ) }
									</StatusBadge>
								</td>
								<td data-label={ __( 'Order', 'mhm-slotiva' ) }>
									<OrderCell appt={ appt } />
								</td>
								{ extraColumns.map( ( c ) => (
									<td key={ c.id } data-label={ c.label }>
										{ cellContent( c, appt ) }
									</td>
								) ) }
								<td
									data-label={ __(
										'Actions',
										'mhm-slotiva'
									) }
									className="slotiva-row-actions"
								>
									<a href={ detailHref( appt ) }>
										{ __( 'Open', 'mhm-slotiva' ) }
									</a>
									{ ACTIVE.includes( appt.status ) && (
										<>
											<a
												href={ `${ detailHref(
													appt
												) }#reschedule` }
											>
												{ __(
													'Change time',
													'mhm-slotiva'
												) }
											</a>
											<ConfirmButton
												variant="danger"
												size="compact"
												label={ __(
													'Cancel',
													'mhm-slotiva'
												) }
												confirmText={ __(
													'Cancel this appointment? The time becomes free again; the order is not changed.',
													'mhm-slotiva'
												) }
												confirmLabel={ __(
													'Cancel appointment',
													'mhm-slotiva'
												) }
												cancelLabel={ __(
													'Keep',
													'mhm-slotiva'
												) }
												busyText={ __(
													'Cancelling…',
													'mhm-slotiva'
												) }
												onConfirm={ () =>
													cancel( appt )
												}
											/>
										</>
									) }
								</td>
							</tr>
						) ) }
				</tbody>
			</DataTable>

			<p className="slotiva-muted">
				{ sprintf(
					/* translators: %s: time zone, e.g. "Europe/Istanbul (UTC+03:00)". */
					__(
						'Times are in %s. An appointment awaiting payment is held until its payment window ends, then released automatically.',
						'mhm-slotiva'
					),
					data.tzLabel || ''
				) }
			</p>

			{ adding && (
				<AddDialog
					products={ data.products || [] }
					today={ data.today }
					onClose={ () => setAdding( false ) }
					onAdded={ () => {
						setAdding( false );
						setNotice( {
							tone: 'success',
							text: __( 'Appointment added.', 'mhm-slotiva' ),
						} );
						reload();
					} }
				/>
			) }
		</div>
	);
}

function OrderCell( { appt } ) {
	if ( ! appt.order ) {
		return (
			<span className="slotiva-muted">
				{ __( 'Manual', 'mhm-slotiva' ) }
			</span>
		);
	}
	const text = `#${ appt.order.id }`;
	return appt.order.url ? <a href={ appt.order.url }>{ text }</a> : text;
}
