( function () {
	'use strict';

	const { restUrl, nonce, connectorsUrl, aiReady } = wpAiDaemonDrawer;

	// -------------------------------------------------------------------------
	// DOM refs
	// -------------------------------------------------------------------------

	const drawer      = document.getElementById( 'wpad-drawer' );
	const toolbarBtn  = document.getElementById( 'wp-admin-bar-wpad-drawer-toggle' );
	const closeBtn    = document.getElementById( 'wpad-drawer-close' );
	const threadEl    = document.getElementById( 'wpad-drawer-thread' );
	const emptyEl     = document.getElementById( 'wpad-drawer-empty' );
	const inputEl     = document.getElementById( 'wpad-drawer-input' );
	const sendBtn     = document.getElementById( 'wpad-drawer-send' );
	const spinner     = document.getElementById( 'wpad-drawer-spinner' );

	if ( ! drawer || ! toolbarBtn ) return;

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	const state = {
		isOpen:         false,
		isLoading:      false,
		conversationId: null,
	};

	// -------------------------------------------------------------------------
	// Open / close
	// -------------------------------------------------------------------------

	function open() {
		state.isOpen = true;
		drawer.classList.add( 'is-open' );
		drawer.setAttribute( 'aria-hidden', 'false' );
		toolbarBtn.classList.add( 'is-active' );
		inputEl.focus();
	}

	function close() {
		state.isOpen = false;
		drawer.classList.remove( 'is-open' );
		drawer.setAttribute( 'aria-hidden', 'true' );
		toolbarBtn.classList.remove( 'is-active' );
	}

	function toggle() {
		state.isOpen ? close() : open();
	}

	// Toolbar button click.
	toolbarBtn.querySelector( 'a' ).addEventListener( 'click', ( e ) => {
		e.preventDefault();
		toggle();
	} );

	// Close button.
	closeBtn.addEventListener( 'click', close );

	// Escape key.
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && state.isOpen ) close();
	} );

	// -------------------------------------------------------------------------
	// Not-ready state
	// -------------------------------------------------------------------------

	if ( ! aiReady ) {
		emptyEl.innerHTML =
			'No AI provider is connected. <a href="' + escHtml( connectorsUrl ) + '">Set one up in Settings → Connectors.</a>';
		return;
	}

	// -------------------------------------------------------------------------
	// Send message
	// -------------------------------------------------------------------------

	sendBtn.addEventListener( 'click', handleSend );

	inputEl.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' && ! e.metaKey && ! e.ctrlKey && ! e.shiftKey ) {
			e.preventDefault();
			handleSend();
		}
	} );

	async function handleSend() {
		const text = inputEl.value.trim();
		if ( ! text || state.isLoading ) return;

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
				appendError( data.message || 'Something went wrong. Please try again.' );
				return;
			}

			state.conversationId = data.conversation_id;
			appendMessage( 'assistant', data.message, data.actions || [] );
		} catch ( err ) {
			removeTyping( typingId );
			appendError( 'Network error. Please try again.' );
		} finally {
			setLoading( false );
			inputEl.focus();
		}
	}

	// -------------------------------------------------------------------------
	// Thread rendering
	// -------------------------------------------------------------------------

	function appendMessage( role, text, actions ) {
		if ( emptyEl ) emptyEl.style.display = 'none';

		const wrap     = document.createElement( 'div' );
		wrap.className = 'wpad-drawer-msg wpad-drawer-msg--' + ( role === 'user' ? 'user' : 'assistant' );

		const bubble     = document.createElement( 'div' );
		bubble.className = 'wpad-drawer-msg__bubble';

		if ( role === 'user' ) {
			bubble.textContent = text;
		} else {
			bubble.innerHTML = renderMarkdown( text );
		}

		wrap.appendChild( bubble );

		if ( role === 'assistant' && actions && actions.length ) {
			const cardsEl     = document.createElement( 'div' );
			cardsEl.className = 'wpad-drawer-msg__actions';
			actions.forEach( ( a ) => cardsEl.appendChild( buildActionCard( a ) ) );
			wrap.appendChild( cardsEl );
		}

		threadEl.appendChild( wrap );
		threadEl.scrollTop = threadEl.scrollHeight;
	}

	function appendError( message ) {
		const el       = document.createElement( 'div' );
		el.className   = 'wpad-drawer-action-card wpad-drawer-action-card--error';
		el.style.marginTop = '4px';
		el.innerHTML   = `<span class="wpad-drawer-action-card__icon">⚠</span><span class="wpad-drawer-action-card__text">${ escHtml( message ) }</span>`;
		threadEl.appendChild( el );
		threadEl.scrollTop = threadEl.scrollHeight;
	}

	// -------------------------------------------------------------------------
	// Typing indicator
	// -------------------------------------------------------------------------

	let typingCounter = 0;

	function appendTyping() {
		const id       = 'wpad-drawer-typing-' + ( ++typingCounter );
		const wrap     = document.createElement( 'div' );
		wrap.id        = id;
		wrap.className = 'wpad-drawer-msg wpad-drawer-msg--assistant wpad-drawer-msg--typing';
		wrap.innerHTML = '<div class="wpad-drawer-msg__bubble"><span></span><span></span><span></span></div>';
		threadEl.appendChild( wrap );
		threadEl.scrollTop = threadEl.scrollHeight;
		return id;
	}

	function removeTyping( id ) {
		document.getElementById( id )?.remove();
	}

	// -------------------------------------------------------------------------
	// Action cards — mirrors chat.js buildActionCard logic
	// -------------------------------------------------------------------------

	function buildActionCard( a ) {
		const card     = document.createElement( 'div' );
		card.className = 'wpad-drawer-action-card';

		const ability = a.ability || '';
		const slug    = ability.includes( '/' ) ? ability.split( '/' ).slice( 1 ).join( '/' ) : ability;

		if ( a.status === 'error' ) {
			card.classList.add( 'wpad-drawer-action-card--error' );
			card.innerHTML = `<span class="wpad-drawer-action-card__icon">⚠</span><span class="wpad-drawer-action-card__text">${ escHtml( ability ) } failed: ${ escHtml( a.error || 'Unknown error' ) }</span>`;
			return card;
		}

		const r = a.result || {};

		switch ( slug ) {
			case 'create-post':
			case 'create-page': {
				const type  = slug === 'create-page' ? 'Page' : 'Post';
				card.innerHTML = `<span class="wpad-drawer-action-card__icon">📄</span><span class="wpad-drawer-action-card__text">Draft ${ type }: "${ escHtml( r.title || 'Untitled' ) }"</span>`;

				if ( r.edit_link ) {
					card.appendChild( makeBtn( 'Edit', r.edit_link, '_blank' ) );
				}
				if ( r.post_id ) {
					const publishBtn = makeActionBtn( 'Publish' );
					publishBtn.addEventListener( 'click', () => publishPost( r.post_id, publishBtn, card ) );
					card.appendChild( publishBtn );
				}
				break;
			}

			case 'update-post':
			case 'update-page': {
				const type = slug === 'update-page' ? 'Page' : 'Post';
				card.innerHTML = `<span class="wpad-drawer-action-card__icon">✏️</span><span class="wpad-drawer-action-card__text">Updated ${ type }: "${ escHtml( r.title || String( r.post_id || '' ) ) }"</span>`;
				if ( r.edit_link ) card.appendChild( makeBtn( 'Edit', r.edit_link, '_blank' ) );
				break;
			}

			case 'write-plugin': {
				card.innerHTML = `<span class="wpad-drawer-action-card__icon">🔌</span><span class="wpad-drawer-action-card__text">Plugin written: ${ escHtml( r.plugin_slug || r.plugin_file || '' ) }</span>`;
				if ( r.plugin_file ) {
					const activateBtn = makeActionBtn( 'Activate' );
					activateBtn.addEventListener( 'click', () => activatePlugin( r.plugin_file, activateBtn, card ) );
					card.appendChild( activateBtn );
				}
				if ( r.code ) card.appendChild( buildSourceBtn( r.code, 'php' ) );
				break;
			}

			case 'run-snippet': {
				const desc = r.description ? escHtml( r.description ) : 'Snippet';
				card.innerHTML = `<span class="wpad-drawer-action-card__icon">⚡</span><span class="wpad-drawer-action-card__text">${ desc }</span>`;

				const pre       = document.createElement( 'pre' );
				pre.className   = 'wpad-drawer-snippet-output';
				pre.textContent = r.output || '(no output)';
				card.appendChild( pre );

				if ( r.code ) card.appendChild( buildSourceBtn( r.code, 'php' ) );
				break;
			}

			default: {
				card.innerHTML = `<span class="wpad-drawer-action-card__icon">✓</span><span class="wpad-drawer-action-card__text">${ escHtml( ability ) }</span>`;
				if ( Object.keys( r ).length > 0 ) card.appendChild( buildSourceBtn( JSON.stringify( r, null, 2 ), 'json' ) );
				break;
			}
		}

		return card;
	}

	// -------------------------------------------------------------------------
	// Inline approval actions
	// -------------------------------------------------------------------------

	async function publishPost( postId, btn, card ) {
		btn.disabled    = true;
		btn.textContent = 'Publishing…';

		try {
			const response = await fetch( restUrl + 'posts/' + postId + '/publish', {
				method:  'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			} );
			const data = await response.json();

			if ( response.ok && data.published ) {
				btn.remove();
				const viewLink = makeBtn( 'View post', data.permalink, '_blank' );
				card.appendChild( viewLink );
				card.classList.add( 'wpad-drawer-action-card--success' );
			} else {
				btn.disabled    = false;
				btn.textContent = 'Publish';
			}
		} catch {
			btn.disabled    = false;
			btn.textContent = 'Publish';
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
				card.classList.add( 'wpad-drawer-action-card--success' );
				const done       = document.createElement( 'span' );
				done.textContent = '— Active';
				card.appendChild( done );
			} else {
				btn.disabled    = false;
				btn.textContent = 'Activate';
			}
		} catch {
			btn.disabled    = false;
			btn.textContent = 'Activate';
		}
	}

	// -------------------------------------------------------------------------
	// Source viewer (shared modal — works page-wide)
	// -------------------------------------------------------------------------

	function buildSourceBtn( code, lang ) {
		const btn       = document.createElement( 'button' );
		btn.className   = 'wpad-drawer-action-card__btn button button-small';
		btn.textContent = 'View source';
		btn.style.flexBasis = '100%';
		btn.style.marginTop = '4px';
		btn.addEventListener( 'click', () => openSourceModal( code, lang ) );
		return btn;
	}

	function openSourceModal( code, lang ) {
		document.getElementById( 'wpad-source-modal' )?.remove();

		const overlay     = document.createElement( 'div' );
		overlay.id        = 'wpad-source-modal';
		overlay.className = 'wpad-modal-overlay';
		overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) overlay.remove(); } );

		const dialog     = document.createElement( 'div' );
		dialog.className = 'wpad-modal-dialog';

		const header     = document.createElement( 'div' );
		header.className = 'wpad-modal-header';

		const title       = document.createElement( 'span' );
		title.className   = 'wpad-modal-title';
		title.textContent = lang === 'json' ? 'Result' : 'Source';
		header.appendChild( title );

		const actions     = document.createElement( 'div' );
		actions.className = 'wpad-modal-actions';

		const copyBtn       = document.createElement( 'button' );
		copyBtn.className   = 'wpad-modal-copy button button-small';
		copyBtn.textContent = 'Copy';
		copyBtn.addEventListener( 'click', () => {
			navigator.clipboard.writeText( code ).then( () => {
				copyBtn.textContent = 'Copied!';
				setTimeout( () => { copyBtn.textContent = 'Copy'; }, 2000 );
			} );
		} );
		actions.appendChild( copyBtn );

		const closeModalBtn     = document.createElement( 'button' );
		closeModalBtn.className = 'wpad-modal-close';
		closeModalBtn.innerHTML = '&times;';
		closeModalBtn.setAttribute( 'aria-label', 'Close' );
		closeModalBtn.addEventListener( 'click', () => overlay.remove() );
		actions.appendChild( closeModalBtn );

		header.appendChild( actions );
		dialog.appendChild( header );

		const pre       = document.createElement( 'pre' );
		pre.className   = 'wpad-modal-code';
		pre.textContent = code;
		dialog.appendChild( pre );

		overlay.appendChild( dialog );
		document.body.appendChild( overlay );

		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) { overlay.remove(); document.removeEventListener( 'keydown', onKey ); }
		};
		document.addEventListener( 'keydown', onKey );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function makeBtn( label, href, target ) {
		const a       = document.createElement( 'a' );
		a.href        = href;
		a.textContent = label;
		a.className   = 'wpad-drawer-action-card__btn button button-small';
		if ( target ) a.target = target;
		return a;
	}

	function makeActionBtn( label ) {
		const btn       = document.createElement( 'button' );
		btn.className   = 'wpad-drawer-action-card__btn button button-small';
		btn.textContent = label;
		return btn;
	}

	function setLoading( active ) {
		state.isLoading  = active;
		sendBtn.disabled = active;
		inputEl.disabled = active;
		spinner.classList.toggle( 'is-active', active );
	}

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

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/**
	 * Mirrors the renderMarkdown / inlineMarkdown logic from chat.js exactly.
	 */
	function renderMarkdown( text ) {
		const lines   = text.split( '\n' );
		const output  = [];
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
				flushList(); flushPara();
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
				flushList(); flushPara();
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
		s = s.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );
		s = s.replace( /__(.+?)__/g,     '<strong>$1</strong>' );
		s = s.replace( /\*(.+?)\*/g,     '<em>$1</em>' );
		s = s.replace( /_(.+?)_/g,       '<em>$1</em>' );
		s = s.replace( /`(.+?)`/g,       '<code>$1</code>' );
		return s;
	}

} )();
