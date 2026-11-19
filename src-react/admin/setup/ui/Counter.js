/*
 * Sayaç (artboard Step3): − / + düğmeleri, sınırda `disabled`. `onChange` her zaman min–max içinde bir değer alır.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

let seq = 0;

export default function Counter( {
	label,
	value,
	min,
	max,
	onChange,
	format = ( v ) => String( v ),
} ) {
	const [ labelId ] = useState( () => `slotiva-counter-${ ++seq }` );
	const clamp = ( v ) => Math.min( max, Math.max( min, v ) );
	return (
		<div className="slotiva-counter">
			<span id={ labelId } className="slotiva-counter__label">
				{ label }
			</span>
			<div
				className="slotiva-counter__ctl"
				role="group"
				aria-labelledby={ labelId }
			>
				<button
					type="button"
					aria-label={ __( 'Decrease', 'mhm-slotiva' ) }
					disabled={ value <= min }
					onClick={ () => onChange( clamp( value - 1 ) ) }
				>
					−
				</button>
				<span className="slotiva-counter__value" aria-live="polite">
					{ format( value ) }
				</span>
				<button
					type="button"
					aria-label={ __( 'Increase', 'mhm-slotiva' ) }
					disabled={ value >= max }
					onClick={ () => onChange( clamp( value + 1 ) ) }
				>
					+
				</button>
			</div>
		</div>
	);
}
