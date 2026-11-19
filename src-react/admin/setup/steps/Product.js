/**
 * Step 3 (artboards Step3, Mobile): the product, "more than one hour?" and a live customer preview. Continue creates
 * the product (or updates the chosen/created one) with POST /setup/product; the created id is kept in the wizard
 * state so a second Continue updates instead of creating another product.
 */
import { useEffect, useState } from '@wordpress/element';
import { _n, __, sprintf } from '@wordpress/i18n';

import { api } from '../../shared/api';
import { formatMoney, parseMoney, priceToText, PRICE_MAX } from '../money';
import { startsFor } from '../preview';
import ChoiceCard from '../ui/ChoiceCard';
import Counter from '../ui/Counter';
import { InfoIcon } from '../ui/icons';
import PreviewPanel from '../ui/PreviewPanel';
import Segmented from '../ui/Segmented';

export const MAX_SLOTS_LIMIT = 10;
const DEFAULT_MAX = 3;

const hoursText = ( n ) =>
	sprintf(
		/* translators: %d: number of hours. */
		_n( '%d hour', '%d hours', n, 'mhm-slotiva' ),
		n
	);

function defaultName( kind ) {
	const names = {
		consulting: __( 'One-on-one consulting', 'mhm-slotiva' ),
		lesson: __( 'Private lesson', 'mhm-slotiva' ),
		session: __( 'Appointment', 'mhm-slotiva' ),
	};
	return names[ kind ] || __( 'Appointment', 'mhm-slotiva' );
}

/**
 * Existing products the user can pick: candidates plus products that are already bookable.
 *
 * @param {Object} wiz Wizard state.
 * @return {Array} Products.
 */
function pickable( wiz ) {
	const seen = new Set();
	const out = [];
	[ ...( wiz.candidates || [] ), ...( wiz.products || [] ) ].forEach(
		( p ) => {
			if ( ! seen.has( p.id ) ) {
				seen.add( p.id );
				out.push( p );
			}
		}
	);
	return out;
}

const findExisting = ( wiz ) =>
	pickable( wiz ).find( ( p ) => p.id === wiz.existingId ) || null;

const isVariable = ( p ) => !! p && p.type === 'variable';

/**
 * Post status label for a product that is not published yet (the wizard publishes it on Continue).
 *
 * @param {Object} p Product.
 * @return {string} Label, or '' for published (or unknown) products.
 */
function statusLabel( p ) {
	const labels = {
		draft: __( 'Draft', 'mhm-slotiva' ),
		pending: __( 'Pending review', 'mhm-slotiva' ),
		private: __( 'Private', 'mhm-slotiva' ),
		future: __( 'Scheduled', 'mhm-slotiva' ),
	};
	return labels[ p?.status ] || '';
}

/**
 * Wizard state for an existing product: its price and "more than one hour" values.
 *
 * @param {Object} p        Product (bookable summary or candidate).
 * @param {Object} currency Store currency.
 * @return {Object} State patch.
 */
export function prefillFrom( p, currency ) {
	const multi = p.pricing === 'per_slot' && p.maxSlots > 1;
	return {
		existingId: p.id,
		// Only a bookable product has a length of its own; a candidate's value is just the store default.
		ownDurationMin: p.pricing ? p.durationMin : null,
		priceText: priceToText( p.price, currency ),
		multi,
		maxSlots: multi
			? Math.min( MAX_SLOTS_LIMIT, Math.max( 2, p.maxSlots ) )
			: DEFAULT_MAX,
	};
}

function productName( wiz ) {
	if ( wiz.mode === 'existing' ) {
		return findExisting( wiz )?.name || '';
	}
	return ( wiz.productName || '' ).trim() || defaultName( wiz.kind );
}

/**
 * Continue handler: POST /setup/product. Throws what the API rejects with (message + data.fields).
 *
 * @param {Object} wiz Wizard state.
 * @return {Promise<Object>} State patch (the created product id).
 */
