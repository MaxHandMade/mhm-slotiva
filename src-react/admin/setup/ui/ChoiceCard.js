/*
 * Büyük seçim kartı (artboard Step1): gerçek radyo + etiket; kartın tamamı tıklanır, klavye Tab/Boşluk/oklar.
 */
export default function ChoiceCard( {
	name,
	value,
	checked,
	onChange,
	icon,
	title,
	description,
} ) {
	const descId = description ? `${ name }-${ value }-desc` : undefined;
	const id = `${ name }-${ value }`;
	return (
		<label
			htmlFor={ id }
			className={ `slotiva-choice${ checked ? ' is-checked' : '' }` }
		>
			<input
				id={ id }
				type="radio"
				name={ name }
				value={ value }
				checked={ checked }
				aria-describedby={ descId }
				onChange={ () => onChange( value ) }
			/>
			{ icon && (
				<span className="slotiva-choice__icon" aria-hidden="true">
					{ icon }
				</span>
			) }
			<span className="slotiva-choice__title">{ title }</span>
			{ description && (
				// Hidden from the label name (it is read once, as the description); aria-describedby still reads it.
				<span
					className="slotiva-choice__desc"
					id={ descId }
					aria-hidden="true"
				>
					{ description }
				</span>
			) }
		</label>
	);
}
