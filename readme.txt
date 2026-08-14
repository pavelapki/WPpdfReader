=== WP PDF Reader ===
Contributors: pavelapki
Tags: pdf, pdf viewer, pdf.js, documents, multilingual
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.6.2
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

= 1.6.2 =
* Fixed the reader failing to start on a lot of hosting. The bundled PDF.js
  build shipped with the .mjs extension, and servers without a MIME type for
  it answer with an empty Content-Type, which browsers refuse to run as a
  module. The files are now named .js, which every server knows. Nothing about
  their contents changed, and the release build refuses to ship .mjs again.
* When the reader cannot load at all, the console now names the file and the
  likely cause instead of only "Failed to fetch dynamically imported module".

= 1.6.1 =
* Review pass: the plugin now passes WordPress Coding Standards with no errors,
  and every remaining warning carries the reason it is deliberate. The standards
  job in CI was passing without checking anything, which is fixed and asserted.
* Security: migrating from another plugin now takes the same capability as the
  screen it runs from, because it copies drafts and private records. The bulk
  import and the file field refuse an attachment the editor may not read, and
  the GitHub repository setting no longer accepts a path that climbs out of the
  API's /repos/ prefix.
* Performance: a 404 on a site that never imported anything costs no query at
  all, and one that did costs one instead of three. The filter values and the
  taxonomy list are read once per request, and the migration screen counts
  every source in one query instead of one per post type.
* Fixed a multisite uninstall that left WordPress's own $blog_id global
  pointing at the last site visited.
* Translations can be regenerated: tools/make-translations.php rebuilds the
  .pot, .po, .mo and the editor's JSON catalogue.

= 1.6.0 =
* A page can list just the documents that belong to it. The categories come
  from a field on the page, chosen once in the settings, and the template
  prints them with wppdf_the_page_documents() or [pdf_grid from_field="1"].
  Whatever ACF returns is accepted: term IDs, term objects, slugs, names or a
  comma separated list. An empty field prints nothing rather than everything.
* The settings screen spells out how the ACF field should be configured,
  including the Save Terms trap that would file the page itself into the
  document categories.

= 1.5.0 =
* The import keeps each record's slug and remembers the full address it used
  to answer on, captured while the other plugin is still active.
* The import screen shows that plugin's URL prefix and takes it over on one
  click, so the old addresses keep resolving once it is deactivated. The
  prefix is remembered, so it can still be adopted after the plugin is gone.
* Anything that does not line up is caught by a permanent redirect from the
  old address to the imported document.

= 1.4.1 =
* The import now carries categories over even when the other plugin kept them
  in a taxonomy of its own, which WordPress no longer knows once that plugin
  is switched off. Terms are matched to existing ones by slug or name and
  created when missing, so a second run reuses them instead of duplicating.

= 1.4.0 =
* Import from another plugin. TNC FlipBook 3D is recognised directly, taking
  over its page count and extracted text so the PDFs need not be read again.
  Any other post type goes through a generic path that locates PDF
  attachments in the record's meta. Originals are never touched and a record
  is never imported twice.

= 1.3.1 =
* Security: protecting a document now also moves the preview images WordPress
  renders from its first page. They were left behind in a public directory.
* The delivery endpoint verifies the file really sits inside the uploads
  directory, redirects to the file itself once a document is no longer
  protected, and offers a sign in link instead of a bare 401.
* Listings prime the attachment caches in one batch. A twelve card grid used
  to fire 48 single row queries; it now fires none.
* Counting documents for the reindex screen uses COUNT instead of loading
  every ID into PHP.

= 1.3.0 =
* Filter bar on the document archive: full text that also reaches inside the
  PDFs, category, language, year and sorting, all kept in the URL so a result
  can be linked. Available anywhere through [pdf_grid filters="1"].
* Czech plural forms are now translated properly.

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
