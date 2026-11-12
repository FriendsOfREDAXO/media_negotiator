<?php

/**
 * Bild-Cache vorwärmen: alle Bilder, die die Seiten der Website zeigen, einmal je ausgeliefertem Format erzeugen.
 * Läuft in Häppchen über rex_api_media_negotiator_warmup, solange die Seite offen ist.
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\MediaNegotiator\Warmup;

$status = Warmup::status();
$formatNames = ['avif' => 'AVIF', 'webp' => 'WebP', 'default' => rex_i18n::msg('media_negotiator_warmup_format_default')];
$formats = implode(', ', array_map(static fn (string $f) => $formatNames[$f] ?? $f, array_keys(Warmup::formats())));
$sitemaps = Warmup::sitemaps();

$body = '<p>' . $this->i18n('media_negotiator_warmup_intro') . '</p>';
$body .= '<dl class="dl-horizontal">'
    . '<dt>' . $this->i18n('media_negotiator_warmup_sitemaps') . '</dt><dd>' . ([] !== $sitemaps ? implode('<br>', array_map('rex_escape', $sitemaps)) : '–') . '</dd>'
    . '<dt>' . $this->i18n('media_negotiator_warmup_formats') . '</dt><dd>' . rex_escape($formats) . '</dd>'
    . '</dl>';

$select = new rex_select();
$select->setId('mn-warmup-concurrency');
$select->setAttribute('class', 'form-control');
foreach ([1, 2, 4, 6, 8] as $n) {
    $select->addOption((string) $n, $n);
}
$select->setSelected((int) ($status['concurrency'] ?? 4));

$body .= '<div class="form-group" style="max-width:420px">'
    . '<label for="mn-warmup-concurrency">' . $this->i18n('media_negotiator_warmup_concurrency') . '</label>'
    . $select->get()
    . '<p class="help-block">' . $this->i18n('media_negotiator_warmup_concurrency_help') . '</p>'
    . '</div>';

$body .= '<p>'
    . '<button type="button" class="btn btn-primary" id="mn-warmup-start"><i class="rex-icon fa-play" aria-hidden="true"></i> ' . $this->i18n('media_negotiator_warmup_start') . '</button> '
    . '<button type="button" class="btn btn-default" id="mn-warmup-resume" hidden><i class="rex-icon fa-forward" aria-hidden="true"></i> ' . $this->i18n('media_negotiator_warmup_resume') . '</button> '
    . '<button type="button" class="btn btn-default" id="mn-warmup-stop" hidden><i class="rex-icon fa-stop" aria-hidden="true"></i> ' . $this->i18n('media_negotiator_warmup_stop') . '</button>'
    . '</p>';
$body .= '<div class="progress" style="height:22px"><div class="progress-bar" id="mn-warmup-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width:0%;min-width:2em">0 %</div></div>';
$body .= '<p id="mn-warmup-text" role="status" aria-live="polite"></p>';
$body .= '<div id="mn-warmup-errors" class="text-warning small"></div>';
$body .= '<p class="help-block">' . $this->i18n('media_negotiator_warmup_console') . ' <code>php redaxo/bin/console media:negotiator:warmup --pages</code></p>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('media_negotiator_warmup_title'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

$i18n = [];
foreach (['pages', 'images', 'done', 'cancelled', 'idle', 'errors', 'running', 'leave'] as $key) {
    $i18n[$key] = rex_i18n::rawMsg('media_negotiator_warmup_text_' . $key);
}
?>
<script>
(function () {
    var params = <?= json_encode(rex_api_media_negotiator_warmup::getUrlParams()) ?>;
    var t = <?= json_encode($i18n) ?>;
    var initial = <?= json_encode($status) ?>;
    var startBtn = document.getElementById('mn-warmup-start');
    var resumeBtn = document.getElementById('mn-warmup-resume');
    var stopBtn = document.getElementById('mn-warmup-stop');
    var bar = document.getElementById('mn-warmup-bar');
    var text = document.getElementById('mn-warmup-text');
    var errors = document.getElementById('mn-warmup-errors');
    var concurrency = document.getElementById('mn-warmup-concurrency');
    var running = false;

    var fill = function (s) {
        var args = Array.prototype.slice.call(arguments, 1);
        return s.replace(/\{(\d)\}/g, function (_, i) { return args[i] !== undefined ? args[i] : ''; });
    };
    var call = function (action) {
        var url = 'index.php?' + new URLSearchParams(Object.assign({}, params, { action: action, concurrency: concurrency.value }));
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); });
    };
    var render = function (s) {
        var active = s.status === 'pages' || s.status === 'images';
        // Seiten zählen 10 %, Bilder 90 % des Fortschritts
        var pct = s.status === 'done' ? 100
            : s.status === 'pages' ? Math.round(10 * (s.pagesDone || 0) / Math.max(1, s.pages || 1))
            : s.jobs ? 10 + Math.floor(90 * (s.jobsDone || 0) / s.jobs) : 0;
        bar.style.width = pct + '%';
        bar.textContent = pct + ' %';
        bar.setAttribute('aria-valuenow', String(pct));
        bar.className = 'progress-bar' + (s.status === 'done' ? ' progress-bar-success' : (active && running ? ' progress-bar-striped active' : ''));
        if (s.status === 'pages') {
            text.textContent = fill(t.pages, s.pagesDone, s.pages, s.images);
        } else if (s.status === 'images' || s.status === 'cancelled') {
            text.textContent = fill(s.status === 'cancelled' ? t.cancelled : t.images, s.jobsDone, s.jobs, s.images, (s.formats || []).length);
        } else if (s.status === 'done') {
            text.textContent = fill(t.done, s.images, s.pages, s.jobs);
        } else {
            text.textContent = t.idle;
        }
        if (active && running) {
            text.textContent += ' ' + t.running;
        }
        errors.textContent = s.errors ? fill(t.errors, s.errors) + ' ' + (s.lastErrors || []).join(' · ') : '';
        startBtn.disabled = running;
        stopBtn.hidden = !running;
        resumeBtn.hidden = running || !(active || s.status === 'cancelled');
    };
    var loop = function () {
        if (!running) return;
        call('step').then(function (s) {
            if (s.status !== 'pages' && s.status !== 'images') running = false;
            render(s);
            if (running) loop();
        }).catch(function () { running = false; render(initial); });
    };
    startBtn.addEventListener('click', function () {
        running = true;
        call('start').then(function (s) { render(s); loop(); });
    });
    resumeBtn.addEventListener('click', function () {
        running = true;
        call('resume').then(function (s) { render(s); loop(); });
    });
    stopBtn.addEventListener('click', function () {
        running = false;
        call('cancel').then(render);
    });
    window.addEventListener('beforeunload', function (e) {
        if (running) { e.preventDefault(); e.returnValue = t.leave; }
    });
    render(initial);
})();
</script>
