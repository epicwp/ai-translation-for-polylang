const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );
const { DefinePlugin } = require( 'webpack' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );

// Edition is a compile-time constant: `__PLLAT_EDITION__` is replaced in the
// source before minification, so the branch for the other edition is dead code
// and its imports never reach the bundle. PLLAT_DIST_DIR keeps a free build
// (`npm run build:free`) from overwriting the pro bundle in dist/.
const distDir = path.resolve( process.cwd(), process.env.PLLAT_DIST_DIR || 'dist' );

module.exports = {
	...defaultConfig,
	output: {
		...defaultConfig.output,
		path: distDir,
	},
	plugins: [
		...defaultConfig.plugins,
		new DefinePlugin( {
			__PLLAT_EDITION__: JSON.stringify( process.env.PLLAT_EDITION || 'pro' ),
		} ),
		new CopyWebpackPlugin( {
			patterns: [
				{
					from: path.resolve( process.cwd(), 'assets/images/*.svg' ),
					to: path.join( distDir, 'images/[name][ext]' ),
				},
			],
		} ),
	],
};
