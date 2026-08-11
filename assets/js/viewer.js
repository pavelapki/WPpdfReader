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
			}, reject );
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

			var start = Math.min( Math.max( 1, self.config.page || 1 ), self.pdf.numPages );
			if ( start > 1 ) {
				self.goToPage( start, false );
			}

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
			self.print();
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

	Viewer.prototype.print = function () {
		var frame = document.createElement( 'iframe' );

		frame.style.position = 'fixed';
		frame.style.right = '0';
		frame.style.bottom = '0';
		frame.style.width = '0';
		frame.style.height = '0';
		frame.style.border = '0';
		frame.src = this.config.url;

		frame.onload = function () {
			try {
				frame.contentWindow.focus();
				frame.contentWindow.print();
			} catch ( e ) {
				window.open( frame.src, '_blank', 'noopener' );
			}

			window.setTimeout( function () {
				if ( frame.parentNode ) {
					frame.parentNode.removeChild( frame );
				}
			}, 60000 );
		};

		document.body.appendChild( frame );
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
