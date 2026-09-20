const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const fs = require( 'fs' );
const path = require( 'path' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );

// Edition is composition at the bundle entry: every bundle has an
// index.pro.jsx and an index.free.jsx, and PLLAT_EDITION picks the one webpack
// starts from. The pro-only modules are imported by the pro entries only, so a
// free build never resolves them. PLLAT_DIST_DIR keeps a free build
// (`npm run build:free`) from overwriting the pro bundle in dist/.
const edition = process.env.PLLAT_EDITION || 'pro';
const distDir = path.resolve( process.cwd(), process.env.PLLAT_DIST_DIR || 'dist' );
const assetsDir = path.resolve( process.cwd(), 'assets' ) + path.sep;

// Writes the list of files under assets/ that the bundles were built from
// (relative paths, sorted) to <dist>/sources.json. The free zip ships exactly
// these sources next to the bundles (scripts/build-free-edition.sh); the free
// entries never reach the pro-only modules, so they never appear in the list.
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
	entry: {
		'admin/translation-dashboard': `./assets/scripts/admin/translation-dashboard/index.${ edition }.jsx`,
		'admin/single-translator': `./assets/scripts/admin/single-translator/index.${ edition }.jsx`,
	},
	output: {
		...defaultConfig.output,
		path: distDir,
	},
	plugins: [
		...defaultConfig.plugins,
		new CopyWebpackPlugin( {
			patterns: [
				{
					from: path.resolve( process.cwd(), 'assets/images/*.svg' ),
					to: path.join( distDir, 'images/[name][ext]' ),
				},
			],
		} ),
		...( edition === 'free' ? [ new SourceListPlugin() ] : [] ),
	],
};
