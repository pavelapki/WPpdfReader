/**
 * WP PDF Reader — bulk import screen.
 */
( function ( $ ) {
	'use strict';

	var config = window.wppdfImport || {};
	var i18n = config.i18n || {};
	var frame = null;
	var running = false;

	/**
	 * Append a line to the results area.
	 *
	 * @param {string}  message Text to show.
	 * @param {boolean} isError Whether it is a failure.
	 */
	function log( message, isError ) {
		$( '#wppdf-import-results' ).append(
			$( '<p />' ).addClass( isError ? 'wppdf-import__error' : '' ).text( message )
		);
	}

	/**
	 * Send one batch of attachments to the server.
	 *
	 * @param {Array} ids Attachment IDs.
	 * @return {Promise} Resolved when the batch finished.
	 */
	function importBatch( ids ) {
		return $.post( config.ajaxUrl, {
			action: 'wppdf_import',
			nonce: config.nonce,
			ids: ids,
			lang: $( '#wppdf-import-language' ).val(),
			status: $( '#wppdf-import-status' ).val(),
			category: $( '#wppdf-import-category' ).val() || 0
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				var message = response && response.data && response.data.message ? response.data.message : i18n.failed;
				log( message, true );
				return;
			}

			( response.data.created || [] ).forEach( function ( item ) {
				var $line = $( '<p />' );

				if ( item.edit ) {
					$line.append( $( '<a />' ).attr( 'href', item.edit ).text( item.title ) );
				} else {
					$line.text( item.title );
				}

				$line.append( document.createTextNode( ' — ' + i18n.created ) );
				$( '#wppdf-import-results' ).append( $line );
			} );

			( response.data.skipped || [] ).forEach( function ( item ) {
				log( '#' + item.id + ' — ' + item.reason, true );
			} );
		} ).fail( function () {
			log( i18n.failed, true );
		} );
	}

	/**
	 * Walk the selection in batches so no single request runs long.
	 *
	 * @param {Array} ids Attachment IDs.
	 */
	function importAll( ids ) {
		var batchSize = config.batch || 20;
		var queue = ids.slice();
		var total = ids.length;
		var done = 0;

		running = true;
		$( '.wppdf-import__spinner' ).addClass( 'is-active' );

		function next() {
			if ( ! queue.length ) {
				running = false;
				$( '.wppdf-import__spinner' ).removeClass( 'is-active' );
				log( i18n.finished.replace( '%d', total ) );
				return;
			}

			var batch = queue.splice( 0, batchSize );

			importBatch( batch ).always( function () {
				done += batch.length;
				next();
			} );
		}

		next();
	}

	// --- Import from another plugin ---------------------------------------

	/**
	 * Show the URL prefix of the selected source and offer to take it over.
	 */
	function refreshSourceUrls() {
		var $option = $( '#wppdf-migrate-source option:selected' );
		var slug = $option.data( 'slug' );
		var active = '1' === String( $option.data( 'active' ) );
		var $button = $( '#wppdf-adopt-slug' );

		$( '#wppdf-adopt-result' ).text( '' );

		if ( ! slug ) {
			$( '#wppdf-migrate-oldurl' ).text( i18n.slugUnknown || '' );
			$button.prop( 'hidden', true );
			return;
		}

		$( '#wppdf-migrate-oldurl' ).html(
			' ' + ( i18n.oldPrefix || '%s' ).replace( '%s', '<code>/' + slug + '/</code>' )
		);

		$button.data( 'slug', slug ).prop( 'hidden', false );
		$button.text( ( i18n.adopt || 'Take over %s' ).replace( '%s', '/' + slug + '/' ) );

		if ( active ) {
			$( '#wppdf-adopt-result' ).text( i18n.stillActive || '' );
		}
	}

	$( document ).on( 'change', '#wppdf-migrate-source', refreshSourceUrls );
	$( refreshSourceUrls );

	$( document ).on( 'click', '#wppdf-adopt-slug', function ( event ) {
		event.preventDefault();

		var $button = $( this );

		$button.prop( 'disabled', true );

		$.post( config.ajaxUrl, {
			action: 'wppdf_adopt_slug',
			nonce: $button.data( 'nonce' ),
			slug: $button.data( 'slug' )
		} ).done( function ( response ) {
			var message = response && response.data && response.data.message ? response.data.message : i18n.failed;
			$( '#wppdf-adopt-result' ).text( message );
		} ).fail( function () {
			$( '#wppdf-adopt-result' ).text( i18n.failed );
		} ).always( function () {
			$button.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'click', '#wppdf-migrate-start', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var source = $( '#wppdf-migrate-source' ).val();
		var imported = 0;

		if ( $button.prop( 'disabled' ) || ! source ) {
			return;
		}

		$button.prop( 'disabled', true );
		$( '.wppdf-migrate__spinner' ).addClass( 'is-active' );
		$( '#wppdf-migrate-results' ).empty();

		/**
		 * Add one line to the migration log.
		 *
		 * @param {string}  message Text to show.
		 * @param {boolean} isError Whether it is a failure.
		 * @param {string}  href    Optional link target.
		 */
		function line( message, isError, href ) {
			var $line = $( '<p />' ).addClass( isError ? 'wppdf-import__error' : '' );

			if ( href ) {
				$line.append( $( '<a />' ).attr( 'href', href ).text( message ) );
			} else {
				$line.text( message );
			}

			$( '#wppdf-migrate-results' ).append( $line );
		}

		/**
		 * Walk the source one batch at a time.
		 *
		 * @param {boolean} first Whether this is the first batch of the run.
		 */
		function step( first ) {
			$.post( config.ajaxUrl, {
				action: 'wppdf_migrate',
				nonce: $button.data( 'nonce' ),
				source: source,
				lang: $( '#wppdf-migrate-language' ).val(),
				status: $( '#wppdf-migrate-status' ).val(),
				reset: first ? 1 : 0
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					finish( response && response.data && response.data.message ? response.data.message : i18n.failed, true );
					return;
				}

				( response.data.imported || [] ).forEach( function ( item ) {
					imported++;
					var suffix = item.notes && item.notes.length ? ' — ' + item.notes.join( ', ' ) : '';
					line( item.title + suffix, false, item.edit );
				} );

				( response.data.skipped || [] ).forEach( function ( item ) {
					line( item.title + ' — ' + item.reason, true );
				} );

				if ( response.data.done ) {
					finish( i18n.migrated.replace( '%1$d', imported ).replace( '%2$d', response.data.left ) );
					return;
				}

				step( false );
			} ).fail( function () {
				finish( i18n.failed, true );
			} );
		}

		/**
		 * Stop and report.
		 *
		 * @param {string}  message Message to show.
		 * @param {boolean} isError Whether it is a failure.
		 */
		function finish( message, isError ) {
			$( '.wppdf-migrate__spinner' ).removeClass( 'is-active' );
			$button.prop( 'disabled', false );
			line( message, isError );
		}

		step( true );
	} );

	// --- Picking records by hand ------------------------------------------

	var previewOffset = 0;

	/**
	 * Update the "n of m selected" line and the state of the import button.
	 */
	function refreshSelection() {
		var $rows = $( '#wppdf-migrate-picker tbody input[type="checkbox"]' );
		var selected = $rows.filter( ':checked' ).length;

		$( '.wppdf-migrate__count' ).text(
			( i18n.selectedCount || '%1$d of %2$d selected' )
				.replace( '%1$d', selected )
				.replace( '%2$d', $rows.length )
		);

		$( '#wppdf-migrate-selected' ).prop( 'disabled', 0 === selected );
	}

	/**
	 * Append one page of records to the picker.
	 *
	 * @param {Array} rows Records as returned by the preview endpoint.
	 */
	function renderRows( rows ) {
		var $body = $( '#wppdf-migrate-picker tbody' );

		rows.forEach( function ( row ) {
			var $check = $( '<input />' )
				.attr( { type: 'checkbox', value: row.id, id: 'wppdf-pick-' + row.id } )
				// A record with no PDF is the reason this screen exists, so it
				// starts unticked rather than merely marked.
				.prop( 'checked', !! row.hasPdf )
				.attr( 'data-has-pdf', row.hasPdf ? '1' : '0' );

			var $title = $( '<td />' );

			$( '<label />' )
				.attr( 'for', 'wppdf-pick-' + row.id )
				.text( row.title )
				.appendTo( $title );

			if ( row.status && 'publish' !== row.status ) {
				$title.append( ' ' ).append( $( '<span />' ).addClass( 'wppdf-migrate__status' ).text( '(' + row.status + ')' ) );
			}

			if ( row.edit ) {
				$title.append( ' ' ).append(
					$( '<a />' ).attr( { href: row.edit, target: '_blank', rel: 'noopener' } ).text( i18n.viewRecord || 'view' )
				);
			}

			var $pdf = $( '<td />' );

			if ( row.hasPdf ) {
				$pdf.text( row.file || '✓' );

				if ( row.pages ) {
					$pdf.append( $( '<span />' ).addClass( 'wppdf-migrate__pages' ).text( ' · ' + row.pages ) );
				}
			} else {
				$pdf.addClass( 'wppdf-migrate__nopdf' ).text( i18n.noPdf || 'no PDF' );
			}

			$( '<tr />' )
				.append( $( '<th />' ).addClass( 'check-column' ).attr( 'scope', 'row' ).append( $check ) )
				.append( $title )
				.append( $pdf )
				.append( $( '<td />' ).text( ( row.terms || [] ).join( ', ' ) ) )
				.appendTo( $body );
		} );

		refreshSelection();
	}

	/**
	 * Fetch one page of records to choose from.
	 *
	 * @param {jQuery}  $button Button that triggered the load.
	 * @param {boolean} reset   Whether to start the list over.
	 */
	function loadPreview( $button, reset ) {
		var source = $( '#wppdf-migrate-source' ).val();

		if ( ! source ) {
			return;
		}

		if ( reset ) {
			previewOffset = 0;
			$( '#wppdf-migrate-picker tbody' ).empty();
		}

		$button.prop( 'disabled', true );
		$( '.wppdf-migrate__spinner' ).addClass( 'is-active' );

		$.post( config.ajaxUrl, {
			action: 'wppdf_migrate_preview',
			nonce: $button.data( 'nonce' ) || $( '#wppdf-migrate-choose' ).data( 'nonce' ),
			source: source,
			offset: previewOffset
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$( '#wppdf-migrate-results' ).text(
					response && response.data && response.data.message ? response.data.message : i18n.failed
				);
				return;
			}

			previewOffset = response.data.offset;
			renderRows( response.data.rows || [] );

			$( '#wppdf-migrate-picker' ).prop( 'hidden', false );
			$( '#wppdf-migrate-more' ).prop( 'hidden', !! response.data.done );
		} ).fail( function () {
			$( '#wppdf-migrate-results' ).text( i18n.failed );
		} ).always( function () {
			$button.prop( 'disabled', false );
			$( '.wppdf-migrate__spinner' ).removeClass( 'is-active' );
		} );
	}

	$( document ).on( 'click', '#wppdf-migrate-choose', function ( event ) {
		event.preventDefault();
		loadPreview( $( this ), true );
	} );

	$( document ).on( 'click', '#wppdf-migrate-more', function ( event ) {
		event.preventDefault();
		loadPreview( $( this ), false );
	} );

	// Changing the source invalidates whatever is listed.
	$( document ).on( 'change', '#wppdf-migrate-source', function () {
		$( '#wppdf-migrate-picker' ).prop( 'hidden', true ).find( 'tbody' ).empty();
		previewOffset = 0;
	} );

	$( document ).on( 'click', '#wppdf-migrate-picker [data-wppdf-select]', function ( event ) {
		event.preventDefault();

		var mode = $( this ).data( 'wppdf-select' );

		$( '#wppdf-migrate-picker tbody input[type="checkbox"]' ).each( function () {
			var $box = $( this );

			if ( 'all' === mode ) {
				$box.prop( 'checked', true );
			} else if ( 'none' === mode ) {
				$box.prop( 'checked', false );
			} else {
				$box.prop( 'checked', '1' === $box.attr( 'data-has-pdf' ) );
			}
		} );

		refreshSelection();
	} );

	$( document ).on( 'change', '#wppdf-migrate-picker tbody input[type="checkbox"]', refreshSelection );

	$( document ).on( 'click', '#wppdf-migrate-selected', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var source = $( '#wppdf-migrate-source' ).val();
		var imported = 0;

		var queue = $( '#wppdf-migrate-picker tbody input[type="checkbox"]:checked' ).map( function () {
			return parseInt( this.value, 10 );
		} ).get();

		if ( $button.prop( 'disabled' ) || ! source || ! queue.length ) {
			return;
		}

		$button.prop( 'disabled', true );
		$( '.wppdf-migrate__spinner' ).addClass( 'is-active' );
		$( '#wppdf-migrate-results' ).empty();

		/**
		 * Add one line to the migration log.
		 *
		 * @param {string}  message Text to show.
		 * @param {boolean} isError Whether it is a failure.
		 * @param {string}  href    Optional link target.
		 */
		function line( message, isError, href ) {
			var $line = $( '<p />' ).addClass( isError ? 'wppdf-import__error' : '' );

			if ( href ) {
				$line.append( $( '<a />' ).attr( 'href', href ).text( message ) );
			} else {
				$line.text( message );
			}

			$( '#wppdf-migrate-results' ).append( $line );
		}

		/**
		 * Stop and report.
		 *
		 * @param {string}  message Message to show.
		 * @param {boolean} isError Whether it is a failure.
		 */
		function finish( message, isError ) {
			$( '.wppdf-migrate__spinner' ).removeClass( 'is-active' );
			$button.prop( 'disabled', false );
			line( message, isError );
		}

		/**
		 * Send the chosen records a batch at a time.
		 *
		 * The server takes at most one batch per request, so the queue is
		 * shortened by what it reports having handled rather than by a fixed
		 * number, and a request that moves nothing ends the run instead of
		 * looping forever.
		 */
		function step() {
			$.post( config.ajaxUrl, {
				action: 'wppdf_migrate',
				nonce: $button.data( 'nonce' ),
				source: source,
				lang: $( '#wppdf-migrate-language' ).val(),
				status: $( '#wppdf-migrate-status' ).val(),
				ids: queue
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					finish( response && response.data && response.data.message ? response.data.message : i18n.failed, true );
					return;
				}

				( response.data.imported || [] ).forEach( function ( item ) {
					imported++;
					var suffix = item.notes && item.notes.length ? ' — ' + item.notes.join( ', ' ) : '';
					line( item.title + suffix, false, item.edit );
				} );

				( response.data.skipped || [] ).forEach( function ( item ) {
					line( item.title + ' — ' + item.reason, true );
				} );

				var handled = response.data.processed || 0;

				queue = queue.slice( handled );

				if ( ! handled || ! queue.length ) {
					finish( i18n.migrated.replace( '%1$d', imported ).replace( '%2$d', response.data.left ) );
					return;
				}

				step();
			} ).fail( function () {
				finish( i18n.failed, true );
			} );
		}

		step();
	} );

	$( document ).on( 'click', '#wppdf-import-select', function ( event ) {
		event.preventDefault();

		if ( running || ! window.wp || ! window.wp.media ) {
			return;
		}

		if ( ! frame ) {
			frame = window.wp.media( {
				title: i18n.selectTitle,
				button: { text: i18n.selectButton },
				library: { type: 'application/pdf' },
				multiple: 'add'
			} );

			frame.on( 'select', function () {
				var ids = frame.state().get( 'selection' ).map( function ( item ) {
					return item.id;
				} );

				if ( ! ids.length ) {
					return;
				}

				$( '#wppdf-import-results' ).empty();
				importAll( ids );
			} );
		}

		frame.open();
	} );
} )( window.jQuery );
