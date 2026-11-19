/**
 * Setup wizard frame (artboard Step1): header (logo + step indicator + "Finish later") and footer (Back / Continue)
 * around the step content. Step 0 is the welcome screen: no step is highlighted.
 */
import { __, _x, sprintf } from '@wordpress/i18n';

import { CheckIcon } from './ui/icons';
import Stepper from './ui/Stepper';

const names = () => [
	__( 'Service', 'mhm-slotiva' ),
	__( 'Hours', 'mhm-slotiva' ),
	__( 'Product', 'mhm-slotiva' ),
	__( 'Checkout', 'mhm-slotiva' ),
	_x( 'Done', 'setup step', 'mhm-slotiva' ),
];

function Logo() {
	return (
		<svg
			width="24"
			height="24"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
		>
			<rect x="3" y="4.5" width="18" height="16" rx="3" />
			<path d="M3 9.5h18M8 2.5v4M16 2.5v4" />
			<path d="M8.5 14.5l2.2 2.2 4.8-4.8" />
		</svg>
	);
}

// Default footer: Back (hidden on the welcome screen) and Continue.
export function StepFooter( {
	onBack,
	onNext,
	canBack = true,
	canNext = true,
	busy = false,
	extra = null,
	nextLabel = '',
} ) {
	return (
		<>
			{ canBack ? (
				<button
					type="button"
					className="slotiva-setup__back"
					onClick={ onBack }
				>
					{ '← ' }
					{ __( 'Back', 'mhm-slotiva' ) }
				</button>
			) : (
				<span />
			) }
			<div className="slotiva-setup__actions">
				{ extra }
				{ canNext && (
					<button
						type="button"
						className="slotiva-setup__next"
						disabled={ busy }
						onClick={ onNext }
					>
						{ nextLabel || __( 'Continue', 'mhm-slotiva' ) }
					</button>
				) }
			</div>
		</>
	);
}

export default function SetupFrame( {
	step,
	total = 5,
	onSkipHref,
	footer,
	doneLabel = '',
	children,
} ) {
	// Step 0 (artboard Main): no step indicator and no footer band; the welcome buttons are the actions.
	const welcome = step === 0;
	// Step 5 (artboard Step5): the step row is replaced by a completion note; the footer is informational.
	const done = step === total;
	return (
		<div className="slotiva-setup">
			<header className="slotiva-setup__header">
				<div className="slotiva-setup__brand">
					<Logo />
					<span>{ __( 'Slotiva', 'mhm-slotiva' ) }</span>
					{ welcome && (
						<small className="slotiva-setup__label">
							{ __( 'Setup', 'mhm-slotiva' ) }
						</small>
					) }
				</div>
				{ done && doneLabel && (
					<div className="slotiva-setup__complete">
						<CheckIcon size={ 16 } />
						{ doneLabel }
					</div>
				) }
				{ ! welcome && ! done && (
					<Stepper
						steps={ names().slice( 0, total ) }
						current={ step }
					/>
				) }
				<div className="slotiva-setup__skip">
					<a href={ onSkipHref }>
						{ welcome
							? __(
									'Skip for now, I’ll finish later',
									'mhm-slotiva'
							  )
							: __( 'Finish later', 'mhm-slotiva' ) }
					</a>
				</div>
			</header>
			{ /* WordPress already renders #wpbody as the main landmark: a second <main> would nest. */ }
			<div className="slotiva-setup__main">
				{ step > 0 && ! done && (
					<p className="slotiva-setup__progress">
						{ sprintf(
							/* translators: 1: current step number, 2: total number of steps. */
							__( 'Step %1$d / %2$d', 'mhm-slotiva' ),
							step,
							total
						) }
					</p>
				) }
				{ children }
			</div>
			{ ! welcome && (
				<footer
					className={ `slotiva-setup__footer${
						done ? ' slotiva-setup__footer--done' : ''
					}` }
				>
					{ footer }
				</footer>
			) }
		</div>
	);
}
