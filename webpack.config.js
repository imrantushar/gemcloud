const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );
const path = require( 'path' );
const fs = require( 'fs' );

// The plugin header's Version is the single source of truth. It must equal
// GEMCLOUD_VERSION, since assets.php enqueues `build/[name].{version}.js`.
const GEMCLOUD_VERSION = (
	fs
		.readFileSync( path.resolve( __dirname, 'gemcloud.php' ), 'utf8' )
		.match( /^[\s*]*Version:\s*(.+)$/m ) || []
)[ 1 ].trim();

module.exports = {
	...defaultConfig,
	entry: {
		app: path.resolve( __dirname, 'dev_gemcloud/app.js' ),
	},
	output: {
		filename: `[name].${ GEMCLOUD_VERSION }.js`,
		path: path.resolve( __dirname, 'assets/build' ),
	},
	plugins: [ ...defaultConfig.plugins, new CleanWebpackPlugin() ],
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve.alias,
			'@GCSComponents': path.resolve( __dirname, 'dev_gemcloud/components/' ),
			'@GCSContainers': path.resolve( __dirname, 'dev_gemcloud/containers/' ),
			'@GCSPages': path.resolve( __dirname, 'dev_gemcloud/containers/BackendDashboard/pages/' ),
			'@GCSRedux': path.resolve( __dirname, 'dev_gemcloud/redux/' ),
			'@GCSUtils': path.resolve( __dirname, 'dev_gemcloud/utils/' ),
		},
	},
};
