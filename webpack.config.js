/**
 * wp-scripts varsayılan yapılandırması; giriş noktaları src-react/ altındadır.
 * Çıktı build/ dizinine gider (git'te değil, ZIP'e girer).
 *
 * WooCommerce paketleri pakete gömülmez: `@woocommerce/*` istekleri WooCommerce'in kayıtlı betiklerine (window.wc.*)
 * dış bağımlılık olarak çevrilir ve asset.php bağımlılık listesine tanıtıcı adıyla yazılır.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const WC_EXTERNALS = {
	'@woocommerce/blocks-checkout': [ 'wc', 'blocksCheckout', 'wc-blocks-checkout' ],
	'@woocommerce/settings': [ 'wc', 'wcSettings', 'wc-settings' ],
};

module.exports = {
	...defaultConfig,
	entry: {
		// Yönetim sayfaları: build/admin/<sayfa>.js + .asset.php (ui-core yükleyicisinin varsayılan düzeni).
		'admin/appointments': path.resolve( __dirname, 'src-react/admin/appointments/index.js' ),
		'admin/detail': path.resolve( __dirname, 'src-react/admin/detail/index.js' ),
		'admin/schedule': path.resolve( __dirname, 'src-react/admin/schedule/index.js' ),
		'admin/settings': path.resolve( __dirname, 'src-react/admin/settings/index.js' ),
		'admin/setup': path.resolve( __dirname, 'src-react/admin/setup/index.js' ),
		'checkout/index': path.resolve( __dirname, 'src-react/checkout/index.js' ),
	},
	plugins: [
		...defaultConfig.plugins.filter( ( p ) => ! ( p instanceof DependencyExtractionWebpackPlugin ) ),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal( request ) {
				return WC_EXTERNALS[ request ] ? WC_EXTERNALS[ request ].slice( 0, 2 ) : undefined;
			},
			requestToHandle( request ) {
				return WC_EXTERNALS[ request ] ? WC_EXTERNALS[ request ][ 2 ] : undefined;
			},
		} ),
	],
};
