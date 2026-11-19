/*
 * Bölümlü seçici (artboard Step3 "Ürün"): radyo grubu, görünümü düğme şeridi.
 */
import { useState } from '@wordpress/element';

let seq = 0;

export default function Segmented( { options, value, onChange, label } ) {
	// Aynı sayfada birden çok grup olabilir; radyo `name`i grup başına tekil olmalı.
	const [ name ] = useState( () => `slotiva-seg-${ ++seq }` );
	return (
		<div
			className="slotiva-segmented"
			role="radiogroup"
			aria-label={ label }
		>
			{ options.map( ( opt ) => (
				<label
					key={ opt.value }
					htmlFor={ `${ name }-${ opt.value }` }
					className={ `slotiva-segmented__opt${
						opt.value === value ? ' is-checked' : ''
					}` }
				>
					<input
						id={ `${ name }-${ opt.value }` }
						type="radio"
						name={ name }
						value={ opt.value }
						checked={ opt.value === value }
						onChange={ () => onChange( opt.value ) }
					/>
					{ opt.label }
				</label>
			) ) }
		</div>
	);
}
