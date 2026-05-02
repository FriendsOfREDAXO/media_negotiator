<?php

// Basis-URL für API (action wird per JS angehängt)
$apiBase = rex_url::backendController([
    'rex-api-call' => 'media_negotiator_warmup',
]);

// Basis-URL für Derivate (Media Manager Frontend-URL)
$mediaUrl = rex_url::frontendController();

// i18n-Strings für JS
$i18n = [
    'stopping'   => rex_i18n::msg('media_negotiator_warmup_stopping'),
    'stopped'    => rex_i18n::msg('media_negotiator_warmup_stopped'),
    'done_ok'    => rex_i18n::msg('media_negotiator_warmup_done_ok'),
    'done_errors'=> rex_i18n::msg('media_negotiator_warmup_done_errors'),
    'no_types'   => rex_i18n::msg('media_negotiator_warmup_no_types'),
];

ob_start(); ?>
<div data-mn-warmup
     data-mn-warmup-api="<?= rex_escape($apiBase) ?>"
     data-mn-warmup-media="<?= rex_escape($mediaUrl) ?>"
     data-mn-stopping="<?= rex_escape($i18n['stopping']) ?>"
     data-mn-stopped="<?= rex_escape($i18n['stopped']) ?>"
     data-mn-done-ok="<?= rex_escape($i18n['done_ok']) ?>"
     data-mn-done-errors="<?= rex_escape($i18n['done_errors']) ?>"
     data-mn-no-types="<?= rex_escape($i18n['no_types']) ?>">

    <p class="text-muted"><?= rex_i18n::msg('media_negotiator_warmup_description') ?></p>

    <!-- Typen-Info -->
    <h5><?= rex_i18n::msg('media_negotiator_warmup_types_headline') ?></h5>
    <div data-mn-warmup-types style="margin-bottom:16px">
        <span class="text-muted"><i class="fa fa-spinner fa-spin"></i> <?= rex_i18n::msg('media_negotiator_warmup_loading') ?></span>
    </div>

    <!-- Steuerung -->
    <div style="margin-bottom:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-primary" data-mn-warmup-start>
            <i class="fa fa-play" aria-hidden="true"></i>
            <?= rex_i18n::msg('media_negotiator_warmup_start') ?>
        </button>
        <button class="btn btn-warning" data-mn-warmup-stop style="display:none">
            <i class="fa fa-stop" aria-hidden="true"></i>
            <?= rex_i18n::msg('media_negotiator_warmup_stop') ?>
        </button>
    </div>

    <!-- Fortschrittsbalken -->
    <div class="progress" style="margin-bottom:6px">
        <div class="progress-bar progress-bar-striped active"
             role="progressbar"
             data-mn-warmup-bar
             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
             style="width:0%;min-width:2em;transition:width .3s ease">
        </div>
    </div>
    <div style="margin-bottom:12px;font-size:0.9em;color:#555">
        <span data-mn-warmup-label>–</span>
        <span data-mn-warmup-summary style="margin-left:12px;font-weight:600"></span>
    </div>

    <!-- Log (nur Fehler) -->
    <div data-mn-warmup-log
         style="max-height:260px;overflow-y:auto;font-family:monospace;font-size:0.82em;
                background:var(--rex-bg-2,#f8f8f8);border:1px solid var(--rex-border-color,#ddd);
                border-radius:4px;padding:8px 12px;display:block">
        <span class="text-muted"><?= rex_i18n::msg('media_negotiator_warmup_log_placeholder') ?></span>
    </div>
    <p class="text-muted" style="font-size:0.82em;margin-top:4px">
        <i class="fa fa-info-circle"></i>
        <?= rex_i18n::msg('media_negotiator_warmup_log_hint') ?>
    </p>
</div>

<style>
.mn-log-error { color: #c0392b; }
.mn-log-warning { color: #e67e22; }
.mn-log-info { color: #2c3e50; }

body.rex-theme-dark [data-mn-warmup-log] {
    background: #1e1e1e;
    border-color: #444;
}
@media (prefers-color-scheme: dark) {
    body.rex-has-theme:not(.rex-theme-light) [data-mn-warmup-log] {
        background: #1e1e1e;
        border-color: #444;
    }
}
</style>
<?php $body = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('media_negotiator_warmup_section_title'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');
