( function () {
	'use strict';

	const { restUrl, nonce } = wpAiDaemon;

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	const state = {
		conversationId: null,
		isLoading:      false,
	};

	// -------------------------------------------------------------------------
	// DOM refs
	// -------------------------------------------------------------------------

	const errorEl      = document.getElementById( 'wpad-error' );
	const threadEl     = document.getElementById( 'wpad-thread' );
	const emptyStateEl = document.getElementById( 'wpad-thread-empty' );
	const inputEl      = document.getElementById( 'wpad-input' );
	const sendBtn      = document.getElementById( 'wpad-send-btn' );
	const sendSpinner  = document.getElementById( 'wpad-send-spinner' );

	// -------------------------------------------------------------------------
	// Init — load conversation from URL param if present
	// -------------------------------------------------------------------------

	if ( wpAiDaemon.conversationId ) {
		loadConversation( wpAiDaemon.conversationId );
	}

	// -------------------------------------------------------------------------
	// Event listeners
	// -------------------------------------------------------------------------

	sendBtn.addEventListener( 'click', handleSend );

	inputEl.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' && ! e.metaKey && ! e.ctrlKey && ! e.shiftKey ) {
			e.preventDefault();
			handleSend();
		}
	} );

	// -------------------------------------------------------------------------
	// Send message
	// -------------------------------------------------------------------------

	async function handleSend() {
		const text = inputEl.value.trim();
		if ( ! text || state.isLoading ) return;

		clearError();
		appendMessage( 'user', text );
		inputEl.value = '';
		setLoading( true );

		const typingId = appendTyping();

		try {
			const response = await apiFetch( 'chat', {
				message:         text,
				conversation_id: state.conversationId || undefined,
			} );
			const data = await response.json();

			removeTyping( typingId );

			if ( ! response.ok || data.code ) {
				showError( friendlyError( data.message ) );
				return;
			}

			state.conversationId = data.conversation_id;

			appendMessage( 'assistant', data.message, data.actions || [] );
		} catch ( err ) {
			removeTyping( typingId );
			showError( err.message );
		} finally {
			setLoading( false );
			inputEl.focus();
		}
	}

	// -------------------------------------------------------------------------
	// Load conversation
	// -------------------------------------------------------------------------

	async function loadConversation( id ) {
		setLoading( true );
		clearError();

		try {
			const response = await fetch( restUrl + 'conversations/' + id, {
				headers: { 'X-WP-Nonce': nonce },
			} );
			const data = await response.json();

			if ( ! response.ok || data.code ) {
				showError( data.message || 'Could not load conversation.' );
				return;
			}

			state.conversationId = id;
			threadEl.innerHTML   = '';
			if ( emptyStateEl ) emptyStateEl.style.display = 'none';

			data.messages.forEach( ( msg ) => {
				if ( msg.role === 'user' ) {
					appendMessage( 'user', msg.content );
				} else {
					appendMessage( 'assistant', msg.content, msg.actions || [] );
				}
			} );

			} catch ( err ) {
			showError( 'Failed to load conversation: ' + err.message );
		} finally {
			setLoading( false );
			scrollThread();
		}
	}

	// -------------------------------------------------------------------------
	// Thread rendering
	// -------------------------------------------------------------------------

	function appendMessage( role, text, actions ) {
		if ( emptyStateEl ) emptyStateEl.style.display = 'none';

		const wrap     = document.createElement( 'div' );
		wrap.className = 'wpad-msg wpad-msg--' + ( role === 'user' ? 'user' : 'assistant' );

		const bubble     = document.createElement( 'div' );
		bubble.className = 'wpad-msg__bubble';

		if ( role === 'user' ) {
			bubble.textContent = text;
		} else {
			bubble.innerHTML = renderMarkdown( text );
		}

		wrap.appendChild( bubble );

		// Render action result cards below the assistant bubble.
		if ( role === 'assistant' && actions && actions.length ) {
			const cardsEl     = document.createElement( 'div' );
			cardsEl.className = 'wpad-msg__actions';

			actions.forEach( ( a ) => {
				cardsEl.appendChild( buildActionCard( a ) );
			} );

			wrap.appendChild( cardsEl );
		}

		threadEl.appendChild( wrap );
		scrollThread();
	}

	/**
	 * Builds a "View source" button that opens a full-screen modal.
	 */
	function buildSourceBlock( code, lang ) {
		const btn       = document.createElement( 'button' );
		btn.className   = 'wpad-source-btn button button-small';
		btn.textContent = 'View source';
		btn.addEventListener( 'click', () => openSourceModal( code, lang ) );
		return btn;
	}

	function openSourceModal( code, lang ) {
		// Remove any existing modal first.
		document.getElementById( 'wpad-source-modal' )?.remove();

		const overlay       = document.createElement( 'div' );
		overlay.id          = 'wpad-source-modal';
		overlay.className   = 'wpad-modal-overlay';
		overlay.addEventListener( 'click', ( e ) => {
			if ( e.target === overlay ) overlay.remove();
		} );

		const dialog       = document.createElement( 'div' );
		dialog.className   = 'wpad-modal-dialog';
		overlay.appendChild( dialog );

		const header       = document.createElement( 'div' );
		header.className   = 'wpad-modal-header';

		const title        = document.createElement( 'span' );
		title.className    = 'wpad-modal-title';
		title.textContent  = lang === 'json' ? 'Result' : 'Source';
		header.appendChild( title );

		const actions      = document.createElement( 'div' );
		actions.className  = 'wpad-modal-actions';

		const copyBtn      = document.createElement( 'button' );
		copyBtn.className  = 'wpad-modal-copy button button-small';
		copyBtn.textContent = 'Copy';
		copyBtn.addEventListener( 'click', () => {
			navigator.clipboard.writeText( code ).then( () => {
				copyBtn.textContent = 'Copied!';
				setTimeout( () => { copyBtn.textContent = 'Copy'; }, 2000 );
			} );
		} );
		actions.appendChild( copyBtn );

		const closeBtn      = document.createElement( 'button' );
		closeBtn.className  = 'wpad-modal-close';
		closeBtn.innerHTML  = '&times;';
		closeBtn.setAttribute( 'aria-label', 'Close' );
		closeBtn.addEventListener( 'click', () => overlay.remove() );
		actions.appendChild( closeBtn );

		header.appendChild( actions );
		dialog.appendChild( header );

		const pre       = document.createElement( 'pre' );
		pre.className   = 'wpad-modal-code';
		pre.textContent = code;
		dialog.appendChild( pre );

		document.body.appendChild( overlay );

		// Close on Escape.
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) { overlay.remove(); document.removeEventListener( 'keydown', onKey ); }
		};
		document.addEventListener( 'keydown', onKey );
	}

	function buildActionCard( a ) {
		const card     = document.createElement( 'div' );
		card.className = 'wpad-action-card';

		// Extract the slug from the full ability name: 'wp-ai-daemon/create-post' → 'create-post'
		const ability = a.ability || '';
		const slug    = ability.includes( '/' ) ? ability.split( '/' ).slice( 1 ).join( '/' ) : ability;

		if ( a.status === 'error' ) {
			card.classList.add( 'wpad-action-card--error' );
			card.innerHTML = `<span class="wpad-action-card__icon">⚠</span><span class="wpad-action-card__text">${ escHtml( ability ) } failed: ${ escHtml( a.error || 'Unknown error' ) }</span>`;
			return card;
		}

		const r = a.result || {};

		switch ( slug ) {
			case 'create-post':
			case 'create-page': {
				const type  = slug === 'create-page' ? 'Page' : 'Post';
				const label = `Draft ${ type }: "${ escHtml( r.title || 'Untitled' ) }"`;

				card.innerHTML = `<span class="wpad-action-card__icon">📄</span><span class="wpad-action-card__text">${ label }</span>`;

				if ( r.edit_link ) {
					const editLink       = document.createElement( 'a' );
					editLink.href        = r.edit_link;
					editLink.target      = '_blank';
					editLink.className   = 'wpad-action-card__btn button button-small';
					editLink.textContent = 'Edit';
					card.appendChild( editLink );
				}

				if ( r.post_id ) {
					const publishBtn       = document.createElement( 'button' );
					publishBtn.className   = 'wpad-action-card__btn button button-small';
					publishBtn.textContent = 'Publish';
					publishBtn.addEventListener( 'click', () => publishPost( r.post_id, publishBtn, card ) );
					card.appendChild( publishBtn );
				}
				break;
			}

			case 'update-post':
			case 'update-page': {
				const type = slug === 'update-page' ? 'Page' : 'Post';
				card.innerHTML = `<span class="wpad-action-card__icon">✏️</span><span class="wpad-action-card__text">Updated ${ type }: "${ escHtml( r.title || String( r.post_id || '' ) ) }"</span>`;

				if ( r.edit_link ) {
					const editLink       = document.createElement( 'a' );
					editLink.href        = r.edit_link;
					editLink.target      = '_blank';
					editLink.className   = 'wpad-action-card__btn button button-small';
					editLink.textContent = 'Edit';
					card.appendChild( editLink );
				}
				break;
			}

			case 'write-plugin': {
				card.innerHTML = `<span class="wpad-action-card__icon">🔌</span><span class="wpad-action-card__text">Plugin written: ${ escHtml( r.plugin_slug || r.plugin_file || '' ) }</span>`;

				if ( r.plugin_file ) {
					const activateBtn       = document.createElement( 'button' );
					activateBtn.className   = 'wpad-action-card__btn button button-small';
					activateBtn.textContent = 'Activate';
					activateBtn.addEventListener( 'click', () => activatePlugin( r.plugin_file, activateBtn, card ) );
					card.appendChild( activateBtn );
				}

				if ( r.code ) {
					card.appendChild( buildSourceBlock( r.code, 'php' ) );
				}
				break;
			}

			case 'run-snippet': {
				const desc = r.description ? escHtml( r.description ) : 'Snippet';

				card.innerHTML = `<span class="wpad-action-card__icon">⚡</span><span class="wpad-action-card__text">${ desc }</span>`;

				const outputPre       = document.createElement( 'pre' );
				outputPre.className   = 'wpad-snippet-output';
				outputPre.textContent = r.output || '(no output)';
				card.appendChild( outputPre );

				if ( r.code ) {
					card.appendChild( buildSourceBlock( r.code, 'php' ) );
				}
				break;
			}

			default: {
				// Generic card for third-party abilities (WooCommerce, SEO plugins, etc.)
				// Shows the full ability name and a JSON dump of the result for transparency.
				card.innerHTML = `<span class="wpad-action-card__icon">✓</span><span class="wpad-action-card__text">${ escHtml( ability ) }</span>`;

				if ( Object.keys( r ).length > 0 ) {
					card.appendChild( buildSourceBlock( JSON.stringify( r, null, 2 ), 'json' ) );
				}
				break;
			}
		}

		return card;
	}

	// -------------------------------------------------------------------------
	// Inline approval actions
	// -------------------------------------------------------------------------

	async function publishPost( postId, btn, card ) {
		btn.disabled     = true;
		btn.textContent  = 'Publishing…';

		try {
			const response = await fetch( restUrl + 'posts/' + postId + '/publish', {
				method:  'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			} );
			const data = await response.json();

			if ( response.ok && data.published ) {
				btn.remove();

				// Add a "View post" link.
				const viewLink       = document.createElement( 'a' );
				viewLink.href        = data.permalink;
				viewLink.target      = '_blank';
				viewLink.className   = 'wpad-action-card__btn button button-small';
				viewLink.textContent = 'View post';
				card.appendChild( viewLink );

				card.classList.add( 'wpad-action-card--published' );
			} else {
				btn.disabled    = false;
				btn.textContent = 'Publish';
				showError( data.message || 'Could not publish post.' );
			}
		} catch ( err ) {
			btn.disabled    = false;
			btn.textContent = 'Publish';
			showError( 'Could not publish post: ' + err.message );
		}
	}

	async function activatePlugin( pluginFile, btn, card ) {
		btn.disabled    = true;
		btn.textContent = 'Activating…';

		try {
			const response = await apiFetch( 'plugins/activate', { plugin_file: pluginFile } );
			const data     = await response.json();

			if ( response.ok && data.activated ) {
				btn.remove();
				card.classList.add( 'wpad-action-card--published' );

				const doneLabel       = document.createElement( 'span' );
				doneLabel.className   = 'wpad-action-card__text';
				doneLabel.textContent = ' — Active';
				card.appendChild( doneLabel );
			} else {
				btn.disabled    = false;
				btn.textContent = 'Activate';
				showError( data.message || 'Could not activate plugin.' );
			}
		} catch ( err ) {
			btn.disabled    = false;
			btn.textContent = 'Activate';
			showError( 'Could not activate plugin: ' + err.message );
		}
	}

	// -------------------------------------------------------------------------
	// Typing indicator
	// -------------------------------------------------------------------------

	let typingCounter = 0;

	function appendTyping() {
		const id       = 'wpad-typing-' + ( ++typingCounter );
		const wrap     = document.createElement( 'div' );
		wrap.id        = id;
		wrap.className = 'wpad-msg wpad-msg--assistant wpad-msg--typing';
		wrap.innerHTML = '<div class="wpad-msg__bubble"><span></span><span></span><span></span></div>';
		threadEl.appendChild( wrap );
		scrollThread();
		return id;
	}

	function removeTyping( id ) {
		const el = document.getElementById( id );
		if ( el ) el.remove();
	}

	function scrollThread() {
		threadEl.scrollTop = threadEl.scrollHeight;
	}

	// -------------------------------------------------------------------------
	// UI helpers
	// -------------------------------------------------------------------------

	function setLoading( active ) {
		state.isLoading  = active;
		sendBtn.disabled = active;
		inputEl.disabled = active;
		sendSpinner.classList.toggle( 'is-active', active );
	}

	function showError( message ) {
		// message may contain a safe link — use innerHTML directly inside the notice wrapper.
		const safeMsg = '<div class="notice notice-error inline"><p>' + message + '</p></div>';
		errorEl.innerHTML     = safeMsg;
		errorEl.style.display = 'block';
	}

	function clearError() {
		errorEl.innerHTML     = '';
		errorEl.style.display = 'none';
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	function apiFetch( endpoint, body ) {
		return fetch( restUrl + endpoint, {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce,
			},
			body: JSON.stringify( body ),
		} );
	}

	function friendlyError( message ) {
		if ( message && message.toLowerCase().includes( 'no models found' ) ) {
			const url = wpAiDaemon.connectorsUrl || '';
			return 'No AI provider is connected.'
				+ ( url ? ' <a href="' + escHtml( url ) + '">Set one up in Settings → Connectors.</a>' : '' );
		}
		return message || 'Something went wrong. Please try again.';
	}

	function notice( type, message ) {
		const safeType = String( type ).replace( /[^a-z-]/gi, '' );
		return '<div class="notice notice-' + safeType + ' inline"><p>' + escHtml( message ) + '</p></div>';
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/**
	 * Minimal markdown → HTML renderer for assistant messages.
	 * Handles: paragraphs, ordered/unordered lists, bold, italic, inline code.
	 */
	function renderMarkdown( text ) {
		const lines  = text.split( '\n' );
		const output = [];
		let listType  = null;
		let listItems = [];
		let paraLines = [];

		function flushList() {
			if ( ! listItems.length ) return;
			const tag = listType;
			output.push( '<' + tag + '>' + listItems.map( ( i ) => '<li>' + i + '</li>' ).join( '' ) + '</' + tag + '>' );
			listItems = [];
			listType  = null;
		}

		function flushPara() {
			if ( ! paraLines.length ) return;
			output.push( '<p>' + paraLines.join( '<br>' ) + '</p>' );
			paraLines = [];
		}

		lines.forEach( ( line ) => {
			const olMatch = line.match( /^(\d+)[.)]\s+(.*)$/ );
			const ulMatch = line.match( /^[-*+]\s+(.*)$/ );
			const h3Match = line.match( /^###\s+(.*)$/ );
			const h2Match = line.match( /^##\s+(.*)$/ );
			const h1Match = line.match( /^#\s+(.*)$/ );

			if ( h1Match || h2Match || h3Match ) {
				flushList();
				flushPara();
				const level   = h1Match ? 1 : h2Match ? 2 : 3;
				const content = inlineMarkdown( ( h1Match || h2Match || h3Match )[ 1 ] );
				output.push( '<h' + level + '>' + content + '</h' + level + '>' );
			} else if ( olMatch ) {
				flushPara();
				if ( listType !== 'ol' ) flushList();
				listType = 'ol';
				listItems.push( inlineMarkdown( olMatch[ 2 ] ) );
			} else if ( ulMatch ) {
				flushPara();
				if ( listType !== 'ul' ) flushList();
				listType = 'ul';
				listItems.push( inlineMarkdown( ulMatch[ 1 ] ) );
			} else if ( line.trim() === '' ) {
				flushList();
				flushPara();
			} else {
				flushList();
				paraLines.push( inlineMarkdown( line ) );
			}
		} );

		flushList();
		flushPara();

		return output.join( '' );
	}

	function inlineMarkdown( raw ) {
		let s = escHtml( raw );
		// Bold before italic to avoid mismatching single asterisks.
		s = s.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );
		s = s.replace( /__(.+?)__/g, '<strong>$1</strong>' );
		s = s.replace( /\*(.+?)\*/g, '<em>$1</em>' );
		s = s.replace( /_(.+?)_/g, '<em>$1</em>' );
		s = s.replace( /`(.+?)`/g, '<code>$1</code>' );
		return s;
	}
} )();
