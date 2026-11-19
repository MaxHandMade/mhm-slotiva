/**
 * Step 5 (artboard Step5): ready. Summary rows come from a GET /setup made after the "completed" POST; each row has
 * "Change" to the related step.
 */
import { __, sprintf } from '@wordpress/i18n';

import { dayNames, hoursSummary } from '../../shared/hours';
import NewTabNote from '../../shared/NewTabNote';
import { formatMoney, parseMoney } from '../money';
import { CheckIcon, ExternalIcon } from '../ui/icons';
import { kindTitle } from './Service';

export { hoursSummary };

function Row( { label, value, extra, onChange, valueClass } ) {
	return (
		<div className="slotiva-done__row">
			<div>
				<div className="slotiva-done__label">{ label }</div>
				<div className={ `slotiva-done__value ${ valueClass || '' }` }>
					{ value }
				</div>
				{ extra && (
					<div className="slotiva-done__extra">{ extra }</div>
				) }
			</div>
			{ onChange && (
				<button
					type="button"
					className="slotiva-done__change"
					onClick={ onChange }
				>
					{ __( 'Change', 'mhm-slotiva' ) }
					<span className="screen-reader-text"> { label }</span>
				</button>
			) }
		</div>
	);
}

export default function Done( { wiz, status, goTo, listUrl } ) {
	const products = status.products || [];
	const wanted = wiz.mode === 'existing' ? wiz.existingId : wiz.createdId;
	const product =
		products.find( ( p ) => p.id === wanted ) ||
		products.find( ( p ) => p.status === 'publish' ) ||
		products[ 0 ] ||
		null;
	const checkout = status.checks?.checkout?.state === 'ok';
	const days = status.schedule?.days || [];
	// Nothing is bookable without an open day: the screen must not say "ready" (e.g. hours closed later, or skipped).
	const open = days.some( ( d ) => d.length > 0 );
	// Saved value first (a reload on this step has no wizard edits); the type is shown only when chosen this visit.
	// An existing bookable product keeps its own length, which may differ from the store default.
	const own =
		wiz.mode === 'existing' && product?.id === wanted
			? Number( product.durationMin )
			: 0;
	const minutes =
		own || Number( status.rules?.durationDefault ) || wiz.durationMin;
	const serviceValue = wiz.kindSet
		? sprintf(
				/* translators: 1: service type, 2: minutes. */
				__( '%1$s · %2$d min', 'mhm-slotiva' ),
				kindTitle( wiz.kind ),
				minutes
		  )
		: sprintf(
				/* translators: %d: minutes. */
				__( '%d min', 'mhm-slotiva' ),
				minutes
		  );
	const currency = status.currency || wiz.currency;
	const amount = product
		? parseMoney( product.price, {
				...currency,
				decimalSep: '.',
				thousandSep: '',
		  } )
		: null;

	let productValue = __( 'No bookable product yet', 'mhm-slotiva' );
	let productExtra = null;
	if ( product ) {
		const perHour = product.pricing === 'per_slot';
		const money = amount === null ? '' : formatMoney( amount, currency );
		if ( ! money ) {
			productValue = product.name;
		} else if ( perHour ) {
			productValue = sprintf(
				/* translators: 1: product name, 2: price. */
				__( '%1$s · %2$s / hour', 'mhm-slotiva' ),
				product.name,
				money
			);
		} else {
			productValue = sprintf(
				/* translators: 1: product name, 2: price. */
				__( '%1$s · %2$s', 'mhm-slotiva' ),
				product.name,
				money
			);
		}
		productExtra =
			product.maxSlots > 1
				? sprintf(
						/* translators: %d: maximum hours per order. */
						__( 'Up to %d hours per order', 'mhm-slotiva' ),
						product.maxSlots
				  )
				: __( '1 hour per order', 'mhm-slotiva' );
	}

	return (
		<div className="slotiva-done">
			<div className="slotiva-done__intro">
				<span className="slotiva-done__badge" aria-hidden="true">
					<CheckIcon size={ 30 } width={ 2.4 } />
				</span>
				<h1>
					{ open
						? __( 'You’re ready to take bookings.', 'mhm-slotiva' )
						: __(
								'Almost there: open at least one day in Working hours.',
								'mhm-slotiva'
						  ) }
				</h1>
				<p>
					{ __(
						'Book a test appointment yourself before your first customer: see the hour close and the emails arrive.',
						'mhm-slotiva'
					) }
				</p>
				<div className="slotiva-done__actions">
					{ product?.status === 'publish' && product.permalink && (
						<a
							className="slotiva-done__primary"
							href={ product.permalink }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Try a booking', 'mhm-slotiva' ) }
							<ExternalIcon />
							<NewTabNote />
						</a>
					) }
					{ product &&
						product.status !== 'publish' &&
						product.editUrl && (
							<a
								className="slotiva-done__primary"
								href={ product.editUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Edit the product', 'mhm-slotiva' ) }
								<ExternalIcon />
								<NewTabNote />
							</a>
						) }
					<a className="slotiva-done__secondary" href={ listUrl }>
						{ __( 'Go to appointments', 'mhm-slotiva' ) }
					</a>
				</div>
			</div>
			<section
				className="slotiva-done__summary"
				aria-label={ __( 'Setup summary', 'mhm-slotiva' ) }
			>
				<Row
					label={ __( 'Service', 'mhm-slotiva' ) }
					value={ serviceValue }
					onChange={ () => goTo( 1 ) }
				/>
				<Row
					label={ __( 'Working hours', 'mhm-slotiva' ) }
					value={
						hoursSummary( days, dayNames( 'short' ) ) ||
						__( 'Not set', 'mhm-slotiva' )
					}
					valueClass={ open ? '' : 'is-warn' }
					onChange={ () => goTo( 2 ) }
				/>
				<Row
					label={ __( 'Product', 'mhm-slotiva' ) }
					value={ productValue }
					extra={ productExtra }
					onChange={ () => goTo( 3 ) }
				/>
				<Row
					label={ __( 'Checkout page', 'mhm-slotiva' ) }
					value={
						checkout
							? __( 'Checkout block · ready', 'mhm-slotiva' )
							: __( 'Needs attention', 'mhm-slotiva' )
					}
					valueClass={ checkout ? 'is-ok' : 'is-warn' }
					onChange={ checkout ? undefined : () => goTo( 4 ) }
				/>
			</section>
		</div>
	);
}
