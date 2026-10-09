/**
 * Download Certificates: run the bulk ZIP as a chunked job (start → steps → one ZIP per part)
 * instead of one long POST. Without this script the form posts exactly as before.
 */
( function () {
	'use strict';

	var cfg  = window.cgCertDlJob;
	var form = document.getElementById( 'cg-zip-form' );
	if ( ! cfg || ! form || ! window.fetch ) {
		return;
	}
	var firstBtn = form.querySelector( '.cg-zip-btn' );
	if ( ! firstBtn ) {
		return;
	}

	var box = firstBtn.parentNode;
	// Same markup as cg_ui_progress() (includes/Admin/ui.php).
	box.innerHTML =
		'<button type="button" class="button button-primary" id="cg-job-start"></button>' +
		'<div class="cg-progress cg-job-progress" id="cg-job-progress" hidden>' +
		'<progress id="cg-job-bar" max="1" value="0" aria-labelledby="cg-job-status"></progress>' +
		'<div class="cg-progress__label"><span class="cg-progress__text" id="cg-job-status" aria-live="polite"></span>' +
		'<span class="cg-progress__count"></span></div></div>' +
		'<ul id="cg-job-links" class="cg-actions"></ul>';

	var startBtn = document.getElementById( 'cg-job-start' );
	var progress = document.getElementById( 'cg-job-progress' );
	var links    = document.getElementById( 'cg-job-links' );
	startBtn.textContent = cfg.i18n.start.replace( '%d', cfg.total );

	function sleep( ms ) {
		return new Promise( function ( r ) { setTimeout( r, ms ); } );
	}

	function post( action, extra, attempt ) {
		attempt = attempt || 0;
		var body = new FormData( form );
		body.delete( '_wpnonce_cg_zip' );
		body.delete( 'cg_download_zip' );
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( extra || {} ).forEach( function ( k ) { body.set( k, extra[ k ] ); } );

		return fetch( cfg.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.busy ) {
					return sleep( 1000 ).then( function () { return post( action, extra, attempt ); } );
				}
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || cfg.i18n.error );
				}
				return json.data;
			} )
			.catch( function ( err ) {
				// Network blips / gateway timeouts: retry with backoff. The server only advances
				// the cursor after a certificate is saved, so a retry never skips or duplicates work.
				if ( attempt >= 4 || /permission|nonce|expired/i.test( err.message ) ) {
					throw err;
				}
				return sleep( 1000 * Math.pow( 2, attempt ) ).then( function () { return post( action, extra, attempt + 1 ); } );
			} );
	}

	function show( done, total, text ) {
		CGUI.progress( progress, done, total, text );
	}

	startBtn.addEventListener( 'click', function () {
		CGUI.busy( startBtn, true );
		links.innerHTML = '';
		show( 0, 0, cfg.i18n.starting );

		var job;
		post( 'cg_cert_dl_start' )
			.then( function ( d ) {
				job = d.job_id;
				return ( function step() {
					return post( 'cg_cert_dl_step', { job_id: job } ).then( function ( s ) {
						show( s.processed, s.total, cfg.i18n.progress.replace( '%1$d', s.processed ).replace( '%2$d', s.total ) );
						return s.complete ? s : step();
					} );
				} )();
			} )
			.then( function ( s ) {
				var parts = [];
				for ( var i = 0; i < s.parts; i++ ) {
					parts.push( i );
				}
				return parts.reduce( function ( chain, i ) {
					return chain.then( function () {
						show( i, s.parts, cfg.i18n.zipping.replace( '%1$d', i + 1 ).replace( '%2$d', s.parts ) );
						return post( 'cg_cert_dl_zip', { job_id: job, part: i } ).then( function ( z ) {
							var li = document.createElement( 'li' );
							var a  = document.createElement( 'a' );
							a.href        = z.download_url;
							a.className   = 'button';
							a.textContent = z.label;
							li.appendChild( a );
							links.appendChild( li );
							if ( 1 === s.parts ) {
								window.location.href = z.download_url;
							}
						} );
					} );
				}, Promise.resolve() ).then( function () {
					show( s.parts, s.parts, s.failed ? cfg.i18n.doneFailed.replace( '%d', s.failed ) : cfg.i18n.done );
				} );
			} )
			.catch( function ( err ) {
				CGUI.progressError( progress, err.message );
			} )
			.then( function () {
				CGUI.busy( startBtn, false );
			} );
	} );
}() );
