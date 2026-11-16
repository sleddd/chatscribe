/**
 * The AI Summary and AI Writer boxes in the post editor's sidebar.
 * Works in the block editor and the classic editor (used by WooCommerce products).
 */
( function () {
	const settings = window.ChatScribeEditor;
	if ( !settings || !window.wp || !wp.apiFetch ) {
		return;
	}
	const i18n = settings.i18n;

	/* ------------------------------------------------------------------ *
	 * The editor: block or classic
	 * ------------------------------------------------------------------ */

	function blockEditor() {
		return window.wp && wp.data && wp.blocks && wp.data.select( 'core/editor' ) ? wp.data : null;
	}

	function classicEditor() {
		const editor = window.tinymce && tinymce.get( 'content' );
		return editor && !editor.isHidden() ? editor : null;
	}

	function getTitle() {
		const data = blockEditor();
		if ( data ) {
			return data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}
		const field = document.getElementById( 'title' );
		return field ? field.value : '';
	}

	function setTitleIfEmpty( title ) {
		if ( !title || getTitle().trim() ) {
			return;
		}
		const data = blockEditor();
		if ( data ) {
			data.dispatch( 'core/editor' ).editPost( {
				title: title
			} );
			return;
		}
		const field = document.getElementById( 'title' );
		if ( field ) {
			field.value = title;
			// Hides the "Add title" placeholder.
			field.dispatchEvent( new Event( 'input', {
				bubbles: true
			} ) );
			const prompt = document.getElementById( 'title-prompt-text' );
			if ( prompt ) {
				prompt.classList.add( 'screen-reader-text' );
			}
		}
	}

	/**
	 * The post's current content, saved or not.
	 */
	function getContent() {
		const data = blockEditor();
		if ( data ) {
			return data.select( 'core/editor' ).getEditedPostContent() || '';
		}
		const editor = classicEditor();
		if ( editor ) {
			return editor.getContent();
		}
		const textarea = document.getElementById( 'content' );
		return textarea ? textarea.value : '';
	}

	// The only tags kept in generated content, as on the server (GeneratePost::ALLOWED_HTML).
	const ALLOWED_TAGS = [ 'H2', 'H3', 'H4', 'P', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'BLOCKQUOTE', 'BR', 'A' ];

	/**
	 * A second check before generated HTML goes into the post (the server already
	 * cleaned it): only ALLOWED_TAGS, no attributes except an http(s) link's href.
	 * DOMParser builds an inert document, so nothing in it runs while it is checked.
	 */
	function cleanHtml( html ) {
		const doc = new DOMParser().parseFromString( '<body>' + html + '</body>', 'text/html' );

		function clean( node ) {
			Array.from( node.childNodes ).forEach( function ( child ) {
				if ( child.nodeType === Node.TEXT_NODE ) {
					return;
				}
				if ( child.nodeType !== Node.ELEMENT_NODE ) {
					child.remove(); // Comments and the like.
					return;
				}
				clean( child );
				if ( ALLOWED_TAGS.indexOf( child.tagName ) === -1 ) {
					// Scripts, styles and the like go entirely; other tags keep their text.
					if ( [ 'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'TEMPLATE', 'NOSCRIPT', 'SVG', 'MATH' ].indexOf( child.tagName.toUpperCase() ) !== -1 ) {
						child.remove();
					} else {
						child.replaceWith( ...child.childNodes );
					}
					return;
				}
				Array.from( child.attributes ).forEach( function ( attr ) {
					const safeLink = 'A' === child.tagName && 'href' === attr.name && /^(https?:\/\/|\/(?!\/))/i.test( attr.value.trim() );
					if ( !safeLink ) {
						child.removeAttribute( attr.name );
					}
				} );
			} );
		}

		clean( doc.body );
		return doc.body.innerHTML;
	}

	/**
	 * Adds HTML to the end of the post: as a Group block in the block editor.
	 * Returns the message to show.
	 */
	function appendContent( html ) {
		html = cleanHtml( html );
		const data = blockEditor();
		if ( data ) {
			const inner = wp.blocks.rawHandler( {
				HTML: html
			} );
			// Named so it is easy to spot in List View and the block toolbar.
			const group = wp.blocks.createBlock( 'core/group', {
				layout: {
					type: 'constrained'
				},
				metadata: {
					name: i18n.groupName
				}
			}, inner );
			data.dispatch( 'core/block-editor' ).insertBlocks( group );
			data.dispatch( 'core/block-editor' ).selectBlock( group.clientId );
			return i18n.inserted;
		}

		const editor = classicEditor();
		if ( editor ) {
			editor.setContent( editor.getContent() + html );
			editor.fire( 'change' );
			return i18n.insertedRaw;
		}
		const textarea = document.getElementById( 'content' );
		if ( textarea ) {
			textarea.value = ( textarea.value ? textarea.value + '\n\n' : '' ) + html;
			textarea.dispatchEvent( new Event( 'input', {
				bubbles: true
			} ) );
		}
		return i18n.insertedRaw;
	}

	/* ------------------------------------------------------------------ *
	 * Calling an ability
	 * ------------------------------------------------------------------ */

	function runAbility( path, input ) {
		return wp.apiFetch( {
			path: path,
			method: 'POST',
			data: {
				input: input
			}
		} );
	}

	function errorMessage( error ) {
		return ( error && error.message ) ? error.message : i18n.error;
	}

	function setBusy( box, busy, message ) {
		box.classList.toggle( 'is-busy', busy );
		box.querySelectorAll( 'button, textarea' ).forEach( function ( el ) {
			el.disabled = busy;
		} );
		setStatus( box, message || '', false );
	}

	function setStatus( box, message, isError ) {
		const status = box.querySelector( '.chatscribe-editor-box__status' );
		status.textContent = message;
		status.classList.toggle( 'is-error', !!isError );
	}

	/* ------------------------------------------------------------------ *
	 * AI Summary
	 * ------------------------------------------------------------------ */

	function initSummary( box ) {
		const result = box.querySelector( '.chatscribe-editor-box__result' );
		const list = box.querySelector( '.chatscribe-editor-box__bullets' );
		let bullets = [];

		box.querySelector( '[data-chatscribe-action="summarize"]' ).addEventListener( 'click', function () {
			const text = getContent();
			if ( !text.replace( /<[^>]*>/g, '' ).trim() ) {
				setStatus( box, i18n.noText, true );
				return;
			}

			setBusy( box, true, i18n.summarizing );
			result.hidden = true;

			runAbility( settings.abilities.summarize, {
				text: text,
				title: getTitle(),
				post_id: settings.postId
			} ).then( function ( response ) {
				bullets = ( response && response.bullets ) || [];
				list.replaceChildren( ...bullets.map( function ( bullet ) {
					const item = document.createElement( 'li' );
					item.textContent = bullet;
					return item;
				} ) );
				result.hidden = !bullets.length;
				setBusy( box, false );
			} ).catch( function ( error ) {
				setBusy( box, false );
				setStatus( box, errorMessage( error ), true );
			} );
		} );

		box.querySelector( '[data-chatscribe-action="copy"]' ).addEventListener( 'click', function () {
			copyBullets( bullets ).then( function () {
				setStatus( box, i18n.copied, false );
			} ).catch( function () {
				setStatus( box, i18n.copyFailed, true );
			} );
		} );
	}

	/**
	 * Copies the key points as a list: pasted into the block editor, the HTML
	 * becomes a List block; elsewhere the plain text is used.
	 */
	function copyBullets( bullets ) {
		const plain = bullets.map( function ( bullet ) {
			return '- ' + bullet;
		} ).join( '\n' );
		const holder = document.createElement( 'ul' );
		bullets.forEach( function ( bullet ) {
			const item = document.createElement( 'li' );
			item.textContent = bullet;
			holder.appendChild( item );
		} );

		const html = holder.outerHTML;

		// The Clipboard API only exists on HTTPS (and localhost), so sites on plain
		// HTTP, such as many local sites, use the older copy command instead.
		if ( !window.isSecureContext || !navigator.clipboard ) {
			return legacyCopy( html, plain ) ? Promise.resolve() : Promise.reject( new Error( 'Copy failed' ) );
		}

		const copied = window.ClipboardItem ?
			navigator.clipboard.write( [ new ClipboardItem( {
				'text/html': new Blob( [ html ], {
					type: 'text/html'
				} ),
				'text/plain': new Blob( [ plain ], {
					type: 'text/plain'
				} )
			} ) ] ) :
			navigator.clipboard.writeText( plain );

		return copied.catch( function () {
			return legacyCopy( html, plain ) ? undefined : Promise.reject( new Error( 'Copy failed' ) );
		} );
	}

	/**
	 * Copies with document.execCommand( 'copy' ), which works without HTTPS. The copy
	 * event puts both the HTML and the plain text on the clipboard.
	 */
	function legacyCopy( html, plain ) {
		const active = document.activeElement;
		const field = document.createElement( 'textarea' );
		field.value = plain;
		field.setAttribute( 'readonly', '' );
		field.style.position = 'fixed';
		field.style.top = '-1000px';
		field.style.opacity = '0';
		document.body.appendChild( field );
		field.select();

		function onCopy( event ) {
			event.clipboardData.setData( 'text/html', html );
			event.clipboardData.setData( 'text/plain', plain );
			event.preventDefault();
		}
		document.addEventListener( 'copy', onCopy );

		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( error ) {
			ok = false;
		}

		document.removeEventListener( 'copy', onCopy );
		field.remove();
		if ( active && active.focus ) {
			active.focus();
		}
		return ok;
	}

	/* ------------------------------------------------------------------ *
	 * AI Writer
	 * ------------------------------------------------------------------ */

	function initGeneration( box ) {
		const field = box.querySelector( 'textarea' );

		box.querySelector( '[data-chatscribe-action="generate"]' ).addEventListener( 'click', function () {
			const instructions = field.value.trim();
			if ( !instructions ) {
				setStatus( box, i18n.noPrompt, true );
				field.focus();
				return;
			}

			setBusy( box, true, i18n.generating );

			runAbility( settings.abilities.generate, {
				instructions: instructions,
				title: getTitle(),
				post_id: settings.postId
			} ).then( function ( response ) {
				setBusy( box, false );
				if ( !response || !response.content ) {
					setStatus( box, i18n.error, true );
					return;
				}
				setTitleIfEmpty( response.title );
				setStatus( box, appendContent( response.content ), false );
			} ).catch( function ( error ) {
				setBusy( box, false );
				setStatus( box, errorMessage( error ), true );
			} );
		} );
	}

	function init() {
		document.querySelectorAll( '[data-chatscribe-tool="summary"]' ).forEach( initSummary );
		document.querySelectorAll( '[data-chatscribe-tool="generation"]' ).forEach( initGeneration );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
