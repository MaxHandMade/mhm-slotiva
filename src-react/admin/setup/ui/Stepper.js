/*
 * Adım göstergesi (artboard Step2 başlığı): tamamlanan = yeşil onay dairesi, şimdiki = dolu mavi, gelecek = çerçeveli.
 * `current` 1 tabanlıdır; 0 hiçbir adımı işaretlemez (karşılama ekranı).
 */
import { __, sprintf } from '@wordpress/i18n';

import { CheckIcon } from './icons';

export default function Stepper( { steps, current, label } ) {
	const total = steps.length;
	return (
		<div className="slotiva-stepper">
			<nav
				className="slotiva-stepper__nav"
				aria-label={ label || __( 'Setup steps', 'mhm-slotiva' ) }
			>
				<ol>
					{ steps.map( ( name, i ) => {
						const n = i + 1;
						let state = 'upcoming';
						if ( n === current ) {
							state = 'current';
						} else if ( n < current ) {
							state = 'done';
						}
						return (
							<li
								key={ n }
								className={ `slotiva-stepper__step is-${ state }` }
								aria-current={
									state === 'current' ? 'step' : undefined
								}
							>
								<span
									className="slotiva-stepper__num"
									aria-hidden="true"
								>
									{ state === 'done' ? <CheckIcon /> : n }
								</span>
								<span>{ name }</span>
								{ state === 'done' && (
									<span className="screen-reader-text">
										{ __( '(completed)', 'mhm-slotiva' ) }
									</span>
								) }
							</li>
						);
					} ) }
				</ol>
			</nav>
			{ current > 0 && current <= total && (
				<div className="slotiva-stepper__compact">
					<div
						className="slotiva-stepper__bar"
						role="progressbar"
						aria-valuemin={ 1 }
						aria-valuemax={ total }
						aria-valuenow={ current }
						aria-label={ sprintf(
							/* translators: 1: current step number, 2: total number of steps. */
							__( 'Step %1$d / %2$d', 'mhm-slotiva' ),
							current,
							total
						) }
					>
						<span
							style={ {
								width: `${ ( current / total ) * 100 }%`,
							} }
						/>
					</div>
					<span className="slotiva-stepper__text">
						{ `${ current } / ${ total } · ${
							steps[ current - 1 ]
						}` }
					</span>
				</div>
			) }
		</div>
	);
}
