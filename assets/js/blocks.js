/**
 * WP PDF Reader — editor blocks.
 *
 * Written without JSX so the plugin needs no build step.
 */
( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var ServerSideRender = serverSideRender;

	var data = window.wppdfBlocks || {};
	var languages = data.languages || [];
	var defaults = data.defaults || {};

	/**
	 * Language options for a select control.
	 *
	 * @return {Array} Options.
	 */
	function languageOptions() {
		var options = [ { label: __( 'Visitor language (with fallback)', 'wp-pdf-reader' ), value: '' } ];

		languages.forEach( function ( language ) {
			options.push( {
				label: language.label + ' (' + language.code + ')',
				value: language.code
			} );
		} );

		return options;
	}

	blocks.registerBlockType( 'wp-pdf-reader/reader', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Document', 'wp-pdf-reader' ) },
						el( TextControl, {
							label: __( 'Document ID', 'wp-pdf-reader' ),
							help: __( 'Leave empty to use the document the block is placed on.', 'wp-pdf-reader' ),
							type: 'number',
							value: attributes.postId || '',
							onChange: function ( value ) {
								setAttributes( { postId: parseInt( value, 10 ) || 0 } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Language', 'wp-pdf-reader' ),
							value: attributes.lang,
							options: languageOptions(),
							onChange: function ( value ) {
								setAttributes( { lang: value } );
							}
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Reader', 'wp-pdf-reader' ) },
						el( RangeControl, {
							label: __( 'Height (px)', 'wp-pdf-reader' ),
							value: attributes.height || defaults.height || 800,
							min: 200,
							max: 2000,
							step: 20,
							onChange: function ( value ) {
								setAttributes( { height: value } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Initial zoom', 'wp-pdf-reader' ),
							value: attributes.zoom,
							options: [
								{ label: __( 'Automatic', 'wp-pdf-reader' ), value: 'auto' },
								{ label: __( 'Fit width', 'wp-pdf-reader' ), value: 'page-width' },
								{ label: __( 'Fit page', 'wp-pdf-reader' ), value: 'page-fit' },
								{ label: '100 %', value: '100' },
								{ label: '125 %', value: '125' },
								{ label: '150 %', value: '150' }
							],
							onChange: function ( value ) {
								setAttributes( { zoom: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show the toolbar', 'wp-pdf-reader' ),
							checked: !! attributes.toolbar,
							onChange: function ( value ) {
								setAttributes( { toolbar: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show the download button', 'wp-pdf-reader' ),
							checked: !! attributes.download,
							onChange: function ( value ) {
								setAttributes( { download: value } );
							}
						} )
					)
				),
				el(
					'div',
					useBlockProps ? useBlockProps() : {},
					el( ServerSideRender, {
						block: 'wp-pdf-reader/reader',
						attributes: attributes
					} )
				)
			);
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'wp-pdf-reader/grid', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Layout', 'wp-pdf-reader' ) },
						el( SelectControl, {
							label: __( 'Layout', 'wp-pdf-reader' ),
							value: attributes.layout,
							options: [
								{ label: __( 'Grid', 'wp-pdf-reader' ), value: 'grid' },
								{ label: __( 'List', 'wp-pdf-reader' ), value: 'list' }
							],
							onChange: function ( value ) {
								setAttributes( { layout: value } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Columns', 'wp-pdf-reader' ),
							value: attributes.columns || defaults.columns || 3,
							min: 1,
							max: 6,
							onChange: function ( value ) {
								setAttributes( { columns: value } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Documents', 'wp-pdf-reader' ),
							value: attributes.perPage || defaults.perPage || 12,
							min: 1,
							max: 48,
							onChange: function ( value ) {
								setAttributes( { perPage: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show excerpts', 'wp-pdf-reader' ),
							checked: !! attributes.excerpt,
							onChange: function ( value ) {
								setAttributes( { excerpt: value } );
							}
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Filtering', 'wp-pdf-reader' ) },
						el( TextControl, {
							label: __( 'Category slug', 'wp-pdf-reader' ),
							value: attributes.category,
							onChange: function ( value ) {
								setAttributes( { category: value } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Order by', 'wp-pdf-reader' ),
							value: attributes.orderby,
							options: [
								{ label: __( 'Date', 'wp-pdf-reader' ), value: 'date' },
								{ label: __( 'Title', 'wp-pdf-reader' ), value: 'title' },
								{ label: __( 'Menu order', 'wp-pdf-reader' ), value: 'menu_order' },
								{ label: __( 'Random', 'wp-pdf-reader' ), value: 'rand' }
							],
							onChange: function ( value ) {
								setAttributes( { orderby: value } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Order', 'wp-pdf-reader' ),
							value: attributes.order,
							options: [
								{ label: __( 'Descending', 'wp-pdf-reader' ), value: 'DESC' },
								{ label: __( 'Ascending', 'wp-pdf-reader' ), value: 'ASC' }
							],
							onChange: function ( value ) {
								setAttributes( { order: value } );
							}
						} )
					)
				),
				el(
					'div',
					useBlockProps ? useBlockProps() : {},
					el( ServerSideRender, {
						block: 'wp-pdf-reader/grid',
						attributes: attributes
					} )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender
);
