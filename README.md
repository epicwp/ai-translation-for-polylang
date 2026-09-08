# AI Translation for Polylang

Source of the free WordPress plugin **AI Translation for Polylang**: translate posts, pages and terms into every language configured in Polylang with your own OpenAI API key.

WordPress.org listing: https://wordpress.org/plugins/ai-translation-for-polylang/ (submission in progress)

## What this repository is

This repository is a one-way mirror. The plugin is developed in a private monorepo together with its Pro edition. On every stable release the free edition's source is exported here as a single commit, tagged with the release version (for example `v4.22.0`). Nothing is developed here, and the history starts with the first exported release.

The tree is the plugin exactly as shipped in the WordPress.org zip, plus what is needed to rebuild the minified JavaScript bundles:

- `assets/scripts/`: the JavaScript and JSX sources of the admin bundles in `dist/admin/`
- `assets/images/`: the SVG icons webpack copies into `dist/images/`
- `webpack.config.js`, `package.json`, `package-lock.json`

## Rebuilding the JavaScript bundles

Requires Node.js 20 or newer.

    npm ci
    npm run build:free

This writes `dist-free/admin/translation-dashboard.js` and `dist-free/admin/single-translator.js` from the sources in `assets/scripts/`, to compare with the shipped bundles in `dist/admin/`. Two differences are expected: the shipped bundles were built by the release pipeline (its Node and dependency versions), and the monorepo build rewrites the text domain literal `polylang-ai-automatic-translation` to `ai-translation-for-polylang` in them afterwards. The stylesheet `dist/admin/admin.css` is compiled from Tailwind sources that live in the monorepo and is not rebuilt here.

## Issues and pull requests

Issues are switched off and pull requests are closed automatically. Changes have to land in the monorepo, from which this repository is regenerated on each release.

- Support for the free plugin: https://wordpress.org/support/plugin/ai-translation-for-polylang/
- Support from the developer: https://www.epicwpsolutions.com/support/
- Pro edition (bulk and automatic translation, Elementor, Bricks, ACF and WooCommerce integrations): https://www.epicwpsolutions.com/plugins/polylang-automatic-ai-translation/

## License

GPLv2 or later. See `LICENSE`.
