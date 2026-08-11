=== WP PDF Reader ===
Contributors: pavelapki
Tags: pdf, pdf viewer, pdf.js, documents, multilingual
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A post-like PDF library with a bundled PDF.js reader and per-language files with a configurable fallback chain.

== Description ==

WP PDF Reader turns PDFs into a browsable library. Documents are a custom post
type that can share the categories and tags of your posts, so existing category
archives keep working.

Each document holds one PDF per language. When the visitor's language has no
file, the plugin walks a configurable fallback chain — Czech first, English
second by default — instead of showing nothing.

Features:

* Bundled PDF.js reader: continuous scrolling, zoom, fullscreen, print,
  download, selectable text layer, lazy loading.
* Configurable post type key, slug, labels and menu icon. Existing documents are
  migrated when the key changes.
* Shared categories and tags with posts, optional dedicated taxonomy, optional
  inclusion in the blog loop and feeds.
* Per-language file fields, external URLs, fallback chain, optional fallback
  notice for visitors.
* Works with or without WPML/Polylang. When active, the current language is
  taken from them.
* Grid and list layouts through shortcodes, blocks or the bundled archive
  template, all overridable from the theme.
* Optional cover images rendered from the first page (requires Imagick with PDF
  support).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it through the Plugins screen.
3. Go to PDF documents → Settings to configure the post type, languages and
   fallback chain.

== Frequently Asked Questions ==

= Do I need WPML? =

No. The per-language fields live on a single document, so the plugin works on a
monolingual site too. When WPML or Polylang is active it is used to detect the
visitor's language.

= What happens when a document has no PDF in the visitor's language? =

The fallback chain is applied: the visitor's language, the base language of a
regional variant, then every code in the configured chain, then the default
language, and optionally any language that has a file.

= Can I use the fields on normal posts? =

Yes, add the post type through the `wppdf_supported_post_types` filter.

== Changelog ==

= 1.0.0 =
* Initial release.
