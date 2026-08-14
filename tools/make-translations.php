<?php
/**
 * Regenerate languages/wp-pdf-reader.pot and rebuild the Czech .po/.mo.
 *
 * WP-CLI's i18n command is the usual tool for this, but it is not always at
 * hand and the plugin only needs a small part of it, so the scan lives here.
 * Existing translations are kept: a string that is already translated in the
 * .po file keeps its translation, strings that vanished from the code are
 * dropped, and new ones are added untranslated.
 *
 * Usage: php tools/make-translations.php
 *
 * @package WP_PDF_Reader
 */

$root      = dirname( __DIR__ );
$domain    = 'wp-pdf-reader';
$languages = $root . '/languages';

/**
 * Every PHP file that can hold a translatable string.
 *
 * @param string $root Plugin root.
 * @return string[]
 */
function wppdf_source_files( $root ) {
	$skip  = array( $root . '/vendor', $root . '/node_modules', $root . '/assets/vendor', $root . '/tools', $root . '/tests' );
	$files = array();

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		$path = $file->getPathname();

		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		foreach ( $skip as $prefix ) {
			if ( 0 === strpos( $path, $prefix . '/' ) ) {
				continue 2;
			}
		}

		$files[] = $path;
	}

	sort( $files );

	return $files;
}

/**
 * Turn a PHP string literal token into its value.
 *
 * @param string $token Raw token including quotes.
 * @return string|null
 */
function wppdf_literal( $token ) {
	$token = trim( $token );

	if ( strlen( $token ) < 2 ) {
		return null;
	}

	$quote = $token[0];

	if ( ( "'" !== $quote && '"' !== $quote ) || substr( $token, -1 ) !== $quote ) {
		return null;
	}

	$inner = substr( $token, 1, -1 );

	if ( "'" === $quote ) {
		return strtr( $inner, array( "\\'" => "'", '\\\\' => '\\' ) );
	}

	return stripcslashes( $inner );
}

/**
 * Collect the translatable strings of one file.
 *
 * @param string $path   File path.
 * @param string $domain Text domain.
 * @param string $root   Plugin root, for relative references.
 * @param array  $out    Collected entries, by key.
 */
function wppdf_scan( $path, $domain, $root, array &$out ) {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$count  = count( $tokens );

	// Which argument positions hold text, and whether the entry has a plural.
	$calls = array(
		'__'             => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
		'_e'             => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
		'esc_html__'     => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
		'esc_html_e'     => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
		'esc_attr__'     => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
		'esc_attr_e'     => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
		'_x'             => array( 'text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2 ),
		'esc_html_x'     => array( 'text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2 ),
		'esc_attr_x'     => array( 'text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2 ),
		'_n'             => array( 'text' => 0, 'plural' => 1, 'context' => null, 'domain' => 3 ),
		'_nx'            => array( 'text' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4 ),
		'_n_noop'        => array( 'text' => 0, 'plural' => 1, 'context' => null, 'domain' => 2 ),
	);

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $calls[ $token[1] ] ) ) {
			continue;
		}

		// Skip method calls and definitions: only the plain function counts.
		$previous = $i > 0 ? $tokens[ $i - 1 ] : null;

		if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		$spec = $calls[ $token[1] ];
		$line = $token[2];
		$j    = $i + 1;

		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}

		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			continue;
		}

		// Read the argument list, one level deep only.
		$args    = array();
		$current = '';
		$depth   = 0;

		for ( $k = $j; $k < $count; $k++ ) {
			$piece = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];

			if ( '(' === $piece ) {
				$depth++;

				if ( 1 === $depth ) {
					continue;
				}
			}

			if ( ')' === $piece ) {
				$depth--;

				if ( 0 === $depth ) {
					$args[] = $current;
					break;
				}
			}

			if ( ',' === $piece && 1 === $depth ) {
				$args[]  = $current;
				$current = '';
				continue;
			}

			$current .= $piece;
		}

		if ( ! isset( $args[ $spec['domain'] ] ) || $domain !== wppdf_literal( $args[ $spec['domain'] ] ) ) {
			continue;
		}

		$text = isset( $args[ $spec['text'] ] ) ? wppdf_literal( $args[ $spec['text'] ] ) : null;

		if ( null === $text || '' === $text ) {
			continue;
		}

		$plural  = null !== $spec['plural'] && isset( $args[ $spec['plural'] ] ) ? wppdf_literal( $args[ $spec['plural'] ] ) : null;
		$context = null !== $spec['context'] && isset( $args[ $spec['context'] ] ) ? wppdf_literal( $args[ $spec['context'] ] ) : null;

		$key = ( null === $context ? '' : $context . "\x04" ) . $text;

		if ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = array(
				'text'       => $text,
				'plural'     => $plural,
				'context'    => $context,
				'references' => array(),
			);
		}

		if ( null === $out[ $key ]['plural'] && null !== $plural ) {
			$out[ $key ]['plural'] = $plural;
		}

		$out[ $key ]['references'][] = ltrim( str_replace( $root, '', $path ), '/' ) . ':' . $line;
	}
}

