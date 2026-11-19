/*
 * "Müşteri … görür" yan paneli (artboard Step2/Step3). Başlığı çağıran verir.
 */
import { useState } from '@wordpress/element';

import { EyeIcon } from './icons';

let seq = 0;

export default function PreviewPanel( { title, children } ) {
	const [ id ] = useState( () => `slotiva-preview-${ ++seq }` );
	return (
		<aside className="slotiva-preview" aria-labelledby={ id }>
			<h2 className="slotiva-preview__title" id={ id }>
				<EyeIcon />
				{ title }
			</h2>
			{ children }
		</aside>
	);
}
