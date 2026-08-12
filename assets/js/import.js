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
