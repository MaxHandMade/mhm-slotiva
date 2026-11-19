/**
 * Settings (spec §8; no canvas frame, §14: ui-core PageHeader + form in the frames' language): the general
 * cancellation/change policy text, personal-data retention in months, "delete data on uninstall" (off by default) and
 * a link to the WooCommerce email settings, where the appointment emails live (§7).
 *
 * All three values are fields of `slotiva_rules` (GET|PUT /admin/rules); only these three are sent, so the booking
 * rules saved on the Working hours screen are kept. `uninstall.php` reads `deleteDataOnUninstall` from the same option.
 * The retention clean-up itself is Task 16; this screen only stores the setting.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Button from '../../../vendor/mhm/ui-core/src-react/components/Button';
import Notice from '../../../vendor/mhm/ui-core/src-react/components/Notice';
import PageHeader from '../../../vendor/mhm/ui-core/src-react/components/PageHeader';
import Widget from '../../../vendor/mhm/ui-core/src-react/components/Widget';

import BookableProducts from './BookableProducts';
import { errorMessage, getRules, pageData, saveRules } from '../shared/api';

const POLICY_MAX = 5000;

export default function App() {
	const data = pageData();
	const [ values, setValues ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		let alive = true;
		getRules().then(
			( r ) =>
				alive &&
				setValues( {
					policyText: r.policyText || '',
					retentionMonths: r.retentionMonths,
					deleteDataOnUninstall: !! r.deleteDataOnUninstall,
				} ),
			( e ) => alive && setLoadError( errorMessage( e ) )
		);
		return () => {
			alive = false;
		};
	}, [] );

	const set = ( key, value ) =>
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const save = () => {
		const months = String( values.retentionMonths ).trim();
		if (
			! /^\d+$/.test( months ) ||
			Number( months ) < 1 ||
			Number( months ) > 120
		) {
			setNotice( {
				tone: 'danger',
				text: __(
					'Keep personal details for 1 to 120 whole months.',
					'mhm-slotiva'
				),
			} );
			return;
		}
		setSaving( true );
		saveRules( {
			policyText: values.policyText,
			retentionMonths: Number( months ),
			deleteDataOnUninstall: !! values.deleteDataOnUninstall,
		} )
			.then(
				( r ) => {
					setValues( {
						policyText: r.policyText || '',
						retentionMonths: r.retentionMonths,
						deleteDataOnUninstall: !! r.deleteDataOnUninstall,
					} );
					setNotice( {
						tone: 'success',
						text: __( 'Settings saved.', 'mhm-slotiva' ),
					} );
				},
				( e ) =>
					setNotice( { tone: 'danger', text: errorMessage( e ) } )
			)
			.finally( () => setSaving( false ) );
	};

	const header = (
		<PageHeader level={ 1 } title={ __( 'Settings', 'mhm-slotiva' ) } />
	);

	if ( loadError ) {
		return (
			<div className="slotiva-settings">
				{ header }
				<Notice tone="danger">{ loadError }</Notice>
			</div>
		);
	}
	if ( ! values ) {
		return (
			<div className="slotiva-settings">
				{ header }
				<p className="slotiva-muted">
					{ __( 'Loading…', 'mhm-slotiva' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="slotiva-settings">
			{ header }
			<BookableProducts />
			{ notice && (
				<Notice
					tone={ notice.tone }
					onDismiss={ () => setNotice( null ) }
					dismissLabel={ __( 'Dismiss this notice.', 'mhm-slotiva' ) }
				>
					{ notice.text }
				</Notice>
			) }

			<Widget level={ 2 } title={ __( 'Policy', 'mhm-slotiva' ) }>
				<p className="slotiva-field">
					<label htmlFor="slotiva-policy">
						{ __(
							'Cancellation and change policy',
							'mhm-slotiva'
						) }
					</label>
					<textarea
						id="slotiva-policy"
						rows="5"
						maxLength={ POLICY_MAX }
						aria-describedby="slotiva-policy-help"
						value={ values.policyText }
						onChange={ ( e ) =>
							set( 'policyText', e.target.value )
						}
					/>
					<span
						id="slotiva-policy-help"
						className="slotiva-rule__help"
					>
						{ __(
							'Shown to the customer under the time picker and with the appointment on the order page. Leave it empty to show nothing. A product can replace it with its own text.',
							'mhm-slotiva'
						) }
					</span>
				</p>
			</Widget>

			<Widget level={ 2 } title={ __( 'Personal data', 'mhm-slotiva' ) }>
				<div className="slotiva-rule">
					<label
						className="slotiva-rule__label"
						htmlFor="slotiva-retention"
					>
						{ __( 'Keep personal details for', 'mhm-slotiva' ) }
					</label>
					<div className="slotiva-rule__control">
						<input
							id="slotiva-retention"
							type="number"
							inputMode="numeric"
							min="1"
							max="120"
							aria-describedby="slotiva-retention-help"
							value={ values.retentionMonths }
							onChange={ ( e ) =>
								set( 'retentionMonths', e.target.value )
							}
						/>
						<span className="slotiva-muted">
							{ __( 'months', 'mhm-slotiva' ) }
						</span>
					</div>
					<p
						id="slotiva-retention-help"
						className="slotiva-rule__help"
					>
						{ __(
							'After this, the name, email and phone of past appointments are anonymised. The appointment record (date, service, status) stays for statistics.',
							'mhm-slotiva'
						) }
					</p>
				</div>
				<p className="slotiva-check">
					<input
						id="slotiva-wipe"
						type="checkbox"
						checked={ values.deleteDataOnUninstall }
						aria-describedby="slotiva-wipe-help"
						onChange={ ( e ) =>
							set( 'deleteDataOnUninstall', e.target.checked )
						}
					/>
					<label htmlFor="slotiva-wipe">
						{ __(
							'Delete all Slotiva data when the plugin is deleted',
							'mhm-slotiva'
						) }
					</label>
				</p>
				<p id="slotiva-wipe-help" className="slotiva-rule__help">
					{ __(
						'Off by default: deleting the plugin keeps appointments, working hours and settings. When on, deleting the plugin removes them for good. WooCommerce orders are never touched.',
						'mhm-slotiva'
					) }
				</p>
			</Widget>

			<Widget level={ 2 } title={ __( 'Emails', 'mhm-slotiva' ) }>
				<p>
					{ __(
						'Appointment emails are WooCommerce emails: turn each one on or off and edit its subject and text in the WooCommerce email settings.',
						'mhm-slotiva'
					) }
				</p>
				<Button
					variant="secondary"
					href={ data.emailSettingsUrl || '' }
				>
					{ __( 'Open WooCommerce email settings', 'mhm-slotiva' ) }
				</Button>
			</Widget>

			<div className="slotiva-schedule__footer">
				<Button
					variant="primary"
					onClick={ save }
					disabled={ saving }
					aria-busy={ saving ? 'true' : undefined }
				>
					{ __( 'Save settings', 'mhm-slotiva' ) }
				</Button>
			</div>
		</div>
	);
}
