=== AI Translation for Polylang ===
Contributors: epicwpsolutions
Tags: polylang, translation, ai, chatgpt, multilingual
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 4.21.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free AI translation for Polylang: translate a post, page or term into all your languages from the editor, with your own OpenAI key.

== Description ==

AI Translation for Polylang adds a free AI translation box to the WordPress editor. Open a post, page or term, tick the languages you need and click Start Translation: the plugin creates the Polylang translations for you with your own OpenAI API key. There is no account to create with us, no subscription and no usage limit in the plugin. You pay OpenAI for what you translate, which for a normal blog post is a fraction of a cent per language.

The free edition runs the same translation engine as the Pro edition. Gutenberg blocks and classic editor HTML keep their structure; only the text changes.

= What the free edition does =

* Translates a post, page or custom post type into every language you have configured in Polylang, straight from the edit screen.
* Translates taxonomy terms (categories, tags, custom taxonomies) from the term edit screen: name, description and slug.
* Translates the title, content, excerpt and slug. Gutenberg block markup and classic editor HTML are preserved.
* Custom fields: the plugin scans the custom fields of each post type and you decide per field whether it is translated, copied or ignored.
* Optional instructions per translation, for example tone of voice, terms that must stay untranslated or a formal address.
* Detects what changed: after you edit the original, translating again only sends the fields that changed. Tick "Force re-translation" to redo everything.
* Translation status column in the post list: translated, in progress, pending, failed or not started, per post.
* Dashboard under Languages > AI Translation with translation coverage per language and per content type, plus an activity feed of recent translations.
* Preflight checks before a translation starts (OpenAI key, Polylang languages, PHP environment, WP-Cron, loopback requests), so a problem is explained instead of failing silently.
* Error log and optional debug log in the Support tab, so you can see what went wrong and quote it when you ask for help.
* Cleans up after itself: its records are removed when you delete content, and its database tables and options are removed when you delete the plugin.

Translations run in the background through Action Scheduler (bundled), so you can keep working while the box shows progress per language.

= Requirements =

* WordPress 5.8 or newer and PHP 8.1 or newer.
* Polylang 3.7 or newer, the free plugin or Polylang Pro.
* An OpenAI account with an API key and billing enabled. See the FAQ for what a translation costs.

= What the Pro edition adds =

AI Translation for Polylang Pro is a separate plugin sold on our website. It adds:

* Bulk translation: pick post types, taxonomies and languages on the dashboard and translate your whole site in one run.
* Auto-Translate 24/7 (upcoming): new and edited content is translated automatically.
* Internal link rewriting: links inside translated content point to the translated pages.
* Elementor, Bricks, ACF and WooCommerce: page builder layouts, custom field groups and products are translated in place.
* Polylang string translations for theme and plugin strings.
* Anthropic Claude, Google Gemini and OpenRouter next to OpenAI, with a free choice of model.
* Site-wide AI context and custom instructions applied to every translation.
* SEO meta for Yoast SEO, Rank Math, SEOPress and All in One SEO.
* Premium support and automatic updates through your account on our website.

