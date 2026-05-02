/**
 * Media Negotiator – AJAX Cache Warmup
 *
 * Ablauf:
 *  1. Typen & Medienzahl vom API laden (action=types)
 *  2. Schrittweise Mediendateien laden (action=media, paginiert)
 *  3. Pro Datei × Typ × Format: fetch() auf die Derivat-URL feuern
 *     → der Media Manager erzeugt und cacht das Derivat serverseitig
 *  4. Fortschrittsbalken + Log aktualisieren
 */

(function () {
    'use strict';

    var PAGE_ID = 'media_manager/media_negotiator/warmup';

    /** Accept-Header je Format */
    var FORMAT_ACCEPT = {
        avif:    'image/avif,image/webp,*/*;q=0.8',
        webp:    'image/webp,*/*;q=0.8',
        'default': 'text/html,*/*;q=0.5'
    };

    var BATCH_SIZE = 50;   // Mediendateien pro API-Request
    var CONCURRENCY = 3;   // parallele Fetch-Anfragen gleichzeitig

    function init() {
        var wrap = document.querySelector('[data-mn-warmup]');
        if (!wrap) {
            return;
        }
        if (wrap.dataset.mnWarmupBound) {
            return;
        }
        wrap.dataset.mnWarmupBound = '1';

        var btnStart  = wrap.querySelector('[data-mn-warmup-start]');
        var btnStop   = wrap.querySelector('[data-mn-warmup-stop]');
        var bar       = wrap.querySelector('[data-mn-warmup-bar]');
        var barLabel  = wrap.querySelector('[data-mn-warmup-label]');
        var logBox    = wrap.querySelector('[data-mn-warmup-log]');
        var summary   = wrap.querySelector('[data-mn-warmup-summary]');
        var typesInfo = wrap.querySelector('[data-mn-warmup-types]');

        var apiBase  = wrap.dataset.mnWarmupApi   || '';
        var mediaUrl = wrap.dataset.mnWarmupMedia  || '';

        var running  = false;
        var stopFlag = false;
        var done     = 0;
        var total    = 0;
        var errors   = 0;

        // ── Typen beim Laden sofort anzeigen ─────────────────────────────────
        loadTypes(apiBase, function (data) {
            if (!data || !data.types || data.types.length === 0) {
                typesInfo.innerHTML = '<span class="text-muted">' + wrap.dataset.mnNoTypes + '</span>';
                if (btnStart) { btnStart.disabled = true; }
                return;
            }

            var totalJobs = 0;
            var html = '<ul class="list-unstyled" style="margin:0">';
            data.types.forEach(function (t) {
                var badges = '';
                if (t.hasNegotiator) {
                    badges += ' <span class="label label-info">Negotiator</span>';
                }
                if (t.hasSrgb) {
                    badges += ' <span class="label label-default">ICC Fix</span>';
                }
                html += '<li style="margin-bottom:2px"><code>' + escHtml(t.name) + '</code>' + badges
                    + ' &rarr; ' + t.jobsPerFile + ' Job(s)/Bild</li>';
                totalJobs += t.jobsPerFile;
            });
            html += '</ul>';
            typesInfo.innerHTML = html;

            // Gesamtanzahl Jobs berechnen und anzeigen
            var totalEstimate = totalJobs * data.totalMedia;
            barLabel.textContent = '0 / ' + totalEstimate;
            total = totalEstimate;
            summary.textContent = '';
        });

        // ── Start ────────────────────────────────────────────────────────────
        if (btnStart) {
            btnStart.addEventListener('click', function () {
                if (running) { return; }
                startWarmup();
            });
        }

        // ── Stop ─────────────────────────────────────────────────────────────
        if (btnStop) {
            btnStop.addEventListener('click', function () {
                stopFlag = true;
                log(wrap.dataset.mnStopping || 'Stopping…', 'warning');
            });
        }

        function startWarmup() {
            running  = true;
            stopFlag = false;
            done     = 0;
            errors   = 0;
            total    = 0;
            logBox.innerHTML = '';
            summary.textContent = '';

            if (btnStart) { btnStart.disabled = true; }
            if (btnStop)  { btnStop.style.display = ''; }

            setProgress(0, '…');

            loadTypes(apiBase, function (data) {
                if (!data || !data.types || data.types.length === 0) {
                    finish(wrap.dataset.mnNoTypes || 'Keine Typen gefunden.');
                    return;
                }

                var types = data.types;
                total = types.reduce(function (s, t) { return s + t.jobsPerFile; }, 0) * data.totalMedia;
                setProgress(0, '0 / ' + total);

                processAllMedia(types, apiBase, mediaUrl, 0);
            });
        }

        function processAllMedia(types, apiBase, mediaUrl, offset) {
            if (stopFlag) {
                finish(wrap.dataset.mnStopped || 'Gestoppt.');
                return;
            }

            loadMedia(apiBase, offset, BATCH_SIZE, function (data) {
                if (!data || !data.files || data.files.length === 0) {
                    finish(null);
                    return;
                }

                // Jobs für diesen Batch aufbauen
                var jobs = [];
                data.files.forEach(function (filename) {
                    types.forEach(function (type) {
                        if (type.hasNegotiator) {
                            ['avif', 'webp', 'default'].forEach(function (fmt) {
                                jobs.push({ filename: filename, type: type.name, format: fmt });
                            });
                        } else {
                            jobs.push({ filename: filename, type: type.name, format: 'default' });
                        }
                    });
                });

                runJobs(jobs, mediaUrl, function () {
                    var nextOffset = offset + BATCH_SIZE;
                    if (!stopFlag && nextOffset < data.total) {
                        processAllMedia(types, apiBase, mediaUrl, nextOffset);
                    } else {
                        finish(null);
                    }
                });
            });
        }

        function runJobs(jobs, mediaUrl, onDone) {
            var idx      = 0;
            var inFlight = 0;

            function next() {
                if (stopFlag) {
                    if (inFlight === 0) { onDone(); }
                    return;
                }
                if (idx >= jobs.length && inFlight === 0) {
                    onDone();
                    return;
                }

                while (inFlight < CONCURRENCY && idx < jobs.length) {
                    var job = jobs[idx++];
                    inFlight++;
                    fireJob(job, mediaUrl, function (job, ok) {
                        inFlight--;
                        done++;
                        if (!ok) { errors++; }
                        setProgress(total > 0 ? done / total : 0, done + ' / ' + total);
                        logResult(job, ok);
                        next();
                    });
                }
            }

            next();
        }

        function fireJob(job, mediaUrl, cb) {
            var url = mediaUrl
                + (mediaUrl.indexOf('?') >= 0 ? '&' : '?')
                + 'rex_media_type=' + encodeURIComponent(job.type)
                + '&rex_media_file=' + encodeURIComponent(job.filename);

            var accept = FORMAT_ACCEPT[job.format] || FORMAT_ACCEPT['default'];

            fetch(url, {
                method: 'GET',
                headers: { 'Accept': accept },
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (r) { cb(job, r.ok); })
                .catch(function ()  { cb(job, false); });
        }

        function setProgress(ratio, label) {
            var pct = Math.round(ratio * 100);
            if (bar) {
                bar.style.width = pct + '%';
                bar.setAttribute('aria-valuenow', pct);
            }
            if (barLabel) {
                barLabel.textContent = label;
            }
        }

        function log(msg, type) {
            if (!logBox) { return; }
            var el = document.createElement('div');
            el.className = 'mn-log-' + (type || 'info');
            el.textContent = msg;
            logBox.appendChild(el);
            logBox.scrollTop = logBox.scrollHeight;
        }

        function logResult(job, ok) {
            // Nur Fehler in das Log schreiben (OK-Meldungen überfluten das Log)
            if (!ok) {
                log('✗ ' + job.type + ' / ' + job.filename + ' [' + job.format + ']', 'error');
            }
        }

        function finish(msg) {
            running = false;
            if (btnStart) { btnStart.disabled = false; }
            if (btnStop)  { btnStop.style.display = 'none'; }

            var text = msg || (
                errors === 0
                    ? (wrap.dataset.mnDoneOk || 'Fertig. Keine Fehler.')
                    : (wrap.dataset.mnDoneErrors || 'Fertig.').replace('%s', errors)
            );
            summary.textContent = text + ' (' + done + ' Jobs)';
            setProgress(1, done + ' / ' + done);
        }
    }

    function loadTypes(apiBase, cb) {
        fetch(apiBase + '&action=types', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(cb)
            .catch(function () { cb(null); });
    }

    function loadMedia(apiBase, offset, limit, cb) {
        var url = apiBase + '&action=media&offset=' + offset + '&limit=' + limit;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(cb)
            .catch(function () { cb(null); });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('rex:ready', init);
}());
