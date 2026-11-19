/*
 * Hap seçenekler (artboard Step1 süre): radyo grubu; seçili çip renk + çerçeve + ✓ ile ayrışır.
 */
import { CheckIcon } from './icons';

export default function ChipGroup( { name, label, options, value, onChange } ) {
	return (
		<div className="slotiva-chips" role="radiogroup" aria-label={ label }>
			{ options.map( ( opt ) => {
				const checked = opt.value === value;
				return (
					<label
						key={ opt.value }
						htmlFor={ `${ name }-${ opt.value }` }
						className={ `slotiva-chip${
							checked ? ' is-checked' : ''
						}` }
					>
						<input
							id={ `${ name }-${ opt.value }` }
							type="radio"
							name={ name }
							value={ opt.value }
							checked={ checked }
							onChange={ () => onChange( opt.value ) }
						/>
						{ checked && <CheckIcon size={ 16 } width={ 2.4 } /> }
						{ opt.label }
					</label>
				);
			} ) }
		</div>
	);
}
