/**
 * Checkout slot picker (block `slotiva/slot-picker`, inner block of `woocommerce/checkout-fields-block`).
 *
 * Props come from the Checkout block wrapper in WooCommerce 11.2.1 (checkout-frontend.js): `cart`, `extensions`,
 * `checkoutExtensionData`, `validation`. Inner blocks do NOT receive `eventRegistration`, so checkout failures are
 * observed through the `core/notices` store, where `processErrorResponse` stores them with `id = error code`.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	select as dataSelect,
	subscribe,
	useDispatch,
	useSelect,
} from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';

import {
	CHECKOUT_STORE,
	MESSAGE_CODES,
	NAMESPACE,
	NOTICE_CONTEXTS,
	NOTICES_STORE,
	REFRESH_CODES,
	VALIDATION_KEY,
	VALIDATION_STORE,
	dayParts,
	endLabel,
	fetchAvailability,
	findBookableLine,
	variationOf,
} from './api';
import DayStrip from './DayStrip';
import TimeGrid from './TimeGrid';
import Summary from './Summary';
import { inkAndPaper } from './ink';

const HANDLED = [ ...REFRESH_CODES, ...MESSAGE_CODES ];

function messageFor( code ) {
	switch ( code ) {
		case 'slotiva_busy':
			return __(
				'Your order is being processed. Please try again in a few seconds.',
				'mhm-slotiva'
			);
		case 'slotiva_hold_quota':
			return __(
				'You have too many bookings awaiting payment. Please complete them first or try again later.',
				'mhm-slotiva'
			);
		case 'slotiva_overlap':
			return __(
				'Some of your chosen times overlap. Remove one and try again.',
				'mhm-slotiva'
			);
		case 'slotiva_qty_mismatch':
			return __(
				'The quantity in your cart did not match the number of chosen times. We have updated it; please place your order again.',
				'mhm-slotiva'
			);
		case 'slotiva_no_slots':
			return __(
				'Choose a day and time for your booking.',
				'mhm-slotiva'
			);
		case 'slotiva_count_range':
			return __(
				'The number of chosen times is not allowed for this booking. Please adjust your selection.',
				'mhm-slotiva'
			);
		case 'slotiva_not_bookable':
			return __(
				'One of your chosen times can no longer be booked. We refreshed the free times; choose again. Your details are kept.',
				'mhm-slotiva'
			);
		case 'slotiva_bad_format':
		case 'slotiva_duplicate':
			return __(
				'Your chosen times could not be checked. We refreshed the free times; choose again. Your details are kept.',
				'mhm-slotiva'
			);
	}
	return '';
}

function takenMessage( lost ) {
	if ( 1 === lost.length ) {
		return sprintf(
			/* translators: %s: the lost time, e.g. "Tuesday, 17 November, 10:00". */
			__(
				'%s was just taken by someone else. We refreshed the free times; choose a new time. Your details are kept.',
				'mhm-slotiva'
			),
			lost[ 0 ].long
		);
	}
	return __(
		'One of your chosen times was just taken by someone else. We refreshed the free times; choose again. Your details are kept.',
		'mhm-slotiva'
	);
}

/**
 * The API lists today plus `horizon` days (`+horizonDays days`, inclusive), so the horizon is one less.
 *
 * @param {Array} days Days from the API.
 * @return {number} Horizon length in days (at least 1).
 */
function horizonDays( days ) {
	return Math.max( 1, days.length - 1 );
}

function isAvailable( d ) {
	return d.open && d.free > 0;
}

