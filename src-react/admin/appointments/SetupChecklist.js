/**
 * Setup checklist card (1.0.2, artboard Checklist): shown above the tabs while setup is incomplete and not dismissed.
 * Progress = done required steps / REQUIRED.length (hours, checkout, bookable product).
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, errorMessage, pageData } from '../shared/api';
import { dayNames, hoursSummary } from '../shared/hours';

const REQUIRED = [ 'hours', 'checkout', 'product' ];

function Row( { number, state, title, detail, children } ) {
	const done = state === 'ok';
	const current = state === 'todo';
	const cls = [
		'slotiva-checklist__row',
		done && 'slotiva-checklist__row--done',
		current && 'slotiva-checklist__row--current',
	]
		.filter( Boolean )
		.join( ' ' );
	return (
		<li className={ cls }>
			<span className="slotiva-checklist__badge" aria-hidden="true">
				{ done ? (
					<svg
						width="15"
						height="15"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="3"
						strokeLinecap="round"
						strokeLinejoin="round"
					>
						<path d="M5 12.5l4.5 4.5L19 7.5" />
					</svg>
				) : (
					number
				) }
			</span>
			<div className="slotiva-checklist__body">
				<div className="slotiva-checklist__title">
					{ title }
					{ done && (
						<span className="screen-reader-text">
							{ ' ' }
							{ __( '(done)', 'mhm-slotiva' ) }
						</span>
					) }
				</div>
				{ detail && (
					<div className="slotiva-checklist__detail">{ detail }</div>
				) }
				{ children && (
					<div className="slotiva-checklist__links">{ children }</div>
				) }
			</div>
		</li>
	);
}

function productHelp() {
	return (
		<>
			{ __( 'None yet.', 'mhm-slotiva' ) }{ ' ' }
			{ __(
				'Open a product → Product data → Slotiva tab → "Bookable product". The selectable hours and pricing are set there too.',
				'mhm-slotiva'
			) }
		</>
	);
}

export default function SetupChecklist() {
	const data = pageData();
	const setup = data.setup;
	const links = data.setupLinks || {};
	const [ hidden, setHidden ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	if ( ! setup || setup.complete || setup.dismissed || hidden ) {
		return null;
	}

	const checks = setup.checks || {};
	const done = REQUIRED.filter( ( k ) => checks[ k ]?.state === 'ok' ).length;
	const left = REQUIRED.length - done;

	const hide = () => {
		setBusy( true );
		setError( '' );
		api.post( '/setup/state', { dismissed: true } ).then(
			() => setHidden( true ),
			( e ) => {
				setError( errorMessage( e ) );
				setBusy( false );
			}
		);
	};

	const hours = checks.hours || {};
	const checkout = checks.checkout || {};
	const product = checks.product || {};
	// Done hours row shows the week like the artboard ("Mon–Fri 09:00–17:00 · Sat–Sun closed").
	const week =
		hours.state === 'ok' && setup.schedule?.days?.length === 7
			? hoursSummary( setup.schedule.days, dayNames( 'short' ) )
			: '';

	return (
		<section
			className="slotiva-checklist"
			aria-labelledby="slotiva-checklist-title"
		>
			<div className="slotiva-checklist__head">
				<div className="slotiva-checklist__intro">
					<h2
						id="slotiva-checklist-title"
						className="slotiva-checklist__heading"
					>
						{ sprintf(
							/* translators: %d: number of setup steps left. */
							_n(
								'%d step left',
								'%d steps left',
								left,
								'mhm-slotiva'
							),
							left
						) }
					</h2>
					<p className="slotiva-checklist__sub">
						{ __(
							'This box disappears by itself once setup is complete.',
							'mhm-slotiva'
						) }
					</p>
				</div>
				<div className="slotiva-checklist__actions">
					<a
						className="slotiva-checklist__primary"
						href={ links.wizard || '#' }
					>
						{ __( 'Finish with the wizard', 'mhm-slotiva' ) }
					</a>
					<button
						type="button"
						className="slotiva-checklist__hide"
						onClick={ hide }
						disabled={ busy }
					>
						{ __( 'Hide', 'mhm-slotiva' ) }
					</button>
				</div>
			</div>
			{ error && (
				<p className="slotiva-checklist__error" role="alert">
					{ error }
				</p>
			) }
			<div
				className="slotiva-checklist__bar"
				role="progressbar"
				aria-valuemin={ 0 }
				aria-valuemax={ REQUIRED.length }
				aria-valuenow={ done }
				aria-label={ __( 'Setup progress', 'mhm-slotiva' ) }
			>
				<div
					className="slotiva-checklist__fill"
					style={ {
						width: `${ ( done / REQUIRED.length ) * 100 }%`,
					} }
				/>
			</div>
			<ol className="slotiva-checklist__list">
				<Row
					number={ 1 }
					state={ hours.state }
					title={ __( 'Working hours', 'mhm-slotiva' ) }
					detail={ week || hours.detail }
				>
					{ hours.state !== 'ok' && links.schedule && (
						<a href={ links.schedule }>
							{ __( 'Set working hours', 'mhm-slotiva' ) }
						</a>
					) }
				</Row>
				<Row
					number={ 2 }
					state={ checkout.state }
					title={ __( 'Checkout page', 'mhm-slotiva' ) }
					detail={ checkout.detail }
				>
					{ checkout.state !== 'ok' && checkout.action && (
						<a href={ checkout.action }>
							{ __( 'Fix the checkout page', 'mhm-slotiva' ) }
						</a>
					) }
				</Row>
				<Row
					number={ 3 }
					state={ product.state }
					title={ __( 'A bookable product', 'mhm-slotiva' ) }
					detail={
						product.state === 'ok' ? product.detail : productHelp()
					}
				>
					{ product.state !== 'ok' && (
						<>
							<a href={ links.product || '#' }>
								{ __(
									'Create with the wizard',
									'mhm-slotiva'
								) }
							</a>
							<a href={ links.products || '#' }>
								{ __( 'Go to products', 'mhm-slotiva' ) }
							</a>
						</>
					) }
				</Row>
			</ol>
		</section>
	);
}