/**
 * JavaScript files that can hold a translatable string.
 *
 * @param string $root Plugin root.
 * @return string[]
 */
function wppdf_script_files( $root ) {
	$files = glob( $root . '/assets/js/*.js' );
	$files = array_merge( $files ? $files : array(), (array) glob( $root . '/blocks/*/*.js' ) );

	sort( $files );

	return array_filter( $files );
}

/**
 * Collect the translatable strings of one script.
 *
 * The editor scripts call wp.i18n directly, so their strings never appear in
 * the PHP scan and would be dropped from the catalogue if this did not run.
 *
 * @param string $path   File path.
 * @param string $domain Text domain.
 * @param string $root   Plugin root, for relative references.
 * @param array  $out    Collected entries, by key.
 */
function wppdf_scan_script( $path, $domain, $root, array &$out ) {
	$source    = (string) file_get_contents( $path );
	$reference = ltrim( str_replace( $root, '', $path ), '/' );
	$quoted    = "(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")";

	$patterns = array(
		// __( 'text', 'domain' ) and _x( 'text', 'context', 'domain' ).
		'/\b_(_|x)\(\s*' . $quoted . '\s*,\s*(?:' . $quoted . '\s*,\s*)?' . $quoted . '\s*\)/',
		// _n( 'single', 'plural', number, 'domain' ).
		'/\b_n\(\s*' . $quoted . '\s*,\s*' . $quoted . '\s*,[^,]+,\s*' . $quoted . '\s*\)/',
	);

	foreach ( $patterns as $index => $pattern ) {
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $matches as $match ) {
			// Each literal occupies two groups, one per quote style.
			$values = array();

			for ( $group = 2; $group < count( $match ); $group += 2 ) {
				$value = '' !== $match[ $group ][0] ? $match[ $group ][0] : ( isset( $match[ $group + 1 ] ) ? $match[ $group + 1 ][0] : '' );

				if ( '' !== $value || -1 !== $match[ $group ][1] ) {
					$values[] = stripcslashes( $value );
				}
			}

			// The last literal is always the domain.
			$found_domain = array_pop( $values );

			if ( $domain !== $found_domain || empty( $values[0] ) ) {
				continue;
			}

			$text    = $values[0];
			$plural  = 1 === $index && isset( $values[1] ) ? $values[1] : null;
			$context = 0 === $index && isset( $values[1] ) ? $values[1] : null;

			// _x is the only one of these with a context, and it is 'x'.
			if ( 0 === $index && 'x' !== substr( $match[1][0], 0, 1 ) ) {
				$context = null;
			}

			$key  = ( null === $context ? '' : $context . "\x04" ) . $text;
			$line = substr_count( substr( $source, 0, $match[0][1] ), "\n" ) + 1;

			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'text'       => $text,
					'plural'     => $plural,
					'context'    => $context,
					'references' => array(),
				);
			}

			$out[ $key ]['references'][] = $reference . ':' . $line;
		}
	}
}

/**
 * Escape a string for a .po file.
 *
 * @param string $value Raw value.
 * @return string
 */
function wppdf_po_escape( $value ) {
	return str_replace(
		array( '\\', '"', "\n", "\t", "\r" ),
		array( '\\\\', '\"', '\n', '\t', '\r' ),
		$value
	);
}

/**
 * Read the translations already stored in a .po file.
 *
 * @param string $path PO file.
 * @return array Map of key => list of translations.
 */
function wppdf_read_po( $path ) {
	if ( ! file_exists( $path ) ) {
		return array();
	}

	$existing = array();
	$entry    = array();
	$field    = null;

	$flush = static function () use ( &$entry, &$existing ) {
		if ( empty( $entry['msgid'] ) ) {
			$entry = array();

			return;
		}

		$key          = ( isset( $entry['msgctxt'] ) ? $entry['msgctxt'] . "\x04" : '' ) . $entry['msgid'];
		$translations = array();

		foreach ( $entry as $name => $value ) {
			if ( 0 === strpos( $name, 'msgstr' ) ) {
				$index                  = '' === substr( $name, 6 ) ? 0 : (int) trim( substr( $name, 6 ), '[]' );
				$translations[ $index ] = $value;
			}
		}

		ksort( $translations );

		if ( '' !== implode( '', $translations ) ) {
			$existing[ $key ] = array_values( $translations );
		}

		$entry = array();
	};

	foreach ( file( $path, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			$flush();
			$field = null;
			continue;
		}

		if ( '#' === substr( $line, 0, 1 ) ) {
			continue;
		}

		if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+"(.*)"$/', $line, $match ) ) {
			$field           = $match[1];
			$entry[ $field ] = stripcslashes( $match[2] );
			continue;
		}

		if ( null !== $field && preg_match( '/^"(.*)"$/', $line, $match ) ) {
			$entry[ $field ] .= stripcslashes( $match[1] );
		}
	}

	$flush();

	unset( $existing[''] );

	return $existing;
}

