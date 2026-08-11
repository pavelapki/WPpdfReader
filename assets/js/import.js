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
