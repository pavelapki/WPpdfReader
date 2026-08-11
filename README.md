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

**Požadavky:** WordPress 5.8+, PHP 7.4+. Na straně návštěvníka prohlížeč
s podporou ES modulů — pokud ji nemá, prohlížeč se nevykreslí a zobrazí se
odkaz na otevření PDF. Pro automatické generování obálek (náhled první stránky)
je potřeba PHP rozšíření Imagick s podporou PDF; bez něj se použije náhledový
obrázek příspěvku nebo zástupný placeholder.

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

* přibalený PDF.js 4.10.38 (legacy build), který se načítá dynamickým importem
  až ve chvíli, kdy je na stránce prohlížeč — stránky bez PDF si ho nestáhnou,
* PDF se parsuje s `isEvalSupported: false`, takže dokument nemůže při
  zpracování spustit JavaScript (ochrana proti CVE-2024-4367 a spol.),
* přibalené standardní fonty (`standard_fonts/`), takže korektně vykreslí i
  PDF, která nemají Helvetiku/Times vložené v souboru,
* plynulé scrollování s vykreslováním stránek až ve chvíli, kdy jsou potřeba,
* stránkování, zoom, fit na šířku/stránku, fullscreen, tisk, stažení,
* textová vrstva → text v PDF jde označit a zkopírovat, prohledává ho i
  vyhledávání v prohlížeči,
* lazy loading (dokument se začne stahovat, až když se prohlížeč doscrolluje),
* klávesy ←/→, PageUp/PageDown, Home/End,
* respektuje `prefers-color-scheme` i `prefers-reduced-motion`.

## Hledání

Ve dvou rovinách:

**Vyhledávání na webu vidí dovnitř PDF.** Po nahrání souboru se na pozadí
(přes WP-Cron, ne v request editace) vytáhne text a uloží k dokumentu. Běžné
hledání ve WordPressu ho pak prohledává spolu s názvem a popisem. Použije se
`pdftotext`, pokud je na serveru; jinak zabere vestavěný PHP parser, který
zvládne většinu textových PDF. Naskenované dokumenty potřebují OCR a
indexovat je nelze — plugin raději neuloží nic, než aby do indexu nalil
nesmysly (kontroluje se podíl smysluplných znaků).

**Hledání v otevřeném dokumentu.** Lupa v toolbaru prohledá celý dokument,
ignoruje diakritiku (`zprava` najde `zpráva`), ukazuje počet výskytů a
mezi nálezy se skáče tlačítky nebo Enterem (Shift+Enter zpět). `Ctrl/Cmd+F`
uvnitř prohlížeče otevře hledání v dokumentu místo v prohlížeči.

## Hromadný import

**PDF dokumenty → Import**: vybereš najednou libovolný počet PDF z knihovny
médií a z každého vznikne dokument. Název se odvodí z názvu souboru, dá se
zvolit jazyk, stav (koncept/publikováno) a kategorie. Zpracovává se po
dávkách po 20 souborech, aby žádný request neběžel dlouho.

## Statistiky, SEO a aktualizace

* **Počítadlo zobrazení a stažení po jazycích** — v přehledu dokumentů je
  vidět, jestli fallback verzi vůbec někdo otevírá. Počítá se jednou za
  relaci prohlížeče, takže jde o řádový přehled, ne o analytiku.
* **Schema.org `DigitalDocument` + Open Graph** na detailu dokumentu. Když
  běží Yoast, Rank Math, SEOPress, AIOSEO nebo The SEO Framework, OG tagy se
  nevypisují, aby se neduplikovaly.
* **Aktualizace z GitHub releasů** — plugin si sám nabídne novou verzi
  v přehledu pluginů. Odpověď API se cachuje na 6 hodin (neúspěch na 2), takže
  administrace na GitHub nikdy nečeká. Balíček se přijme jen z HTTPS a jen
  z domén GitHubu.

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
| `wppdf_search_applies` | zda dotaz prohledává text PDF |
| `wppdf_text_quality_ratio` | přísnost kontroly kvality extrahovaného textu |
| `wppdf_binary_path` | cesta k `pdftotext` / `pdfinfo` mimo PATH |
| `wppdf_count_hit` | zda se zobrazení započítá (rate limit, boti) |
| `wppdf_schema_data` | data pro JSON-LD |
| `wppdf_seo_plugin_active` | vypnutí OG tagů kvůli jinému SEO pluginu |

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

`tests/smoke.php` obsahuje odlehčený harness, který si nastubuje potřebné
funkce WordPressu a projde klíčové cesty — jazykový fallback, vyhodnocení
souboru, HTML prohlížeče, shortcody, sanitizaci nastavení, extrakci textu
z reálně vygenerovaného PDF, rozšíření SQL dotazu při hledání i validaci
balíčků z GitHubu:

```bash
php tests/smoke.php
```

GitHub Action (`.github/workflows/ci.yml`) k tomu pouští lint na PHP 7.4, 8.1
a 8.3, kontrolu syntaxe JavaScriptu a PHPCS podle WordPress Coding Standards
(`composer install && vendor/bin/phpcs`).

## Zátěž serveru

Návrh se drží několika pravidel, aby plugin nebyl drahý:

* Extrakce textu i generování obálek běží na WP-Cronu, ne v requestu uložení.
* PDF.js (390 kB) se stahuje dynamickým importem jen na stránkách, kde
  prohlížeč skutečně je.
* Prohlížeč drží vykreslené jen stránky v okolí té aktuální, zbytek uvolní.
* Extrahovaný text má strop 200 000 znaků na jazyk, soubory nad 60 MB se
  v PHP neparsují.
* JOIN na text PDF se do dotazu přidá jen při fulltextovém hledání a jen pro
  typy obsahu, kterých se to týká.
* Dostupnost `pdftotext` i odpověď GitHub API se cachují v transientech.

## Aktualizace PDF.js

```bash
npm pack pdfjs-dist@<verze>
tar -xzf pdfjs-dist-<verze>.tgz
cp package/legacy/build/pdf.min.mjs package/legacy/build/pdf.worker.min.mjs \
   assets/vendor/pdfjs/
cp package/standard_fonts/* assets/vendor/pdfjs/standard_fonts/
```

Používá se **legacy** build kvůli podpoře starších prohlížečů. Načítá se přes
dynamický `import()` z `assets/js/viewer.js`, žádná registrace skriptu ve
WordPressu není potřeba — cesty se předávají v objektu `wppdfSettings`
(`includes/class-wppdf-viewer.php`).

Pozor při přechodu na PDF.js 5.x: mění se API textové vrstvy a roste požadavek
na verze prohlížečů.

### CJK dokumenty

Pro čínštinu, japonštinu a korejštinu zkopírujte navíc adresář `cmaps`
z pdfjs-dist do `assets/vendor/pdfjs/cmaps/`. Plugin si jeho přítomnost sám
zjistí a předá cestu prohlížeči. Ve výchozím stavu přibalený není (~1,7 MB).

## Licence

GPL-2.0-or-later. Přibalený PDF.js je pod Apache License 2.0
(`assets/vendor/pdfjs/LICENSE`).
