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
* **klikatelné odkazy uvnitř PDF** — externí i skoky v rámci dokumentu,
* **boční panel** s náhledy stránek a s obsahem dokumentu (PDF záložky),
* **tisk** vykreslením stránek, ne přes skrytý iframe, takže funguje i na iOSu,
  s volbou rozsahu stránek,
* **odkaz na konkrétní stránku** — `#page=12` otevře dokument rovnou tam
  a tlačítko v liště takový odkaz zkopíruje,
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
zvládne většinu textových PDF. Plugin raději neuloží nic, než aby do indexu
nalil nesmysly — kontroluje se podíl smysluplných znaků.

**Naskenované dokumenty.** Sken nemá textovou vrstvu, takže z něj nic vytáhnout
nejde. Když jsou na serveru `pdftoppm` a `tesseract`, plugin takový dokument
projede OCR — jen tehdy, když nenašel žádný text, na naplánované úloze a
s limitem stránek (výchozí 20). Jazyky se nastavují jako `ces+eng`.

**Doindexování.** Text se vytahuje při uložení souboru, takže dokumenty přidané
dřív index nemají. V nastavení je tlačítko, které je po dávkách projde; u velké
knihovny je lepší `wp pdf-reader reindex` (volby `--force`, `--skip-covers`,
`--limit`).

**Hledání v otevřeném dokumentu.** Lupa v toolbaru prohledá celý dokument,
ignoruje diakritiku (`zprava` najde `zpráva`), ukazuje počet výskytů a
mezi nálezy se skáče tlačítky nebo Enterem (Shift+Enter zpět). `Ctrl/Cmd+F`
uvnitř prohlížeče otevře hledání v dokumentu místo v prohlížeči.

## Hromadný import

**PDF dokumenty → Import**: vybereš najednou libovolný počet PDF z knihovny
médií a z každého vznikne dokument. Název se odvodí z názvu souboru, dá se
zvolit jazyk, stav (koncept/publikováno) a kategorie. Zpracovává se po
dávkách po 20 souborech, aby žádný request neběžel dlouho.

## Import z jiného pluginu

Na stránce **Import** je druhý panel, který zkopíruje existující záznamy do
dokumentů — název, text, datum, autora, kategorie, náhledový obrázek a hlavně
PDF. Originály zůstávají nedotčené, nic se nemaže. Každý import si u dokumentu
poznamená, odkud přišel (`_wppdf_imported_from`), takže opakované spuštění
nic nezduplikuje.

**TNC FlipBook 3D** je rozpoznaný napřímo. Klíče jsem si přečetl ze zdrojáku
pluginu, ne odhadl: typ obsahu `tnc_flipbook`, PDF v `_tncfb3d_pdf_id`
(u vícesouborových v `_tncfb3d_pdf_ids`). Navíc se přebírá `_tncfb3d_page_count`
a `_tncfb3d_extracted_text`, takže se nemusí znovu číst všechna PDF.

**Ostatní pluginy** jedou obecnou cestou: import projde meta záznamu a najde
v nich přílohy typu PDF — i vnořené v polích — nebo odkaz na `.pdf`. Když je
soubor v knihovně médií, připojí se; když leží jinde, uloží se jako externí
URL. Placenou verzi TNC FlipBook Classic jsem si stáhnout nemohl, takže její
klíče nemám ověřené — půjde přes obecnou cestu.

**Kategorie přežijí i z cizí taxonomie.** TNC Classic si kategorie drží ve
vlastní taxonomii, kterou po vypnutí pluginu WordPress už nezná — řádky ale
v databázi zůstávají, takže je import čte přímo z tabulek. Termy se pak spárují
s tvými kategoriemi podle slugu, jinak podle názvu, a když neexistují, založí
se. Druhý běh je použije znovu, nezaloží dvojníky. Když je taxonomie taková,
kterou dokumenty stejně používají (běžná `category`), termy se prostě převezmou
i s jejich ID.

### Zachování adres

Migrace je navržená tak, aby se po vypnutí TNC nerozbily odkazy.

**Slug každého záznamu se kopíruje.** Dokument dostane stejný `post_name`, jaký
měl flipbook, takže poslední část adresy sedí. Zároveň se u dokumentu uloží
celá původní cesta (`/flipbook/vyrocni-zprava-2024/`) — sbírá se ve chvíli, kdy
je TNC ještě aktivní, protože potom už jeho permalink nikdo nesestaví.

