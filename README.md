# WP PDF Reader

WordPress plugin pro knihovnu PDF dokumentů. Dokumenty se chovají jako příspěvky
(vlastní typ obsahu se sdílenými kategoriemi a štítky), zobrazují se v gridu nebo
seznamu a otevírají se v prohlížeči postaveném na **PDF.js**, který je přibalený
v pluginu — nic se nenačítá z CDN.

Klíčová vlastnost: **jeden dokument = jedna sada PDF polí pro jednotlivé jazyky**
s nastavitelným fallbackem. Výchozí jazyk je čeština; když v něm PDF chybí,
prohlížeč sáhne po angličtině (nebo dalším jazyce v řadě).

---

## Instalace

1. Zkopírujte složku pluginu do `wp-content/plugins/wp-pdf-reader/`
   (nebo naklonujte repozitář přímo tam).
2. Aktivujte plugin v administraci.
3. Otevřete **PDF documents → Settings** a nastavte název typu obsahu, jazyky
   a fallback.

Žádný build step není potřeba — plugin nemá závislosti na npm ani composeru,
JavaScript je psaný bez JSX.

**Požadavky:** WordPress 5.8+, PHP 7.4+. Pro automatické generování obálek
(náhled první stránky) je potřeba PHP rozšíření Imagick s podporou PDF; bez něj
se použije náhledový obrázek příspěvku nebo zástupný dlaždicový placeholder.

## Jak to funguje

### Typ obsahu

Plugin registruje vlastní typ obsahu (výchozí klíč `pdf_document`, URL `/pdf/`).
V nastavení lze změnit:

* **klíč typu obsahu** — při změně se existující dokumenty automaticky přepíšou
  na nový klíč, takže se nic neztratí,
* **URL slug**, **názvy** (jednotné/množné číslo) a **ikonu v menu**,
* zda se sdílí **kategorie a štítky s příspěvky** (výchozí ano — díky tomu
  fungují stávající archivy kategorií pro příspěvky i PDF),
* zda se dokumenty mají objevovat **v hlavním výpisu blogu, feedech a
  archivech** (výchozí ne),
* volitelnou **samostatnou taxonomii** `pdf_category`, pokud chcete kategorie
  oddělené od blogu.

### Jazyky a fallback

Na každém dokumentu je metabox **PDF files by language** s jedním polem pro
každý nakonfigurovaný jazyk. Do pole se vybírá soubor z knihovny médií, případně
se dá vložit externí URL.

Pořadí hledání souboru při zobrazení:

1. jazyk návštěvníka (z WPML/Polylang, jinak z locale webu),
2. základní jazyk regionální varianty (`en-gb` → `en`),
3. **fallback řetězec** z nastavení (výchozí `cs, en`),
4. výchozí jazyk,
5. volitelně jakýkoli jazyk, ve kterém soubor existuje,
6. pokud je dokument přeložený přes WPML/Polylang, prohledají se ještě
   jeho sourozenecké překlady.

Když se použije jiný než požadovaný jazyk, může se návštěvníkovi zobrazit
nenápadná poznámka („Tento dokument není ve vašem jazyce, zobrazujeme anglickou
verzi.“) — jde vypnout v nastavení.

WPML není povinné. Když je aktivní, bere se z něj pouze aktuální jazyk
návštěvníka a jazyky se přidají do seznamu polí.

### Prohlížeč

* přibalený PDF.js 3.11.174 (legacy build, funguje i ve starších prohlížečích),
* plynulé scrollování s vykreslováním stránek až ve chvíli, kdy jsou potřeba,
* stránkování, zoom, fit na šířku/stránku, fullscreen, tisk, stažení,
* textová vrstva → text v PDF jde označit a zkopírovat, prohledává ho i
  vyhledávání v prohlížeči,
* lazy loading (dokument se začne stahovat, až když se prohlížeč doscrolluje),
* klávesy ←/→, PageUp/PageDown, Home/End,
* respektuje `prefers-color-scheme` i `prefers-reduced-motion`.

## Použití

### Shortcody

```
[pdf_reader id="12" lang="en" height="800" zoom="page-width" toolbar="1" download="1"]
[pdf_grid columns="3" per_page="12" category="vyrocni-zpravy"]
[pdf_list per_page="20" pagination="1"]
[pdf_download id="12" text="Stáhnout PDF"]
```

