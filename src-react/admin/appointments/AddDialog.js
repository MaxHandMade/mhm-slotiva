/**
 * "+ Add appointment" (spec §8): service, a free slot from the admin grid, customer name/phone/e-mail, optional
 * confirmation e-mail. No order is created (`payment_state=manual`). ui-core has no modal → @wordpress/components Modal.
 */
import { Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Notice from '../../../vendor/mhm/ui-core/src-react/components/Notice';
import { createAppointment, dayLabels, errorMessage } from '../shared/api';
import SlotPicker from '../shared/SlotPicker';

export default function AddDialog( { products, today, onClose, onAdded } ) {
	const [ product, setProduct ] = useState(
		products.length ? String( products[ 0 ].id ) : ''
	);
	const [ slot, setSlot ] = useState( null );
	const [ name, setName ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ notify, setNotify ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ refreshKey, setRefreshKey ] = useState( 0 );

	const submit = ( event ) => {
		event.preventDefault();
		if ( busy ) {
			return;
		}
		if ( ! product ) {
			setError( __( 'Choose a service.', 'mhm-slotiva' ) );
			return;
		}
		if ( ! slot ) {
			setError( __( 'Pick a time.', 'mhm-slotiva' ) );
			return;
		}
		if ( name.trim() === '' ) {
			setError( __( 'Enter the customer name.', 'mhm-slotiva' ) );
			return;
		}
		setError( '' );
		setBusy( true );
		createAppointment( {
			productId: product,
			slot: slot.start,
			name,
			phone,
			email,
			notify,
		} ).then(
			( appt ) => {
				setBusy( false );
				onAdded( appt );
			},
			( e ) => {
				setBusy( false );
				setError( errorMessage( e ) );
				if ( e && e.code === 'slotiva_slot_taken' ) {
					setSlot( null );
					setRefreshKey( ( k ) => k + 1 );
				}
			}
		);
	};

	return (
		<Modal
			title={ __( 'Add appointment', 'mhm-slotiva' ) }
			onRequestClose={ onClose }
			className="slotiva-add-dialog"
			size="medium"
		>
			<form onSubmit={ submit } noValidate>
				{ error && <Notice tone="danger">{ error }</Notice> }
				<p className="slotiva-field">
					<label htmlFor="slotiva-add-product">
						{ __( 'Service', 'mhm-slotiva' ) }
					</label>
					<select
						id="slotiva-add-product"
						value={ product }
						onChange={ ( e ) => {
							setProduct( e.target.value );
							setSlot( null );
						} }
					>
						{ products.length === 0 && (
							<option value="">
								{ __( 'No bookable service', 'mhm-slotiva' ) }
							</option>
						) }
						{ products.map( ( p ) => (
							<option key={ p.id } value={ String( p.id ) }>
								{ p.name }
							</option>
						) ) }
					</select>
				</p>
				<SlotPicker
					product={ Number( product ) || 0 }
					today={ today }
					value={ slot }
					onChange={ setSlot }
					refreshKey={ refreshKey }
				/>
				<div className="slotiva-add-dialog__fields">
					<p className="slotiva-field">
						<label htmlFor="slotiva-add-name">
							{ __( 'Customer name', 'mhm-slotiva' ) }{ ' ' }
							<span aria-hidden="true">*</span>
						</label>
						<input
							id="slotiva-add-name"
							type="text"
							required
							aria-required="true"
							maxLength={ 190 }
							value={ name }
							onChange={ ( e ) => setName( e.target.value ) }
						/>
					</p>
					<p className="slotiva-field">
						<label htmlFor="slotiva-add-phone">
							{ __( 'Phone', 'mhm-slotiva' ) }
						</label>
						<input
							id="slotiva-add-phone"
							type="tel"
							maxLength={ 60 }
							value={ phone }
							onChange={ ( e ) => setPhone( e.target.value ) }
						/>
					</p>
					<p className="slotiva-field">
						<label htmlFor="slotiva-add-email">
							{ __( 'Email', 'mhm-slotiva' ) }
						</label>
						<input
							id="slotiva-add-email"
							type="email"
							maxLength={ 190 }
							value={ email }
							onChange={ ( e ) => setEmail( e.target.value ) }
						/>
					</p>
				</div>
				<p className="slotiva-check">
					<input
						id="slotiva-add-notify"
						type="checkbox"
						checked={ notify }
						onChange={ ( e ) => setNotify( e.target.checked ) }
					/>
					<label htmlFor="slotiva-add-notify">
						{ __(
							'Email the confirmation to the customer (when an email is given)',
							'mhm-slotiva'
						) }
					</label>
				</p>
				<div className="slotiva-add-dialog__footer">
					<span>
						{ slot
							? sprintf(
									/* translators: 1: day, 2: time. */
									__( 'Chosen: %1$s, %2$s', 'mhm-slotiva' ),
									dayLabels( slot.day ).long,
									slot.label
							  )
							: __( 'No time chosen yet.', 'mhm-slotiva' ) }
					</span>
					<button
						type="submit"
						className="button button-primary"
						aria-disabled={ busy ? 'true' : undefined }
					>
						{ busy
							? __( 'Adding…', 'mhm-slotiva' )
							: __( 'Add appointment', 'mhm-slotiva' ) }
					</button>
				</div>
			</form>
		</Modal>
	);
}