**URL prefix jde převzít.** Na stránce Import se ukáže, pod jakým prefixem
záznamy odpovídají, a tlačítkem ho přepneš na svůj typ obsahu. Pořadí je
důležité:

1. naimportuj záznamy (TNC ještě běží, ať se dá přečíst prefix i permalinky),
2. **vypni TNC**,
3. teprve pak převezmi prefix.

Když jsou oba pluginy aktivní naráz, hlásí se o stejné adresy a vyhraje jen
jeden. Tlačítko na to upozorní, pokud je zdrojový plugin ještě aktivní.
Prefix se navíc pamatuje v databázi, takže se dá převzít i potom, co je TNC
pryč a WordPress už jeho typ obsahu nezná.

**Záchranná síť.** Co se přesto nesejde — třeba když nechceš prefix měnit —
odchytí přesměrování: adresa, která by skončila 404 a patřila naimportovanému
záznamu, se trvale (301) přesměruje na jeho dokument. Hledá se nejdřív podle
celé staré cesty, teprve pak podle slugu. Vypíná se v nastavení.

Co se neimportuje samo:

* **Flipbooky složené z obrázků** žádné PDF nemají, nahlásí se jako přeskočené.
* **Záznam s víc PDF** dostane do zvoleného jazyka první z nich, ostatní se
  vypíšou do protokolu, ať si je přiřadíš k jazykům sám.

## Dokumenty patřící ke stránce

Typický případ: každá stránka má svoje PDF a v šabloně chceš vypsat jen ta,
která k ní patří. Stránka nese ACF pole s kategoriemi, plugin z něj přečte
termy a vypíše dokumenty jen z nich.

V **Nastavení → Dokumenty patřící ke stránce** vybereš pole ze seznamu (načte
se z ACF field groups). Pak v šabloně:

```php
<?php wppdf_the_page_documents(); ?>
<?php wppdf_the_page_documents( array( 'columns' => 4, 'layout' => 'list' ) ); ?>
```

nebo shortcodem, když to má jít z editoru:

```
[pdf_grid from_field="1"]
[pdf_grid from_field="jine_pole" columns="4"]
```

Když je pole prázdné, **nevypíše se nic** — ne celá knihovna. To by byla horší
chyba než prázdné místo. Přepíná se přes `empty="all"`.

### Jak to pole v ACF nastavit

* **Typ pole: Taxonomy**, ne obyčejný Select. Nabídne skutečné kategorie
  (nedá se překlepnout) a ukládá ID, takže přejmenování kategorie nic
  nerozbije.
* **Taxonomy:** ta, kterou používají dokumenty — při sdílených kategoriích
  `category`, jinak `pdf_category`.
* **Appearance:** Multi Select nebo Checkbox, ať jich jde vybrat víc.
* **Return Format:** Term ID.
* **Save Terms: vypnuto.** Tohle je ta zrádnost — když to necháš zapnuté, ACF
  zařadí do těch kategorií **samotnou stránku** a ta se pak objeví v archivech
  kategorií mezi dokumenty.
* **Load Terms:** taky vypnuto, ze stejného důvodu.

Select ani Checkbox nejsou zakázané — plugin páruje i slugy a názvy, a textové
pole umí i „kategorie1, kategorie2" — ale takové pole se od skutečného seznamu
kategorií časem rozejde.

Bez ACF to funguje taky: pole je pak obyčejný meta klíč, do kterého si ID nebo
slugy uloží cokoli jiného.

## Filtrování archivu

Nad výpisem dokumentů je lišta s fulltextem, kategorií, jazykem, rokem
a řazením. Vypíná se v nastavení.

* **Fulltext** hledá i uvnitř PDF — používá stejný index jako vyhledávání na
  webu. Nastavuje se přitom jen hledaný výraz, ne příznak „tohle je
  vyhledávání“, takže archiv si nechá svou šablonu a nespadne do `search.php`.
* **Jazyk** znamená „dokumenty, které mají soubor v tomto jazyce“, ne jazyk
  návštěvníka. Hodí se, když chceš vidět, co existuje anglicky.
* **Rok** se nabízí jen z let, ve kterých nějaký dokument je; seznam se cachuje
  a zneplatní při uložení nebo smazání dokumentu.
* **Řazení**: od nejnovějších, od nejstarších, podle názvu, nebo ruční pořadí
  (pole Pořadí u dokumentu).

Stejnou lištu jde umístit kamkoli:

```
[pdf_grid filters="1" per_page="24" pagination="1"]
```

