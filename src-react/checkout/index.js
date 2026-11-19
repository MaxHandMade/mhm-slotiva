/**
 * Checkout slot picker entry: registers `slotiva/slot-picker` as a forced inner block of the Checkout fields block.
 * `attributes.lock.default.remove = true` makes WooCommerce render it on existing Checkout pages without editing
 * the page content.
 *
 * WooCommerce 11.2.1 renders forced blocks after all saved children of their area (wc-cart-checkout-base-frontend.js),
 * i.e. after the "Place order" button. The picker is therefore portalled into a host element placed directly before
 * the payment step (frame 1 order), so the visual order and the keyboard/DOM order stay the same. Without a payment
 * step or actions block yet, the host waits at the end of the form and moves once one mounts; outside a checkout
 * form it renders in place.
 */
import {
	createPortal,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { registerCheckoutBlock } from '@woocommerce/blocks-checkout';

import metadata from './block.json';
import Picker from './Picker';
import './style.scss';

const ANCHORS = [
	'#payment-method',
	'.wp-block-woocommerce-checkout-payment-block',
	'.wc-block-checkout__actions',
	'.wp-block-woocommerce-checkout-actions-block',
];

/**
 * Direct child of the checkout form that holds the payment step (or, failing that, the actions block).
 *
 * @param {Element} form Checkout form.
 * @return {Element|null} Child to insert before.
 */
function findAnchor( form ) {
	for ( const selector of ANCHORS ) {
		const hit = [ ...form.children ].find(
			( child ) =>
				! child.classList.contains( 'slotiva-picker-host' ) &&
				( child.matches( selector ) || child.querySelector( selector ) )
		);
		if ( hit ) {
			return hit;
		}
	}
	return null;
}

export function PlacedPicker( props ) {
	const markerRef = useRef( null );
	// undefined = not decided yet, null = render in place, Element = portal host.
	const [ host, setHost ] = useState( undefined );

	useLayoutEffect( () => {
		const form = markerRef.current?.closest( '.wc-block-checkout__form' );
		if ( ! form ) {
			setHost( null );
			return undefined;
		}
		const el = document.createElement( 'div' );
		el.className = 'slotiva-picker-host';
		// No payment step / actions yet: wait at the end of the form (where WooCommerce would draw the block).
		form.insertBefore( el, findAnchor( form ) );
		// The payment step / actions can mount after this block: keep the host right before them.
		const observer = new window.MutationObserver( () => {
			const next = findAnchor( form );
			if ( next && el.nextElementSibling !== next ) {
				form.insertBefore( el, next );
			}
		} );
		observer.observe( form, { childList: true } );
		setHost( el );
		return () => {
			observer.disconnect();
			el.remove();
		};
	}, [] );

	return (
		<>
			<span ref={ markerRef } hidden />
			{ host ? createPortal( <Picker { ...props } />, host ) : null }
			{ null === host ? <Picker { ...props } /> : null }
		</>
	);
}

registerCheckoutBlock( { metadata, component: PlacedPicker } );
