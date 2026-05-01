/* wpb-access-control admin UI — plain JS, no dependencies */
( function () {
	'use strict';

	var cfg     = window.wpbAcAdmin || {};
	var ajaxUrl = cfg.ajaxUrl || '';
	var nonce   = cfg.nonce   || '';
	var i18n    = cfg.i18n    || {};

	/* ── Helpers ──────────────────────────────────────────────────────────── */

	function escHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/* ── Per-panel initializer ────────────────────────────────────────────── */

	function initPanel( panel ) {
		if ( panel.dataset.wpbAcBound ) { return; }
		panel.dataset.wpbAcBound = '1';

		var typeSelect = panel.querySelector( '.wpb-ac-type-select' );
		var optRows    = panel.querySelectorAll( '.wpb-ac-options-row' );

		/* ── Type-select toggle ─────────────────────────────────────────── */
		function applyToggle() {
			var chosen = typeSelect ? typeSelect.value : '';
			optRows.forEach( function ( row ) {
				var active = row.classList.contains( 'wpb-ac-options-' + chosen );
				row.style.display = active ? '' : 'none';

				if ( ! active ) {
					/* Uncheck role/membership checkboxes. */
					row.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
						cb.checked = false;
					} );
					/* Remove user tags (clears their hidden inputs). */
					row.querySelectorAll( '.wpb-ac-user-tag' ).forEach( function ( tag ) {
						tag.remove();
					} );
				}
			} );
		}

		if ( typeSelect ) {
			typeSelect.addEventListener( 'change', applyToggle );
		}

		/* ── User search ────────────────────────────────────────────────── */
		var searchInput = panel.querySelector( '.wpb-ac-user-search' );
		if ( ! searchInput ) { return; }

		var wrap        = searchInput.closest( '.wpb-ac-user-search-wrap' );
		var resultsBox  = wrap ? wrap.querySelector( '.wpb-ac-search-results' ) : null;
		var selectedBox = panel.querySelector( '.wpb-ac-selected-users' );

		if ( ! resultsBox || ! selectedBox ) { return; }

		var timer = null;

		function getSelectedIds() {
			return Array.from( selectedBox.querySelectorAll( 'input[type="hidden"]' ) )
				.map( function ( el ) { return el.value; } );
		}

		function buildTag( user ) {
			var tag = document.createElement( 'span' );
			tag.className  = 'wpb-ac-user-tag';
			tag.dataset.id = user.id;
			tag.innerHTML  =
				'<span>' + escHtml( user.display_name ) + '</span>' +
				' <span class="wpb-ac-user-tag-login">(' + escHtml( user.login ) + ')</span>' +
				' <button type="button" class="wpb-ac-remove-user" aria-label="' + escHtml( i18n.remove || 'Remove' ) + '">&times;</button>' +
				'<input type="hidden" name="ac_options[]" value="' + escHtml( user.id ) + '">';
			tag.querySelector( '.wpb-ac-remove-user' ).addEventListener( 'click', function () {
				tag.remove();
			} );
			return tag;
		}

		/* Remove-button clicks on pre-rendered tags (added on page load). */
		selectedBox.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.wpb-ac-remove-user' );
			if ( btn ) {
				btn.closest( '.wpb-ac-user-tag' ).remove();
			}
		} );

		function renderResults( users ) {
			resultsBox.innerHTML = '';

			if ( ! users || ! users.length ) {
				var msg = document.createElement( 'div' );
				msg.className   = 'wpb-ac-search-no-results';
				msg.textContent = i18n.noResults || 'No users found.';
				resultsBox.appendChild( msg );
				resultsBox.style.display = 'block';
				return;
			}

			users.forEach( function ( user ) {
				var item = document.createElement( 'div' );
				item.className = 'wpb-ac-search-result-item';
				item.setAttribute( 'tabindex', '0' );
				item.innerHTML =
					'<strong>' + escHtml( user.display_name ) + '</strong>' +
					' <span class="wpb-ac-result-meta">&mdash; ' +
					escHtml( user.login ) + ' &lt;' + escHtml( user.email ) + '&gt;</span>';

				function selectUser() {
					if ( getSelectedIds().indexOf( user.id ) === -1 ) {
						selectedBox.appendChild( buildTag( user ) );
					}
					searchInput.value        = '';
					resultsBox.style.display = 'none';
					searchInput.focus();
				}

				item.addEventListener( 'click', selectUser );
				item.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); selectUser(); }
				} );
				resultsBox.appendChild( item );
			} );

			resultsBox.style.display = 'block';
		}

		function doSearch( term ) {
			if ( term.length < 2 ) {
				resultsBox.style.display = 'none';
				return;
			}

			resultsBox.innerHTML = '<div class="wpb-ac-search-no-results">' + escHtml( i18n.searching || 'Searching…' ) + '</div>';
			resultsBox.style.display = 'block';

			var url = ajaxUrl +
				'?action=wpb_access_control_search_users' +
				'&term=' + encodeURIComponent( term ) +
				'&_ajax_nonce=' + encodeURIComponent( nonce );

			fetch( url )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( data && data.success ) {
						renderResults( data.data );
					} else {
						renderResults( [] );
					}
				} )
				.catch( function () {
					resultsBox.style.display = 'none';
				} );
		}

		searchInput.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () { doSearch( searchInput.value.trim() ); }, 300 );
		} );

		/* Close results when clicking outside. */
		document.addEventListener( 'click', function ( e ) {
			if ( ! wrap.contains( e.target ) ) {
				resultsBox.style.display = 'none';
			}
		} );

		/* Keyboard: Escape closes results. */
		searchInput.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				resultsBox.style.display = 'none';
			}
		} );
	}

	/* ── Boot ─────────────────────────────────────────────────────────────── */

	function init() {
		document.querySelectorAll( '[data-wpb-ac-form]' ).forEach( initPanel );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
