const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		app: path.resolve( __dirname, 'dev_gemcloudstorage/app.js' ),
	},
	output: {
		filename: '[name].js',
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