Both editions share their settings and translation records, so moving to Pro keeps your configuration and existing translations. Learn more at [epicwpsolutions.com](https://www.epicwpsolutions.com/plugins/polylang-automatic-ai-translation/).

= Privacy =

Your content is sent to the OpenAI API only when you start a translation, and nothing is sent to us. The External services section below lists exactly what is sent, when, and under which OpenAI terms.

== Installation ==

1. Install and activate Polylang (or Polylang Pro) and add your languages under Languages > Languages.
2. Install AI Translation for Polylang from Plugins > Add New (search for "AI Translation for Polylang") or upload the zip, then activate it.
3. Go to Languages > AI Settings, paste your OpenAI API key and save. The post types and taxonomies you translate are the ones you enabled for translation in Polylang.
4. Open a post, page or term. In the AI Translation box, select the target languages and click Start Translation.
5. Follow progress in the box, or on the Languages > AI Translation dashboard.

== Frequently Asked Questions ==

= Do I need my own OpenAI account? =

Yes. The plugin sends your content to OpenAI with your own API key. Create an account at platform.openai.com, add a payment method or prepaid credit under Billing, create a key under API keys (https://platform.openai.com/api-keys) and paste it into Languages > AI Settings. A ChatGPT subscription is not the same thing: the API is billed separately by OpenAI, based on usage.

= What does a translation cost? =

The plugin itself is free and has no usage limits. OpenAI bills you per token at the price of the model. The free edition uses OpenAI's GPT-5.4 Nano model (USD 0.20 per million input tokens and USD 1.25 per million output tokens at the time of writing), so a typical 1,000-word post costs well under one cent per language. Long pages and many languages add up proportionally. Your OpenAI usage dashboard shows the exact amounts. Saving your key (and re-running a failed preflight check) sends one tiny test request that also costs a fraction of a cent.

= Which content is translated? =

For posts, pages and custom post types: the title, the content (Gutenberg blocks and classic editor HTML, with the structure preserved), the excerpt, the slug and the custom fields you marked as translatable in AI Settings. For terms: the name, description and slug. The translations are linked in Polylang as usual, so the language switcher works right away.

= Do I need Polylang? Does it work with Polylang Pro? =

Yes, Polylang 3.7 or newer is required: the plugin uses Polylang's languages and translation links and adds no language system of its own. Both the free Polylang plugin and Polylang Pro are supported.

= Does it work with Elementor, Bricks and other page builders? =

Content written in the block editor or the classic editor is translated. Layouts that a page builder stores in its own fields, such as Elementor and Bricks, are copied unchanged into the translation in the free edition, so the translated page opens in the builder with the original text and you translate it there. The Pro edition translates Elementor and Bricks layouts, ACF fields and WooCommerce products in place.

= Where does my content go? Is my data safe? =

Content is sent to the OpenAI API only when you start a translation (plus a one-word test message when you save your key). Nothing is sent to us or to anyone else: the plugin has no telemetry, needs no account and does not phone home. Your API key is stored in your WordPress database like any other setting. Error logs and optional debug logs stay on your server, in wp-content/uploads/pllat-logs. How OpenAI handles API data is described in its terms and privacy policy, linked in the External services section.

= Can I choose the AI model? =

The free edition uses OpenAI with a fixed default model, currently GPT-5.4 Nano, chosen for its price and translation quality. The Pro edition lets you pick any supported OpenAI model and adds Anthropic Claude, Google Gemini and OpenRouter.

= Can I translate my whole site at once? =

The free edition translates one post, page or term at a time from its edit screen, into as many languages as you like in one go. Bulk runs across post types and the upcoming Auto-Translate 24/7 are part of the Pro edition.

= What happens when I edit the original? =

Open it and translate again. Only the fields that changed since the last translation are sent to OpenAI; the rest is kept. Tick "Force re-translation" if you want everything translated again, for example after changing your instructions.

= Why does the plugin check loopback requests and WP-Cron? =

Translations run as background jobs. Before a translation starts, the plugin makes one request to your own site address to verify that background jobs can run on your host. If your host blocks loopback requests or has WP-Cron disabled, the preflight check tells you what to fix.

= What is the difference between the free and the Pro edition? =

Free: single post, page and term translation from the editor into all your languages, OpenAI with the default model, the dashboard with coverage and activity, the status column, preflight checks, logs and support on the WordPress.org forum. Pro: bulk and site-wide translation, Auto-Translate 24/7 (upcoming), internal link rewriting, Elementor, Bricks, ACF and WooCommerce, Polylang strings, Claude, Gemini and OpenRouter with model choice, site-wide AI context and instructions, SEO meta, premium support and updates. Details at https://www.epicwpsolutions.com/plugins/polylang-automatic-ai-translation/

= Where do I get support? =

Post in the support forum for this plugin on WordPress.org; the Support tab under Languages > AI Settings has the error log you can quote in your post. Pro customers get priority support through the support desk on our website.

== Screenshots ==

1. The AI Translation box on the post edit screen: pick the target languages, add optional instructions and start the translation.
2. Languages > AI Settings with the OpenAI API key.
3. A translated post next to its source.
4. The Languages > AI Translation dashboard: coverage per language and content type, and the activity feed.
5. The Pro card on the dashboard, listing what the Pro edition adds.

== External services ==

This plugin connects to the OpenAI API to translate your content. OpenAI is a third-party service with its own terms and billing: you need your own OpenAI account and API key, and OpenAI charges you for the requests the plugin makes.

**OpenAI API** (https://api.openai.com)

* What is sent: the text of the post, page, term or custom field being translated, the source and target language, the plugin's translation prompt and any instructions you typed into the AI Translation box. Your API key is sent in the request header for authentication, and WordPress adds its standard User-Agent header (your WordPress version and site address). No user accounts, e-mail addresses or other site data are sent.
* When: only when you click Start Translation in the AI Translation box, and once, with a one-word test message, when you save your API key on the settings page or re-run a failed preflight check.
* Terms of use: https://openai.com/policies/terms-of-use
* Privacy policy: https://openai.com/policies/privacy-policy

**Your own site (loopback request)**

Before a translation starts, the preflight check sends one HTTP request to your own site address (your site URL with the query parameter pllat_loopback=1) to verify that background jobs can run. It goes to your own server and carries no content.

No other external requests are made. The plugin sends no usage data to the plugin author, and it does not contact WordPress.org or any other service itself. Links to our website inside the plugin are ordinary links, opened only when you click them.

== Changelog ==

= 4.21.4 =

**Bug Fixes**

* Fixed compatibility to allow activation alongside Polylang Pro again
* Fixed translation snapshot handling to improve stability

= 4.21.3 =

**Bug Fixes**

* Fixed translation of component blocks in Gutenberg content for better accuracy.
* Fixed issue with source text freezing during batch translations to ensure smooth processing.

= 4.21.2 =

**Bug Fixes**

* Elementor HTML widget content is now translated
* Bricks page content is no longer silently skipped when translations run in the background
* Translation runs now stop with a clear error when your AI provider account is out of credits, instead of appearing stuck
* Saving a translation no longer clears related custom fields in other languages

= 4.21.1 =

**Improvements**

* More reliable handling of large translations with slow AI responses
* Clearer error reporting when a translation fails, so issues are easier to resolve

**Bug Fixes**

* Translating no longer overwrites ACF repeater content in the source language
* Translations no longer conflict with Polylang field synchronization while being written
* Empty translations from OpenRouter reasoning models are handled correctly
* Restored the option to translate Bricks array content
* The single-post translator no longer reports completion before translation actually starts
* Content edited during a translation run is now picked up correctly

= 4.21.0 =

**What's New**

* Added token price display for every translation model in the selection dropdown
* Added new Claude models Sonnet 5 and Opus 4.8 with optimized performance settings
* Set OpenAI default model to GPT-5.4-nano for improved translations
* Enabled Claude translations using the native Anthropic Messages API for better integration
* Refreshed and updated all provider model lists with improved settings

**Improvements**

* Improved handling of Gemini 3 models to reduce unnecessary processing during translations
* Updated Gemini integration to support new authentication keys seamlessly

**Bug Fixes**

* Fixed issue where AI refusals were being written into blank translation fields
* Fixed max output tokens setting to ensure a minimum limit of 16,000 tokens
* Fixed Claude client to avoid conflicts with WordPress internals

= 4.20.0 =

**What's New**

* Added a new feature to customize AI translation prompts with the System Prompt Enricher.
* Enabled carrying custom instructions for each translation run to improve translation accuracy.

**Improvements**

* Improved the AI status column to now appear on the Pages list for easier monitoring.
* Enhanced translation context by including website-specific information in every prompt.
* Refined URL and path preservation during translation to keep links intact.
* Improved handling of label and value fields to ensure only non-translatable slugs are skipped, preserving readable text.

**Bug Fixes**

* Fixed incorrect time display on translated language cards.
* Resolved crashes when translating empty repeater or group fields.
* Prevented loss of custom instructions during translation requests.
* Fixed issues with URL preservation and label-value skipping in translations.

= 4.19.0 =

**What's New**

* Added a new progress indicator to show prime operation status in the dashboard.
* Introduced a migration ledger and fail-safe migration runner for smoother upgrades.
* Added a System Report endpoint for remote diagnosis and index coverage checks.
* Enabled parallel processing with a new concurrency filter for translation workers.
* Launched a new Strings translation tab in the dashboard with improved user interface and chunked translation support.

**Improvements**

* Improved real-time progress updates and eliminated startup delays in the dashboard.
* Enhanced translation filters and post-list AI column accuracy for better content management.
* Streamlined user interface elements including cancel buttons and notification visuals.
* Refined the single-translator experience with clearer feedback and auto-scroll for notices.
* Optimized backend processes for better performance and reliability during translation runs.

**Bug Fixes**

* Fixed issues with bulk translation progress and post-list filters showing incorrect data.
* Resolved authentication problems with Single Translator REST routes.
* Corrected translation of complex fields and media content to ensure completeness.
* Addressed various UI glitches including duplicate activity feed entries and misleading notices.
* Fixed stability problems with pipeline processing to prevent stalled or infinite loops during translation tasks.

= 4.18.7 =

**Bug Fixes**

* Fixed an issue with the installer to ensure smooth rollback from the beta version

= 4.18.6 =

**Improvements**

* Simplified job creation process for smoother translation management
* Restored administrator role for support access to improve troubleshooting

**Bug Fixes**

* Fixed issue with stuck translation tasks by improving worker handling
* Improved preflight checks with clearer, actionable messages
* Prevented accidental deletion of jobs by resetting their status correctly

= 4.18.5 =

**Bug Fixes**

* Fixed an issue where incomplete AI translations incorrectly triggered the circuit breaker

= 4.18.4 =

**Bug Fixes**

* Fixed an issue that could cause the site to become unresponsive.

= 4.18.3 =

**Bug Fixes**

* Fixed an issue that improved compatibility by removing unnecessary restrictions during setup.

= 4.18.2 =

**Bug Fixes**

* Changed a system check to a warning instead of an error to improve compatibility

= 4.18.1 =

**Bug Fixes**

* Fixed an issue with the consistency check to better recognize worker-pool actions, improving reliability during translation tasks.

= 4.18.0 =

**What's New**

* Added automatic detection of content changes to trigger re-translation tasks.
* Added new diagnostic tools including a site-wide snapshot and job timeline views to help monitor translation jobs.
* Added admin notices to alert when the license is missing or invalid.
* Added manual retry option for translation jobs via a new admin endpoint.
* Added a preflight check system to ensure your site and translation provider are ready before starting translations.

**Improvements**

* Improved support for translating media attachments.
* Improved handling of rate limits from translation providers to avoid unnecessary retries.
* Improved security and access controls for support access with scoped roles and audit logging.
* Improved logging and error reporting with better traceability and sensitive data scrubbing.
* Improved cleanup of orphaned translation jobs to keep your site tidy.

**Bug Fixes**

* Fixed issue where an admin notice was missing when no languages were configured in Polylang.
* Fixed translation problems related to media attachments and carousel elements.
* Fixed various issues to prevent orphaned translation jobs and race conditions during job cancellation.
* Fixed JSON patch handling to improve translation accuracy.
* Fixed heartbeat timing to reduce stale job timeouts.

= Older versions =

The full changelog, including older releases, is available at [epicwpsolutions.com](https://www.epicwpsolutions.com/polylang-ai-translation-changelog/).
