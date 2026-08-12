/**
 * WP PDF Reader — admin scripts.
 *
 * Media picker for the per-language files and the repeatable language list
 * on the settings screen.
 */
( function ( $ ) {
	'use strict';

	var i18n = ( window.wppdfAdmin && window.wppdfAdmin.i18n ) || {};
	var frames = {};

	/**
	 * Open the media library filtered to PDFs.
	 *
	 * @param {jQuery} $row Meta box row.
	 */
	function openPicker( $row ) {
		var lang = $row.data( 'lang' );

		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		if ( ! frames[ lang ] ) {
			frames[ lang ] = window.wp.media( {
				title: i18n.selectTitle || 'Select a PDF file',
				button: { text: i18n.selectButton || 'Use this PDF' },
				library: { type: 'application/pdf' },
				multiple: false
			} );

			frames[ lang ].on( 'select', function () {
				var attachment = frames[ lang ].state().get( 'selection' ).first().toJSON();

				$row.find( '.wppdf-file-id' ).val( attachment.id );
				$row.find( '.wppdf-file-name' ).text( attachment.filename || attachment.title ).prop( 'hidden', false );
				$row.find( '.wppdf-file-size' )
					.text( attachment.filesizeHumanReadable || '' )
					.prop( 'hidden', ! attachment.filesizeHumanReadable );
				$row.find( '.wppdf-file-empty' ).prop( 'hidden', true );
				$row.find( '.wppdf-remove' ).prop( 'hidden', false );
				$row.addClass( 'is-filled' );
			} );
		}

		frames[ lang ].open();
	}

	/**
	 * Clear the file of a row.
	 *
	 * @param {jQuery} $row Meta box row.
	 */
	function clearRow( $row ) {
		$row.find( '.wppdf-file-id' ).val( 0 );
		$row.find( '.wppdf-file-name' ).text( '' ).prop( 'hidden', true );
		$row.find( '.wppdf-file-size' ).text( '' ).prop( 'hidden', true );
		$row.find( '.wppdf-file-empty' ).text( i18n.noFile || 'No file for this language.' ).prop( 'hidden', false );
		$row.find( '.wppdf-remove' ).prop( 'hidden', true );
		$row.removeClass( 'is-filled' );

		if ( ! $row.find( '.wppdf-file-row__url input' ).val() ) {
			$row.removeClass( 'is-filled' );
		}
	}

	$( document ).on( 'click', '.wppdf-file-row .wppdf-select', function ( event ) {
		event.preventDefault();
		openPicker( $( this ).closest( '.wppdf-file-row' ) );
	} );

	$( document ).on( 'click', '.wppdf-file-row .wppdf-remove', function ( event ) {
		event.preventDefault();
		clearRow( $( this ).closest( '.wppdf-file-row' ) );
	} );

	// --- Settings screen: repeatable languages -----------------------------

	$( document ).on( 'click', '#wppdf-add-language', function ( event ) {
		event.preventDefault();

		var $wrapper = $( '#wppdf-language-rows' );
		var option = $wrapper.data( 'option' );
		var index = 'new' + Date.now();

		var $row = $( '<div class="wppdf-language-row" />' );

		$( '<input type="text" class="wppdf-language-code" size="6" />' )
			.attr( 'name', option + '[languages][' + index + '][code]' )
			.attr( 'placeholder', i18n.code || 'code' )
			.appendTo( $row );

		$( '<input type="text" />' )
			.attr( 'name', option + '[languages][' + index + '][label]' )
			.attr( 'placeholder', i18n.label || 'label' )
			.appendTo( $row );

		$( '<button type="button" class="button-link wppdf-remove-language">&times;</button>' )
			.attr( 'aria-label', i18n.removeRow || 'Remove language' )
			.appendTo( $row );

		$wrapper.append( $row );
		$row.find( 'input' ).first().trigger( 'focus' );
	} );

	// --- Settings screen: reindexing --------------------------------------

	$( document ).on( 'click', '#wppdf-reindex-start', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var force = $( '#wppdf-reindex-force' ).is( ':checked' ) ? 1 : 0;
		var indexed = 0;

		if ( $button.prop( 'disabled' ) ) {
			return;
		}

		$button.prop( 'disabled', true );
		$( '.wppdf-reindex__spinner' ).addClass( 'is-active' );
		$( '#wppdf-reindex-log' ).empty();

		/**
		 * Walk the queue one batch at a time.
		 *
		 * @param {number} offset Offset for forced runs.
		 */
		function step( offset ) {
			$.post( ( window.wppdfAdmin && window.wppdfAdmin.ajaxUrl ) || window.ajaxurl, {
				action: 'wppdf_reindex',
				nonce: $button.data( 'nonce' ),
				force: force,
				offset: offset
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					finish( i18n.reindexError, true );
					return;
				}

				( response.data.processed || [] ).forEach( function ( item ) {
					indexed++;
					$( '#wppdf-reindex-log' ).append(
						$( '<p />' ).text( item.title + ( item.pages ? ' — ' + item.pages + ' s.' : '' ) )
					);
				} );

				$( '#wppdf-reindex-status' ).text(
					( i18n.reindexed || '%1$d indexed, %2$d left.' )
						.replace( '%1$d', indexed )
						.replace( '%2$d', response.data.pending )
				);

				if ( response.data.done ) {
					finish( i18n.reindexDone );
					return;
				}

				step( response.data.offset );
			} ).fail( function () {
				finish( i18n.reindexError, true );
			} );
		}

		/**
		 * Stop and report.
		 *
		 * @param {string}  message Message to show.
		 * @param {boolean} isError Whether it is a failure.
		 */
		function finish( message, isError ) {
			$( '.wppdf-reindex__spinner' ).removeClass( 'is-active' );
			$button.prop( 'disabled', false );
			$( '#wppdf-reindex-log' ).append(
				$( '<p />' ).addClass( isError ? 'wppdf-import__error' : '' ).text( message )
			);
		}

		step( 0 );
	} );

	$( document ).on( 'click', '.wppdf-remove-language', function ( event ) {
		event.preventDefault();

		var $rows = $( '#wppdf-language-rows .wppdf-language-row' );

		if ( $rows.length < 2 ) {
			return;
		}

		if ( window.confirm( i18n.confirmRow || 'Remove this language?' ) ) {
			$( this ).closest( '.wppdf-language-row' ).remove();
		}
	} );
} )( window.jQuery );
