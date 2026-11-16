document.addEventListener( 'DOMContentLoaded', function () {
	const chatContainer = document.getElementById( 'chatscribeChat' );
	const closeButton = document.getElementById( 'chatscribeClose' );
	const clearButton = document.getElementById( 'chatscribeClear' );
	const sendButton = document.getElementById( 'chatscribeSend' );
	const inputField = document.getElementById( 'chatscribeInput' );
	const messagesContainer = document.getElementById( 'chatscribeMessages' );
	const greetingElement = document.getElementById( 'chatscribeGreeting' );

	const context = ( window.ChatScribe && ChatScribe.context ) ? ChatScribe.context : 'frontend';

	// The conversation so far, sent with each message so the assistant can follow it.
	// In memory only: it is never written to any storage, and is gone when the page
	// is reloaded or left.
	const HISTORY_LIMIT = 12;
	let history = [];
	// Goes up each time the conversation is cleared, so a reply still on its way is dropped.
	let conversation = 0;

	// Earlier versions kept the conversation in sessionStorage. Remove what they left behind.
	try {
		sessionStorage.removeItem( 'chatscribeHistory' );
		sessionStorage.removeItem( 'chatscribeHistoryBackend' );
	} catch ( error ) {
		// Storage unavailable: nothing was left behind.
	}

	function remember( role, text ) {
		history.push( { role: role, text: text } );
		history = history.slice( -HISTORY_LIMIT );
	}

	// Time-aware greeting, based on the visitor's own clock.
	if ( greetingElement ) {
		greetingElement.textContent = getGreeting();
	}

	function getGreeting() {
		const hour = new Date().getHours();
		let timeOfDay;
		if ( hour < 5 ) {
			timeOfDay = 'Hi there, night owl';
		} else if ( hour < 12 ) {
			timeOfDay = 'Good morning';
		} else if ( hour < 17 ) {
			timeOfDay = 'Good afternoon';
		} else {
			timeOfDay = 'Good evening';
		}
		if ( context === 'backend' ) {
			return `${timeOfDay}! How can I help you with the site today?`;
		}
		const siteName = ( window.ChatScribe && ChatScribe.siteName ) ? ChatScribe.siteName : '';
		const welcome = siteName ? ` Welcome to ${siteName}.` : '';
		return `${timeOfDay}!${welcome} How can I help you today?`;
	}

	// Clear the conversation: forget it, and empty the window back to the greeting.
	if ( clearButton ) {
		clearButton.addEventListener( 'click', function () {
			conversation++;
			history = [];
			Array.from( messagesContainer.children ).forEach( function ( element ) {
				if ( element !== greetingElement ) {
					element.remove();
				}
			} );
			inputField.focus();
		} );
	}

	// Minimize to the bubble, and open again from it. The conversation is kept meanwhile.
	const launcher = document.getElementById( 'chatscribeLauncher' );

	closeButton.addEventListener( 'click', function () {
		chatContainer.hidden = true;
		if ( launcher ) {
			launcher.hidden = false;
			launcher.focus();
		}
	} );

	if ( launcher ) {
		launcher.addEventListener( 'click', function () {
			launcher.hidden = true;
			chatContainer.hidden = false;
			messagesContainer.scrollTop = messagesContainer.scrollHeight;
			inputField.focus();
		} );
	}

	// Send message functionality
	sendButton.addEventListener( 'click', sendMessage );
	inputField.addEventListener( 'keypress', function ( event ) {
		if ( event.key === 'Enter' ) {
			sendMessage();
		}
	} );

	function sendMessage() {
		const userMessage = inputField.value.trim();
		if ( userMessage ) {
			const earlier = history.slice();
			const startedIn = conversation;
			appendMessage( userMessage, 'user' );
			inputField.value = '';
			showTyping();
			setTimeout( () => {
				send( { message: userMessage, history: earlier } )
					.then( data => {
						if ( startedIn !== conversation ) {
							return;
						}
						hideTyping();
						if ( data.status === 'success' && typeof data.message === 'string' ) {
							appendMessage( data.message, 'bot' );
							// Only completed exchanges are remembered, so turns always alternate.
							remember( 'user', userMessage );
							remember( 'bot', data.message );
						} else {
							appendMessage( errorText( data ), 'bot' );
						}
					} )
					.catch( error => {
						console.error( 'Error:', error );
						if ( startedIn !== conversation ) {
							return;
						}
						hideTyping();
						appendMessage( "Sorry, there was an error processing your message.", 'bot' );
					} );
			}, 1000 );
		}
	}

	// Posts a message to the chat endpoint. The page's nonce belongs to the login session
	// it was loaded in, so it goes stale when the user logs in again or their login changes
	// in another tab. Then a fresh nonce is fetched for the current login and the message
	// is sent once more. If they are no longer logged in, the frontend chat sends it as a
	// visitor (the server decides whether visitors may chat).
	function send( body ) {
		return post( body, ChatScribe.nonce ).then( data => {
			if ( !data || data.code !== 'rest_cookie_invalid_nonce' ) {
				return data;
			}
			return freshNonce().then( nonce => {
				if ( nonce ) {
					ChatScribe.nonce = nonce;
					return post( body, nonce );
				}
				return context === 'frontend' ? post( body, '' ) : data;
			} );
		} );
	}

	function post( body, nonce ) {
		const headers = { 'Content-Type': 'application/json' };
		if ( nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}
		return fetch( ChatScribe.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( body ),
		} ).then( response => response.json() );
	}

	// A new REST nonce for whoever is logged in now, or '' if nobody is.
	function freshNonce() {
		if ( !ChatScribe.ajaxUrl ) {
			return Promise.resolve( '' );
		}
		return fetch( ChatScribe.ajaxUrl + '?action=rest-nonce', { credentials: 'same-origin' } )
			.then( response => response.ok ? response.text() : '' )
			.then( text => /^[a-f0-9]{10}$/.test( text.trim() ) ? text.trim() : '' )
			.catch( () => '' );
	}

	// What to tell the user when the server refuses the message.
	function errorText( data ) {
		const code = data && data.code;
		if ( code === 'rest_cookie_invalid_nonce' ) {
			// A fresh nonce could not be had: the user is no longer logged in.
			return 'Your login has expired. Please log in again, then reload the page.';
		}
		// Any other refusal from WordPress: show its reason, so the cause can be seen and fixed.
		if ( code && typeof data.message === 'string' && data.message ) {
			return data.message;
		}
		return 'Sorry, there was an error processing your message.';
	}

	function appendMessage( message, sender ) {
		const messageElement = document.createElement( sender === 'bot' ? 'div' : 'p' );
		messageElement.classList.add( 'chatscribe-message', `chatscribe-message--${sender}` );
		if ( sender === 'bot' ) {
			// Safe: code is stripped, then everything left is escaped before formatting tags are added.
			const html = renderMarkdown( stripCode( stripEmojis( message ) ) );
			messageElement.innerHTML = html || '<p>Sorry, I can only answer that in plain language.</p>';
		} else {
			messageElement.textContent = message;
		}
		messagesContainer.appendChild( messageElement );
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
	}

	// Removes anything code-like so replies stay natural language only.
	function stripCode( text ) {
		return String( text )
			.replace( /\r\n/g, '\n' )
			// Fenced code blocks, including one the model never closed.
			.replace( /(```|~~~)[\s\S]*?(\1|$)/g, '\n' )
			// Dangerous HTML elements along with everything inside them.
			.replace( /<(script|style|iframe|object|embed|svg|math|template|noscript|textarea)\b[\s\S]*?(<\/\1\s*>|$)/gi, '' )
			// HTML comments and any remaining tags.
			.replace( /<!--[\s\S]*?(-->|$)/g, '' )
			.replace( /<\/?[a-z][^>]*>/gi, '' )
			// Images, inline code spans and stray backticks.
			.replace( /!\[[^\]]*\]\([^)]*\)/g, '' )
			// Links that aren't web links (javascript:, data:, etc.) keep only their label.
			.replace( /\[([^\]]*)\]\((?!https?:\/\/|\/)[^)]*\)\)?/g, '$1' )
			// The backend chat keeps `inline code` for option names, meta keys and the like;
			// visitors on the frontend only ever get natural language.
			.replace( /`[^`\n]*`/g, match => context === 'backend' ? match : '' )
			.replace( /`/g, match => context === 'backend' ? match : '' )
			// Bold or italic markers left wrapped around nothing, e.g. **** from **`code`**.
			.replace( /(^|[^*])\*\*[ \t]*\*\*(?!\*)/g, '$1' )
			.replace( /(^|[^_\w])__[ \t]*__(?![_\w])/g, '$1' )
			// Tidy the gaps left behind.
			.replace( /[ \t]+([.,;:!?])/g, '$1' )
			.replace( /[ \t]{2,}/g, ' ' )
			.replace( /\n{3,}/g, '\n\n' )
			.trim();
	}

	// Turns the model's markdown (bold, italic, lists, links, headings) into HTML.
	function renderMarkdown( text ) {
		const source = escapeHtml( text );
		const html = [];
		let paragraph = [];
		let list = null;

		function flushParagraph() {
			if ( paragraph.length ) {
				html.push( '<p>' + paragraph.map( formatInline ).join( '<br>' ) + '</p>' );
				paragraph = [];
			}
		}

		function flushList() {
			if ( list ) {
				html.push( '<' + list.tag + '>' + list.items.map( item => '<li>' + formatInline( item ) + '</li>' ).join( '' ) + '</' + list.tag + '>' );
				list = null;
			}
		}

		source.split( '\n' ).forEach( function ( line ) {
			const bulletMatch = line.match( /^\s*[-*•]\s+(.*)$/ );
			const numberMatch = line.match( /^\s*\d+[.)]\s+(.*)$/ );
			const headingMatch = line.match( /^#{1,6}\s+(.*)$/ );

			if ( !line.trim() ) {
				flushParagraph();
				flushList();
			} else if ( headingMatch ) {
				flushParagraph();
				flushList();
				html.push( '<p class="chatscribe-heading">' + formatInline( headingMatch[ 1 ] ) + '</p>' );
			} else if ( bulletMatch || numberMatch ) {
				const tag = bulletMatch ? 'ul' : 'ol';
				flushParagraph();
				if ( !list || list.tag !== tag ) {
					flushList();
					list = { tag: tag, items: [] };
				}
				list.items.push( ( bulletMatch || numberMatch )[ 1 ] );
			} else if ( list && /^\s+/.test( line ) ) {
				// Indented line continues the previous list item.
				list.items[ list.items.length - 1 ] += ' ' + line.trim();
			} else {
				flushList();
				paragraph.push( line );
			}
		} );
		flushParagraph();
		flushList();

		return html.join( '' );
	}

	// Bold, italic, links and `code` within a single line. Code spans are set aside first,
	// so underscores or asterisks inside a name like `_wp_page_template` are never formatting.
	function formatInline( text ) {
		const codes = [];
		const withoutCode = text.replace( /`([^`\n]+)`/g, function ( match, code ) {
			codes.push( code );
			return '\u0000' + ( codes.length - 1 ) + '\u0000';
		} );
		return formatText( withoutCode ).replace( /\u0000(\d+)\u0000/g, function ( match, index ) {
			return '<code>' + codes[ index ] + '</code>';
		} );
	}

	function formatText( text ) {
		return text
			.replace( /\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>' )
			.replace( /\*\*(.+?)\*\*|__(.+?)__/g, function ( match, a, b ) {
				return '<strong>' + ( a || b ) + '</strong>';
			} )
			.replace( /(^|[^\w*])\*(?!\s)(.+?)\*(?!\w)/g, '$1<em>$2</em>' )
			.replace( /(^|[^\w])_(?!\s)(.+?)_(?!\w)/g, '$1<em>$2</em>' );
	}

	function escapeHtml( text ) {
		return text
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	// Removes emojis (and their modifiers) from bot replies.
	function stripEmojis( text ) {
		return String( text )
			.replace( /(?![©®™])[\p{Extended_Pictographic}\u{1F1E6}-\u{1F1FF}\u{1F3FB}-\u{1F3FF}\u{FE0F}\u{200D}\u{20E3}]/gu, '' )
			.replace( / {2,}/g, ' ' )
			.trim();
	}

	// Shows the bouncing "thinking" dots as a bot bubble.
	function showTyping() {
		hideTyping();
		const typingElement = document.createElement( 'div' );
		typingElement.id = 'chatscribeTyping';
		typingElement.classList.add( 'chatscribe-message', 'chatscribe-message--bot', 'chatscribe-typing' );
		typingElement.setAttribute( 'role', 'status' );
		const assistantName = ( window.ChatScribe && ChatScribe.assistantName ) ? ChatScribe.assistantName : 'The assistant';
		typingElement.setAttribute( 'aria-label', `${assistantName} is thinking` );
		typingElement.innerHTML = '<span></span><span></span><span></span>';
		messagesContainer.appendChild( typingElement );
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
	}

	function hideTyping() {
		const typingElement = document.getElementById( 'chatscribeTyping' );
		if ( typingElement ) {
			typingElement.remove();
		}
	}
} );
