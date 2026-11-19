/**
 * Step 1 (artboard Step1): service type + appointment length. Continue saves `durationDefault` only; the type is
 * kept in the wizard state (suggestions, default product name) and not stored.
 */
import { __, sprintf } from '@wordpress/i18n';

import { api } from '../../shared/api';
import { STEP } from '../../schedule/validate';
import ChipGroup from '../ui/ChipGroup';
import ChoiceCard from '../ui/ChoiceCard';
import { InfoIcon, KindIcon } from '../ui/icons';

export const DURATIONS = [ 30, 45, 60, 90, 120 ];
// Mirrors `Rules::DURATION_MAX` (PHP).
const MAX_DURATION = 480;

/**
 * Length typed into "Other…".
 *
 * @param {string} text Raw input.
 * @return {number|null} Whole minutes (15-minute multiple, 15 to 480), else null.
 */
export function parseCustom( text ) {
	const t = String( text ).trim();
	if ( ! /^\d+$/.test( t ) ) {
		return null;
	}
	const n = Number( t );
	return n >= STEP && n <= MAX_DURATION && n % STEP === 0 ? n : null;
}

/**
 * Continue handler: validates, then PUT /rules {durationDefault}. Throws an Error with a user message.
 *
 * @param {Object} wiz Wizard state.
 */
export async function saveService( wiz ) {
	const minutes = wiz.durationCustom
		? parseCustom( wiz.customText )
		: wiz.durationMin;
	if ( minutes === null ) {
		throw new Error(
			__(
				'The appointment length must be a multiple of 15 minutes (15 to 480).',
				'mhm-slotiva'
			)
		);
	}
	await api.put( '/rules', { durationDefault: minutes } );
	// Continue counts as choosing the service type, so the ready screen shows the default one too.
	return { kindSet: true };
}

/**
 * @param {string} kind Service type key.
 * @return {string} Display title.
 */
export function kindTitle( kind ) {
	const titles = {
		consulting: __( 'Consulting', 'mhm-slotiva' ),
		lesson: __( 'Lesson / training', 'mhm-slotiva' ),
		session: __( 'Appointment / session', 'mhm-slotiva' ),
		other: __( 'Another service', 'mhm-slotiva' ),
	};
	return titles[ kind ] || titles.other;
}

export default function Service( { wiz, update } ) {
	const kinds = [
		[
			'consulting',
			__( 'Consulting', 'mhm-slotiva' ),
			__( 'Meetings, coaching, online sessions', 'mhm-slotiva' ),
		],
		[
			'lesson',
			__( 'Lesson / training', 'mhm-slotiva' ),
			__( 'Private lessons, courses, workshops', 'mhm-slotiva' ),
		],
		[
			'session',
			__( 'Appointment / session', 'mhm-slotiva' ),
			__( 'Clinic, therapy, beauty', 'mhm-slotiva' ),
		],
		[
			'other',
			__( 'Another service', 'mhm-slotiva' ),
			__( 'Anything sold by the hour', 'mhm-slotiva' ),
		],
	];
	const options = [
		...DURATIONS.map( ( m ) => ( {
			value: String( m ),
			label: sprintf(
				/* translators: %d: number of minutes. */
				__( '%d min', 'mhm-slotiva' ),
				m
			),
		} ) ),
		{ value: 'custom', label: __( 'Other…', 'mhm-slotiva' ) },
	];
	const selected = wiz.durationCustom ? 'custom' : String( wiz.durationMin );
	const question = __( 'How long does one appointment take?', 'mhm-slotiva' );

	const pick = ( value ) => {
		if ( value === 'custom' ) {
			update( {
				durationCustom: true,
				customText: wiz.customText || String( wiz.durationMin ),
			} );
		} else {
			update( { durationCustom: false, durationMin: Number( value ) } );
		}
	};
	const typed = ( text ) => {
		const n = parseCustom( text );
		update( {
			customText: text,
			...( n === null ? {} : { durationMin: n } ),
		} );
	};

	return (
		<div className="slotiva-step">
			<div>
				<h1>{ __( 'What do you sell?', 'mhm-slotiva' ) }</h1>
				<p className="slotiva-step__lead">
					{ __(
						'We will suggest lengths and wording based on your choice. All of it can be changed.',
						'mhm-slotiva'
					) }
				</p>
			</div>

			<fieldset className="slotiva-step__fieldset">
				<legend className="screen-reader-text">
					{ __( 'Service type', 'mhm-slotiva' ) }
				</legend>
				<div className="slotiva-kinds">
					{ kinds.map( ( [ value, title, description ] ) => (
						<ChoiceCard
							key={ value }
							name="slotiva-kind"
							value={ value }
							checked={ wiz.kind === value }
							onChange={ ( v ) =>
								update( { kind: v, kindSet: true } )
							}
							icon={ <KindIcon kind={ value } /> }
							title={ title }
							description={ description }
						/>
					) ) }
				</div>
			</fieldset>

			<div className="slotiva-step__fieldset">
				<h2 className="slotiva-step__legend">{ question }</h2>
				<ChipGroup
					name="slotiva-duration"
					label={ question }
					options={ options }
					value={ selected }
					onChange={ pick }
				/>
				{ wiz.durationCustom && (
					<label
						htmlFor="slotiva-duration-minutes"
						className="slotiva-step__custom"
					>
						<span>{ __( 'Minutes', 'mhm-slotiva' ) }</span>
						<input
							id="slotiva-duration-minutes"
							type="number"
							inputMode="numeric"
							min={ STEP }
							max={ MAX_DURATION }
							step={ STEP }
							value={ wiz.customText }
							onChange={ ( e ) => typed( e.target.value ) }
						/>
					</label>
				) }
				<p className="slotiva-step__note">
					<InfoIcon />
					<span>
						{ __(
							'This is the length of one hour a customer picks. For customers who want 2–3 hours, step 3 lets you turn on “more than one hour”.',
							'mhm-slotiva'
						) }
					</span>
				</p>
			</div>
		</div>
	);
}
