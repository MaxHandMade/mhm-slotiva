/**
 * wp-scripts ESLint yapılandırması + WooCommerce dış paketleri.
 * `@woocommerce/*` paketleri npm'de kurulu değildir: çalışma anında WooCommerce'in kayıtlı betiklerinden (window.wc.*)
 * gelir (webpack.config.js). Bu yüzden içe aktarma çözücüsü onları çekirdek modül sayar.
 */
const base = require( '@wordpress/scripts/config/.eslintrc.js' );

module.exports = {
	...base,
	settings: {
		...( base.settings || {} ),
		'import/core-modules': [ '@woocommerce/blocks-checkout', '@woocommerce/settings' ],
	},
};
