=== WP PDF Reader ===
Contributors: pavelapki
Tags: pdf, pdf viewer, pdf.js, documents, multilingual
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.2.0
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

* Bundled PDF.js 4 reader: continuous scrolling, zoom, fullscreen, print,
  download, selectable text layer, lazy loading. The library is imported only
  on pages that actually show a document, and PDFs are parsed with script
  evaluation disabled.
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

= 1.2.0 =
* Links inside PDFs are clickable, both external ones and jumps within the
  document.
* Sidebar with page thumbnails and the document's own table of contents.
* Printing renders the pages instead of handing the file to a hidden iframe,
  so it also works on iOS, and a page range can be chosen.
* Open a document at a given page with #page=12, and a toolbar button copies
  such a link.
* Backfill for documents added before extraction existed: a button on the
  settings screen and a WP-CLI command, wp pdf-reader reindex.
* OCR for scanned documents when pdftoppm and tesseract are available, with a
  page limit and configurable languages.
* Documents can be marked as readable by logged in visitors only. Their files
  move into a directory that denies direct access and are served through PHP,
  with byte range support.

= 1.1.0 =
* Text of uploaded PDFs is extracted in the background and included in the site
  search, using pdftotext when available and a built-in PHP parser otherwise.
* Search inside the open document, diacritics insensitive, with match
  navigation and highlighting.
* Bulk import: pick many PDFs at once and get one document per file.
* Language switcher in the reader toolbar.
* View and download counters per language.
* Schema.org DigitalDocument and Open Graph output.
* Plugin updates from GitHub releases.
* Page count shown in the admin, ordering support, ARIA and focus handling.
* Rendered pages outside a window around the current one are released, so long
  documents no longer grow in memory.

= 1.0.0 =
* Initial release.
