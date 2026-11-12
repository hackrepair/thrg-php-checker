/**
 * Client-side controller for The Hack Repair Guy's PHP Checker.
 *
 * Drives time-boxed AJAX scan steps with both component-level and file-level
 * progress feedback, single-component rescanning, target switching, and
 * plain-text report export.
 */
( function () {
    'use strict';

    var cfg = window.thrgPcData || {};
    var form = document.getElementById( 'thrg-pc-scan-form' );
    var targetSelect = document.getElementById( 'thrg-pc-target' );
    var scopeSelect = document.getElementById( 'thrg-pc-scope' );
    var btnScan = document.getElementById( 'thrg-pc-btn-scan' );
    var btnRescan = document.getElementById( 'thrg-pc-btn-rescan' );
    var btnStop = document.getElementById( 'thrg-pc-btn-stop' );
    var progressBox = document.getElementById( 'thrg-pc-progress' );
    var progressFill = document.getElementById( 'thrg-pc-progress-fill' );
    var progressStatus = document.getElementById( 'thrg-pc-progress-status' );
    var progressDetail = document.getElementById( 'thrg-pc-progress-detail' );

    var active = false;
    var stopping = false;
    var rescanRequested = false;

    function postAjax( action, params ) {
        var body = new URLSearchParams();
        body.set( 'action', action );
        body.set( '_ajax_nonce', cfg.nonce || '' );
        Object.keys( params || {} ).forEach( function ( k ) {
            body.set( k, params[ k ] );
        } );
        return window.fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        } ).then( function ( r ) {
            return r.json();
        } );
    }

    function redirectToTarget( target, hash ) {
        var t = target || ( targetSelect ? targetSelect.value : cfg.target );
        var url = ( cfg.pageUrl || window.location.pathname ) + '&target=' + encodeURIComponent( t );
        if ( hash ) {
            url += hash;
        }
        window.location.href = url;
    }

    function updateProgressUi( data ) {
        if ( ! progressBox ) {
            return;
        }
        progressBox.hidden = false;
        var total = parseInt( data.total, 10 ) || 0;
        var finished = parseInt( data.finished, 10 ) || 0;
        var filesDone = parseInt( data.files_done, 10 ) || 0;
        var filesTotal = parseInt( data.files_total, 10 ) || 0;
        var frac = 0;

        if ( total > 0 ) {
            var sub = filesTotal > 0 ? Math.min( 0.99, filesDone / filesTotal ) : 0;
            frac = Math.min( 100, Math.round( ( ( finished + sub ) / total ) * 100 ) );
        }

        if ( progressFill ) {
            progressFill.style.width = frac + '%';
        }
        if ( progressStatus ) {
            progressStatus.textContent = total > 0
                ? 'Scanning for PHP ' + ( data.target || cfg.target ) + ': ' + finished + ' of ' + total + ' components completed (' + frac + '%)'
                : 'Scanning…';
        }
        if ( progressDetail ) {
            if ( data.current ) {
                progressDetail.textContent = filesTotal > 0
                    ? data.current + ' — ' + filesDone + ' / ' + filesTotal + ' PHP files'
                    : data.current;
            } else {
                progressDetail.textContent = '';
            }
        }
    }

    function setControlsBusy( busy ) {
        active = busy;
        if ( btnScan ) {
            btnScan.disabled = busy;
        }
        if ( btnRescan ) {
            btnRescan.disabled = busy;
        }
        if ( targetSelect ) {
            targetSelect.disabled = busy;
        }
        if ( scopeSelect ) {
            scopeSelect.disabled = busy;
        }
        if ( btnStop ) {
            btnStop.hidden = ! busy;
        }
    }

    function stepLoop( hashAfter ) {
        if ( stopping ) {
            return;
        }
        postAjax( 'thrg_pc_scan_step', {} )
            .then( function ( res ) {
                if ( stopping ) {
                    return;
                }
                if ( ! res || ! res.success || ! res.data ) {
                    if ( progressStatus ) {
                        progressStatus.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Scan step interrupted — reload page to resume.';
                    }
                    setControlsBusy( false );
                    return;
                }
                updateProgressUi( res.data );
                if ( res.data.done ) {
                    redirectToTarget( res.data.target, hashAfter );
                    return;
                }
                stepLoop( hashAfter );
            } )
            .catch( function () {
                if ( progressStatus ) {
                    progressStatus.textContent = 'Network interruption during scan — reload page to resume where it left off.';
                }
                setControlsBusy( false );
            } );
    }

    function startScan( params, hashAfter ) {
        stopping = false;
        setControlsBusy( true );
        updateProgressUi( {
            total: 0,
            finished: 0,
            current: 'Building component queue…',
            target: params.target
        } );

        postAjax( 'thrg_pc_scan_start', params )
            .then( function ( res ) {
                if ( ! res || ! res.success || ! res.data ) {
                    if ( progressStatus ) {
                        progressStatus.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Unable to start scan.';
                    }
                    setControlsBusy( false );
                    return;
                }
                if ( res.data.done ) {
                    redirectToTarget( res.data.target, hashAfter );
                    return;
                }
                updateProgressUi( res.data );
                stepLoop( hashAfter );
            } )
            .catch( function () {
                if ( progressStatus ) {
                    progressStatus.textContent = 'Request failed while starting scan.';
                }
                setControlsBusy( false );
            } );
    }

    if ( btnRescan ) {
        btnRescan.addEventListener( 'click', function () {
            rescanRequested = true;
        } );
    }
    if ( btnScan ) {
        btnScan.addEventListener( 'click', function () {
            rescanRequested = false;
        } );
    }

    if ( targetSelect ) {
        targetSelect.addEventListener( 'change', function () {
            if ( ! active ) {
                redirectToTarget( targetSelect.value );
            }
        } );
    }

    if ( form && window.fetch ) {
        form.addEventListener( 'submit', function ( e ) {
            if ( stopping ) {
                return;
            }
            e.preventDefault();
            startScan( {
                target: targetSelect ? targetSelect.value : cfg.target,
                scope: scopeSelect ? scopeSelect.value : 'all',
                rescan: rescanRequested ? '1' : ''
            } );
        } );
    }

    if ( btnStop && window.fetch ) {
        btnStop.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            stopping = true;
            postAjax( 'thrg_pc_scan_stop', {} ).finally( function () {
                redirectToTarget( targetSelect ? targetSelect.value : cfg.target );
            } );
        } );
    }

    // Single-component Rescan buttons on finding cards and clean table rows.
    document.querySelectorAll( '.thrg-pc-rescan-single' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            if ( active ) {
                return;
            }
            var singleKey = btn.getAttribute( 'data-single-key' ) || '';
            var target = btn.getAttribute( 'data-target' ) || ( targetSelect ? targetSelect.value : cfg.target );
            var card = btn.closest( 'article.thrg-card' );
            var hash = card && card.id ? '#' + card.id : '';
            btn.disabled = true;
            btn.textContent = 'Scanning…';
            startScan(
                {
                    target: target,
                    scope: 'single',
                    single_key: singleKey,
                    rescan: '1'
                },
                hash
            );
        } );
    } );

    // Resume an already-running scan when page loads.
    if ( cfg.running && window.fetch ) {
        setControlsBusy( true );
        if ( cfg.state ) {
            updateProgressUi( {
                total: cfg.state.total,
                finished: cfg.state.finished,
                current: cfg.state.current,
                files_done: cfg.state.files_done,
                files_total: cfg.state.files_total,
                target: cfg.target
            } );
        }
        stepLoop();
    }

    // Copy, Download, and Print buttons.
    var copyBtn = document.getElementById( 'thrg-pc-copy-btn' );
    var downloadBtn = document.getElementById( 'thrg-pc-download-btn' );
    var printBtn = document.getElementById( 'thrg-pc-print-btn' );
    var plainArea = document.getElementById( 'thrg-pc-plain-text' );
    var copyStatus = document.getElementById( 'thrg-pc-copy-status' );

    if ( copyBtn && plainArea ) {
        copyBtn.addEventListener( 'click', function () {
            var text = plainArea.value;
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText( text ).then( function () {
                    if ( copyStatus ) {
                        copyStatus.textContent = 'Copied to clipboard.';
                    }
                } );
            } else {
                plainArea.select();
                document.execCommand( 'copy' );
                if ( copyStatus ) {
                    copyStatus.textContent = 'Copied to clipboard.';
                }
            }
        } );
    }

    if ( downloadBtn && plainArea ) {
        downloadBtn.addEventListener( 'click', function () {
            var blob = new Blob( [ plainArea.value ], { type: 'text/plain;charset=utf-8' } );
            var url = URL.createObjectURL( blob );
            var a = document.createElement( 'a' );
            a.href = url;
            a.download = downloadBtn.getAttribute( 'data-filename' ) || 'php-checker-report.txt';
            document.body.appendChild( a );
            a.click();
            document.body.removeChild( a );
            URL.revokeObjectURL( url );
        } );
    }

    if ( printBtn ) {
        printBtn.addEventListener( 'click', function () {
            window.print();
        } );
    }

    // Expand / Collapse all code finding tables.
    var toggleAllBtn = document.getElementById( 'thrg-pc-toggle-all-details' );
    if ( toggleAllBtn ) {
        toggleAllBtn.addEventListener( 'click', function () {
            var expanded = '1' === toggleAllBtn.getAttribute( 'data-expanded' );
            var nextState = ! expanded;
            document.querySelectorAll( 'article.thrg-card .thrg-pc-tech-details' ).forEach( function ( el ) {
                el.open = nextState;
            } );
            toggleAllBtn.setAttribute( 'data-expanded', nextState ? '1' : '0' );
            toggleAllBtn.textContent = nextState ? 'Collapse code tables' : 'Expand all code tables';
        } );
    }

    // Auto-open collapsible panels when clicked from the left navigation.
    document.querySelectorAll( '[data-open-details]' ).forEach( function ( link ) {
        link.addEventListener( 'click', function () {
            var detailsId = link.getAttribute( 'data-open-details' );
            var detailsEl = detailsId ? document.getElementById( detailsId ) : null;
            if ( detailsEl ) {
                detailsEl.open = true;
            }
        } );
    } );

    // Manual Check Pending Updates button.
    var prescanBtn = document.getElementById( 'thrg-pc-prescan-btn' );
    var prescanStatus = document.getElementById( 'thrg-pc-prescan-status' );
    if ( prescanBtn && window.fetch ) {
        prescanBtn.addEventListener( 'click', function () {
            prescanBtn.disabled = true;
            if ( prescanStatus ) {
                prescanStatus.textContent = 'Checking pending update packages in memory…';
            }
            var body = new URLSearchParams();
            body.set( 'action', 'thrg_pc_prescan_now' );
            body.set( '_ajax_nonce', cfg.prescanNonce || '' );
            window.fetch( cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            } )
                .then( function ( r ) {
                    return r.json();
                } )
                .then( function ( res ) {
                    prescanBtn.disabled = false;
                    if ( res && res.success && res.data ) {
                        if ( prescanStatus ) {
                            prescanStatus.textContent = 'Checked ' + res.data.checked + ' of ' + res.data.offers + ' pending update(s). Reloading…';
                        }
                        redirectToTarget( targetSelect ? targetSelect.value : cfg.target, '#update-checks' );
                    } else if ( prescanStatus ) {
                        prescanStatus.textContent = 'Unable to check pending updates right now.';
                    }
                } )
                .catch( function () {
                    prescanBtn.disabled = false;
                    if ( prescanStatus ) {
                        prescanStatus.textContent = 'Network error while checking updates.';
                    }
                } );
        } );
    }
}() );
