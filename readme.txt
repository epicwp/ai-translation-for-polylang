=== EPICWP AI Translation for Polylang ===
Contributors: epicwpsolutions
Tags: polylang, ai translation, automatic translation, translation, chatgpt
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 4.22.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free AI translation for Polylang: translate a post, page or term into all your languages from the editor, with your own OpenAI key.

== Description ==

**Translate a post, page or category into every language on your Polylang site in one click, with your own OpenAI key and no plugin limits.**

EPICWP AI Translation for Polylang is a free WordPress plugin that translates posts, pages, custom post types and terms with AI on sites that use Polylang. It creates the Polylang translations from the edit screen and runs the work in the background on your server. It uses your own OpenAI API key with the GPT-5.4 Nano model, so there is no account with us, no subscription and no usage limit. Block and HTML structure stay intact, and a second run only sends the fields you changed. The post list and a dashboard show the status of every translation.

= How it works =

1. Install Polylang and add your languages. Then install and activate EPICWP AI Translation for Polylang.
2. Paste your OpenAI API key under Languages > AI Settings and click Test connection.
3. Open a published post, page or term, tick the target languages and click Start Translation.

The translations appear in Polylang moments later, linked to the original and ready for the language switcher. On a fresh site the first translated post is minutes away.

= What it translates =

* **Content:** posts, pages and every custom post type you enabled for translation in Polylang: title, content, excerpt and slug.
* **Editors:** the block editor (Gutenberg) and the classic editor. Block markup, HTML tags and links are preserved; only the text changes.
* **Taxonomies:** categories, tags and custom taxonomies: name, description and slug, from the term edit screen.
* **Languages:** every language configured in Polylang, from any source language, into several targets in one run.

= What it does not do =

* It does not translate the front end on the fly. It writes real Polylang translations that you can edit, and they stay if you remove the plugin.
* It does not replace Polylang: Polylang 3.7 or newer, free or Pro, provides the languages and the translation links.
* It does not include AI usage: you bring your own OpenAI key, and OpenAI bills you a fraction of a cent per language for a normal post.
* It does not create drafts. Only published content is translated and the translation is published right away; review it in the editor afterwards.
* The free edition does not send custom fields, page builder layouts, WooCommerce data, SEO meta or Polylang strings to the AI; Elementor and Bricks layouts are copied unchanged for you to translate in the builder. The Pro edition translates all of these.
* It does not translate navigation menus; Polylang manages menus per language.

= Free vs Pro =

The free edition is complete for translating content one item at a time. The Pro edition is for sites with a lot of content, page builders, WooCommerce or SEO plugins.

**Free edition**

* ✅ Post, page and term translation from the editor
* ✅ OpenAI with the GPT-5.4 Nano model
* ✅ Custom instructions per translation
* ✅ Change detection and per-field re-translation
* ✅ Status column and filter in the post list
* ✅ Dashboard with coverage and activity feed
* ✅ Preflight checks, error log and debug log
* ❌ Bulk translation of post types, taxonomies and the whole site
* ❌ Elementor, Bricks, ACF and WooCommerce translated in place
* ❌ Custom field management (translate, copy or ignore per field)
* ❌ SEO meta (eight SEO plugins, listed under Pro)
* ❌ Polylang string translations
* ❌ Internal links rewritten to the translated pages
* ❌ Claude, Gemini and OpenRouter with model choice
* ❌ Site-wide AI context and instructions
* ❌ Premium support and automatic updates

**Pro edition**

* ✅ Post, page and term translation from the editor
* ✅ OpenAI with the GPT-5.4 Nano model or any supported OpenAI model
* ✅ Custom instructions per translation
* ✅ Change detection and per-field re-translation
* ✅ Status column and filter in the post list
* ✅ Dashboard with coverage and activity feed
* ✅ Preflight checks, error log and debug log
* ✅ Bulk translation of post types, taxonomies and the whole site
* ✅ Elementor, Bricks, ACF and WooCommerce translated in place
* ✅ Custom field management (translate, copy or ignore per field)
* ✅ SEO meta for Yoast SEO, Rank Math, All in One SEO, SEOPress, Slim SEO, Squirrly SEO, The SEO Framework and SEO Engine Pro
* ✅ Polylang string translations
* ✅ Internal links rewritten to the translated pages
* ✅ Anthropic Claude, Google Gemini and OpenRouter with model choice
* ✅ Site-wide AI context and instructions
* ✅ Premium support and automatic updates

Upcoming in Pro: Auto-Translate 24/7, which translates new and edited content automatically.

