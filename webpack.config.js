const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );
const path = require( 'path' );
const fs = require( 'fs' );

// The plugin header's Version is the single source of truth. It must equal
// GEMCLOUDSTORAGE_VERSION, since assets.php enqueues `build/[name].{version}.js`.
const GEMCLOUDSTORAGE_VERSION = (
	fs
		.readFileSync( path.resolve( __dirname, 'gemcloudstorage.php' ), 'utf8' )
		.match( /^[\s*]*Version:\s*(.+)$/m ) || []
)[ 1 ].trim();

module.exports = {
	...defaultConfig,
	entry: {
		app: path.resolve( __dirname, 'dev_gemcloudstorage/app.js' ),
	},
	output: {
		filename: `[name].${ GEMCLOUDSTORAGE_VERSION }.js`,
		path: path.resolve( __dirname, 'assets/build' ),
	},
	plugins: [ ...defaultConfig.plugins, new CleanWebpackPlugin() ],
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve.alias,
			'@GCSComponents': path.resolve( __dirname, 'dev_gemcloudstorage/components/' ),
			'@GCSContainers': path.resolve( __dirname, 'dev_gemcloudstorage/containers/' ),
			'@GCSPages': path.resolve( __dirname, 'dev_gemcloudstorage/containers/BackendDashboard/pages/' ),
			'@GCSRedux': path.resolve( __dirname, 'dev_gemcloudstorage/redux/' ),
			'@GCSUtils': path.resolve( __dirname, 'dev_gemcloudstorage/utils/' ),
		},
	},
};
