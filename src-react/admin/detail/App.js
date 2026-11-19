/**
 * Appointment detail (spec §8, frame 7): details, change count + time to start (warning under 24 h), history
 * (`slotiva_events`), completed / no-show / cancel through ConfirmButton, "Change time" on the shared SlotPicker with
 * the two boxes, and "Extend hold" for bank-transfer holds.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import ConfirmButton from '../../../vendor/mhm/ui-core/src-react/components/ConfirmButton';
import DetailLayout from '../../../vendor/mhm/ui-core/src-react/components/DetailLayout';
import DetailList from '../../../vendor/mhm/ui-core/src-react/components/DetailList';
import Notice from '../../../vendor/mhm/ui-core/src-react/components/Notice';
import PageHeader from '../../../vendor/mhm/ui-core/src-react/components/PageHeader';
import Widget from '../../../vendor/mhm/ui-core/src-react/components/Widget';

import {
	ACTIVE,
	WARN_MINUTES,
	dayLabels,
	errorMessage,
	extendHold,
	getAppointment,
	pageData,
	paymentLabel,
	paymentTone,
	reschedule,
	setStatus,
	statusLabel,
	statusTone,
	timeRange,
	timeToStart,
} from '../shared/api';
import { detailPanels } from '../../extensions';
import SlotPicker from '../shared/SlotPicker';

/**
 * Formats a UTC instant in the site time zone ("9 Nov 21:40"); falls back to UTC when the zone is not IANA.
 *
 * @param {string} iso      Instant.
 * @param {string} timeZone Site time zone.
 * @return {string} Text.
 */
function dateTime( iso, timeZone ) {
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return String( iso || '' );
	}
	const options = {
		day: 'numeric',
		month: 'short',
		hour: '2-digit',
		minute: '2-digit',
		hourCycle: 'h23',
	};
	const lang =
		( typeof document !== 'undefined' && document.documentElement.lang ) ||
		undefined;
	try {
		return new Intl.DateTimeFormat( lang, { ...options, timeZone } ).format(
			date
		);
	} catch ( e ) {
		return `${ new Intl.DateTimeFormat( lang, {
			...options,
			timeZone: 'UTC',
		} ).format( date ) } UTC`;
	}
}

function eventText( event, timeZone ) {
	const d = event.data || {};
	switch ( event.type ) {
		case 'status': {
			const to = statusLabel( d.to );
			const text = d.from
				? `${ statusLabel( d.from ) } → ${ to }`
				: sprintf(
						/* translators: %s: status, e.g. "Held". */
						__( 'Created: %s', 'mhm-slotiva' ),
						to
				  );
			return d.reason === 'admin'
				? sprintf(
						/* translators: %s: status change, e.g. "Confirmed → Cancelled". */
						__( '%s (by an admin)', 'mhm-slotiva' ),
						text
				  )
				: text;
		}
		case 'rescheduled': {
			const from = dateTime( d.from, timeZone );
			const to = dateTime( d.to, timeZone );
			return d.counted
				? sprintf(
						/* translators: 1: old time, 2: new time. */
						__( 'Time changed: %1$s → %2$s', 'mhm-slotiva' ),
						from,
						to
				  )
				: sprintf(
						/* translators: 1: old time, 2: new time. */
						__(
							'Time changed: %1$s → %2$s (not counted)',
							'mhm-slotiva'
						),
						from,
						to
				  );
		}
		case 'hold_extended':
			return sprintf(
				/* translators: 1: hours, 2: new end of the payment window. */
				__(
					'Payment window extended by %1$d h, until %2$s',
					'mhm-slotiva'
				),
				Number( d.hours || 0 ),
				dateTime( d.until, timeZone )
			);
		case 'mailed_confirmed':
			return __(
				'Confirmation handled (shop email; customer email if enabled)',
				'mhm-slotiva'
			);
		case 'mailed_cancelled':
			return __(
				'Cancellation handled (customer email if enabled)',
				'mhm-slotiva'
			);
		case 'reminder_sent':
			return __( 'Reminder sent', 'mhm-slotiva' );
		case 'admin_cancelled_paid':
			return __(
				'The order was paid after an admin cancelled this appointment (conflict)',
				'mhm-slotiva'
			);
		case 'anonymised':
			return __(
				'Personal details removed (retention period ended or erasure request)',
				'mhm-slotiva'
			);
		default:
			return event.type;
	}
}

