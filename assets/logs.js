( function () {
	'use strict';

	const { restUrl, nonce } = wpAiDaemon;

	const logBodyEl    = document.getElementById( 'wpad-log-body' );
	const logTableEl   = document.getElementById( 'wpad-log-table' );
	const logEmptyEl   = document.getElementById( 'wpad-log-empty' );
	const logLoadingEl = document.getElementById( 'wpad-log-loading' );
	const filterBtns   = document.querySelectorAll( '.wpad-log-filter-btn' );

	let currentLevel = '';

	loadLog( '' );

	filterBtns.forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			filterBtns.forEach( ( b ) => b.classList.remove( 'is-active' ) );
			btn.classList.add( 'is-active' );
			currentLevel = btn.dataset.level || '';
			loadLog( currentLevel );
		} );
	} );

	const copyBtn = document.getElementById( 'wpad-log-copy' );
	if ( copyBtn ) {
		copyBtn.addEventListener( 'click', async () => {
			const limit = currentLevel === 'debug' ? 200 : 100;
			const url   = restUrl + 'log?limit=' + limit + ( currentLevel ? '&level=' + encodeURIComponent( currentLevel ) : '' );

			try {
				const response = await fetch( url, { headers: { 'X-WP-Nonce': nonce } } );
				const data     = await response.json();

				await navigator.clipboard.writeText( JSON.stringify( data, null, 2 ) );

				const orig = copyBtn.textContent;
				copyBtn.textContent = 'Copied!';
				setTimeout( () => { copyBtn.textContent = orig; }, 2000 );
			} catch {
				copyBtn.textContent = 'Copy failed';
				setTimeout( () => { copyBtn.textContent = 'Copy log as JSON'; }, 2000 );
			}
		} );
	}

	async function loadLog( level ) {
		logLoadingEl.style.display = 'block';
		logTableEl.style.display   = 'none';
		logEmptyEl.style.display   = 'none';

		const limit = level === 'debug' ? 200 : 20;

		try {
			const url = restUrl + 'log?limit=' + limit + ( level ? '&level=' + encodeURIComponent( level ) : '' );
			const response = await fetch( url, {
				headers: { 'X-WP-Nonce': nonce },
			} );
			const data = await response.json();

			logLoadingEl.style.display = 'none';

			if ( ! response.ok || ! Array.isArray( data ) ) {
				logEmptyEl.style.display = 'block';
				return;
			}

			if ( data.length === 0 ) {
				logEmptyEl.style.display = 'block';
				return;
			}

			logBodyEl.innerHTML = '';

			data.forEach( ( entry ) => {
				const tr = document.createElement( 'tr' );

				const tdTime    = document.createElement( 'td' );
				const tdLevel   = document.createElement( 'td' );
				const tdMsg     = document.createElement( 'td' );
				const tdContext = document.createElement( 'td' );

				tdTime.textContent  = entry.created_at || '';
				tdLevel.textContent = entry.level || '';
				tdMsg.textContent   = entry.message || '';

				if ( entry.level === 'error' ) {
					tr.classList.add( 'wpad-log-row--error' );
				} else if ( entry.level === 'debug' ) {
					tr.classList.add( 'wpad-log-row--debug' );
				}

				if ( entry.context ) {
					try {
						const parsed  = JSON.parse( entry.context );
						const pre     = document.createElement( 'pre' );
						pre.className = 'wpad-log-context';
						pre.textContent = JSON.stringify( parsed, null, 2 );

						const details     = document.createElement( 'details' );
						const summary     = document.createElement( 'summary' );
						summary.textContent = 'context';
						details.appendChild( summary );
						details.appendChild( pre );
						tdContext.appendChild( details );
					} catch {
						tdContext.textContent = entry.context;
					}
				}

				tr.appendChild( tdTime );
				tr.appendChild( tdLevel );
				tr.appendChild( tdMsg );
				tr.appendChild( tdContext );
				logBodyEl.appendChild( tr );
			} );

			logTableEl.style.display = 'table';
		} catch {
			logLoadingEl.style.display = 'none';
			logEmptyEl.style.display   = 'block';
		}
	}
} )();
