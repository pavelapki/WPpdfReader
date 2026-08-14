/**
 * WP PDF Reader — front-end reader built on PDF.js.
 *
 * Continuous scrolling with on-demand page rendering, a bounded number of
 * rendered pages so long documents do not grow without limit, zoom, search
 * across the whole document, language switching and a selectable text layer.
 */
( function () {
	'use strict';

	var settings = window.wppdfSettings || {};
	var i18n = settings.i18n || {};
	var MAX_DPR = 2;
	var MIN_SCALE = 0.25;
	var MAX_SCALE = 5;

	// How many pages around the current one keep their rendered canvas.
	var RENDER_WINDOW = 3;

	var libPromise = null;

	/**
	 * Load PDF.js on demand.
	 *
	 * PDF.js 4 ships as an ES module, so it is pulled in with a dynamic import
	 * the first time a reader actually needs it — pages without a reader never
	 * download it. The import is built through the Function constructor so that
	 * browsers too old to parse `import()` fail here instead of breaking the
	 * whole script; they fall back to the plain download link.
	 *
	 * @return {Promise} Resolves with the PDF.js module.
	 */
	function loadPdfJs() {
		if ( libPromise ) {
			return libPromise;
		}

		libPromise = new Promise( function ( resolve, reject ) {
			if ( ! settings.libSrc ) {
				reject( new Error( 'WP PDF Reader: missing library URL.' ) );
				return;
			}

			var dynamicImport;

			try {
				dynamicImport = new Function( 'url', 'return import( url );' );
			} catch ( e ) {
				reject( new Error( 'WP PDF Reader: dynamic import is not supported.' ) );
				return;
			}

			dynamicImport( settings.libSrc ).then( function ( lib ) {
				if ( settings.workerSrc ) {
					lib.GlobalWorkerOptions.workerSrc = settings.workerSrc;
				}

				resolve( lib );
			}, function ( error ) {
				// A failed module import says only "Failed to fetch dynamically
				// imported module", which sends people looking for a broken file
				// when the file is usually fine and the server is the problem:
				// either it 404s or it answers without a JavaScript MIME type.
				reject(
					new Error(
						'WP PDF Reader: could not load ' + settings.libSrc +
						'. Check that the file exists and that the server sends it ' +
						'with a JavaScript Content-Type. Original error: ' +
						( error && error.message ? error.message : error )
					)
				);
			} );
		} );

		return libPromise;
	}

	/**
	 * Lowercase and strip diacritics so "Zprava" also finds "Zpráva".
	 *
	 * Returns the folded string together with a map from folded index back to
	 * the index in the original string, so matches can be highlighted in the
	 * text that is actually on screen.
	 *
	 * @param {string} text Original text.
	 * @return {Object} Object with `text` and `map`.
	 */
	function fold( text ) {
		var out = '';
		var map = [];

		for ( var i = 0; i < text.length; i++ ) {
			var folded = text[ i ].toLowerCase();

			if ( String.prototype.normalize ) {
				folded = folded.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
			}

			for ( var j = 0; j < folded.length; j++ ) {
				out += folded[ j ];
				map.push( i );
			}
		}

		map.push( text.length );

		return { text: out, map: map };
	}

	/**
	 * One reader instance.
	 *
	 * @param {HTMLElement} root Reader wrapper.
	 */
	function Viewer( root ) {
		this.root = root;
		this.config = parseConfig( root );
		this.pages = [];
		this.scale = 1;
		this.baseViewport = null;
		this.pdf = null;
		this.lib = null;
		this.currentPage = 1;
		this.zoomMode = this.config.zoom || 'auto';
		this.destroyed = false;
		this.matches = [];
		this.matchIndex = -1;
		this.term = '';
		this.indexed = false;

		this.stage = root.querySelector( '.wppdf-viewer__pages' );
		this.status = root.querySelector( '.wppdf-viewer__status' );
		this.statusText = root.querySelector( '.wppdf-status-text' );
		this.live = root.querySelector( '.wppdf-live' );

		if ( ! this.stage || ! this.config.url ) {
			return;
		}

		this.bindToolbar();
		this.bindKeyboard();
		this.observeResize();

		if ( this.config.lazy && 'IntersectionObserver' in window ) {
			this.observeVisibility();
		} else {
			this.load();
		}
	}

	/**
	 * Read the JSON config from the wrapper.
	 *
	 * @param {HTMLElement} root Reader wrapper.
	 * @return {Object} Config.
	 */
	function parseConfig( root ) {
		try {
			return JSON.parse( root.getAttribute( 'data-wppdf' ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	Viewer.prototype.observeVisibility = function () {
		var self = this;
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						observer.disconnect();
						self.load();
					}
				} );
			},
			{ rootMargin: '300px 0px' }
		);

		observer.observe( this.root );
	};

	Viewer.prototype.setStatus = function ( message, isError ) {
		if ( ! this.status ) {
			return;
		}

		if ( ! message ) {
			this.status.hidden = true;
			this.status.classList.remove( 'is-error' );
			return;
		}

		this.status.hidden = false;
		this.status.classList.toggle( 'is-error', !! isError );

		if ( this.statusText ) {
			this.statusText.textContent = message;
		}
	};

	/**
	 * Announce something to screen readers.
	 *
	 * @param {string} message Text to announce.
	 */
	Viewer.prototype.announce = function ( message ) {
		if ( this.live ) {
			this.live.textContent = message;
		}
	};

	Viewer.prototype.load = function () {
		var self = this;

		if ( this.loading || this.pdf ) {
			return;
		}

		this.loading = true;
		this.setStatus( i18n.loading || 'Loading…' );

		loadPdfJs()
			.then( function ( lib ) {
				self.lib = lib;

				var params = {
					url: self.config.url,
					// Documents must never be able to run script during parsing.
					isEvalSupported: false
				};

				if ( settings.standardFontDataUrl ) {
					params.standardFontDataUrl = settings.standardFontDataUrl;
				}

				if ( settings.cMapUrl ) {
					params.cMapUrl = settings.cMapUrl;
					params.cMapPacked = true;
				}

				var task = lib.getDocument( params );
				self.task = task;

				task.onProgress = function ( progress ) {
					if ( progress.total && self.statusText ) {
						var percent = Math.round( ( progress.loaded / progress.total ) * 100 );
						self.statusText.textContent = ( i18n.loading || 'Loading…' ) + ' ' + Math.min( 100, percent ) + ' %';
					}
				};

				return task.promise;
			} )
			.then( function ( pdf ) {
				self.loading = false;
				self.pdf = pdf;
				self.root.classList.add( 'is-loaded' );
				self.countHit( 'view' );

				return self.setup();
			} )
			.catch( function ( error ) {
				self.loading = false;
				self.root.classList.add( 'is-error' );
				self.setStatus( i18n.error || 'The document could not be loaded.', true );

				if ( window.console && window.console.warn ) {
					window.console.warn( 'WP PDF Reader:', error );
				}
			} );
	};

	Viewer.prototype.setup = function () {
		var self = this;

		return this.pdf.getPage( 1 ).then( function ( page ) {
			self.baseViewport = page.getViewport( { scale: 1 } );
			self.scale = self.computeScale( self.zoomMode );
			self.buildPages();
			self.setStatus( '' );
			self.updateToolbar();
			self.observePages();
			self.setupSidebar();

			var start = self.applyDeepLink() || self.config.page || 1;
			start = Math.min( Math.max( 1, start ), self.pdf.numPages );

			if ( start > 1 ) {
				self.goToPage( start, false );
			}

			self.markCurrentThumbnail();
			self.renderVisible();
		} );
	};

	/**
	 * Translate a zoom mode into a scale factor.
	 *
	 * @param {string} mode Zoom mode.
	 * @return {number} Scale.
	 */
	Viewer.prototype.computeScale = function ( mode ) {
		if ( ! this.baseViewport ) {
			return 1;
		}

		var available = this.stage.clientWidth - 24;
		var height = this.stage.clientHeight - 24;

		if ( available <= 0 ) {
			available = this.root.clientWidth - 24;
		}

		switch ( mode ) {
			case 'page-width':
				return clamp( available / this.baseViewport.width );
			case 'page-fit':
				return clamp( Math.min( available / this.baseViewport.width, height / this.baseViewport.height ) );
			case 'auto':
				// Fit the width on small screens, cap the enlargement on large ones.
				return clamp( Math.min( available / this.baseViewport.width, 1.5 ) );
			default:
				var numeric = parseFloat( mode );
				return isNaN( numeric ) ? 1 : clamp( numeric / 100 );
		}
	};

	function clamp( scale ) {
		return Math.min( MAX_SCALE, Math.max( MIN_SCALE, scale ) );
	}

	Viewer.prototype.buildPages = function () {
		var self = this;

		this.stage.innerHTML = '';
		this.pages = [];

		for ( var number = 1; number <= this.pdf.numPages; number++ ) {
			var element = document.createElement( 'div' );
			element.className = 'wppdf-page';
			element.setAttribute( 'data-page', number );
			element.setAttribute( 'aria-label', format( i18n.pageOf || 'Page %1$d of %2$d', number, this.pdf.numPages ) );

			var canvas = document.createElement( 'canvas' );
			canvas.className = 'wppdf-page__canvas';
			element.appendChild( canvas );

			var textLayer = document.createElement( 'div' );
			textLayer.className = 'wppdf-page__text textLayer';
			element.appendChild( textLayer );

			this.stage.appendChild( element );

			this.pages.push( {
				number: number,
				element: element,
				canvas: canvas,
				textLayer: textLayer,
				rendered: false,
				rendering: false,
				task: null,
				textTask: null,
				viewport: null,
				index: null
			} );
		}

		this.pages.forEach( function ( page ) {
			self.sizePage( page );
		} );
	};

	/**
	 * Give a page its box before it is rendered, so scrolling stays stable.
	 *
	 * @param {Object} page Page record.
	 */
	Viewer.prototype.sizePage = function ( page ) {
		var viewport = page.viewport || this.baseViewport;

		if ( ! viewport ) {
			return;
		}

		var width = Math.floor( viewport.width * this.scale );
		var height = Math.floor( viewport.height * this.scale );

		page.element.style.width = width + 'px';
		page.element.style.height = height + 'px';
		page.element.style.setProperty( '--scale-factor', this.scale );
	};

	Viewer.prototype.observePages = function () {
		var self = this;

		if ( this.pageObserver ) {
			this.pageObserver.disconnect();
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			this.renderVisible();
			return;
		}

		this.pageObserver = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					var page = self.pages[ parseInt( entry.target.getAttribute( 'data-page' ), 10 ) - 1 ];

					if ( page && entry.isIntersecting ) {
						self.renderPage( page );
					}
				} );

				self.trackCurrentPage();
			},
			{ root: this.stage, rootMargin: '200px 0px' }
		);

		this.pages.forEach( function ( page ) {
			self.pageObserver.observe( page.element );
		} );

		if ( ! this.scrollBound ) {
			this.scrollBound = true;
			this.stage.addEventListener( 'scroll', throttle( function () {
				self.trackCurrentPage();
			}, 120 ) );
		}
	};

	Viewer.prototype.renderVisible = function () {
		var self = this;
		var stageTop = this.stage.scrollTop;
		var stageBottom = stageTop + this.stage.clientHeight + 200;

		this.pages.forEach( function ( page ) {
			var top = page.element.offsetTop;
			var bottom = top + page.element.offsetHeight;

			if ( bottom >= stageTop - 200 && top <= stageBottom ) {
				self.renderPage( page );
			}
		} );
	};

	Viewer.prototype.renderPage = function ( page ) {
		var self = this;

		if ( ! this.pdf || page.rendered || page.rendering ) {
			return;
		}

		page.rendering = true;

		this.pdf.getPage( page.number ).then( function ( pdfPage ) {
			if ( self.destroyed ) {
				return;
			}

			var viewport = pdfPage.getViewport( { scale: self.scale } );
			page.viewport = pdfPage.getViewport( { scale: 1 } );
			self.sizePage( page );

			var dpr = Math.min( MAX_DPR, window.devicePixelRatio || 1 );
			var context = page.canvas.getContext( '2d', { alpha: false } );

			page.canvas.width = Math.floor( viewport.width * dpr );
			page.canvas.height = Math.floor( viewport.height * dpr );
			page.canvas.style.width = Math.floor( viewport.width ) + 'px';
			page.canvas.style.height = Math.floor( viewport.height ) + 'px';

			page.task = pdfPage.render( {
				canvasContext: context,
				viewport: viewport,
				transform: dpr !== 1 ? [ dpr, 0, 0, dpr, 0, 0 ] : null
			} );

			return page.task.promise
				.then( function () {
					page.rendered = true;
					page.rendering = false;
					page.element.classList.add( 'is-rendered' );

					return self.renderTextLayer( pdfPage, page, viewport );
				} )
				.then( function () {
					return self.renderLinks( pdfPage, page, viewport );
				} )
				.then( function () {
					self.highlightPage( page );
					self.evict();
				} )
				.catch( function ( error ) {
					page.rendering = false;

					if ( error && 'RenderingCancelledException' !== error.name && window.console ) {
						window.console.warn( 'WP PDF Reader:', error );
					}
				} );
		} ).catch( function () {
			page.rendering = false;
		} );
	};

	/**
	 * Draw the selectable text layer. Failures are never fatal.
	 *
	 * @param {Object} pdfPage  PDF.js page.
	 * @param {Object} page     Page record.
	 * @param {Object} viewport Scaled viewport.
	 * @return {Promise} Resolved when done.
	 */
	Viewer.prototype.renderTextLayer = function ( pdfPage, page, viewport ) {
		var self = this;

		if ( ! this.lib || ! this.lib.TextLayer ) {
			return Promise.resolve();
		}

		return pdfPage.getTextContent().then( function ( textContent ) {
			page.textLayer.innerHTML = '';

			var layer = new self.lib.TextLayer( {
				textContentSource: textContent,
				container: page.textLayer,
				viewport: viewport
			} );

			page.textTask = layer;

			return layer.render();
		} ).catch( function () {
			// A missing text layer only costs selectable text, never the page.
			return Promise.resolve();
		} );
	};

	/**
	 * Place clickable areas over the link annotations of a page.
	 *
	 * PDF.js ships a full annotation layer, but it needs a link service and
	 * brings form widgets and popups with it. Links are what a document
	 * library actually needs, so they are drawn directly from the annotation
	 * rectangles instead.
	 *
	 * @param {Object} pdfPage  PDF.js page.
	 * @param {Object} page     Page record.
	 * @param {Object} viewport Scaled viewport.
	 * @return {Promise} Resolved when the links are placed.
	 */
	Viewer.prototype.renderLinks = function ( pdfPage, page, viewport ) {
		var self = this;

		return pdfPage.getAnnotations( { intent: 'display' } ).then( function ( annotations ) {
			if ( page.linkLayer && page.linkLayer.parentNode ) {
				page.linkLayer.parentNode.removeChild( page.linkLayer );
			}

			var layer = document.createElement( 'div' );
			layer.className = 'wppdf-page__links';
			page.linkLayer = layer;

			annotations.forEach( function ( annotation ) {
				if ( 'Link' !== annotation.subtype || ! annotation.rect ) {
					return;
				}

				var rect = viewport.convertToViewportRectangle( annotation.rect );
				var left = Math.min( rect[ 0 ], rect[ 2 ] );
				var top = Math.min( rect[ 1 ], rect[ 3 ] );
				var width = Math.abs( rect[ 2 ] - rect[ 0 ] );
				var height = Math.abs( rect[ 3 ] - rect[ 1 ] );

				if ( width < 1 || height < 1 ) {
					return;
				}

				var element = self.buildLink( annotation );

				if ( ! element ) {
					return;
				}

				element.className = 'wppdf-link';
				element.style.left = left + 'px';
				element.style.top = top + 'px';
				element.style.width = width + 'px';
				element.style.height = height + 'px';

				layer.appendChild( element );
			} );

			if ( layer.childNodes.length ) {
				page.element.appendChild( layer );
			} else {
				page.linkLayer = null;
			}
		} ).catch( function () {
			return Promise.resolve();
		} );
	};

	/**
	 * Build the element for one link annotation.
	 *
	 * @param {Object} annotation PDF.js annotation.
	 * @return {HTMLElement|null} Anchor or button.
	 */
	Viewer.prototype.buildLink = function ( annotation ) {
		var self = this;

		if ( annotation.url ) {
			if ( ! /^(https?|mailto):/i.test( annotation.url ) ) {
				return null;
			}

			var anchor = document.createElement( 'a' );
			anchor.href = annotation.url;
			anchor.target = '_blank';
			anchor.rel = 'noopener noreferrer';
			anchor.title = annotation.url;

			return anchor;
		}

		if ( ! annotation.dest ) {
			return null;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			self.goToDestination( annotation.dest );
		} );

		return button;
	};

	/**
	 * Resolve a PDF destination and scroll to it.
	 *
	 * @param {Array|string} dest Destination array or named destination.
	 */
	Viewer.prototype.goToDestination = function ( dest ) {
		var self = this;

		if ( ! this.pdf || ! dest ) {
			return;
		}

		var resolve = 'string' === typeof dest ? this.pdf.getDestination( dest ) : Promise.resolve( dest );

		resolve.then( function ( target ) {
			if ( ! target || ! target.length ) {
				return null;
			}

			var reference = target[ 0 ];

			if ( reference && 'object' === typeof reference ) {
				return self.pdf.getPageIndex( reference );
			}

			// Some documents store a plain page index instead of a reference.
			return 'number' === typeof reference ? reference : null;
		} ).then( function ( index ) {
			if ( null === index || 'undefined' === typeof index ) {
				return;
			}

			self.goToPage( index + 1 );
		} ).catch( function () {} );
	};

	/**
	 * Drop rendered canvases far away from the current page.
	 *
	 * Without this a long document keeps every page it ever showed in memory.
	 */
	Viewer.prototype.evict = function () {
		var current = this.currentPage;

		for ( var i = 0; i < this.pages.length; i++ ) {
			var page = this.pages[ i ];

			if ( ! page.rendered || Math.abs( page.number - current ) <= RENDER_WINDOW ) {
				continue;
			}

			this.releasePage( page );
		}
	};

	/**
	 * Free the memory of one rendered page, keeping its placeholder box.
	 *
	 * @param {Object} page Page record.
	 */
	Viewer.prototype.releasePage = function ( page ) {
		if ( page.task && page.task.cancel ) {
			try {
				page.task.cancel();
			} catch ( e ) {}
		}

		if ( page.textTask && page.textTask.cancel ) {
			try {
				page.textTask.cancel();
			} catch ( e ) {}
		}

		page.task = null;
		page.textTask = null;
		page.rendered = false;
		page.rendering = false;
		page.element.classList.remove( 'is-rendered' );
		page.textLayer.innerHTML = '';

		if ( page.linkLayer && page.linkLayer.parentNode ) {
			page.linkLayer.parentNode.removeChild( page.linkLayer );
		}

		page.linkLayer = null;

		// Setting the dimensions to zero is what actually releases the backing
		// store in every engine.
		page.canvas.width = 0;
		page.canvas.height = 0;
		page.canvas.style.width = '';
		page.canvas.style.height = '';
	};

	Viewer.prototype.rerender = function () {
		var self = this;

		if ( ! this.pdf ) {
			return;
		}

		var anchor = this.currentPage;

		this.pages.forEach( function ( page ) {
			self.releasePage( page );
			self.sizePage( page );
		} );

		this.goToPage( anchor, false );
		this.renderVisible();
		this.updateToolbar();
	};

	Viewer.prototype.setZoom = function ( mode ) {
		this.zoomMode = mode;
		this.scale = this.computeScale( mode );
		this.rerender();
	};

	Viewer.prototype.zoomBy = function ( delta ) {
		this.scale = clamp( this.scale + delta );
		this.zoomMode = String( Math.round( this.scale * 100 ) );
		this.rerender();
	};

	Viewer.prototype.goToPage = function ( number, smooth ) {
		number = Math.min( Math.max( 1, number ), this.pages.length );

		var page = this.pages[ number - 1 ];

		if ( ! page ) {
			return;
		}

		this.currentPage = number;
		this.stage.scrollTo( {
			top: page.element.offsetTop - 12,
			behavior: smooth === false ? 'auto' : 'smooth'
		} );

		this.updateToolbar();
		this.markCurrentThumbnail();
		this.renderVisible();
	};

	Viewer.prototype.trackCurrentPage = function () {
		var middle = this.stage.scrollTop + this.stage.clientHeight / 3;
		var current = this.currentPage;

		for ( var i = 0; i < this.pages.length; i++ ) {
			var element = this.pages[ i ].element;

			if ( element.offsetTop <= middle && element.offsetTop + element.offsetHeight > middle ) {
				current = i + 1;
				break;
			}
		}

		if ( current !== this.currentPage ) {
			this.currentPage = current;
			this.updateToolbar();
			this.markCurrentThumbnail();
			this.announce( format( i18n.pageOf || 'Page %1$d of %2$d', current, this.pages.length ) );
			this.evict();
		}
	};

	// --- Search ------------------------------------------------------------

	/**
	 * Build the folded text index of every page, once per document.
	 *
	 * @return {Promise} Resolved when the whole document is indexed.
	 */
	Viewer.prototype.buildIndex = function () {
		var self = this;

		if ( this.indexed ) {
			return Promise.resolve();
		}

		if ( this.indexing ) {
			return this.indexing;
		}

		this.indexing = this.pages.reduce( function ( chain, page ) {
			return chain.then( function () {
				if ( page.index ) {
					return null;
				}

				return self.pdf.getPage( page.number )
					.then( function ( pdfPage ) {
						return pdfPage.getTextContent();
					} )
					.then( function ( textContent ) {
						var text = '';
						var items = [];

						textContent.items.forEach( function ( item ) {
							if ( 'undefined' === typeof item.str ) {
								return;
							}

							items.push( { start: text.length, end: text.length + item.str.length } );
							text += item.str;

							if ( item.hasEOL ) {
								text += '\n';
							}
						} );

						var folded = fold( text );

						page.index = {
							text: text,
							items: items,
							folded: folded.text,
							map: folded.map
						};
					} )
					.catch( function () {
						page.index = { text: '', items: [], folded: '', map: [] };
					} );
			} );
		}, Promise.resolve() ).then( function () {
			self.indexed = true;
			self.indexing = null;
		} );

		return this.indexing;
	};

	/**
	 * Run a search across the whole document.
	 *
	 * @param {string} term Raw search term.
	 */
	Viewer.prototype.find = function ( term ) {
		var self = this;

		term = ( term || '' ).trim();

		if ( term === this.term ) {
			return;
		}

		this.term = term;
		this.clearHighlights();
		this.matches = [];
		this.matchIndex = -1;

		if ( ! this.pdf || term.length < 2 ) {
			this.updateSearchUi();
			return;
		}

		if ( this.searchCount ) {
			this.searchCount.textContent = i18n.searching || 'Searching…';
		}

		this.buildIndex().then( function () {
			if ( self.term !== term ) {
				return;
			}

			var needle = fold( term ).text;

			self.pages.forEach( function ( page ) {
				if ( ! page.index || ! page.index.folded ) {
					return;
				}

				var haystack = page.index.folded;
				var from = 0;
				var found = haystack.indexOf( needle, from );

				while ( -1 !== found && self.matches.length < 5000 ) {
					self.matches.push( {
						page: page.number,
						start: page.index.map[ found ],
						end: page.index.map[ Math.min( found + needle.length, page.index.map.length - 1 ) ]
					} );

					from = found + needle.length;
					found = haystack.indexOf( needle, from );
				}
			} );

			self.updateSearchUi();

			if ( self.matches.length ) {
				self.goToMatch( 0 );
			} else {
				self.announce( i18n.noMatches || 'No matches' );
			}
		} );
	};

	/**
	 * Jump to one match and highlight it.
	 *
	 * @param {number} index Match index.
	 */
	Viewer.prototype.goToMatch = function ( index ) {
		if ( ! this.matches.length ) {
			return;
		}

		if ( index < 0 ) {
			index = this.matches.length - 1;
		}

		if ( index >= this.matches.length ) {
			index = 0;
		}

		this.matchIndex = index;

		var match = this.matches[ index ];

		this.clearHighlights();
		this.goToPage( match.page );
		this.highlightPage( this.pages[ match.page - 1 ] );
		this.updateSearchUi();
	};

	/**
	 * Paint the current match onto a page, if it belongs there.
	 *
	 * @param {Object} page Page record.
	 */
	Viewer.prototype.highlightPage = function ( page ) {
		if ( ! page || ! page.rendered || this.matchIndex < 0 ) {
			return;
		}

		var match = this.matches[ this.matchIndex ];

		if ( ! match || match.page !== page.number || ! page.index ) {
			return;
		}

		var spans = page.textLayer.querySelectorAll( 'span' );

		page.index.items.forEach( function ( item, position ) {
			if ( item.end <= match.start || item.start >= match.end ) {
				return;
			}

			var span = spans[ position ];

			if ( ! span ) {
				return;
			}

			var from = Math.max( 0, match.start - item.start );
			var to = Math.min( item.end - item.start, match.end - item.start );

			highlightInSpan( span, from, to );
		} );

		var mark = page.textLayer.querySelector( '.wppdf-match' );

		if ( mark && mark.scrollIntoView ) {
			mark.scrollIntoView( { block: 'center', behavior: 'auto' } );
		}
	};

	/**
	 * Wrap part of a text layer span in a highlight.
	 *
	 * @param {HTMLElement} span Text layer span.
	 * @param {number}      from Start offset.
	 * @param {number}      to   End offset.
	 */
	function highlightInSpan( span, from, to ) {
		var text = span.textContent;

		if ( from >= to || ! text ) {
			return;
		}

		if ( null === span.getAttribute( 'data-wppdf-text' ) ) {
			span.setAttribute( 'data-wppdf-text', text );
		}

		var mark = document.createElement( 'mark' );
		mark.className = 'wppdf-match';
		mark.textContent = text.slice( from, to );

		span.textContent = '';
		span.appendChild( document.createTextNode( text.slice( 0, from ) ) );
		span.appendChild( mark );
		span.appendChild( document.createTextNode( text.slice( to ) ) );
	}

	Viewer.prototype.clearHighlights = function () {
		this.pages.forEach( function ( page ) {
			var spans = page.textLayer.querySelectorAll( 'span[data-wppdf-text]' );

			Array.prototype.forEach.call( spans, function ( span ) {
				span.textContent = span.getAttribute( 'data-wppdf-text' );
				span.removeAttribute( 'data-wppdf-text' );
			} );
		} );
	};

	Viewer.prototype.updateSearchUi = function () {
		var hasMatches = this.matches.length > 0;

		if ( this.searchCount ) {
			if ( ! this.term || this.term.length < 2 ) {
				this.searchCount.textContent = '';
			} else if ( ! hasMatches ) {
				this.searchCount.textContent = i18n.noMatches || 'No matches';
			} else {
				this.searchCount.textContent = format(
					i18n.matches || '%1$d of %2$d',
					this.matchIndex + 1,
					this.matches.length
				);
			}
		}

		if ( this.searchPrev ) {
			this.searchPrev.disabled = ! hasMatches;
		}

		if ( this.searchNext ) {
			this.searchNext.disabled = ! hasMatches;
		}
	};

	// --- Languages and statistics -----------------------------------------

	/**
	 * Swap the loaded file for another language version.
	 *
	 * @param {string} code Language code.
	 */
	Viewer.prototype.switchLanguage = function ( code ) {
		var sources = this.config.sources || [];
		var target = null;

		for ( var i = 0; i < sources.length; i++ ) {
			if ( sources[ i ].lang === code ) {
				target = sources[ i ];
				break;
			}
		}

		if ( ! target || target.url === this.config.url ) {
			return;
		}

		this.pages.forEach( this.releasePage, this );

		if ( this.pageObserver ) {
			this.pageObserver.disconnect();
			this.pageObserver = null;
		}

		if ( this.thumbObserver ) {
			this.thumbObserver.disconnect();
			this.thumbObserver = null;
		}

		this.thumbs = [];

		if ( this.thumbsPanel ) {
			this.thumbsPanel.innerHTML = '';
		}

		if ( this.outlinePanel ) {
			this.outlinePanel.innerHTML = '';
		}

		if ( this.pdf && this.pdf.destroy ) {
			this.pdf.destroy();
		}

		this.pdf = null;
		this.pages = [];
		this.matches = [];
		this.matchIndex = -1;
		this.term = '';
		this.indexed = false;
		this.indexing = null;
		this.currentPage = 1;
		this.config.url = target.url;
		this.config.lang = target.lang;
		this.stage.innerHTML = '';
		this.root.setAttribute( 'lang', target.lang );

		if ( this.searchInput ) {
			this.searchInput.value = '';
		}

		this.updateSearchUi();

		var download = this.root.querySelector( '.wppdf-download' );

		if ( download ) {
			download.setAttribute( 'href', target.url );
		}

		var fallbackNotice = this.root.querySelector( '.wppdf-viewer__fallback' );

		if ( fallbackNotice ) {
			fallbackNotice.hidden = true;
		}

		this.load();
	};

	/**
	 * Report a view or a download, at most once per browser session.
	 *
	 * @param {string} type view or download.
	 */
	Viewer.prototype.countHit = function ( type ) {
		if ( ! this.config.stats || ! settings.rest || ! settings.rest.hit || ! this.config.postId ) {
			return;
		}

		var key = 'wppdf:' + type + ':' + this.config.postId + ':' + this.config.lang;

		try {
			if ( window.sessionStorage && window.sessionStorage.getItem( key ) ) {
				return;
			}

			if ( window.sessionStorage ) {
				window.sessionStorage.setItem( key, '1' );
			}
		} catch ( e ) {
			// Private mode without storage: counting once per page view is fine.
		}

		var payload = JSON.stringify( {
			id: this.config.postId,
			lang: this.config.lang,
			type: type
		} );

		if ( window.fetch ) {
			window.fetch( settings.rest.hit, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: payload,
				credentials: 'omit',
				keepalive: true
			} ).catch( function () {} );
		}
	};

	// --- Sidebar: thumbnails and outline -----------------------------------

	Viewer.prototype.setupSidebar = function () {
		this.buildThumbnails();
		this.loadOutline();
	};

	Viewer.prototype.toggleSidebar = function ( force ) {
		if ( ! this.sidebar ) {
			return;
		}

		var open = 'undefined' === typeof force ? this.sidebar.hidden : force;

		this.sidebar.hidden = ! open;
		this.root.classList.toggle( 'has-sidebar', open );

		if ( this.sidebarToggle ) {
			this.sidebarToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		if ( open ) {
			this.renderVisibleThumbnails();
		}

		// The stage changed width, so a fitted zoom has to be recomputed.
		if ( this.pdf && ( 'auto' === this.zoomMode || 'page-width' === this.zoomMode || 'page-fit' === this.zoomMode ) ) {
			this.scale = this.computeScale( this.zoomMode );
			this.rerender();
		}
	};

	Viewer.prototype.showPanel = function ( name ) {
		var tabs = this.root.querySelectorAll( '.wppdf-sidebar__tab' );

		Array.prototype.forEach.call( tabs, function ( tab ) {
			var active = tab.getAttribute( 'data-panel' ) === name;
			tab.classList.toggle( 'is-active', active );
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );

		if ( this.thumbsPanel ) {
			this.thumbsPanel.hidden = 'thumbs' !== name;
		}

		if ( this.outlinePanel ) {
			this.outlinePanel.hidden = 'outline' !== name;
		}

		if ( 'thumbs' === name ) {
			this.renderVisibleThumbnails();
		}
	};

	Viewer.prototype.buildThumbnails = function () {
		var self = this;

		if ( ! this.thumbsPanel ) {
			return;
		}

		this.thumbsPanel.innerHTML = '';
		this.thumbs = [];

		this.pages.forEach( function ( page ) {
			var item = document.createElement( 'button' );
			item.type = 'button';
			item.className = 'wppdf-thumb';
			item.setAttribute( 'data-page', page.number );

			var canvas = document.createElement( 'canvas' );
			canvas.className = 'wppdf-thumb__canvas';
			item.appendChild( canvas );

			var label = document.createElement( 'span' );
			label.className = 'wppdf-thumb__label';
			label.textContent = page.number;
			item.appendChild( label );

			item.addEventListener( 'click', function () {
				self.goToPage( page.number );
			} );

			self.thumbsPanel.appendChild( item );
			self.thumbs.push( { number: page.number, element: item, canvas: canvas, rendered: false } );
		} );

		if ( 'IntersectionObserver' in window ) {
			this.thumbObserver = new IntersectionObserver(
				function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting ) {
							self.renderThumbnail( self.thumbs[ parseInt( entry.target.getAttribute( 'data-page' ), 10 ) - 1 ] );
						}
					} );
				},
				{ root: this.thumbsPanel, rootMargin: '200px 0px' }
			);

			this.thumbs.forEach( function ( thumb ) {
				self.thumbObserver.observe( thumb.element );
			} );
		}
	};

	Viewer.prototype.renderVisibleThumbnails = function () {
		var self = this;

		if ( ! this.thumbs || ! this.thumbs.length ) {
			return;
		}

		// The first screenful, so an opened panel is never blank.
		this.thumbs.slice( 0, 8 ).forEach( function ( thumb ) {
			self.renderThumbnail( thumb );
		} );
	};

	Viewer.prototype.renderThumbnail = function ( thumb ) {
		var self = this;

		if ( ! thumb || thumb.rendered || thumb.rendering || ! this.pdf ) {
			return;
		}

		thumb.rendering = true;

		this.pdf.getPage( thumb.number ).then( function ( pdfPage ) {
			var base = pdfPage.getViewport( { scale: 1 } );
			var scale = 130 / base.width;
			var viewport = pdfPage.getViewport( { scale: scale } );

			thumb.canvas.width = Math.floor( viewport.width );
			thumb.canvas.height = Math.floor( viewport.height );

			return pdfPage.render( {
				canvasContext: thumb.canvas.getContext( '2d', { alpha: false } ),
				viewport: viewport
			} ).promise;
		} ).then( function () {
			thumb.rendered = true;
			thumb.rendering = false;
			thumb.element.classList.add( 'is-rendered' );
		} ).catch( function () {
			thumb.rendering = false;
		} );

		return self;
	};

	Viewer.prototype.markCurrentThumbnail = function () {
		if ( ! this.thumbs ) {
			return;
		}

		var current = this.currentPage;

		this.thumbs.forEach( function ( thumb ) {
			thumb.element.classList.toggle( 'is-current', thumb.number === current );
		} );
	};

	Viewer.prototype.loadOutline = function () {
		var self = this;

		if ( ! this.outlinePanel || ! this.pdf || ! this.pdf.getOutline ) {
			return;
		}

		this.pdf.getOutline().then( function ( outline ) {
			if ( ! outline || ! outline.length ) {
				self.outlinePanel.innerHTML = '';
				self.outlinePanel.appendChild( document.createTextNode( i18n.noOutline || '' ) );
				return;
			}

			var tab = self.root.querySelector( '.wppdf-sidebar__tab[data-panel="outline"]' );

			if ( tab ) {
				tab.hidden = false;
			}

			self.outlinePanel.innerHTML = '';
			self.outlinePanel.appendChild( self.buildOutlineList( outline ) );
		} ).catch( function () {} );
	};

	/**
	 * Turn the outline tree into nested lists.
	 *
	 * @param {Array} items Outline items.
	 * @return {HTMLElement} List element.
	 */
	Viewer.prototype.buildOutlineList = function ( items ) {
		var self = this;
		var list = document.createElement( 'ul' );
		list.className = 'wppdf-outline__list';

		items.forEach( function ( item ) {
			var entry = document.createElement( 'li' );
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'wppdf-outline__item';
			button.textContent = item.title || '';

			button.addEventListener( 'click', function () {
				if ( item.dest ) {
					self.goToDestination( item.dest );
				} else if ( item.url && /^https?:/i.test( item.url ) ) {
					window.open( item.url, '_blank', 'noopener' );
				}
			} );

			entry.appendChild( button );

			if ( item.items && item.items.length ) {
				entry.appendChild( self.buildOutlineList( item.items ) );
			}

			list.appendChild( entry );
		} );

		return list;
	};

	// --- Printing ----------------------------------------------------------

	/**
	 * Render the requested pages into the document and print them.
	 *
	 * Handing the file to a hidden iframe only works where the browser has a
	 * built in PDF plugin, which rules out iOS. Printing rendered pages works
	 * anywhere the reader itself works.
	 *
	 * @param {number} from First page.
	 * @param {number} to   Last page.
	 */
	Viewer.prototype.printRange = function ( from, to ) {
		var self = this;

		if ( ! this.pdf || this.printing ) {
			return;
		}

		from = Math.min( Math.max( 1, from ), this.pages.length );
		to = Math.min( Math.max( from, to ), this.pages.length );

		this.printing = true;

		var container = document.createElement( 'div' );
		container.className = 'wppdf-print-container';
		document.body.appendChild( container );
		document.body.classList.add( 'wppdf-is-printing' );

		var canvas = document.createElement( 'canvas' );
		var context = canvas.getContext( '2d', { alpha: false } );
		var number = from;

		var progress = this.root.querySelector( '.wppdf-print-progress' );

		/**
		 * Clean up whatever the print run created.
		 */
		function cleanup() {
			self.printing = false;
			document.body.classList.remove( 'wppdf-is-printing' );

			if ( container.parentNode ) {
				container.parentNode.removeChild( container );
			}

			if ( progress ) {
				progress.textContent = '';
			}
		}

		/**
		 * Render one page, then queue the next.
		 *
		 * @return {Promise} Resolved when the range is done.
		 */
		function step() {
			if ( number > to ) {
				return Promise.resolve();
			}

			if ( progress ) {
				progress.textContent = ( i18n.preparing || '' ) + ' ' + ( number - from + 1 ) + '/' + ( to - from + 1 );
			}

			return self.pdf.getPage( number ).then( function ( pdfPage ) {
				// 150 dpi is the usual compromise between sharpness and size.
				var viewport = pdfPage.getViewport( { scale: 150 / 72 } );

				canvas.width = Math.floor( viewport.width );
				canvas.height = Math.floor( viewport.height );

				return pdfPage.render( {
					canvasContext: context,
					viewport: viewport
				} ).promise.then( function () {
					var image = document.createElement( 'img' );
					image.className = 'wppdf-print-page';
					image.src = canvas.toDataURL( 'image/jpeg', 0.92 );
					container.appendChild( image );

					number++;

					return step();
				} );
			} );
		}

		step().then( function () {
			if ( progress ) {
				progress.textContent = '';
			}

			// Give the images a frame to lay out before the print dialog opens.
			window.setTimeout( function () {
				window.print();

				window.setTimeout( cleanup, 1000 );
			}, 100 );
		} ).catch( function () {
			cleanup();
		} );
	};

	// --- Sharing and deep links -------------------------------------------

	/**
	 * Open at the page named in the URL, e.g. …/report/#page=12
	 */
	Viewer.prototype.applyDeepLink = function () {
		var match = /[#&?]page=(\d+)/.exec( window.location.hash + window.location.search );

		if ( ! match ) {
			return 0;
		}

		var page = parseInt( match[ 1 ], 10 );

		return page > 0 ? page : 0;
	};

	Viewer.prototype.share = function () {
		var base = window.location.href.split( '#' )[ 0 ];
		var url = base + '#page=' + this.currentPage;

		var done = function ( ok ) {
			var note = ok ? i18n.copied : i18n.copyFailed + ' ' + url;

			if ( this.searchCount ) {
				// The count area doubles as the toolbar's message line.
				this.searchCount.textContent = note;
			}

			this.announce( note );
		}.bind( this );

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( function () {
				done( true );
			} ).catch( function () {
				done( false );
			} );

			return;
		}

		done( false );
	};

	// --- Toolbar and input -------------------------------------------------

	Viewer.prototype.bindToolbar = function () {
		var self = this;
		var root = this.root;

		this.pageInput = root.querySelector( '.wppdf-page-input' );
		this.pageTotal = root.querySelector( '.wppdf-page-total' );
		this.zoomLevel = root.querySelector( '.wppdf-zoom-level' );
		this.searchInput = root.querySelector( '.wppdf-search-input' );
		this.searchCount = root.querySelector( '.wppdf-search-count' );
		this.searchPrev = root.querySelector( '.wppdf-search-prev' );
		this.searchNext = root.querySelector( '.wppdf-search-next' );
		this.fitButton = root.querySelector( '.wppdf-fit' );
		this.fullscreenButton = root.querySelector( '.wppdf-fullscreen' );
		this.sidebar = root.querySelector( '.wppdf-viewer__sidebar' );
		this.sidebarToggle = root.querySelector( '.wppdf-sidebar-toggle' );
		this.thumbsPanel = root.querySelector( '.wppdf-thumbs' );
		this.outlinePanel = root.querySelector( '.wppdf-outline' );
		this.printDialog = root.querySelector( '.wppdf-print-dialog' );

		on( root, '.wppdf-sidebar-toggle', function () {
			self.toggleSidebar();
		} );

		on( root, '.wppdf-share', function () {
			self.share();
		} );

		Array.prototype.forEach.call( root.querySelectorAll( '.wppdf-sidebar__tab' ), function ( tab ) {
			tab.addEventListener( 'click', function () {
				self.showPanel( tab.getAttribute( 'data-panel' ) );
			} );
		} );

		on( root, '.wppdf-print-cancel', function () {
			self.togglePrintDialog( false );
		} );

		on( root, '.wppdf-print-start', function () {
			var selected = root.querySelector( '.wppdf-print-dialog input[type="radio"]:checked' );
			var mode = selected ? selected.value : 'all';
			var from = 1;
			var to = self.pages.length;

			if ( 'current' === mode ) {
				from = self.currentPage;
				to = self.currentPage;
			} else if ( 'range' === mode ) {
				from = parseInt( root.querySelector( '.wppdf-print-from' ).value, 10 ) || 1;
				to = parseInt( root.querySelector( '.wppdf-print-to' ).value, 10 ) || from;
			}

			self.printRange( from, to );
		} );

		on( root, '.wppdf-prev', function () {
			self.goToPage( self.currentPage - 1 );
		} );

		on( root, '.wppdf-next', function () {
			self.goToPage( self.currentPage + 1 );
		} );

		on( root, '.wppdf-zoom-in', function () {
			self.zoomBy( 0.25 );
		} );

		on( root, '.wppdf-zoom-out', function () {
			self.zoomBy( -0.25 );
		} );

		on( root, '.wppdf-fit', function () {
			self.setZoom( 'page-width' === self.zoomMode ? 'page-fit' : 'page-width' );
		} );

		on( root, '.wppdf-fullscreen', function () {
			self.toggleFullscreen();
		} );

		on( root, '.wppdf-print', function () {
			self.togglePrintDialog();
		} );

		on( root, '.wppdf-search-prev', function () {
			self.goToMatch( self.matchIndex - 1 );
		} );

		on( root, '.wppdf-search-next', function () {
			self.goToMatch( self.matchIndex + 1 );
		} );

		var download = root.querySelector( '.wppdf-download' );

		if ( download ) {
			download.addEventListener( 'click', function () {
				self.countHit( 'download' );
			} );
		}

		if ( this.pageInput ) {
			this.pageInput.addEventListener( 'change', function () {
				self.goToPage( parseInt( self.pageInput.value, 10 ) || 1 );
			} );
		}

		if ( this.searchInput ) {
			var run = throttle( function () {
				self.find( self.searchInput.value );
			}, 350 );

			this.searchInput.addEventListener( 'input', run );

			this.searchInput.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();

					if ( self.matches.length && self.searchInput.value.trim() === self.term ) {
						self.goToMatch( event.shiftKey ? self.matchIndex - 1 : self.matchIndex + 1 );
					} else {
						self.find( self.searchInput.value );
					}
				}

				if ( 'Escape' === event.key ) {
					self.searchInput.value = '';
					self.find( '' );
				}
			} );
		}

		var languageSelect = root.querySelector( '.wppdf-language-select' );

		if ( languageSelect ) {
			languageSelect.addEventListener( 'change', function () {
				self.switchLanguage( languageSelect.value );
			} );
		}

		document.addEventListener( 'fullscreenchange', function () {
			var isFullscreen = document.fullscreenElement === self.root;
			self.root.classList.toggle( 'is-fullscreen', isFullscreen );

			if ( self.fullscreenButton ) {
				self.fullscreenButton.setAttribute( 'aria-pressed', isFullscreen ? 'true' : 'false' );
			}

			if ( isFullscreen ) {
				self.stage.focus();
			}

			if ( self.pdf ) {
				window.setTimeout( function () {
					self.setZoom( self.zoomMode );
				}, 60 );
			}
		} );
	};

	Viewer.prototype.bindKeyboard = function () {
		var self = this;

		this.root.addEventListener( 'keydown', function ( event ) {
			// Ctrl/Cmd+F searches this document instead of the page.
			if ( 'f' === event.key.toLowerCase() && ( event.ctrlKey || event.metaKey ) && self.searchInput ) {
				event.preventDefault();
				self.searchInput.focus();
				self.searchInput.select();
			}
		} );

		this.stage.addEventListener( 'keydown', function ( event ) {
			switch ( event.key ) {
				case 'ArrowRight':
				case 'PageDown':
					self.goToPage( self.currentPage + 1 );
					event.preventDefault();
					break;
				case 'ArrowLeft':
				case 'PageUp':
					self.goToPage( self.currentPage - 1 );
					event.preventDefault();
					break;
				case 'Home':
					self.goToPage( 1 );
					event.preventDefault();
					break;
				case 'End':
					self.goToPage( self.pages.length );
					event.preventDefault();
					break;
				default:
					break;
			}
		} );
	};

	Viewer.prototype.observeResize = function () {
		var self = this;

		var handler = throttle( function () {
			if ( ! self.pdf ) {
				return;
			}

			if ( 'auto' === self.zoomMode || 'page-width' === self.zoomMode || 'page-fit' === self.zoomMode ) {
				var next = self.computeScale( self.zoomMode );

				if ( Math.abs( next - self.scale ) > 0.01 ) {
					self.scale = next;
					self.rerender();
				}
			}
		}, 250 );

		if ( 'ResizeObserver' in window ) {
			this.resizeObserver = new ResizeObserver( handler );
			this.resizeObserver.observe( this.root );
		} else {
			window.addEventListener( 'resize', handler );
		}
	};

	Viewer.prototype.toggleFullscreen = function () {
		if ( document.fullscreenElement === this.root ) {
			if ( document.exitFullscreen ) {
				document.exitFullscreen();
			}
			return;
		}

		if ( this.root.requestFullscreen ) {
			this.root.requestFullscreen();
		}
	};

	/**
	 * Show or hide the print range chooser.
	 *
	 * @param {boolean} force Explicit state.
	 */
	Viewer.prototype.togglePrintDialog = function ( force ) {
		if ( ! this.printDialog ) {
			return;
		}

		var open = 'undefined' === typeof force ? this.printDialog.hidden : force;

		this.printDialog.hidden = ! open;

		var button = this.root.querySelector( '.wppdf-print' );

		if ( button ) {
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		if ( ! open ) {
			return;
		}

		var from = this.root.querySelector( '.wppdf-print-from' );
		var to = this.root.querySelector( '.wppdf-print-to' );

		if ( from ) {
			from.max = this.pages.length;
			from.value = this.currentPage;
		}

		if ( to ) {
			to.max = this.pages.length;
			to.value = this.pages.length;
		}
	};

	Viewer.prototype.updateToolbar = function () {
		if ( this.pageInput ) {
			this.pageInput.value = this.currentPage;
			this.pageInput.max = this.pages.length || 1;
		}

		if ( this.pageTotal ) {
			this.pageTotal.textContent = '/ ' + ( this.pages.length || '–' );
		}

		if ( this.zoomLevel ) {
			this.zoomLevel.textContent = Math.round( this.scale * 100 ) + ' %';
		}

		if ( this.fitButton ) {
			this.fitButton.setAttribute( 'aria-pressed', 'page-width' === this.zoomMode || 'page-fit' === this.zoomMode ? 'true' : 'false' );
		}
	};

	/**
	 * Bind a click handler to a child of the reader.
	 *
	 * @param {HTMLElement} root     Reader wrapper.
	 * @param {string}      selector Child selector.
	 * @param {Function}    handler  Click handler.
	 */
	function on( root, selector, handler ) {
		var element = root.querySelector( selector );

		if ( ! element ) {
			return;
		}

		element.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			handler( event );
		} );
	}

	/**
	 * Fill %1$d style placeholders.
	 *
	 * @param {string} template Template string.
	 * @return {string} Filled string.
	 */
	function format( template ) {
		var values = Array.prototype.slice.call( arguments, 1 );

		return String( template ).replace( /%(\d+)\$[ds]/g, function ( match, position ) {
			var value = values[ parseInt( position, 10 ) - 1 ];

			return 'undefined' === typeof value ? match : value;
		} );
	}

	/**
	 * Simple trailing throttle.
	 *
	 * @param {Function} fn    Callback.
	 * @param {number}   delay Delay in ms.
	 * @return {Function} Throttled callback.
	 */
	function throttle( fn, delay ) {
		var timer = null;

		return function () {
			if ( timer ) {
				return;
			}

			timer = window.setTimeout( function () {
				timer = null;
				fn();
			}, delay );
		};
	}

	/**
	 * Initialise every reader that is not initialised yet.
	 */
	function init() {
		var nodes = document.querySelectorAll( '.wppdf-viewer:not(.is-initialised)' );

		Array.prototype.forEach.call( nodes, function ( node ) {
			node.classList.add( 'is-initialised' );
			node.wppdfViewer = new Viewer( node );
		} );
	}

	/**
	 * Pick up readers inserted after page load: block editor previews,
	 * AJAX loaded content, tabs and similar.
	 */
	function watch() {
		if ( ! ( 'MutationObserver' in window ) || ! document.body ) {
			return;
		}

		var scheduled = throttle( init, 200 );

		new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				if ( mutations[ i ].addedNodes && mutations[ i ].addedNodes.length ) {
					scheduled();
					return;
				}
			}
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	window.wppdfInit = init;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
			watch();
		} );
	} else {
		init();
		watch();
	}
} )();
