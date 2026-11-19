/**
 * Setup wizard: frame + step routing. Wizard state lives here (loaded once from GET /setup) so steps 3–5 can read
 * it; each step saves on Continue and, on a REST error, stays put and shows the server message.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { api, pageData } from '../shared/api';
import { docsUrl } from '../shared/docs';
import { NewTabLink } from '../shared/NewTabNote';
import SetupFrame, { StepFooter } from './SetupFrame';
import { DEFAULT_CURRENCY } from './money';
import Checkout from './steps/Checkout';
import Done from './steps/Done';
import Hours, { daysFromApi, saveHours } from './steps/Hours';
import Product, { prefillFrom, saveProduct } from './steps/Product';
import Service, { DURATIONS, saveService } from './steps/Service';
import Welcome from './steps/Welcome';
import useStep, { LAST_STEP } from './useStep';

// Steps whose Continue saves something first.
// A saver may return a state patch (step 3 returns the created product id).
const SAVERS = { 1: saveService, 2: saveHours, 3: saveProduct };

/**
 * Step 3 start: a re-run picks the existing bookable product (the one named by `?product=` first, else the first one)
 * so Continue updates it instead of creating a second product. `?new=1` (Settings "+ New bookable product") keeps the
 * new-product mode.
 *
 * @param {Array}  products Bookable products from GET /setup.
 * @param {Object} currency Store currency.
 * @return {Object} State patch.
 */
function initialProduct( products, currency ) {
	const params = new URLSearchParams( window.location.search );
	const named = products.find(
		( p ) => String( p.id ) === params.get( 'product' )
	);
	if ( ! named && params.get( 'new' ) === '1' ) {
		return {};
	}
	const pick = named || products[ 0 ];
	return pick ? { mode: 'existing', ...prefillFrom( pick, currency ) } : {};
}

/**
 * After a product is created, the URL names it (`product=ID`, no `new=1`) so a reload updates it.
 *
 * @param {number} id Created product id.
 */
function rememberCreated( id ) {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'new' );
	url.searchParams.set( 'product', String( id ) );
	window.history.replaceState( window.history.state, '', url.toString() );
}

function initialState( res ) {
	const minutes = Number( res?.rules?.durationDefault ) || 60;
	const custom = ! DURATIONS.includes( minutes );
	const currency = { ...DEFAULT_CURRENCY, ...( res?.currency || {} ) };
	const products = res?.products || [];
	return {
		kind: 'consulting',
		// The service type is not saved; it is shown on step 5 only when chosen in this visit.
		kindSet: false,
		durationMin: minutes,
		durationCustom: custom,
		customText: custom ? String( minutes ) : '',
		days: daysFromApi( res?.schedule?.days || [] ),
		preset: null,
		currency,
		products,
		candidates: res?.candidates || [],
		mode: 'new',
		productName: '',
		priceText: '',
		multi: false,
		maxSlots: 3,
		existingId: null,
		createdId: undefined,
		...initialProduct( products, currency ),
	};
}

