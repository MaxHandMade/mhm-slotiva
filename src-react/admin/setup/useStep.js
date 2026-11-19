/**
 * Wizard step state kept in the URL (`?step=N`) with history.replaceState, so a reload returns to the same step
 * without growing the browser history. Steps: 0 = welcome, 1..5 = the five steps.
 */
import { useCallback, useState } from '@wordpress/element';

export const LAST_STEP = 5;

export function clampStep( value ) {
	const n = parseInt( value, 10 );
	if ( Number.isNaN( n ) ) {
		return 0;
	}
	return Math.min( LAST_STEP, Math.max( 0, n ) );
}

function stepFromUrl() {
	if ( typeof window === 'undefined' ) {
		return 0;
	}
	return clampStep(
		new URLSearchParams( window.location.search ).get( 'step' )
	);
}

export default function useStep() {
	const [ step, setStep ] = useState( stepFromUrl );

	const go = useCallback( ( next ) => {
		const target = clampStep( next );
		setStep( target );
		const url = new URL( window.location.href );
		url.searchParams.set( 'step', String( target ) );
		window.history.replaceState( window.history.state, '', url.toString() );
	}, [] );

	return [ step, go ];
}