/**
 * Serialise entries into a .po or .pot body.
 *
 * @param array  $entries  Collected entries.
 * @param array  $existing Known translations.
 * @param string $header   File header.
 * @return string
 */
function wppdf_build_po( array $entries, array $existing, $header ) {
	$out = $header;

	foreach ( $entries as $key => $entry ) {
		$out .= "\n";

		foreach ( array_unique( $entry['references'] ) as $reference ) {
			$out .= '#: ' . $reference . "\n";
		}

		if ( null !== $entry['context'] ) {
			$out .= 'msgctxt "' . wppdf_po_escape( $entry['context'] ) . "\"\n";
		}

		$out .= 'msgid "' . wppdf_po_escape( $entry['text'] ) . "\"\n";

		$known = isset( $existing[ $key ] ) ? $existing[ $key ] : array();

		if ( null !== $entry['plural'] ) {
			$out .= 'msgid_plural "' . wppdf_po_escape( $entry['plural'] ) . "\"\n";

			// Czech has three plural forms; the .pot carries two empty ones.
			$forms = max( 3, count( $known ) );

			for ( $i = 0; $i < $forms; $i++ ) {
				$value = isset( $known[ $i ] ) ? $known[ $i ] : '';
				$out  .= 'msgstr[' . $i . '] "' . wppdf_po_escape( $value ) . "\"\n";
			}

			continue;
		}

		$value = isset( $known[0] ) ? $known[0] : '';
		$out  .= 'msgstr "' . wppdf_po_escape( $value ) . "\"\n";
	}

	return $out;
}

/**
 * Compile a .po file into a .mo file.
 *
 * @param string $po_path PO file.
 * @param string $mo_path MO file.
 * @return int Number of translated entries written.
 */