export default function App() {
	const data = pageData();
	const [ step, go ] = useStep();
	const [ wiz, setWiz ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ invalid, setInvalid ] = useState( [] );
	// Steps 4 and 5 read a fresh GET /setup (step 5 first records completion).
	const [ status, setStatus ] = useState( null );
	const [ statusError, setStatusError ] = useState( '' );
	const ready = !! wiz;

	useEffect( () => {
		let live = true;
		api.get( '/setup' )
			.then( ( res ) => live && setWiz( initialState( res ) ) )
			.catch(
				( e ) =>
					live &&
					setLoadError(
						e?.message ||
							__(
								'The setup data could not be loaded.',
								'mhm-slotiva'
							)
					)
			);
		return () => {
			live = false;
		};
	}, [] );

	useEffect( () => {
		if ( ! ready || ( step !== 4 && step !== 5 ) ) {
			return undefined;
		}
		let live = true;
		setStatus( null );
		setStatusError( '' );
		( async () => {
			try {
				if ( step === 5 ) {
					await api.post( '/setup/state', { completed: true } );
				}
				const res = await api.get( '/setup' );
				if ( live ) {
					setStatus( res );
				}
			} catch ( e ) {
				if ( live ) {
					setStatusError(
						e?.message ||
							__(
								'The setup data could not be loaded.',
								'mhm-slotiva'
							)
					);
				}
			}
		} )();
		return () => {
			live = false;
		};
	}, [ step, ready ] );

	const update = ( patch ) => {
		setError( '' );
		setInvalid( [] );
		setWiz( ( prev ) => ( { ...prev, ...patch } ) );
	};
	const goTo = ( target ) => {
		setError( '' );
		setInvalid( [] );
		go( target );
	};
	const next = async () => {
		const save = SAVERS[ step ];
		if ( save ) {
			setBusy( true );
			try {
				const patch = await save( wiz );
				if ( patch ) {
					setWiz( ( prev ) => ( { ...prev, ...patch } ) );
					if ( patch.createdId ) {
						rememberCreated( patch.createdId );
					}
				}
			} catch ( e ) {
				setInvalid( e?.data?.fields || [] );
				setError(
					e?.message ||
						__( 'Saving failed. Please try again.', 'mhm-slotiva' )
				);
				setBusy( false );
				return;
			}
			setBusy( false );
		}
		goTo( step + 1 );
	};

	let body;
	if ( step === 0 ) {
		body = (
			<Welcome onStart={ () => goTo( 1 ) } skipHref={ data.listUrl } />
		);
	} else if ( loadError ) {
		body = <p role="alert">{ loadError }</p>;
	} else if ( ! wiz ) {
		body = <p>{ __( 'Loading…', 'mhm-slotiva' ) }</p>;
	} else if ( step === 1 ) {
		body = <Service wiz={ wiz } update={ update } />;
	} else if ( step === 2 ) {
		body = <Hours wiz={ wiz } update={ update } />;
	} else if ( step === 3 ) {
		body = (
			<Product wiz={ wiz } update={ update } invalidFields={ invalid } />
		);
	} else if ( statusError ) {
		body = <p role="alert">{ statusError }</p>;
	} else if ( ! status ) {
		body = <p>{ __( 'Loading…', 'mhm-slotiva' ) }</p>;
	} else if ( step === 4 ) {
		body = <Checkout status={ status } />;
	} else {
		body = (
			<Done
				wiz={ wiz }
				status={ status }
				goTo={ goTo }
				listUrl={ data.listUrl }
			/>
		);
	}

	const checks = status?.checks || {};
	const okCount = [ 'hours', 'product', 'checkout' ].filter(
		( k ) => checks[ k ]?.state === 'ok'
	).length;
	const checkoutDone = [ 'timezone', 'payment', 'checkout' ].every(
		( k ) => ( checks[ k ]?.state || 'ok' ) === 'ok'
	);
	const productLabel =
		wiz?.mode === 'existing'
			? __( 'Save the product and continue', 'mhm-slotiva' )
			: __( 'Create the product and continue', 'mhm-slotiva' );
	const guideUrl = docsUrl( 'getting-started/setup-wizard/' );
	let footer = null;
	if ( step === LAST_STEP ) {
		footer = (
			<>
				<span>
					{ __( 'Run this wizard again from', 'mhm-slotiva' ) }{ ' ' }
					<strong>
						{ __(
							'Slotiva → Settings → Setup wizard',
							'mhm-slotiva'
						) }
					</strong>
				</span>
				{ guideUrl && (
					<NewTabLink href={ guideUrl }>
						{ __( 'First-time setup guide', 'mhm-slotiva' ) }
					</NewTabLink>
				) }
			</>
		);
	} else if ( step > 0 ) {
		footer = (
			<StepFooter
				canNext
				busy={ busy || ! wiz || !! loadError }
				onBack={ () => goTo( step - 1 ) }
				onNext={ next }
				nextLabel={ step === 3 ? productLabel : '' }
				extra={
					step === 4 && status && ! checkoutDone ? (
						<button
							type="button"
							className="slotiva-setup__later"
							onClick={ () => goTo( 5 ) }
						>
							{ __( 'I’ll do this later', 'mhm-slotiva' ) }
						</button>
					) : null
				}
			/>
		);
	}

	return (
		<SetupFrame
			step={ step }
			total={ LAST_STEP }
			onSkipHref={ data.listUrl }
			footer={ footer }
			doneLabel={
				status && step === LAST_STEP
					? sprintf(
							/* translators: 1: completed steps, 2: total steps. */
							__( '%1$d of %2$d done', 'mhm-slotiva' ),
							2 + okCount,
							LAST_STEP
					  )
					: ''
			}
		>
			{ body }
			{ error && (
				<p role="alert" className="slotiva-setup__error">
					{ error }
				</p>
			) }
		</SetupFrame>
	);
}