export async function saveProduct( wiz ) {
	const existing = wiz.mode === 'existing';
	const found = existing ? findExisting( wiz ) : null;
	if ( existing && ! found ) {
		throw new Error( __( 'Choose a product first.', 'mhm-slotiva' ) );
	}
	// Variable products: the server saves the price only for simple products, so the current one is sent unchanged.
	let price;
	if ( isVariable( found ) ) {
		price = String( found.price || '0' );
	} else {
		// Parsed once with the store's separators; the server gets a canonical dot-decimal string.
		const parsed = parseMoney( wiz.priceText, wiz.currency );
		// Zero is a valid price (free consultations); only empty, invalid, negative or above-ceiling input is rejected.
		if ( parsed === null || parsed < 0 || parsed > PRICE_MAX ) {
			const err = new Error(
				__( 'Enter a valid price.', 'mhm-slotiva' )
			);
			err.data = { fields: [ 'price' ] };
			throw err;
		}
		price = String( parsed );
	}
	const body = {
		price,
		durationMin: wiz.durationMin,
		multi: wiz.multi,
	};
	if ( wiz.multi ) {
		body.maxSlots = wiz.maxSlots;
	}
	if ( existing ) {
		body.productId = found.id;
	} else {
		body.name = productName( wiz );
		if ( wiz.createdId ) {
			body.productId = wiz.createdId;
		}
	}
	const res = await api.post( '/setup/product', body );
	if ( existing ) {
		return {};
	}
	// Without an id the next Continue would create a duplicate product: stay on the step instead.
	if ( ! res?.product?.id ) {
		throw new Error(
			__(
				'The product was not saved correctly. Please try again.',
				'mhm-slotiva'
			)
		);
	}
	return { createdId: res.product.id };
}

function useWide() {
	const query = '(min-width: 783px)';
	const [ wide, setWide ] = useState(
		() =>
			typeof window.matchMedia !== 'function' ||
			window.matchMedia( query ).matches
	);
	useEffect( () => {
		if ( typeof window.matchMedia !== 'function' ) {
			return undefined;
		}
		const mq = window.matchMedia( query );
		const on = ( e ) => setWide( e.matches );
		mq.addEventListener?.( 'change', on );
		return () => mq.removeEventListener?.( 'change', on );
	}, [] );
	return wide;
}

function Preview( { wiz, price, minutes } ) {
	const n = wiz.multi ? 2 : 1;
	const lang = document.documentElement.lang || undefined;
	const open = wiz.days.map( ( d ) => ( d.open ? d.ranges : [] ) );

	let date = null;
	let starts = [];
	const today = new Date();
	for ( let i = 0; i < 14 && ! date; i++ ) {
		const d = new Date(
			today.getFullYear(),
			today.getMonth(),
			today.getDate() + i
		);
		const s = startsFor( open[ ( d.getDay() + 6 ) % 7 ], minutes );
		if ( s.length >= n ) {
			date = d;
			starts = s;
		}
	}
	const shown = starts.slice( 0, 6 );
	const first = n === 2 && shown.length >= 3 ? 1 : 0;
	const picked = shown.slice( first, first + n );
	const taken =
		shown.length >= 4 && ! picked.includes( shown[ shown.length - 1 ] )
			? shown[ shown.length - 1 ]
			: null;
	const unit = price === null ? null : formatMoney( price, wiz.currency );

	return (
		<PreviewPanel
			title={ sprintf(
				/* translators: %s: "1 hour" or "2 hours". */
				__( 'Customer picks %s', 'mhm-slotiva' ),
				hoursText( n )
			) }
		>
			{ date ? (
				<>
					<div className="slotiva-product__date" lang={ lang }>
						{ new Intl.DateTimeFormat( lang, {
							weekday: 'long',
							day: 'numeric',
							month: 'long',
						} ).format( date ) }
					</div>
					<div className="slotiva-product__starts">
						{ shown.map( ( s ) => {
							let cls = '';
							if ( picked.includes( s ) ) {
								cls = 'is-picked';
							} else if ( s === taken ) {
								cls = 'is-taken';
							}
							return (
								<span key={ s } className={ cls }>
									{ s }
								</span>
							);
						} ) }
					</div>
					{ taken && (
						<div className="slotiva-product__hint">
							{ __(
								'A crossed-out hour is booked by another customer.',
								'mhm-slotiva'
							) }
						</div>
					) }
					<div className="slotiva-product__lines">
						<div>
							<span>{ __( 'Selected', 'mhm-slotiva' ) }</span>
							<strong>{ picked.join( ', ' ) }</strong>
						</div>
						<div>
							<span>{ __( 'In cart', 'mhm-slotiva' ) }</span>
							<strong>
								{ n } × { productName( wiz ) }
							</strong>
						</div>
						{ unit && (
							<div>
								<span>{ __( 'Total', 'mhm-slotiva' ) }</span>
								<strong>
									{ n } × { unit }
								</strong>
							</div>
						) }
					</div>
					{ wiz.multi && (
						<div className="slotiva-product__hint">
							{ __(
								'The hours are paid in one order; all of them are blocked in the calendar.',
								'mhm-slotiva'
							) }
						</div>
					) }
				</>
			) : (
				<p className="slotiva-step__note">
					{ __( 'No open days yet.', 'mhm-slotiva' ) }
				</p>
			) }
		</PreviewPanel>
	);
}

