/**
 * Para biçimi: mağazanın WooCommerce alanlarıyla (`currency` bloğu, GET /setup). `Intl` para birimi biçimi
 * KULLANILMAZ; sunucudaki `wc_price` ile aynı görünümü üretir.
 */
export const DEFAULT_CURRENCY = {
	symbol: '$',
	decimals: 2,
	decimalSep: '.',
	thousandSep: ',',
	position: 'left',
};

/** Mirrors `SetupProduct::PRICE_MAX` (PHP): the highest price the wizard accepts. */
export const PRICE_MAX = 1000000000000;

const NBSP = String.fromCharCode( 160 );

/**
 * @param {number} amount   Tutar.
 * @param {Object} currency `{symbol, decimals, decimalSep, thousandSep, position}`.
 * @return {string} Örn. "1.234,50 ₺".
 */
export function formatMoney( amount, currency ) {
	const c = { ...DEFAULT_CURRENCY, ...( currency || {} ) };
	const [ whole, frac ] = Math.abs( Number( amount ) )
		.toFixed( c.decimals )
		.split( '.' );
	const grouped = whole.replace( /\B(?=(\d{3})+(?!\d))/g, c.thousandSep );
	const num =
		( Number( amount ) < 0 ? '-' : '' ) +
		grouped +
		( c.decimals > 0 ? c.decimalSep + frac : '' );
	switch ( c.position ) {
		case 'right':
			return num + c.symbol;
		case 'left_space':
			return c.symbol + NBSP + num;
		case 'right_space':
			return num + NBSP + c.symbol;
		default:
			return c.symbol + num;
	}
}

/**
 * Mağaza ondalık ayırıcısıyla yazılmış metni sayıya çevirir (binlik ayırıcı atılır, simge/boşluk yok sayılır).
 *
 * @param {string} text     Kullanıcı girdisi.
 * @param {Object} currency Para birimi bloğu.
 * @return {number|null} Negatif olmayan sayı; çözülemezse null.
 */
export function parseMoney( text, currency ) {
	const c = { ...DEFAULT_CURRENCY, ...( currency || {} ) };
	let t = String( text ).replace( /\s/g, '' );
	if ( c.symbol ) {
		t = t.split( c.symbol ).join( '' );
	}
	if ( c.thousandSep && c.thousandSep !== c.decimalSep ) {
		t = t.split( c.thousandSep ).join( '' );
	}
	if ( c.decimalSep !== '.' ) {
		t = t.split( c.decimalSep ).join( '.' );
	}
	return /^(\d+(\.\d*)?|\.\d+)$/.test( t ) ? Number( t ) : null;
}

/**
 * Sunucudan gelen "12.5" fiyatını girdi alanında gösterilecek metne çevirir (mağaza ondalık ayırıcısı).
 *
 * @param {string} price    Nokta ondalıklı fiyat.
 * @param {Object} currency Para birimi bloğu.
 * @return {string} Alan metni.
 */
export function priceToText( price, currency ) {
	const c = { ...DEFAULT_CURRENCY, ...( currency || {} ) };
	return String( price ?? '' ).replace( '.', c.decimalSep );
}
