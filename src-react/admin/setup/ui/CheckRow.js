/*
 * Denetim satırı (artboard Step4 / Checklist). Durum asla yalnız renkle verilmez: görünür metin rozeti var.
 * Durum → kit ton adı: ok→success, warn→warning, todo→info, optional→neutral.
 */
import { __, _x } from '@wordpress/i18n';

import { AlertIcon, CheckIcon, DashIcon } from './icons';

const tones = {
	ok: 'success',
	warn: 'warning',
	todo: 'info',
	optional: 'neutral',
};

export default function CheckRow( { state, title, detail, children } ) {
	const labels = {
		ok: _x( 'Done', 'check state', 'mhm-slotiva' ),
		todo: __( 'To do', 'mhm-slotiva' ),
		warn: __( 'Check', 'mhm-slotiva' ),
		optional: __( 'Optional', 'mhm-slotiva' ),
	};
	const icons = {
		ok: <CheckIcon size={ 16 } />,
		warn: <AlertIcon />,
		todo: null,
		optional: <DashIcon />,
	};
	return (
		<li
			className={ `slotiva-check-row slotiva-check-row--${ tones[ state ] }` }
		>
			<span className="slotiva-check-row__icon" aria-hidden="true">
				{ icons[ state ] }
			</span>
			<div className="slotiva-check-row__body">
				<div className="slotiva-check-row__title">{ title }</div>
				{ detail && (
					<div className="slotiva-check-row__detail">{ detail }</div>
				) }
				{ children && (
					<div className="slotiva-check-row__actions">
						{ children }
					</div>
				) }
			</div>
			<span className="slotiva-check-row__state">
				{ labels[ state ] }
			</span>
		</li>
	);
}