Filtry se drží v URL (`?wppdf_q=…&wppdf_lang_filter=en`), takže výsledek jde
poslat odkazem. Formulář se dá přepsat v tématu jako
`wp-pdf-reader/parts/filters.php`.

## Dokumenty jen pro přihlášené

U jednotlivého dokumentu se dá zaškrtnout, že ho smí otevřít jen přihlášený
návštěvník. Neschovává se přitom jen odkaz — soubory se **přesunou** do
`wp-content/uploads/wppdf-protected/`, kam se položí `.htaccess` se zákazem
přímého přístupu, a servírují se přes PHP až po kontrole oprávnění. Endpoint
umí Range requesty, takže PDF.js může zobrazit první stránku dřív, než dorazí
celý soubor.

Původní URL souboru tím přestane fungovat — to je záměr, ale počítej s tím,
pokud jsi ho někam nalinkoval.

Spolu s PDF se přesouvají i **náhledy, které si WordPress z první stránky
vyrábí sám**. Bez toho by po zamčení dokumentu zůstal v uploads veřejně ležet
čitelný obrázek první stránky. Co zůstává veřejné, je malá obálka v gridu —
je to jen dlaždice a název i popis dokumentu jsou stejně veřejné, ale pokud
nechceš ukazovat ani ji, nastav u chráněného dokumentu vlastní náhledový
obrázek.

Když ochranu zase vypneš, endpoint na soubor přesměruje, takže odkazy vzniklé
během ochrany dál fungují a veřejné PDF se nežene přes PHP.

**Na nginxu `.htaccess` nic nedělá.** Metabox na to upozorní; do konfigurace
serveru je potřeba přidat:

```nginx
location ^~ /wp-content/uploads/wppdf-protected/ { deny all; }
```

Kdo smí číst, se dá předefinovat filtrem `wppdf_user_can_read` — třeba podle
členství.

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
| `wppdf_file_url` | URL, ze které se soubor servíruje |
| `wppdf_is_protected` | zda dokument vyžaduje přihlášení |
| `wppdf_user_can_read` | kdo smí chráněný dokument otevřít |
| `wppdf_ocr_max_pages` | kolik stránek skenu projde OCR |
| `wppdf_hit_throttle` | okno pro rate limit počítadla |

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

## Bezpečnost

* PDF se parsuje s `isEvalSupported: false` — dokument nemůže při zpracování
  spustit JavaScript.
* `pdftotext` a `pdfinfo` se hledají procházením PATH v PHP a spouští se
  polem přes `proc_open`, takže se vůbec nesahá na shell. Název souboru se
  znaky jako `;` nebo `$(…)` je proto neškodný — pokrývá to regresní test.
* Každý zápis má nonce i kontrolu oprávnění. Jediný anonymní endpoint je
  počítadlo zobrazení; ověřuje, že jde o publikovaný dokument a známý jazyk,
  a když web má persistentní object cache, i rate limituje.
* Balíček aktualizace se přijme jen přes HTTPS a jen z domén GitHubu, verze
  musí odpovídat tvaru čísla verze.
* Všechny meta klíče začínají podtržítkem, takže se neobjeví v REST API ani
  v editoru vlastních polí. Extrahovaný text se nikde nevypisuje, slouží
  jen pro `LIKE` v dotazu.

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
* Výpisy si předehřejí cache příloh jednou dávkou. Grid o dvanácti kartách
  dřív odpálil 48 samostatných dotazů na přílohy, teď žádný — měřeno
  harnessem v `tests/`.
* Seznam let a počty dokumentů pro doindexování se počítají v databázi, ne
  natažením všech ID do PHP.

## Aktualizace PDF.js

```bash
npm pack pdfjs-dist@<verze>
tar -xzf pdfjs-dist-<verze>.tgz
cp package/legacy/build/pdf.min.mjs   assets/vendor/pdfjs/pdf.min.js
cp package/legacy/build/pdf.worker.min.mjs assets/vendor/pdfjs/pdf.worker.min.js
cp package/standard_fonts/* assets/vendor/pdfjs/standard_fonts/
```

Přípona se při kopírování mění z `.mjs` na `.js` schválně: spousta hostingů
nemá pro `.mjs` nastavený MIME typ a pošle soubor s prázdnou hlavičkou
`Content-Type`, což prohlížeč jako ES modul odmítne spustit. Obsah souboru
je nedotčený, mění se jen jméno.

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