`[pdf_reader]` bez `id` použije aktuální příspěvek. `lang` bez hodnoty znamená
„jazyk návštěvníka + fallback“.

Parametry `[pdf_grid]` / `[pdf_list]`: `columns`, `per_page`, `layout`,
`category`, `tag`, `taxonomy` + `terms`, `ids`, `exclude`, `author`, `orderby`,
`order`, `search`, `lang`, `excerpt`, `meta`, `pagination`.

### Bloky

V editoru jsou k dispozici bloky **PDF reader** a **PDF library** (kategorie
Média) se stejnými možnostmi jako shortcody.

### Funkce pro šablony

```php
wppdf_the_viewer( $post_id, array( 'height' => 600 ) );
$file = wppdf_get_file( $post_id );          // pole s url, lang, is_fallback, filesize…
$url  = wppdf_get_file_url( $post_id, 'en' );
$has  = wppdf_has_file( $post_id );
$langs = wppdf_get_available_languages( $post_id );
$cover = wppdf_get_cover_id( $post_id );
$type  = wppdf_get_post_type();
```

### Šablony

Plugin použije vlastní šablony jen tehdy, když je téma nemá. Pořadí:

1. `single-{post_type}.php` / `archive-{post_type}.php` v tématu,
2. `wp-pdf-reader/single-document.php` / `wp-pdf-reader/archive-document.php`
   v tématu,
3. šablony pluginu.

Přepsat lze i jednotlivé části: `wp-pdf-reader/parts/card.php` a
`wp-pdf-reader/parts/archive-loop.php`.

### Filtry

| Filtr | K čemu |
| --- | --- |
| `wppdf_settings` | úprava celého pole nastavení |
| `wppdf_languages` | seznam jazyků pro pole na dokumentu |
| `wppdf_current_language` | detekovaný jazyk návštěvníka |
| `wppdf_fallback_order` | pořadí jazyků při hledání souboru |
| `wppdf_resolved_file` | výsledek hledání souboru |
| `wppdf_supported_post_types` | přidání polí i k `post`/`page` |
| `wppdf_post_type_args` | argumenty `register_post_type()` |
| `wppdf_viewer_html` | výsledné HTML prohlížeče |
| `wppdf_no_file_html` | co se zobrazí, když dokument nemá žádný soubor |
| `wppdf_query_args` | argumenty dotazu pro grid |
| `wppdf_allowed_mime_types` | povolené typy souborů |
| `wppdf_cover_width` | šířka generovaných obálek |
| `wppdf_theme_template_directory` | složka pro přepsání šablon v tématu |

Příklad — přidat pole i k běžným příspěvkům:

```php
add_filter( 'wppdf_supported_post_types', function ( $types ) {
	$types[] = 'post';
	return $types;
} );
```

## Struktura

```
wp-pdf-reader.php          bootstrap, konstanty, aktivace
includes/                  třídy pluginu (nastavení, jazyky, CPT, viewer, …)
templates/                 šablony archivu, detailu a částí (jdou přepsat v tématu)
assets/css|js/             frontend a admin styly a skripty
assets/vendor/pdfjs/       přibalený PDF.js (Apache-2.0)
blocks/                    definice bloků (block.json)
languages/                 .pot + český překlad
tests/smoke.php            smoke test bez WordPressu
```

## Testy

`tests/smoke.php` obsahuje odlehčený harness, který si naStubuje potřebné
funkce WordPressu a projde klíčové cesty — jazykový fallback, vyhodnocení
souboru, HTML prohlížeče, shortcody i sanitizaci nastavení:

```bash
php tests/smoke.php
```

## Aktualizace PDF.js

Stáhněte `pdf.min.js` a `pdf.worker.min.js` z legacy buildu pdfjs-dist a
nahraďte soubory v `assets/vendor/pdfjs/`. Verzi upravte v
`includes/class-wppdf-viewer.php` (`wp_register_script( 'pdfjs', … )`).
Od pdfjs-dist 4.x jsou k dispozici pouze ES moduly (`.mjs`), což by vyžadovalo
úpravu načítání skriptu.

## Licence

GPL-2.0-or-later. Přibalený PDF.js je pod Apache License 2.0
(`assets/vendor/pdfjs/LICENSE`).
