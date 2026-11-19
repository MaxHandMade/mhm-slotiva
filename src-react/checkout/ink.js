/**
 * Temanın paleti yokken seçili gün/saat okunabilsin diye gerçek yazı rengini ve sayfa zeminini okur.
 * `currentcolor` aynı öğenin `color`ına çözüldüğü için arka plan olarak kullanılamaz; bu değerler
 * `--slotiva-ink` / `--slotiva-paper` olarak style.scss'teki yedek zincire girer.
 */

const WHITE = 'rgb(255, 255, 255)';
const BLACK = 'rgb(0, 0, 0)';

function alphaOf( color ) {
	if ( ! color || 'transparent' === color ) {
		return 0;
	}
	const m = color.match( /^rgba?\(([^)]+)\)$/i );
	if ( m ) {
		const parts = m[ 1 ].split( /[\s,/]+/ ).filter( Boolean );
		return parts.length > 3 ? parseFloat( parts[ 3 ] ) : 1;
	}
	return 1;
}

function rgbOf( color ) {
	const hex = ( color || '' ).trim().match( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i );
	if ( hex ) {
		const h =
			3 === hex[ 1 ].length
				? hex[ 1 ].replace( /./g, ( c ) => c + c )
				: hex[ 1 ];
		return [ 0, 2, 4 ].map( ( i ) => parseInt( h.slice( i, i + 2 ), 16 ) );
	}
	const m = ( color || '' ).match( /^rgba?\(([^)]+)\)$/i );
	if ( ! m ) {
		return null;
	}
	const [ r, g, b ] = m[ 1 ]
		.split( /[\s,/]+/ )
		.filter( Boolean )
		.slice( 0, 3 )
		.map( Number );
	return [ r, g, b ].some( ( n ) => Number.isNaN( n ) ) ? null : [ r, g, b ];
}

function luminance( [ r, g, b ] ) {
	const lin = ( v ) => {
		const c = v / 255;
		return c <= 0.03928
			? c / 12.92
			: Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	};
	return 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b );
}

/**
 * WCAG kontrast oranı; renkler çözülemezse (ör. oklch) null.
 *
 * @param {string} a Renk.
 * @param {string} b Renk.
 * @return {number|null} Oran (1..21).
 */
export function contrastRatio( a, b ) {
	const ra = rgbOf( a );
	const rb = rgbOf( b );
	if ( ! ra || ! rb ) {
		return null;
	}
	const [ hi, lo ] = [ luminance( ra ), luminance( rb ) ].sort(
		( x, y ) => y - x
	);
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

/**
 * @param {Element} el Seçici kök öğesi.
 * @return {{ink: string, paper: string}} Yazı rengi ve en yakın opak ata arka planı (yoksa beyaz).
 */
export function inkAndPaper( el ) {
	const ink = window.getComputedStyle( el ).color;
	let paper = WHITE;
	for ( let node = el.parentElement; node; node = node.parentElement ) {
		const bg = window.getComputedStyle( node ).backgroundColor;
		if ( alphaOf( bg ) > 0.5 ) {
			paper = bg;
			break;
		}
	}
	// Zemin yazıyla yeterince ayrışmıyorsa (şeffaf katmanlar, görsel zemin) yazıya göre siyah/beyaz seç.
	const ratio = contrastRatio( ink, paper );
	const inkRgb = rgbOf( ink );
	if ( null !== ratio && ratio < 4.5 && inkRgb ) {
		paper = luminance( inkRgb ) > 0.5 ? BLACK : WHITE;
	}
	return { ink, paper };
}
