import { build } from 'esbuild';

await build({
  entryPoints: ['src/index.js'],
  bundle: true,
  format: 'iife',
  target: 'es2019',
  jsx: 'transform',
  jsxFactory: 'h',
  jsxFragment: 'Fragment',
  loader: { '.js': 'jsx' },
  outfile: 'dist/roundtable.js',
  minify: true,
  sourcemap: false,
});

console.log('built dist/roundtable.js + dist/roundtable.css');