function PriceField( { wiz, update, invalid } ) {
	const c = wiz.currency;
	const before = [ 'left', 'left_space' ].includes( c.position );
	const symbol = (
		<span className="slotiva-product__symbol" aria-hidden="true">
			{ c.symbol }
		</span>
	);
	return (
		<div className="slotiva-product__field">
			<label htmlFor="slotiva-product-price">
				{ __( 'Price of one hour', 'mhm-slotiva' ) }
			</label>
			<div className="slotiva-product__money">
				{ before && symbol }
				<input
					id="slotiva-product-price"
					type="text"
					inputMode="decimal"
					autoComplete="off"
					value={ wiz.priceText }
					aria-invalid={ invalid ? 'true' : undefined }
					aria-describedby={
						invalid ? 'slotiva-product-price-error' : undefined
					}
					onChange={ ( e ) =>
						update( { priceText: e.target.value } )
					}
				/>
				{ ! before && symbol }
			</div>
			{ invalid && (
				<p
					id="slotiva-product-price-error"
					className="slotiva-product__error"
				>
					{ __( 'Enter a valid price.', 'mhm-slotiva' ) }
				</p>
			) }
		</div>
	);
}

export default function Product( { wiz, update, invalidFields = [] } ) {
	const wide = useWide();
	const existing = wiz.mode === 'existing';
	const found = existing ? findExisting( wiz ) : null;
	const variable = isVariable( found );
	const price = variable
		? parseMoney( priceToText( found.price, wiz.currency ), wiz.currency )
		: parseMoney( wiz.priceText, wiz.currency );
	// A bookable product keeps its own length (spec: summary and preview show the product's real length); a new or
	// candidate product gets the store default the wizard saves.
	const productMinutes = found?.pricing ? found.durationMin : wiz.durationMin;
	const ownLength =
		productMinutes !== wiz.durationMin ? productMinutes : null;
	const showPrice = ! existing || ( found && ! variable );
	const bad = ( f ) => invalidFields.includes( f );

	const choose = ( value ) => {
		const p = pickable( wiz ).find( ( x ) => String( x.id ) === value );
		if ( ! p ) {
			update( { existingId: null } );
			return;
		}
		update( prefillFrom( p, wiz.currency ) );
	};

	const rows = Array.from( { length: wiz.maxSlots }, ( _, i ) => i + 1 );

	return (
		<div className="slotiva-step slotiva-product">
			<div className="slotiva-product__main">
				<div>
					<h1>
						{ __( 'What will your customer buy?', 'mhm-slotiva' ) }
					</h1>
				</div>

				<Segmented
					label={ __( 'Which product', 'mhm-slotiva' ) }
					value={ wiz.mode }
					onChange={ ( mode ) => update( { mode } ) }
					options={ [
						{
							value: 'new',
							label: __( 'Create a new product', 'mhm-slotiva' ),
						},
						{
							value: 'existing',
							label: __(
								'Use an existing product',
								'mhm-slotiva'
							),
						},
					] }
				/>

				<div
					className={ `slotiva-product__pair${
						showPrice ? '' : ' is-single'
					}` }
				>
					{ existing ? (
						<div className="slotiva-product__field">
							<label htmlFor="slotiva-product-existing">
								{ __( 'Product', 'mhm-slotiva' ) }
							</label>
							<select
								id="slotiva-product-existing"
								value={ wiz.existingId ?? '' }
								onChange={ ( e ) => choose( e.target.value ) }
							>
								<option value="">
									{ __( 'Choose a product…', 'mhm-slotiva' ) }
								</option>
								{ pickable( wiz ).map( ( p ) => (
									<option key={ p.id } value={ p.id }>
										{ statusLabel( p )
											? sprintf(
													/* translators: 1: product name, 2: post status, e.g. "Draft". */
													__(
														'%1$s (%2$s)',
														'mhm-slotiva'
													),
													p.name,
													statusLabel( p )
											  )
											: p.name }
									</option>
								) ) }
							</select>
						</div>
					) : (
						<div className="slotiva-product__field">
							<label htmlFor="slotiva-product-name">
								{ __( 'Product name', 'mhm-slotiva' ) }
							</label>
							<input
								id="slotiva-product-name"
								type="text"
								value={ wiz.productName }
								placeholder={ defaultName( wiz.kind ) }
								aria-invalid={
									bad( 'name' ) ? 'true' : undefined
								}
								onChange={ ( e ) =>
									update( { productName: e.target.value } )
								}
							/>
						</div>
					) }

					{ showPrice && (
						<PriceField
							wiz={ wiz }
							update={ update }
							invalid={ bad( 'price' ) }
						/>
					) }
				</div>
				{ statusLabel( found ) && (
					<p className="slotiva-step__note">
						<InfoIcon />
						<span>
							{ __(
								'This product will be published.',
								'mhm-slotiva'
							) }
						</span>
					</p>
				) }
				{ existing && found && ownLength && (
					<p className="slotiva-step__note">
						<InfoIcon />
						<span>
							{ sprintf(
								/* translators: %d: the product's own appointment length in minutes. */
								__(
									'This product keeps its own length (%d min). Change it in the product’s Slotiva tab.',
									'mhm-slotiva'
								),
								ownLength
							) }
						</span>
					</p>
				) }
				{ variable && (
					<p className="slotiva-step__note">
						<InfoIcon />
						<span>
							{ __(
								'The prices of variable products are edited on the product itself.',
								'mhm-slotiva'
							) }
						</span>
					</p>
				) }

				<fieldset className="slotiva-step__fieldset">
					<legend className="slotiva-step__legend">
						{ __(
							'Can a customer book more than one hour at a time?',
							'mhm-slotiva'
						) }
					</legend>
					<div className="slotiva-multi">
						<ChoiceCard
							name="slotiva-multi"
							value="no"
							checked={ ! wiz.multi }
							onChange={ () => update( { multi: false } ) }
							title={ __( 'No, one hour', 'mhm-slotiva' ) }
							description={ __(
								'One appointment per order. The price is fixed.',
								'mhm-slotiva'
							) }
						/>
						<ChoiceCard
							name="slotiva-multi"
							value="yes"
							checked={ wiz.multi }
							onChange={ () => update( { multi: true } ) }
							title={
								// Names the counter's real upper bound (2…MAX_SLOTS_LIMIT), not a fixed "2–3".
								wiz.maxSlots > 2
									? sprintf(
											/* translators: %d: most hours per order (3 or more). */
											__(
												'Yes, 2–%d hours too',
												'mhm-slotiva'
											),
											wiz.maxSlots
									  )
									: __( 'Yes, 2 hours too', 'mhm-slotiva' )
							}
							description={ __(
								'The price is multiplied by the number of hours.',
								'mhm-slotiva'
							) }
						/>
					</div>
				</fieldset>

				{ wiz.multi && (
					<>
						<Counter
							label={ __( 'At most per order', 'mhm-slotiva' ) }
							value={ wiz.maxSlots }
							min={ 2 }
							max={ MAX_SLOTS_LIMIT }
							format={ hoursText }
							onChange={ ( v ) => update( { maxSlots: v } ) }
						/>
						{ price !== null && (
							<ul
								className="slotiva-product__prices"
								aria-label={ __(
									'Price by hours',
									'mhm-slotiva'
								) }
							>
								{ rows.map( ( k ) => (
									<li key={ k }>
										<span>{ hoursText( k ) }</span>
										<strong>
											{ k } ×{ ' ' }
											{ formatMoney(
												price,
												wiz.currency
											) }
										</strong>
									</li>
								) ) }
							</ul>
						) }
					</>
				) }

				<p className="slotiva-step__note slotiva-step__note--plain">
					{ __(
						'To change this later: Products → your product → Product data → Slotiva tab.',
						'mhm-slotiva'
					) }
				</p>
			</div>

			<details className="slotiva-fold" open={ wide }>
				<summary>
					{ __( 'What will the customer see?', 'mhm-slotiva' ) }
				</summary>
				<Preview
					wiz={ wiz }
					price={ price }
					minutes={ productMinutes }
				/>
			</details>
		</div>
	);
}