Moving to Pro keeps your settings and translation records. The Pro edition is a separate plugin, sold on our website as Polylang AI Automatic Translation: see [pricing and licenses](https://www.epicwpsolutions.com/plugins/polylang-automatic-ai-translation/?utm_source=wordpress.org&utm_medium=listing&utm_campaign=free#pricing) or [upgrade to Pro](https://www.epicwpsolutions.com/upgrade/?utm_source=wordpress.org&utm_medium=listing&utm_campaign=free). In both editions the AI provider bills the AI usage, not us.

= Translation providers and quality =

The free edition uses the OpenAI API with GPT-5.4 Nano, chosen for its price and translation quality. The Pro edition adds Anthropic Claude, Google Gemini and OpenRouter, with a choice of model.

A language model translates the whole post in context, so tone and terminology stay consistent, and it follows instructions such as keeping brand names or using the formal address. The plugin checks every answer before writing it; a refusal or an incomplete answer is reported, not saved. To redo a result, change the instructions and tick Force re-translation for the fields you want.

= Why people choose this plugin =

* In production since 2023; the free and the Pro edition share one translation engine.
* Background jobs through Action Scheduler: a closed browser tab or a slow AI response does not lose your work; failed items are retried three times.
* Preflight checks explain a blocked setup (key, languages, WP-Cron, loopback) before a run starts, instead of failing silently.
* Change detection keeps your OpenAI bill low: a second run only sends what you changed.
* Anyone who can edit a post can translate it; no administrator role needed.
* The Pro edition is rated 4.5 out of 5 on Trustpilot from 13 reviews.

= Compatibility and requirements =

* WordPress 6.8 or newer, PHP 8.1 or newer.
* Polylang 3.7 or newer, the free plugin or Polylang Pro.
* An OpenAI account with an API key and billing enabled.
* Block editor and classic editor. Elementor and Bricks: copied for translation in the builder (free) or translated in place (Pro). Other page builders are not supported.
* WooCommerce products: Pro edition.
* Background jobs need WP-Cron or a server cron and loopback requests to your own site; the preflight check reports if either is blocked.

= Privacy and third-party services =

Your content goes to the OpenAI API only when you start a translation, and nothing goes to us: no telemetry, no account, no phoning home. The External services section below lists what is sent, when, and the OpenAI terms and privacy policy that apply. No visitor data is ever sent.

= Not affiliated with Polylang =

EPICWP AI Translation for Polylang is an independent plugin by EPIC WP. It is not developed by, endorsed by or affiliated with the makers of Polylang.

= Support and documentation =

* Support forum: https://wordpress.org/support/plugin/epicwp-ai-translation-for-polylang/
* The Support tab under Languages > AI Settings has the error log to quote in your post.
* [Server cron setup guide](https://www.epicwpsolutions.com/how-to-set-up-server-cron-for-better-plugin-performance/) for faster background processing.
* Pro customers get support through the support desk on our website.

== Installation ==

1. Install and activate Polylang (or Polylang Pro) and add your languages under Languages > Languages.
2. Install EPICWP AI Translation for Polylang from Plugins > Add New (search for "EPICWP AI Translation for Polylang") or upload the zip, then activate it.
3. Create an API key in your OpenAI account at https://platform.openai.com/api-keys and add a payment method or prepaid credit under Billing.
4. Go to Languages > AI Settings, paste the key, click Test connection and save. The post types and taxonomies you can translate are the ones you enabled for translation in Polylang.
5. Open a published post, page or term. In the AI Translation box, tick the target languages, add instructions if you like and click Start Translation.
6. Follow progress in the box or on the Languages > AI Translation dashboard, then open the translation in the editor to review it.

== Frequently Asked Questions ==

= Do I need my own OpenAI account and API key? =

Yes. The plugin sends your content to OpenAI with your own API key and nothing else is needed: no account with us, no subscription. Create the key at https://platform.openai.com/api-keys with billing enabled; a ChatGPT subscription is not the same thing: the API is billed separately by usage.

= What does it cost to translate a post? =

The plugin is free and has no usage limits; OpenAI bills you per token for the GPT-5.4 Nano model, which comes to well under one cent per language for a typical 1,000-word post. Long pages and many languages add up in proportion, and your OpenAI usage page shows the exact amounts.

= What is the difference between the free and the Pro edition? =

The free edition translates one post, page or term at a time from the editor, with OpenAI; the Pro edition adds bulk translation, page builders, WooCommerce, custom fields, SEO meta, Polylang strings, more AI providers and premium support. The full comparison is in the Free vs Pro section above. Both editions share their settings and translation records, so upgrading keeps everything.

= Which content is translated? =

For posts, pages and custom post types: the title, the content, the excerpt and the slug; for terms: the name, description and slug. Block editor markup and classic editor HTML keep their structure. Custom fields are not sent to the AI in the free edition; Polylang's own custom fields synchronisation copies them, and the Pro edition lets you decide per field whether it is translated, copied or ignored.

= Can I translate into multiple languages at once? =

Yes. Tick as many target languages as you like in the AI Translation box and one run creates all of them. Translating every item of a post type in one go is a Pro feature.

= Does it work with Polylang Pro and the free Polylang plugin? =

Yes, both, from Polylang 3.7 onwards. The plugin uses Polylang's languages and translation links and adds no language system of its own. It is not affiliated with Polylang.

= Does it work with Elementor, Bricks and other page builders? =

Content written in the block editor or the classic editor is translated in every edition. In the free edition, Elementor and Bricks layouts are copied unchanged into the translation, so the translated page opens in the builder with the original text for you to translate there; the Pro edition translates Elementor and Bricks layouts in place. Other page builders are not supported.

= Does it translate WooCommerce products? =

In the Pro edition, yes: product content and the WooCommerce product fields are translated in place. The free edition is not built for shops: when Polylang lists products as translatable it translates a product's title, description, short description and slug only, and leaves prices, attributes and variations alone.

= Does it translate ACF fields, custom fields and SEO meta from Yoast or Rank Math? =

In the Pro edition, yes. It translates ACF fields, custom fields you mark as translatable, and SEO meta for Yoast SEO, Rank Math, All in One SEO, SEOPress, Slim SEO, Squirrly SEO, The SEO Framework and SEO Engine Pro. The free edition leaves custom fields and SEO meta to Polylang's own synchronisation.

= Can I review a translation before it is published? =

No. The translation is published as soon as it is written, with the same status as the original, and you review or correct it in the editor afterwards. Only published content is translated; a draft or private post waits until you publish it.

= What happens when I edit the original? =

Open it and translate again: only the fields that changed since the last translation are sent to OpenAI, the rest is kept. Tick Force re-translation to redo everything, or only the fields you select, for example after changing your instructions.

= Can I bulk translate my whole site? =

Not with the free edition, which translates one post, page or term at a time into as many languages as you like. Bulk runs across post types and taxonomies, and the upcoming Auto-Translate 24/7, are part of the Pro edition.

= What happens if a translation fails? =

The language card shows the error and the Support tab logs it, and nothing half-finished is written. Each item is retried up to three times when the cause is temporary (a rate limit, a slow response); when your OpenAI account is out of credit the run stops with a clear message. Fix the cause and start the translation again.

= Where does my content go? Is it used to train AI models? =

Your content goes to the OpenAI API only when you start a translation, and nowhere else: the plugin has no telemetry and sends nothing to us. OpenAI states that data sent through its API is not used to train its models unless you opt in; its terms and privacy policy are linked in the External services section. Your API key is stored in your WordPress database like any other setting, and logs stay on your server in wp-content/uploads/pllat-logs.

= Can I choose the AI model? =

Not in the free edition, which uses OpenAI's GPT-5.4 Nano model. The Pro edition lets you pick any supported OpenAI model and adds Anthropic Claude, Google Gemini and OpenRouter.

= Does it slow down my site? =

No. Translations run as background jobs on your server and only when you start them; nothing runs on the front end and nothing changes for your visitors. Before a run, the plugin sends one request to your own site address to check that background jobs can run; if your host blocks loopback requests or WP-Cron, the preflight check tells you what to fix.

= Is this the official Polylang plugin? =

No. EPICWP AI Translation for Polylang is an independent plugin by EPIC WP and is not developed by, endorsed by or affiliated with the makers of Polylang. It requires Polylang to be installed.

= Where do I get support? =

Post in the support forum for this plugin on WordPress.org. The Support tab under Languages > AI Settings has the error log you can quote in your post. Pro customers get priority support through the support desk on our website.

== Screenshots ==

1. The AI Translation box in the editor mid-run: languages translated, translating and queued.
2. The result on the front end: the English post next to its Spanish translation, linked in Polylang.
3. The AI Translation dashboard: coverage per content type and language, plus the activity feed.
4. The posts list with the AI column: translated languages per post, and a filter by status.
5. Languages > AI Settings: paste your OpenAI key, test the connection and save.
6. The AI Translation box on the category edit screen: terms are translated the same way as posts.

== External services ==

This plugin connects to the OpenAI API to translate your content. OpenAI is a third-party service with its own terms and billing: you need your own OpenAI account and API key, and OpenAI charges you for the requests the plugin makes.

**OpenAI API** (https://api.openai.com)

* What is sent: the text of the post, page or term being translated, the source and target language, the plugin's translation prompt and any instructions you typed into the AI Translation box. Your API key is sent in the request header for authentication, and WordPress adds its standard User-Agent header (your WordPress version and site address). No user accounts, e-mail addresses or other site data are sent.
* When: only when you click Start Translation in the AI Translation box, and once, with a one-word test message, when you save your API key on the settings page, click Test connection or re-run a failed preflight check.
* Terms of use: https://openai.com/policies/terms-of-use
* Privacy policy: https://openai.com/policies/privacy-policy

**Your own site (loopback request)**

Before a translation starts, the preflight check sends one HTTP request to your own site address (your site URL with the query parameter pllat_loopback=1) to verify that background jobs can run. It goes to your own server and carries no content.

No other external requests are made. The plugin sends no usage data to the plugin author, and it does not contact WordPress.org or any other service itself. Links to our website inside the plugin are ordinary links, opened only when you click them.

== Changelog ==

= 4.22.1 =

**Bug Fixes**

* Fixed the application password warning to display correctly without affecting the layout

= 4.22.0 =

**What's New**

* Made custom field management available exclusively in the Pro version

**Bug Fixes**

* Fixed an issue that prevented sites from recovering when their license activation was deleted on SureCart

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

= Older versions =

The full changelog, including older releases, is available at [epicwpsolutions.com](https://www.epicwpsolutions.com/polylang-ai-translation-changelog/).