function wppdf_compile_mo( $po_path, $mo_path ) {
	$translations = wppdf_read_po( $po_path );
	$header       = '';

	foreach ( file( $po_path, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( preg_match( '/^"(.*)"$/', trim( $line ), $match ) ) {
			$header .= stripcslashes( $match[1] );
			continue;
		}

		if ( '' !== $header && 0 === strpos( trim( $line ), '#' ) ) {
			break;
		}
	}

	$pairs = array( '' => $header );

	foreach ( $translations as $key => $forms ) {
		$pairs[ $key ] = implode( "\x00", $forms );
	}

	ksort( $pairs );

	$ids      = array_keys( $pairs );
	$strings  = array_values( $pairs );
	$count    = count( $ids );
	$id_table = '';
	$st_table = '';
	$offset   = 28 + ( $count * 16 );

	$id_offsets = array();
	$st_offsets = array();

	foreach ( $ids as $id ) {
		$id_offsets[] = array( strlen( $id ), $offset + strlen( $id_table ) );
		$id_table    .= $id . "\x00";
	}

	$offset += strlen( $id_table );

	foreach ( $strings as $string ) {
		$st_offsets[] = array( strlen( $string ), $offset + strlen( $st_table ) );
		$st_table    .= $string . "\x00";
	}

	$mo = pack( 'Iiiiiii', 0x950412de, 0, $count, 28, 28 + ( $count * 8 ), 0, 0 );

	foreach ( $id_offsets as $entry ) {
		$mo .= pack( 'ii', $entry[0], $entry[1] );
	}

	foreach ( $st_offsets as $entry ) {
		$mo .= pack( 'ii', $entry[0], $entry[1] );
	}

	file_put_contents( $mo_path, $mo . $id_table . $st_table );

	return $count - 1;
}

/**
 * Write the JSON catalogue wp.i18n loads for one script.
 *
 * WordPress looks for {domain}-{locale}-{md5 of the script path}.json, so the
 * name is not free: get it wrong and the editor silently falls back to English.
 *
 * @param string $script    Script path relative to the plugin root.
 * @param array  $entries   Collected entries.
 * @param array  $existing  Known translations.
 * @param string $languages Languages directory.
 * @param string $domain    Text domain.
 * @param string $locale    Locale code.
 * @return int Number of strings written.
 */
function wppdf_write_json( $script, array $entries, array $existing, $languages, $domain, $locale ) {
	$messages = array(
		'' => array(
			'domain'       => 'messages',
			'lang'         => $locale,
			'plural-forms' => 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
		),
	);

	foreach ( $entries as $key => $entry ) {
		$from_script = false;

		foreach ( $entry['references'] as $reference ) {
			if ( 0 === strpos( $reference, $script . ':' ) ) {
				$from_script = true;
				break;
			}
		}

		if ( ! $from_script || ! isset( $existing[ $key ] ) ) {
			continue;
		}

		$messages[ $key ] = array_values( $existing[ $key ] );
	}

	$path = $languages . '/' . $domain . '-' . $locale . '-' . md5( $script ) . '.json';

	file_put_contents(
		$path,
		wp_json_encode_compat(
			array(
				'translation-revision-date' => '2026-08-11 00:00:00+0000',
				'generator'                 => 'wp-pdf-reader',
				'domain'                    => 'messages',
				'locale_data'               => array( 'messages' => $messages ),
			)
		)
	);

	// The empty header entry is not a string.
	return count( $messages ) - 1;
}

/**
 * JSON with the flags WordPress uses, kept readable.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_compat( $value ) {
	return (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
}

// --- Run ---------------------------------------------------------------.

$entries = array();

foreach ( wppdf_source_files( $root ) as $file ) {
	wppdf_scan( $file, $domain, $root, $entries );
}

foreach ( wppdf_script_files( $root ) as $file ) {
	wppdf_scan_script( $file, $domain, $root, $entries );
}

uasort(
	$entries,
	static function ( $a, $b ) {
		return strcmp( $a['text'], $b['text'] );
	}
);

$version = '1.0.0';
$plugin  = (string) file_get_contents( $root . '/wp-pdf-reader.php' );

if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $plugin, $match ) ) {
	$version = trim( $match[1] );
}

$pot_header = "# Copyright (C) 2026 Pavel Apki\n"
	. "# This file is distributed under the GPL-2.0-or-later license.\n"
	. "msgid \"\"\n"
	. "msgstr \"\"\n"
	. '"Project-Id-Version: WP PDF Reader ' . $version . "\\n\"\n"
	. "\"Report-Msgid-Bugs-To: https://github.com/pavelapki/WPpdfReader/issues\\n\"\n"
	. "\"MIME-Version: 1.0\\n\"\n"
	. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
	. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
	. "\"POT-Creation-Date: 2026-08-11T00:00:00+00:00\\n\"\n"
	. "\"X-Domain: wp-pdf-reader\\n\"\n";

file_put_contents( $languages . '/' . $domain . '.pot', wppdf_build_po( $entries, array(), $pot_header ) );

$po_path  = $languages . '/' . $domain . '-cs_CZ.po';
$existing = wppdf_read_po( $po_path );

$po_header = "# Czech translation of WP PDF Reader.\n"
	. "msgid \"\"\n"
	. "msgstr \"\"\n"
	. '"Project-Id-Version: WP PDF Reader ' . $version . "\\n\"\n"
	. "\"MIME-Version: 1.0\\n\"\n"
	. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
	. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
	. "\"Language: cs_CZ\\n\"\n"
	. "\"Plural-Forms: nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;\\n\"\n"
	. "\"X-Domain: wp-pdf-reader\\n\"\n";

file_put_contents( $po_path, wppdf_build_po( $entries, $existing, $po_header ) );

$written = wppdf_compile_mo( $po_path, $languages . '/' . $domain . '-cs_CZ.mo' );

// Re-read, so the JSON files carry the translations that were just written.
$existing = wppdf_read_po( $po_path );

foreach ( wppdf_script_files( $root ) as $script ) {
	$relative = ltrim( str_replace( $root, '', $script ), '/' );
	$in_json  = wppdf_write_json( $relative, $entries, $existing, $languages, $domain, 'cs_CZ' );

	if ( 0 === $in_json ) {
		// Nothing to translate in this script, so the file would only confuse.
		@unlink( $languages . '/' . $domain . '-cs_CZ-' . md5( $relative ) . '.json' );
		continue;
	}

	printf( "%s: %d strings\n", $relative, $in_json );
}

$untranslated = 0;

foreach ( array_keys( $entries ) as $key ) {
	if ( ! isset( $existing[ $key ] ) ) {
		$untranslated++;
	}
}

printf(
	"%d strings found, %d translated, %d still untranslated.\n",
	count( $entries ),
	$written,
	$untranslated
);