const actorText = ( actor ) =>
	actor === 'system'
		? __( 'system', 'mhm-slotiva' )
		: sprintf(
				/* translators: %s: user id. */
				__( 'user #%s', 'mhm-slotiva' ),
				actor
		  );

export default function App( { id } ) {
	const data = pageData();
	const tz = data.timezone || 'UTC';
	const [ appt, setAppt ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ slot, setSlot ] = useState( null );
	const [ notify, setNotify ] = useState( true );
	const [ countChange, setCountChange ] = useState( true );
	const [ moving, setMoving ] = useState( false );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ hours, setHours ] = useState( 24 );
	const [ extending, setExtending ] = useState( false );
	const [ statusNotify, setStatusNotify ] = useState( true );

	const load = useCallback(
		() =>
			getAppointment( id ).then(
				( r ) => {
					setAppt( r );
					setLoadError( '' );
				},
				( e ) => setLoadError( errorMessage( e ) )
			),
		[ id ]
	);

	useEffect( () => {
		if ( id ) {
			load();
		} else {
			setLoadError( __( 'No appointment selected.', 'mhm-slotiva' ) );
		}
	}, [ id, load ] );

	const back = {
		label: __( 'Appointments', 'mhm-slotiva' ),
		href: data.listUrl || '',
	};

	if ( ! appt ) {
		return (
			<div className="slotiva-detail">
				<PageHeader
					level={ 1 }
					title={ __( 'Appointment', 'mhm-slotiva' ) }
					back={ back }
				/>
				{ loadError ? (
					<Notice tone="danger">{ loadError }</Notice>
				) : (
					<p>{ __( 'Loading…', 'mhm-slotiva' ) }</p>
				) }
			</div>
		);
	}

	const day = dayLabels( appt.local_date );
	// A conflict (paid, slot not taken) can get a new time or be cancelled too (final review F3).
	const active = ACTIVE.includes( appt.status ) || appt.status === 'conflict';
	const underDay =
		appt.minutes_to_start > 0 && appt.minutes_to_start < WARN_MINUTES;

	const changeStatus = ( status, message ) =>
		setStatus( appt.id, status, statusNotify ).then(
			( r ) => {
				setAppt( r );
				setNotice( { tone: 'success', text: message } );
			},
			( e ) => {
				setNotice( { tone: 'danger', text: errorMessage( e ) } );
				if ( e && e.code === 'slotiva_invalid_state' ) {
					load();
				}
			}
		);

	const submitMove = () => {
		if ( ! slot || moving ) {
			return;
		}
		setMoving( true );
		reschedule( appt.id, {
			start: slot.start,
			countChange,
			notify,
			sequence: appt.sequence,
		} ).then(
			( r ) => {
				setMoving( false );
				setAppt( r );
				setSlot( null );
				setRefreshKey( ( k ) => k + 1 );
				setNotice( {
					tone: 'success',
					text: sprintf(
						/* translators: 1: day, 2: time range. */
						__( 'Time changed to %1$s, %2$s.', 'mhm-slotiva' ),
						dayLabels( r.local_date ).long,
						timeRange( r )
					),
				} );
			},
			( e ) => {
				setMoving( false );
				const code = e && e.code;
				setNotice( {
					tone: code === 'slotiva_busy' ? 'warning' : 'danger',
					text: errorMessage( e ),
				} );
				if ( code === 'slotiva_slot_taken' ) {
					setSlot( null );
					setRefreshKey( ( k ) => k + 1 );
				} else if (
					code === 'slotiva_stale' ||
					code === 'slotiva_invalid_state'
				) {
					setSlot( null );
					load().then( () => setRefreshKey( ( k ) => k + 1 ) );
				}
			}
		);
	};

	const submitExtend = () => {
		if ( extending ) {
			return;
		}
		setExtending( true );
		extendHold( appt.id, hours ).then(
			( r ) => {
				setExtending( false );
				setNotice( {
					tone: 'success',
					text: sprintf(
						/* translators: %s: new end of the payment window. */
						__(
							'Payment window extended until %s.',
							'mhm-slotiva'
						),
						dateTime( r.hold_expires_at, tz )
					),
				} );
				load();
			},
			( e ) => {
				setExtending( false );
				setNotice( { tone: 'danger', text: errorMessage( e ) } );
			}
		);
	};

	// v1 shows usage only; no limit is shown or enforced (spec §6).
	const changes = sprintf(
		/* translators: %d: number of time changes counted for this appointment. */
		_n(
			'%d change used',
			'%d changes used',
			appt.reschedule_count,
			'mhm-slotiva'
		),
		appt.reschedule_count
	);
	const left = underDay
		? sprintf(
				/* translators: %s: time left, e.g. "5 h 20 min". */
				__( '%s (under 24 hours)', 'mhm-slotiva' ),
				timeToStart( appt.minutes_to_start )
		  )
		: timeToStart( appt.minutes_to_start );

	const contact = [ appt.customer.email, appt.customer.phone ]
		.filter( Boolean )
		.join( ' · ' );
	const orderValue = appt.order ? (
		<>
			{ appt.order.url ? (
				<a href={ appt.order.url }>#{ appt.order.id }</a>
			) : (
				`#${ appt.order.id }`
			) }
			{ ` · ${ paymentLabel( appt.payment_state ) }` }
		</>
	) : (
		sprintf(
			/* translators: %s: payment state, e.g. "Manual". */
			__( 'No order · %s', 'mhm-slotiva' ),
			paymentLabel( appt.payment_state )
		)
	);

	const canComplete = [ 'confirmed', 'no_show' ].includes( appt.status );
	const canNoShow = [ 'confirmed', 'completed' ].includes( appt.status );
	// The server refuses completed / no-show before the start (409); the buttons say so up front.
	const notStarted = appt.minutes_to_start >= 0;
	const canExtend =
		appt.status === 'held' && appt.payment_state === 'awaiting_transfer';

	const notifyBox = (
		<p className="slotiva-check">
			<input
				id="slotiva-status-notify"
				type="checkbox"
				checked={ statusNotify }
				onChange={ ( e ) => setStatusNotify( e.target.checked ) }
			/>
			<label htmlFor="slotiva-status-notify">
				{ __( 'Email the customer', 'mhm-slotiva' ) }
			</label>
		</p>
	);

	return (
		<div className="slotiva-detail">
			<PageHeader
				level={ 1 }
				back={ back }
				title={ sprintf(
					/* translators: 1: day, e.g. "Thursday, 12 November", 2: time range. */
					__( 'Appointment: %1$s, %2$s', 'mhm-slotiva' ),
					day.long,
					timeRange( appt )
				) }
				badge={ {
					text: statusLabel( appt.status ),
					tone: statusTone( appt.status ),
				} }
				badges={ [
					{
						text: paymentLabel( appt.payment_state ),
						tone: paymentTone( appt.payment_state ),
					},
				] }
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

			<DetailLayout
				asideLabel={ __( 'Change time', 'mhm-slotiva' ) }
				aside={
					active || canExtend ? (
						<>
							{ active && (
								<div id="reschedule">
									<Widget
										title={ __(
											'Change time',
											'mhm-slotiva'
										) }
										subtitle={ __(
											'The new time is locked first, then the old one is released; nobody can take it in between.',
											'mhm-slotiva'
										) }
									>
										<SlotPicker
											exclude={ appt.id }
											product={ appt.product.id }
											today={ data.today }
											current={ appt.starts_at }
											value={ slot }
											onChange={ setSlot }
											refreshKey={ refreshKey }
										/>
										<p className="slotiva-check">
											<input
												id="slotiva-move-notify"
												type="checkbox"
												checked={ notify }
												onChange={ ( e ) =>
													setNotify(
														e.target.checked
													)
												}
											/>
											<label htmlFor="slotiva-move-notify">
												{ __(
													'Email the new time to the customer',
													'mhm-slotiva'
												) }
											</label>
										</p>
										<p className="slotiva-check">
											<input
												id="slotiva-move-count"
												type="checkbox"
												checked={ countChange }
												onChange={ ( e ) =>
													setCountChange(
														e.target.checked
													)
												}
											/>
											<label htmlFor="slotiva-move-count">
												{ __(
													'Count this as a change (the customer asked)',
													'mhm-slotiva'
												) }
											</label>
										</p>
										<div className="slotiva-detail__move">
											<p>
												{ slot ? (
													<>
														{ __(
															'New:',
															'mhm-slotiva'
														) }{ ' ' }
														<strong>
															{ `${
																dayLabels(
																	slot.day
																).long
															}, ${
																slot.label
															}` }
														</strong>
													</>
												) : (
													__(
														'Pick a new day and time.',
														'mhm-slotiva'
													)
												) }
											</p>
											<button
												type="button"
												className="button button-primary"
												aria-disabled={
													! slot || moving
														? 'true'
														: undefined
												}
												onClick={ submitMove }
											>
												{ moving
													? __(
															'Changing…',
															'mhm-slotiva'
													  )
													: __(
															'Change time',
															'mhm-slotiva'
													  ) }
											</button>
										</div>
									</Widget>
								</div>
							) }

							{ canExtend && (
								<Widget
									title={ __(
										'Bank transfer hold',
										'mhm-slotiva'
									) }
								>
									<p>
										{ appt.hold_expires_at
											? sprintf(
													/* translators: %s: end of the payment window. */
													__(
														'The time is held until %s while the transfer is awaited.',
														'mhm-slotiva'
													),
													dateTime(
														appt.hold_expires_at,
														tz
													)
											  )
											: __(
													'The time is held while the transfer is awaited.',
													'mhm-slotiva'
											  ) }
									</p>
									<div className="slotiva-detail__extend">
										<label htmlFor="slotiva-extend-hours">
											{ __(
												'Extend by (hours)',
												'mhm-slotiva'
											) }
										</label>
										<input
											id="slotiva-extend-hours"
											type="number"
											min={ 1 }
											max={ 168 }
											value={ hours }
											onChange={ ( e ) =>
												setHours(
													Math.min(
														168,
														Math.max(
															1,
															Number(
																e.target.value
															) || 1
														)
													)
												)
											}
										/>
										<button
											type="button"
											className="button"
											aria-disabled={
												extending ? 'true' : undefined
											}
											onClick={ submitExtend }
										>
											{ __(
												'Extend hold',
												'mhm-slotiva'
											) }
										</button>
									</div>
								</Widget>
							) }
						</>
					) : null
				}
			>
				<Widget title={ __( 'Details', 'mhm-slotiva' ) }>
					<DetailList
						layout="inline"
						emptyText="—"
						items={ [
							{
								label: __( 'Customer', 'mhm-slotiva' ),
								value: appt.customer.name,
							},
							{
								label: __( 'Email / phone', 'mhm-slotiva' ),
								value: contact,
							},
							{
								label: __( 'Service', 'mhm-slotiva' ),
								value: appt.product.name,
							},
							{
								label: __( 'Location', 'mhm-slotiva' ),
								value: appt.location,
							},
							{
								label: __( 'Order', 'mhm-slotiva' ),
								value: orderValue,
							},
							{
								label: __( 'Changes', 'mhm-slotiva' ),
								value: changes,
							},
							{
								label: __( 'Time to start', 'mhm-slotiva' ),
								value: left,
								tone: underDay ? 'warning' : undefined,
							},
						] }
					/>

					<h3
						className="slotiva-detail__subhead"
						id="slotiva-history"
					>
						{ __( 'History', 'mhm-slotiva' ) }
					</h3>
					{ appt.events.length ? (
						<ol
							className="slotiva-history"
							aria-labelledby="slotiva-history"
						>
							{ appt.events.map( ( ev, i ) => (
								<li key={ `${ ev.created_at }-${ i }` }>
									{ dateTime( ev.created_at, tz ) } —{ ' ' }
									{ eventText( ev, tz ) }{ ' ' }
									<span className="slotiva-muted">
										({ actorText( ev.actor ) })
									</span>
								</li>
							) ) }
						</ol>
					) : (
						<p className="slotiva-muted">
							{ __( 'No history yet.', 'mhm-slotiva' ) }
						</p>
					) }

					{ detailPanels( appt ).map( ( panel ) => (
						<div className="slotiva-detail__panel" key={ panel.id }>
							<h3 className="slotiva-detail__subhead">
								{ panel.title }
							</h3>
							{ 'string' === typeof panel.content ? (
								<p>{ panel.content }</p>
							) : (
								panel.content
							) }
						</div>
					) ) }

					{ ( canComplete || canNoShow || active ) && (
						<div className="slotiva-detail__actions">
							{ ( canComplete || canNoShow ) && notStarted && (
								<p
									id="slotiva-status-early"
									className="slotiva-muted"
								>
									{ __(
										'You can mark this appointment completed or no-show once it has started.',
										'mhm-slotiva'
									) }
								</p>
							) }
							{ canComplete && (
								<ConfirmButton
									variant="primary"
									disabled={ notStarted }
									describedBy={
										notStarted
											? 'slotiva-status-early'
											: undefined
									}
									label={ __(
										'Mark completed',
										'mhm-slotiva'
									) }
									confirmText={ __(
										'Mark this appointment as completed?',
										'mhm-slotiva'
									) }
									confirmLabel={ __(
										'Yes, mark completed',
										'mhm-slotiva'
									) }
									cancelLabel={ __( 'Back', 'mhm-slotiva' ) }
									busyText={ __( 'Saving…', 'mhm-slotiva' ) }
									onConfirm={ () =>
										changeStatus(
											'completed',
											__(
												'Status updated.',
												'mhm-slotiva'
											)
										)
									}
								/>
							) }
							{ canNoShow && (
								<ConfirmButton
									variant="secondary"
									disabled={ notStarted }
									describedBy={
										notStarted
											? 'slotiva-status-early'
											: undefined
									}
									label={ __(
										'Mark no-show',
										'mhm-slotiva'
									) }
									confirmText={ __(
										'Mark that the customer did not come?',
										'mhm-slotiva'
									) }
									confirmLabel={ __(
										'Yes, mark no-show',
										'mhm-slotiva'
									) }
									cancelLabel={ __( 'Back', 'mhm-slotiva' ) }
									busyText={ __( 'Saving…', 'mhm-slotiva' ) }
									onConfirm={ () =>
										changeStatus(
											'no_show',
											__(
												'Status updated.',
												'mhm-slotiva'
											)
										)
									}
								/>
							) }
							{ active && (
								<ConfirmButton
									variant="danger"
									label={ __(
										'Cancel appointment…',
										'mhm-slotiva'
									) }
									confirmText={
										// A conflict holds no time (another booking has it), so nothing becomes free.
										appt.status === 'conflict'
											? __(
													'Cancel this appointment? It holds no time, so no time becomes free; the order is not changed.',
													'mhm-slotiva'
											  )
											: __(
													'Cancel this appointment? The time becomes free again; the order is not changed.',
													'mhm-slotiva'
											  )
									}
									confirmLabel={ __(
										'Cancel appointment',
										'mhm-slotiva'
									) }
									cancelLabel={ __( 'Keep', 'mhm-slotiva' ) }
									busyText={ __(
										'Cancelling…',
										'mhm-slotiva'
									) }
									onConfirm={ () =>
										changeStatus(
											'cancelled',
											__(
												'Appointment cancelled.',
												'mhm-slotiva'
											)
										)
									}
								>
									{ notifyBox }
								</ConfirmButton>
							) }
						</div>
					) }
				</Widget>
			</DetailLayout>
		</div>
	);
}