function PickerBody( { item, ext, setExtensionData } ) {
	const product = Number( ext.product_id );
	const variation = variationOf( item, ext );
	const max = Math.max( 1, Number( ext.max ) || 1 );
	const min = Math.max( 1, Number( ext.min ) || 1 );
	const multi = max > 1;
	const duration = Number( ext.duration ) || 0;
	const contactUrl =
		( getSetting( 'slotiva-slot-picker_data', {} ) || {} ).contactUrl || '';

	const [ loadState, setLoadState ] = useState( 'loading' );
	const [ reload, setReload ] = useState( { n: 0, quiet: false } );
	const [ days, setDays ] = useState( [] );
	const [ tzLabel, setTzLabel ] = useState( '' );
	const [ activeDay, setActiveDay ] = useState( '' );
	const [ slots, setSlots ] = useState( {} );
	const [ picks, setPicks ] = useState( [] );
	const [ alert, setAlert ] = useState( '' );

	const picksRef = useRef( picks );
	picksRef.current = picks;
	const alertRef = useRef( null );
	const rootRef = useRef( null );
	// Quantity sync state: last count WooCommerce confirmed, the count in flight, a request sequence and a resync tick.
	const confirmedCount = useRef( Number( item.quantity ) || 1 );
	const inflightCount = useRef( 0 );
	const syncSeq = useRef( 0 );
	const forceSync = useRef( false );
	const [ syncTick, setSyncTick ] = useState( 0 );

	// Days of the horizon (initial load, retry, and quiet refresh after a conflict).
	useEffect( () => {
		let live = true;
		if ( ! reload.quiet ) {
			setLoadState( 'loading' );
		}
		fetchAvailability( { product, variation } )
			.then( ( body ) => {
				if ( ! live ) {
					return;
				}
				const list = Array.isArray( body?.days ) ? body.days : [];
				setDays( list );
				setTzLabel( body?.tz_label || '' );
				setSlots( {} );
				setActiveDay( ( prev ) => {
					const keep = list.find(
						( d ) => d.date === prev && isAvailable( d )
					);
					const first = list.find( isAvailable );
					return keep ? prev : ( first && first.date ) || '';
				} );
				setLoadState( 'ready' );
			} )
			.catch( () => live && setLoadState( 'error' ) );
		return () => {
			live = false;
		};
	}, [ product, variation, reload ] );

	// Free slots of the active day.
	useEffect( () => {
		if (
			'ready' !== loadState ||
			! activeDay ||
			undefined !== slots[ activeDay ]
		) {
			return undefined;
		}
		let live = true;
		fetchAvailability( { product, variation, day: activeDay } )
			.then( ( body ) => {
				if ( live ) {
					setSlots( ( s ) => ( {
						...s,
						[ activeDay ]: Array.isArray( body?.slots )
							? body.slots
							: [],
					} ) );
				}
			} )
			.catch( () => live && setLoadState( 'error' ) );
		return () => {
			live = false;
		};
	}, [ product, variation, activeDay, slots, loadState ] );

	// Selection → checkout request (`extensions.slotiva.slots`, ISO-8601 UTC, chronological). The setter is read from
	// a ref: its identity is not guaranteed stable and re-sending on every render would loop through the store.
	const setterRef = useRef( setExtensionData );
	setterRef.current = setExtensionData;
	useEffect( () => {
		setterRef.current(
			NAMESPACE,
			'slots',
			picks.map( ( p ) => p.start )
		);
	}, [ picks ] );

	// No (or too few) slots → "Place order" is blocked by a validation error.
	const { setValidationErrors, clearValidationError } =
		useDispatch( VALIDATION_STORE ) || {};
	useEffect( () => {
		if ( ! setValidationErrors || ! clearValidationError ) {
			return;
		}
		if ( picks.length >= min ) {
			clearValidationError( VALIDATION_KEY );
			return;
		}
		const message =
			min > 1
				? sprintf(
						/* translators: %d: minimum number of times. */
						_n(
							'Choose at least %d time for your booking.',
							'Choose at least %d times for your booking.',
							min,
							'mhm-slotiva'
						),
						min
				  )
				: __(
						'Choose a day and time for your booking.',
						'mhm-slotiva'
				  );
		setValidationErrors( {
			[ VALIDATION_KEY ]: { message, hidden: true },
		} );
	}, [ picks.length, min, setValidationErrors, clearValidationError ] );
	useEffect(
		() => () =>
			clearValidationError && clearValidationError( VALIDATION_KEY ),
		[ clearValidationError ]
	);
	const validationError = useSelect( ( select ) => {
		const store = select( VALIDATION_STORE );
		return store && store.getValidationError
			? store.getValidationError( VALIDATION_KEY )
			: undefined;
	}, [] );

	// Teması paletsiz sayfalarda seçili durumun rengi: gerçek yazı rengi ve zemin. Mount, durum değişimi, OS açık/koyu
	// değişimi ve temanın `class`/`style`/`data-theme` ile açık/koyu geçişi (html ve body) sonrası yeniden yazılır.
	useEffect( () => {
		const root = rootRef.current;
		if ( ! root ) {
			return undefined;
		}
		const apply = () => {
			const { ink, paper } = inkAndPaper( root );
			root.style.setProperty( '--slotiva-ink', ink );
			root.style.setProperty( '--slotiva-paper', paper );
		};
		apply();
		const mq =
			typeof window.matchMedia === 'function'
				? window.matchMedia( '(prefers-color-scheme: dark)' )
				: null;
		if ( mq && mq.addEventListener ) {
			mq.addEventListener( 'change', apply );
		}
		let observer = null;
		if ( typeof window.MutationObserver === 'function' ) {
			observer = new window.MutationObserver( apply );
			[ document.documentElement, document.body ].forEach(
				( node ) =>
					node &&
					observer.observe( node, {
						attributes: true,
						attributeFilter: [ 'class', 'style', 'data-theme' ],
					} )
			);
		}
		return () => {
			if ( mq && mq.removeEventListener ) {
				mq.removeEventListener( 'change', apply );
			}
			if ( observer ) {
				observer.disconnect();
			}
		};
	}, [ loadState, days ] );

	// "Sipariş ver" seçimsiz: seçici kayar, odak ilk açık güne gelir. Yalnız bizim hatamız görünür tek hataysa ve
	// WooCommerce'in odaklayacağı ilk geçersiz alan seçicinin üstünde değilse; aksi halde WooCommerce'e bırakılır.
	const errorVisible = !! (
		validationError &&
		! validationError.hidden &&
		validationError.message
	);
	const focusPicker = () => {
		const root = rootRef.current;
		const store = dataSelect( VALIDATION_STORE );
		if ( ! root || ! store || ! store.getValidationError ) {
			return;
		}
		const ours = store.getValidationError( VALIDATION_KEY );
		if ( ! ours || ours.hidden ) {
			return;
		}
		const all =
			( store.getValidationErrors && store.getValidationErrors() ) || {};
		if (
			Object.keys( all ).some(
				( key ) => key !== VALIDATION_KEY && ! all[ key ]?.hidden
			)
		) {
			return;
		}
		const invalid = document.querySelector(
			'input:invalid, .has-error input, .has-error select'
		);
		// compareDocumentPosition döndürür bit maskesi; FOLLOWING biti = seçici ilk geçersiz alandan sonra.
		const above =
			invalid &&
			! root.contains( invalid ) &&
			// eslint-disable-next-line no-bitwise
			invalid.compareDocumentPosition( root ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING;
		if ( above ) {
			return;
		}
		if ( typeof root.scrollIntoView === 'function' ) {
			root.scrollIntoView( { block: 'center' } );
		}
		const first = root.querySelector(
			'.slotiva-picker__day:not(:disabled)'
		);
		if ( first ) {
			first.focus( { preventScroll: true } );
		}
	};
	const focusPickerRef = useRef( focusPicker );
	focusPickerRef.current = focusPicker;
	useEffect( () => {
		if ( errorVisible ) {
			focusPickerRef.current();
		}
	}, [ errorVisible, loadState ] );
	// Her "Sipariş ver" denemesi: checkout deposunun herkese açık `isBeforeProcessing` seçicisinin yükselen kenarı
	// (WooCommerce her denemede durumu before_processing'e alır). Hata zaten görünürken ikinci basış da buradan yakalanır;
	// WooCommerce kendi odağını 50 ms sonra verdiği için karar 120 ms sonra alınır.
	useEffect( () => {
		const store = () => dataSelect( CHECKOUT_STORE );
		let before = !! store()?.isBeforeProcessing?.();
		let timer = 0;
		const stop = subscribe( () => {
			const now = !! store()?.isBeforeProcessing?.();
			if ( now && ! before ) {
				window.clearTimeout( timer );
				timer = window.setTimeout(
					() => focusPickerRef.current(),
					120
				);
			}
			before = now;
		}, CHECKOUT_STORE );
		return () => {
			stop();
			window.clearTimeout( timer );
		};
	}, [] );

	// Per-slot pricing: cart quantity follows the number of chosen slots (server re-validates).
	// The update runs inside `disableCheckoutFor`, so "Place order" stays disabled until the cart has the new
	// quantity. The count is committed only on success; a failure leaves the last confirmed count, so the next
	// change (or a `slotiva_qty_mismatch` resync) sends it again. Only the latest request may commit.
	const { disableCheckoutFor } = useDispatch( CHECKOUT_STORE ) || {};
	useEffect( () => {
		const count = picks.length;
		if (
			'per_slot' !== ext.pricing ||
			count < 1 ||
			count === inflightCount.current ||
			( count === confirmedCount.current && ! forceSync.current )
		) {
			return;
		}
		forceSync.current = false;
		const seq = ++syncSeq.current;
		inflightCount.current = count;
		const run = () =>
			Promise.resolve(
				extensionCartUpdate( { namespace: NAMESPACE, data: { count } } )
			);
		Promise.resolve(
			disableCheckoutFor ? disableCheckoutFor( run ) : run()
		)
			.then( () => {
				if ( seq === syncSeq.current ) {
					confirmedCount.current = count;
				}
			} )
			.catch( ( error ) => {
				if ( seq === syncSeq.current ) {
					setAlert(
						error && 'slotiva_busy' === error.code
							? messageFor( 'slotiva_busy' )
							: __(
									'We could not update the quantity in your cart. Please change your selection or try again.',
									'mhm-slotiva'
							  )
					);
				}
			} )
			.finally( () => {
				if ( seq === syncSeq.current ) {
					inflightCount.current = 0;
				}
			} );
	}, [ picks.length, ext.pricing, syncTick, disableCheckoutFor ] );

	// Checkout failures: WooCommerce turns the error body into a `core/notices` notice with id = code.
	const noticeKey = useSelect( ( select ) => {
		const store = select( NOTICES_STORE );
		if ( ! store || ! store.getNotices ) {
			return '';
		}
		for ( const context of NOTICE_CONTEXTS ) {
			const found = store
				.getNotices( context )
				.find( ( n ) => HANDLED.includes( n.id ) );
			if ( found ) {
				return `${ context }|${ found.id }`;
			}
		}
		return '';
	}, [] );
	const { removeNotice } = useDispatch( NOTICES_STORE ) || {};
	useEffect( () => {
		if ( ! noticeKey ) {
			return;
		}
		const [ context, code ] = noticeKey.split( '|' );
		if ( removeNotice ) {
			removeNotice( code, context );
		}
		if ( REFRESH_CODES.includes( code ) ) {
			setAlert(
				'slotiva_slot_taken' === code
					? takenMessage( picksRef.current )
					: messageFor( code )
			);
			setPicks( [] );
			setReload( ( r ) => ( { n: r.n + 1, quiet: true } ) );
			return;
		}
		setAlert( messageFor( code ) );
		if ( 'slotiva_qty_mismatch' === code ) {
			// Cart quantity is not what we last saw confirmed: force a resync of the current count.
			forceSync.current = true;
			inflightCount.current = 0;
			setSyncTick( ( t ) => t + 1 );
		}
	}, [ noticeKey, removeNotice ] );

	useEffect( () => {
		if (
			alert &&
			alertRef.current &&
			typeof alertRef.current.scrollIntoView === 'function'
		) {
			alertRef.current.scrollIntoView( { block: 'center' } );
		}
	}, [ alert ] );

	const selected = useMemo(
		() => new Set( picks.map( ( p ) => p.start ) ),
		[ picks ]
	);
	const picksByDay = useMemo( () => {
		const out = {};
		picks.forEach( ( p ) => {
			out[ p.date ] = ( out[ p.date ] || 0 ) + 1;
		} );
		return out;
	}, [ picks ] );

	const toggle = ( slot ) => {
		setAlert( '' );
		setPicks( ( current ) => {
			if ( current.some( ( p ) => p.start === slot.start ) ) {
				return current.filter( ( p ) => p.start !== slot.start );
			}
			const parts = dayParts( activeDay );
			const pick = {
				start: slot.start,
				date: activeDay,
				label: slot.label,
				long: `${ parts.long }, ${ slot.label }`,
				range: `${ parts.short } · ${ slot.label }–${ endLabel(
					slot.label,
					duration
				) }`,
			};
			if ( ! multi ) {
				return [ pick ];
			}
			if ( current.length >= max ) {
				return current;
			}
			return [ ...current, pick ].sort( ( a, b ) =>
				a.start < b.start ? -1 : 1
			);
		} );
	};
	const remove = ( start ) =>
		setPicks( ( current ) => current.filter( ( p ) => p.start !== start ) );

	const title = multi
		? __( 'Choose your times', 'mhm-slotiva' )
		: __( 'Choose your time', 'mhm-slotiva' );
	let intro = '';
	if ( multi ) {
		intro = sprintf(
			/* translators: %d: maximum number of times. */
			__(
				'Choose one or more times, on different days if you like. At most %d.',
				'mhm-slotiva'
			),
			max
		);
	} else if ( duration ) {
		intro = sprintf(
			/* translators: %d: session length in minutes. */
			__( '%d-minute session.', 'mhm-slotiva' ),
			duration
		);
	}
	const header = (
		<div className="slotiva-picker__head">
			<h2 id="slotiva-picker-title" className="slotiva-picker__title">
				{ title }
			</h2>
			{ tzLabel ? (
				<span className="slotiva-picker__tz">{ tzLabel }</span>
			) : null }
		</div>
	);

	// While a conflict/server alert is on screen it already tells the customer what to do.
	const showError =
		! alert &&
		validationError &&
		! validationError.hidden &&
		validationError.message;

	if ( 'loading' === loadState ) {
		return (
			<section
				ref={ rootRef }
				className="slotiva-picker is-loading"
				aria-labelledby="slotiva-picker-title"
				aria-busy="true"
			>
				{ header }
				<div
					className="slotiva-picker__skeleton-days"
					aria-hidden="true"
				>
					{ [ 0, 1, 2, 3, 4 ].map( ( i ) => (
						<div
							key={ i }
							className="slotiva-picker__skeleton slotiva-picker__skeleton--day"
						/>
					) ) }
				</div>
				<div
					className="slotiva-picker__skeleton-times"
					aria-hidden="true"
				>
					{ [ 0, 1, 2, 3 ].map( ( i ) => (
						<div
							key={ i }
							className="slotiva-picker__skeleton slotiva-picker__skeleton--time"
						/>
					) ) }
				</div>
				<p className="slotiva-picker__muted">
					{ __( 'Loading free times…', 'mhm-slotiva' ) }
				</p>
			</section>
		);
	}

	if ( 'error' === loadState ) {
		return (
			<section
				ref={ rootRef }
				className="slotiva-picker is-error"
				aria-labelledby="slotiva-picker-title"
			>
				{ header }
				<p className="slotiva-picker__muted">
					{ __( 'Free times could not be loaded.', 'mhm-slotiva' ) }
				</p>
				<button
					type="button"
					className="slotiva-picker__retry"
					onClick={ () =>
						setReload( ( r ) => ( { n: r.n + 1, quiet: false } ) )
					}
				>
					{ __( 'Try again', 'mhm-slotiva' ) }
				</button>
			</section>
		);
	}

	if ( ! days.some( isAvailable ) ) {
		return (
			<section
				ref={ rootRef }
				className="slotiva-picker is-empty"
				aria-labelledby="slotiva-picker-title"
			>
				{ header }
				<p className="slotiva-picker__intro">
					{ sprintf(
						/* translators: %d: number of days in the booking horizon. */
						_n(
							'There are no free times in the next %d day. Write to us and we will find a time that suits you.',
							'There are no free times in the next %d days. Write to us and we will find a time that suits you.',
							horizonDays( days ),
							'mhm-slotiva'
						),
						horizonDays( days )
					) }
				</p>
				{ contactUrl ? (
					<a className="slotiva-picker__contact" href={ contactUrl }>
						{ __( 'Contact us', 'mhm-slotiva' ) }
					</a>
				) : null }
				<p
					className="slotiva-picker__blocked"
					role={ showError ? 'alert' : undefined }
				>
					{ __(
						'Ordering is not possible right now.',
						'mhm-slotiva'
					) }
				</p>
			</section>
		);
	}

	const daySlots = slots[ activeDay ];
	const activeParts = activeDay ? dayParts( activeDay ) : null;
	const capReached = multi && picks.length >= max;

	return (
		<section
			ref={ rootRef }
			className={ `slotiva-picker${ showError ? ' has-error' : '' }` }
			aria-labelledby="slotiva-picker-title"
		>
			{ header }
			{ intro ? (
				<p className="slotiva-picker__intro">{ intro }</p>
			) : null }
			{ alert ? (
				<div
					ref={ alertRef }
					className="slotiva-picker__alert"
					role="alert"
				>
					<svg
						width="20"
						height="20"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="2"
						aria-hidden="true"
						focusable="false"
					>
						<circle cx="12" cy="12" r="10" />
						<path d="M12 7v6M12 17h.01" />
					</svg>
					<p>{ alert }</p>
				</div>
			) : null }
			<DayStrip
				days={ days }
				activeDay={ activeDay }
				onPick={ setActiveDay }
				picksByDay={ picksByDay }
				multi={ multi }
			/>
			<div className={ multi ? 'slotiva-picker__cols' : '' }>
				<div className="slotiva-picker__main">
					{ activeParts ? (
						<p className="slotiva-picker__day-title">
							{ duration
								? sprintf(
										/* translators: 1: date, 2: session length in minutes. */
										__(
											'%1$s · free times (%2$d min)',
											'mhm-slotiva'
										),
										activeParts.long,
										duration
								  )
								: activeParts.long }
						</p>
					) : null }
					{ undefined === daySlots ? (
						<div
							className="slotiva-picker__skeleton-times"
							aria-busy="true"
						>
							{ [ 0, 1, 2, 3 ].map( ( i ) => (
								<div
									key={ i }
									className="slotiva-picker__skeleton slotiva-picker__skeleton--time"
								/>
							) ) }
						</div>
					) : (
						<TimeGrid
							slots={ daySlots }
							selected={ selected }
							onToggle={ toggle }
							capReached={ capReached }
							multi={ multi }
						/>
					) }
					{ capReached ? (
						<p className="slotiva-picker__cap" role="status">
							{ sprintf(
								/* translators: %d: maximum number of times. */
								__(
									'You can choose at most %d times; remove one to add another.',
									'mhm-slotiva'
								),
								max
							) }
						</p>
					) : null }
				</div>
				{ multi ? (
					<Summary
						picks={ picks }
						multi
						pricing={ ext.pricing }
						prices={ item.prices }
						onRemove={ remove }
					/>
				) : null }
			</div>
			<div className="slotiva-picker__foot">
				{ multi ? null : <Summary picks={ picks } multi={ false } /> }
				{ ext.policy ? (
					<p className="slotiva-picker__policy">{ ext.policy }</p>
				) : null }
			</div>
			{ showError ? (
				<p className="slotiva-picker__error" role="alert">
					{ validationError.message }
				</p>
			) : null }
		</section>
	);
}

/**
 * Block component. Renders only while the cart has a bookable line.
 *
 * @param {Object} props
 * @param {Object} props.cart                  useStoreCart() data passed by the Checkout block.
 * @param {Object} props.checkoutExtensionData `{ setExtensionData }`.
 */
export default function Picker( { cart, checkoutExtensionData } ) {
	const line = findBookableLine( cart );
	const setExtensionData = checkoutExtensionData?.setExtensionData;
	if ( ! line || typeof setExtensionData !== 'function' ) {
		return null;
	}
	return (
		<PickerBody
			key={ `${ line.ext.product_id }-${ line.item.id }` }
			item={ line.item }
			ext={ line.ext }
			setExtensionData={ setExtensionData }
		/>
	);
}
