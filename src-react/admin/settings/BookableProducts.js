/**
 * "Bookable products" card at the top of Settings (1.0.2, artboard Settings): one row per bookable product with its
 * length, hours per order and pricing, a link to the product's Slotiva tab, the setup wizard link and a new-product link.
 * The data is `window.slotivaAdmin.bookable` (the same summary the wizard uses); no request is made here.
 */
import { __, sprintf } from '@wordpress/i18n';

import { pageData } from '../shared/api';

// `#slotiva_product_data` is the id of the Slotiva panel in WC's product data box (ProductTab::render_panel).
const withTab = ( url ) => {
	const base = String( url || '' );
	return base.includes( '#' ) ? base : base + '#slotiva_product_data';
};

function perOrder( p ) {
	const min = Number( p.minSlots ) || 1;
	const max = Number( p.maxSlots ) || 1;
	if ( max > 1 ) {
		return sprintf(
			/* translators: 1: minimum hours, 2: maximum hours per order. */
			__( '%1$d–%2$d hours', 'mhm-slotiva' ),
			min,
			max
		);
	}
	return __( '1 hour', 'mhm-slotiva' );
}

export default function BookableProducts() {
	const data = pageData();
	const products = Array.isArray( data.bookable ) ? data.bookable : [];
	const links = data.setupLinks || {};
	const newUrl = links.product || links.wizard || '';

	return (
		<section
			className="slotiva-products"
			aria-labelledby="slotiva-products-title"
		>
			<div className="slotiva-products__head">
				<h2 id="slotiva-products-title">
					{ __( 'Bookable products', 'mhm-slotiva' ) }
				</h2>
				<a
					className="slotiva-products__wizard"
					href={ links.wizard || '' }
				>
					<svg
						width="15"
						height="15"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="2"
						strokeLinecap="round"
						strokeLinejoin="round"
						aria-hidden="true"
					>
						<path d="M4 20l9.5-9.5M14 4l1 3 3 1-3 1-1 3-1-3-3-1 3-1z" />
					</svg>
					{ __( 'Open the setup wizard', 'mhm-slotiva' ) }
				</a>
			</div>
			<div className="slotiva-products__info">
				<svg
					width="18"
					height="18"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2"
					strokeLinecap="round"
					strokeLinejoin="round"
					aria-hidden="true"
				>
					<circle cx="12" cy="12" r="9" />
					<path d="M12 11v5M12 8h.01" />
				</svg>
				<span>
					<strong>
						{ __(
							'Length, pricing and how many hours can be booked in one order belong to the product.',
							'mhm-slotiva'
						) }
					</strong>{ ' ' }
					{ __(
						'Set them on each product’s Product data → Slotiva tab. General settings are below.',
						'mhm-slotiva'
					) }
				</span>
			</div>
			{ products.length === 0 ? (
				<div className="slotiva-products__empty">
					<p>
						<strong>
							{ __( 'No bookable products yet', 'mhm-slotiva' ) }
						</strong>
					</p>
					<a href={ newUrl }>
						{ __(
							'Create your first bookable product',
							'mhm-slotiva'
						) }
					</a>
				</div>
			) : (
				<>
					{ /* Scrolls sideways on narrow screens: focusable so the keyboard can scroll it. */ }
					<div
						className="slotiva-products__scroll"
						role="region"
						tabIndex={ 0 }
						aria-label={ __(
							'Bookable products table',
							'mhm-slotiva'
						) }
					>
						<table className="slotiva-products__table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Product', 'mhm-slotiva' ) }
									</th>
									<th scope="col">
										{ __( 'Length', 'mhm-slotiva' ) }
									</th>
									<th scope="col">
										{ __( 'Per order', 'mhm-slotiva' ) }
									</th>
									<th scope="col">
										{ __( 'Pricing', 'mhm-slotiva' ) }
									</th>
									<th scope="col">
										<span className="screen-reader-text">
											{ __( 'Action', 'mhm-slotiva' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ products.map( ( p ) => (
									<tr key={ p.id }>
										<td className="slotiva-products__name">
											{ p.name }
										</td>
										<td>
											{ sprintf(
												/* translators: %d: minutes. */
												__( '%d min', 'mhm-slotiva' ),
												Number( p.durationMin )
											) }
										</td>
										<td>{ perOrder( p ) }</td>
										<td>
											{ p.pricing === 'fixed'
												? __( 'Fixed', 'mhm-slotiva' )
												: __(
														'Per slot',
														'mhm-slotiva'
												  ) }
										</td>
										<td className="slotiva-products__action">
											<a href={ withTab( p.editUrl ) }>
												{ __(
													'Open Slotiva tab →',
													'mhm-slotiva'
												) }
											</a>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					<div className="slotiva-products__foot">
						<a href={ newUrl }>
							{ __( '+ New bookable product', 'mhm-slotiva' ) }
						</a>
					</div>
				</>
			) }
		</section>
	);
}
