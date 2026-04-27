( function () {
	'use strict';

	const { restUrl, nonce } = window.wpAiDaemonAbilities;

	document.querySelectorAll( '.wpad-toggle__input' ).forEach( function ( toggle ) {
		toggle.addEventListener( 'change', async function () {
			const ability  = this.dataset.ability;
			const enabled  = this.checked;
			const card     = this.closest( '.wpad-ability-card' );

			// Optimistic UI update.
			card.classList.toggle( 'wpad-ability-card--disabled', ! enabled );
			this.disabled = true;

			try {
				const response = await fetch( restUrl + 'abilities/toggle', {
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   nonce,
					},
					body: JSON.stringify( { name: ability, enabled: enabled } ),
				} );

				if ( ! response.ok ) {
					// Revert on failure.
					this.checked = ! enabled;
					card.classList.toggle( 'wpad-ability-card--disabled', enabled );
				}
			} catch ( _err ) {
				// Revert on network error.
				this.checked = ! enabled;
				card.classList.toggle( 'wpad-ability-card--disabled', enabled );
			} finally {
				this.disabled = false;
			}
		} );
	} );
} )();
