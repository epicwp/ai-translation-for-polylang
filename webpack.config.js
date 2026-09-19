const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const fs = require( 'fs' );
const path = require( 'path' );
const { DefinePlugin } = require( 'webpack' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );

// Edition is a compile-time constant: `__PLLAT_EDITION__` is replaced in the
// source before minification, so the branch for the other edition is dead code
// and its imports never reach the bundle. PLLAT_DIST_DIR keeps a free build
// (`npm run build:free`) from overwriting the pro bundle in dist/.
const distDir = path.resolve( process.cwd(), process.env.PLLAT_DIST_DIR || 'dist' );
const assetsDir = path.resolve( process.cwd(), 'assets' ) + path.sep;

// Writes the list of files under assets/ that the bundles were built from
// (relative paths, sorted) to <dist>/sources.json. The free zip ships exactly
// these sources next to the bundles (scripts/build-free-edition.sh); pro-only
// modules are required inside `__PLLAT_EDITION__ === 'pro'` branches, so a free
// build never resolves them and they never appear in the list.
class SourceListPlugin {
	apply( compiler ) {
		compiler.hooks.done.tap( 'PLLATSourceList', ( stats ) => {
			const files = new Set();
			// An entry point is a concatenated module: its own file is the
			// rootModule, the modules it inlines are in `modules`.
			const collect = ( module ) => {
				const resource = module.resource || ( module.rootModule && module.rootModule.resource ) || '';
				if ( resource.startsWith( assetsDir ) && ! resource.includes( `${ path.sep }node_modules${ path.sep }` ) ) {
					files.add( path.relative( process.cwd(), resource ) );
				}
				for ( const inner of module.modules || [] ) {
					collect( inner );
				}
			};
			stats.compilation.modules.forEach( collect );
			fs.writeFileSync( path.join( distDir, 'sources.json' ), JSON.stringify( [ ...files ].sort(), null, 2 ) + '\n' );
		} );
	}
}

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
		...( process.env.PLLAT_EDITION === 'free' ? [ new SourceListPlugin() ] : [] ),
	],
};
