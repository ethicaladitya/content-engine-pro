/* Content Engine Pro — Setup Wizard JS */
( function () {
	'use strict';

	// ── Niche card picker (Step 2) ─────────────────────────────────────────
	var nicheCards = document.querySelectorAll( '.cep-niche-card' );
	var nicheInput = document.getElementById( 'cep-niche-vertical' );

	if ( nicheCards.length && nicheInput ) {
		nicheCards.forEach( function ( card ) {
			card.addEventListener( 'click', function () {
				// Deselect all
				nicheCards.forEach( function ( c ) {
					c.classList.remove( 'is-selected' );
					var radio = c.querySelector( 'input[type="radio"]' );
					if ( radio ) radio.checked = false;
				} );

				// Select clicked
				card.classList.add( 'is-selected' );
				var radio = card.querySelector( 'input[type="radio"]' );
				if ( radio ) radio.checked = true;
				nicheInput.value = card.dataset.niche;
			} );
		} );
	}

	// ── AI provider toggle (Step 3) ────────────────────────────────────────
	var providerOptions = document.querySelectorAll( '.cep-provider-option' );

	if ( providerOptions.length ) {
		providerOptions.forEach( function ( opt ) {
			opt.addEventListener( 'click', function () {
				providerOptions.forEach( function ( o ) { o.classList.remove( 'is-active' ); } );
				opt.classList.add( 'is-active' );
			} );
		} );
	}

	// ── Palette picker with live preview (Step 4) ──────────────────────────
	var paletteDefs = {
		pink     : { from: '#e879a0', to: '#f9a8d4' },
		purple   : { from: '#7c3aed', to: '#a78bfa' },
		emerald  : { from: '#059669', to: '#6ee7b7' },
		crimson  : { from: '#dc2626', to: '#fca5a5' },
		midnight : { from: '#1e40af', to: '#93c5fd' },
		gold     : { from: '#b45309', to: '#fcd34d' },
	};

	var paletteCards = document.querySelectorAll( '.cep-palette-card' );
	var previewBar   = document.getElementById( 'cep-preview-bar' );

	function applyPalette( key ) {
		var def = paletteDefs[ key ];
		if ( ! def || ! previewBar ) return;
		previewBar.style.background = 'linear-gradient(135deg,' + def.from + ' 0%,' + def.to + ' 100%)';
	}

	if ( paletteCards.length ) {
		paletteCards.forEach( function ( card ) {
			card.addEventListener( 'click', function () {
				paletteCards.forEach( function ( c ) { c.classList.remove( 'is-selected' ); } );
				card.classList.add( 'is-selected' );
				var radio = card.querySelector( 'input[type="radio"]' );
				if ( radio ) radio.checked = true;
				applyPalette( card.dataset.palette );
			} );
		} );
	}

	// ── Keyboard navigation for card grids ────────────────────────────────
	[ '.cep-niche-grid', '.cep-palette-grid' ].forEach( function ( selector ) {
		var grid = document.querySelector( selector );
		if ( ! grid ) return;

		grid.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' && e.key !== ' ' ) return;
			var focused = document.activeElement;
			if ( focused && grid.contains( focused ) ) {
				e.preventDefault();
				focused.click();
			}
		} );

		// Make cards focusable
		grid.querySelectorAll( 'label' ).forEach( function ( lbl ) {
			lbl.setAttribute( 'tabindex', '0' );
		} );
	} );

} )();
