/**
 * Step 0 (artboard Main): welcome. Has its own buttons; the frame footer is empty on this step.
 */
import { __ } from '@wordpress/i18n';

import { ArrowRightIcon } from '../ui/icons';

export default function Welcome( { onStart, skipHref } ) {
	const items = [
		[
			__( 'What do you sell?', 'mhm-slotiva' ),
			__(
				'Service type and the length of one appointment',
				'mhm-slotiva'
			),
		],
		[
			__( 'When do you work?', 'mhm-slotiva' ),
			__( 'Open days and time ranges', 'mhm-slotiva' ),
		],
		[
			__( 'Which product?', 'mhm-slotiva' ),
			__(
				'Price and how many hours can be booked at once',
				'mhm-slotiva'
			),
		],
		[
			__( 'Checkout page', 'mhm-slotiva' ),
			__(
				'We check the page where the time picker appears',
				'mhm-slotiva'
			),
		],
		[
			__( 'Ready', 'mhm-slotiva' ),
			__( 'Product page and a test booking', 'mhm-slotiva' ),
		],
	];
	return (
		<div className="slotiva-welcome">
			<div className="slotiva-welcome__intro">
				<span className="slotiva-welcome__badge">
					{ __( '5 steps · about 3 minutes', 'mhm-slotiva' ) }
				</span>
				<h1>
					{ __(
						'A store ready for bookings, in a few questions.',
						'mhm-slotiva'
					) }
				</h1>
				<p>
					{ __(
						'We will ask what you sell and when you work, set up your product together and check your checkout page. Every answer can be changed later from the normal settings screens.',
						'mhm-slotiva'
					) }
				</p>
				<div className="slotiva-welcome__actions">
					<button
						type="button"
						className="slotiva-setup__next"
						onClick={ onStart }
					>
						{ __( 'Let’s start', 'mhm-slotiva' ) }
						<ArrowRightIcon />
					</button>
					<a href={ skipHref }>
						{ __( 'I’ll set it up myself', 'mhm-slotiva' ) }
					</a>
				</div>
			</div>
			<ol className="slotiva-welcome__list">
				{ items.map( ( [ title, text ], i ) => (
					<li key={ title }>
						<span aria-hidden="true">{ i + 1 }</span>
						<div>
							<strong>{ title }</strong>
							<small>{ text }</small>
						</div>
					</li>
				) ) }
			</ol>
		</div>
	);
}
