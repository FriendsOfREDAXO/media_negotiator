<?php

use FriendsOfRedaxo\MediaNegotiator\Helper;

// ── helpers ────────────────────────────────────────────────────────────────

/** Returns a check or times icon with the matching Bootstrap colour. */
$icon = static function (bool $ok): string {
    return $ok
        ? '<i class="fa fa-check-circle text-success" aria-hidden="true"></i>'
        : '<i class="fa fa-times-circle text-danger" aria-hidden="true"></i>';
};

/** One list-group row: icon + label [ + optional small text ]. */
$statusRow = static function (bool $ok, string $label, string $sub = '') use ($icon): string {
    $out = '<li class="list-group-item" style="padding:8px 12px">';
    $out .= $icon($ok) . ' ' . $label;
    if ($sub !== '') {
        $out .= ' <small class="text-muted">' . $sub . '</small>';
    }
    $out .= '</li>';
    return $out;
};

// ── server capabilities ─────────────────────────────────────────────────────

$imagickAvailable = class_exists(Imagick::class);
$imagickWebp      = false;
$imagickAvif      = false;
$imagickVersion   = '';

if ($imagickAvailable) {
    $imagickObj     = new Imagick();
    $formats        = $imagickObj->queryFormats();
    $imagickWebp    = in_array('WEBP', $formats, true);
    $imagickAvif    = in_array('AVIF', $formats, true);
    $imagickVersion = Imagick::getVersion()['versionString'];
}

$gdImagewebp = function_exists('imagewebp');
$gdImageavif = function_exists('imageavif');
$gdWebp      = Helper::gdSupportsWebp();
$gdAvif      = Helper::gdSupportsAvif();
$redaxoOk    = rex_version::compare(rex::getVersion(), '5.15.0', '>=');
$webpPossible = Helper::webpPossible();
$avifPossible = Helper::avifPossible();

// ── browser check ──────────────────────────────────────────────────────────

$acceptHeader = rex_server('HTTP_ACCEPT', 'string', '');
$userAgent    = rex_server('HTTP_USER_AGENT', 'string', '');
/** @var list<string> $acceptTypes */
$acceptTypes  = array_values(array_filter(array_map('trim', explode(',', $acceptHeader))));

$browserDeclaredAvif = in_array('image/avif', array_map(
    static fn (string $t): string => strtolower(trim(explode(';', $t)[0])),
    $acceptTypes,
), true);

$browserDeclaredWebp = in_array('image/webp', array_map(
    static fn (string $t): string => strtolower(trim(explode(';', $t)[0])),
    $acceptTypes,
), true);

// Pure browser capability from UA (independent of server support)
$browserSupport    = Helper::getBrowserFormatSupport($userAgent);
$uaBrowserAvif     = $browserSupport['avif'];
$uaBrowserWebp     = $browserSupport['webp'];

$formatFromAccept  = Helper::getOutputFormat($acceptTypes);
$formatFromUa      = Helper::getOutputFormatFromUserAgent($userAgent);
$uaFallbackEnabled = Helper::uaFallbackEnabled();

// What would actually be delivered for this request?
$wouldDeliver    = $formatFromAccept;
$wouldDeliverVia = 'accept';
if ($wouldDeliver === 'default' && $uaFallbackEnabled) {
    $wouldDeliver    = $formatFromUa;
    $wouldDeliverVia = 'ua';
}

$formatBadge = match ($wouldDeliver) {
    'avif'  => '<span class="label label-success" style="font-size:1em">' . rex_i18n::msg('media_negotiator_setup_format_avif') . '</span>',
    'webp'  => '<span class="label label-info"    style="font-size:1em">' . rex_i18n::msg('media_negotiator_setup_format_webp') . '</span>',
    default => '<span class="label label-default" style="font-size:1em">' . rex_i18n::msg('media_negotiator_setup_format_original') . '</span>',
};

// ── 1. Server section ──────────────────────────────────────────────────────

ob_start(); ?>
<div class="row">
    <div class="col-sm-6">
        <h5 style="margin-top:0"><?= rex_i18n::msg('media_negotiator_setup_server_gd') ?></h5>
        <ul class="list-group">
            <?= $statusRow($gdImagewebp, 'imagewebp()') ?>
            <?= $statusRow($gdImageavif, 'imageavif()') ?>
            <?= $statusRow($gdWebp,      rex_i18n::msg('media_negotiator_setup_gd_webp_yes') . ' / ' . rex_i18n::msg('media_negotiator_setup_gd_webp_no')) ?>
            <?= $statusRow($gdAvif,      rex_i18n::msg('media_negotiator_setup_gd_avif_yes') . ' / ' . rex_i18n::msg('media_negotiator_setup_gd_avif_no')) ?>
            <?= $statusRow($redaxoOk,    'REDAXO ≥ 5.15.0 (' . rex::getVersion() . ')') ?>
        </ul>
    </div>
    <div class="col-sm-6">
        <h5 style="margin-top:0"><?= rex_i18n::msg('media_negotiator_setup_server_imagick') ?></h5>
        <ul class="list-group">
            <?= $statusRow($imagickAvailable, rex_i18n::msg('media_negotiator_setup_imagick_installed')) ?>
            <?= $statusRow($imagickWebp,      rex_i18n::msg('media_negotiator_setup_imagick_webp_yes') . ' / ' . rex_i18n::msg('media_negotiator_setup_imagick_webp_no')) ?>
            <?= $statusRow($imagickAvif,      rex_i18n::msg('media_negotiator_setup_imagick_avif_yes') . ' / ' . rex_i18n::msg('media_negotiator_setup_imagick_avif_no')) ?>
            <?php if ($imagickVersion !== ''): ?>
            <li class="list-group-item" style="padding:8px 12px">
                <i class="fa fa-info-circle text-muted" aria-hidden="true"></i>
                <small class="text-muted"><?= rex_escape($imagickVersion) ?></small>
            </li>
            <?php endif; ?>
        </ul>
        <div class="row" style="margin-top:12px">
            <div class="col-xs-6">
                <div class="panel panel-<?= $webpPossible ? 'success' : 'danger' ?>" style="text-align:center;padding:10px 0 6px">
                    <div style="font-size:22px"><?= $webpPossible ? '✓' : '✗' ?></div>
                    <div><strong>WebP</strong></div>
                    <div><small><?= rex_i18n::msg($webpPossible ? 'media_negotiator_yes' : 'media_negotiator_no') ?></small></div>
                </div>
            </div>
            <div class="col-xs-6">
                <div class="panel panel-<?= $avifPossible ? 'success' : 'danger' ?>" style="text-align:center;padding:10px 0 6px">
                    <div style="font-size:22px"><?= $avifPossible ? '✓' : '✗' ?></div>
                    <div><strong>AVIF</strong></div>
                    <div><small><?= rex_i18n::msg($avifPossible ? 'media_negotiator_yes' : 'media_negotiator_no') ?></small></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $serverBody = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('media_negotiator_setup_section_server'), false);
$fragment->setVar('body', $serverBody, false);
echo $fragment->parse('core/page/section.php');

// ── 2. Browser section ─────────────────────────────────────────────────────

ob_start(); ?>
<div class="row">
    <div class="col-sm-6">
        <ul class="list-group">
            <?= $statusRow($browserDeclaredAvif, rex_i18n::msg('media_negotiator_setup_browser_declared_avif')) ?>
            <?= $statusRow($browserDeclaredWebp, rex_i18n::msg('media_negotiator_setup_browser_declared_webp')) ?>
        </ul>

        <?php if (!$browserDeclaredAvif && !$browserDeclaredWebp): ?>
        <p class="text-muted" style="margin-top:8px;font-size:0.9em">
            <i class="fa fa-info-circle"></i>
            <?= rex_i18n::msg('media_negotiator_setup_browser_no_accept_formats') ?>
        </p>
        <?php endif; ?>

        <h5 style="margin-top:16px"><?= rex_i18n::msg('media_negotiator_setup_browser_ua_avif') ?> / <?= rex_i18n::msg('media_negotiator_setup_browser_ua_webp') ?></h5>
        <ul class="list-group">
            <?= $statusRow($uaBrowserAvif, rex_i18n::msg('media_negotiator_setup_browser_ua_avif')) ?>
            <?= $statusRow($uaBrowserWebp, rex_i18n::msg('media_negotiator_setup_browser_ua_webp')) ?>
        </ul>
        <ul class="list-group" style="margin-top:8px">
            <?= $statusRow(
                $uaFallbackEnabled,
                rex_i18n::msg($uaFallbackEnabled
                    ? 'media_negotiator_setup_browser_ua_fallback_active'
                    : 'media_negotiator_setup_browser_ua_fallback_inactive'
                )
            ) ?>
        </ul>
    </div>
    <div class="col-sm-6">
        <div class="panel panel-default" style="padding:16px 20px">
            <p style="margin-bottom:6px"><strong><?= rex_i18n::msg('media_negotiator_setup_browser_would_deliver') ?>:</strong></p>
            <p style="font-size:1.6em;margin:0 0 8px"><?= $formatBadge ?></p>
            <p class="text-muted" style="font-size:0.85em;margin:0">
                <?php if ($wouldDeliverVia === 'ua'): ?>
                    <i class="fa fa-user-circle"></i> <?= rex_i18n::msg('media_negotiator_setup_browser_via_ua') ?>
                <?php else: ?>
                    <i class="fa fa-exchange"></i> <?= rex_i18n::msg('media_negotiator_setup_browser_via_accept') ?>
                <?php endif; ?>
            </p>
        </div>

        <p style="margin-bottom:4px;font-size:0.85em;color:#777"><?= rex_i18n::msg('media_negotiator_setup_browser_accept_header') ?>:</p>
        <code style="display:block;word-break:break-all;font-size:0.8em;background:#f5f5f5;padding:8px;border-radius:3px;margin-bottom:10px">
            <?= rex_escape($acceptHeader ?: '–') ?>
        </code>

        <p style="margin-bottom:4px;font-size:0.85em;color:#777"><?= rex_i18n::msg('media_negotiator_setup_browser_user_agent') ?>:</p>
        <code style="display:block;word-break:break-all;font-size:0.8em;background:#f5f5f5;padding:8px;border-radius:3px">
            <?= rex_escape($userAgent ?: '–') ?>
        </code>
    </div>
</div>
<?php $browserBody = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('media_negotiator_setup_section_browser'), false);
$fragment->setVar('body', $browserBody, false);
echo $fragment->parse('core/page/section.php');

// ── 3. Demo images section ─────────────────────────────────────────────────

rex_view::addJsFile(rex_url::addonAssets('media_negotiator', 'setup_compare.js'));

$demo_img = rex_path::addon('media_negotiator', 'data/demo.jpg');
$addon = rex_addon::get('media_negotiator');

$forceImagick = (bool) $addon->getConfig('force_imagick', false);
$disableAvif  = (bool) $addon->getConfig('disable_avif', false);
$webpQuality  = Helper::getWebpQuality();
$avifQuality  = Helper::getAvifQuality();
$preferred    = Helper::getPreferredFormat();

/** @var list<array{id:string,label:string,mime:string,size:float,src:string}> $demos */
$demos = [];

$raw_jpeg = rex_file::get($demo_img);
if ($raw_jpeg !== null && $raw_jpeg !== '') {
    $size_jpeg = strlen($raw_jpeg) / 1000;
    $demos[] = [
        'id'    => 'jpeg',
        'label' => rex_i18n::msg('media_negotiator_setup_original') . ' (JPEG)',
        'mime'  => 'image/jpeg',
        'size'  => $size_jpeg,
        'src'   => 'data:image/jpeg;base64,' . base64_encode($raw_jpeg),
    ];
} else {
    $size_jpeg = 0;
}

$convertWithGd = static function (string $sourcePath, string $targetFormat, int $quality): string {
    $image = imagecreatefromjpeg($sourcePath);
    if ($image === false) {
        return '';
    }

    ob_start();
    $ok = false;
    if ($targetFormat === 'webp' && function_exists('imagewebp')) {
        $ok = imagewebp($image, null, $quality);
    } elseif ($targetFormat === 'avif' && function_exists('imageavif')) {
        $ok = imageavif($image, null, $quality);
    }

    $imgData = ob_get_clean();

    if (!$ok || $imgData === false || $imgData === '') {
        return '';
    }

    return $imgData;
};

$convertWithImagick = static function (string $sourcePath, string $targetFormat, int $quality): string {
    if (!class_exists(Imagick::class)) {
        return '';
    }

    try {
        $im = new Imagick($sourcePath);
        $im->setImageFormat($targetFormat);
        $im->setImageCompressionQuality($quality);
        $imgData = $im->getImageBlob();
        $im->clear();
        $im->destroy();
        return $imgData;
    } catch (Exception) {
        return '';
    }
};

$addDemo = static function (array &$rows, string $id, string $label, string $mime, string $imgData): void {
    if ($imgData === '') {
        return;
    }

    $rows[] = [
        'id'    => $id,
        'label' => $label,
        'mime'  => $mime,
        'size'  => strlen($imgData) / 1000,
        'src'   => 'data:' . $mime . ';base64,' . base64_encode($imgData),
    ];
};

$webpData = '';
if ($forceImagick) {
    $webpData = $convertWithImagick($demo_img, 'webp', $webpQuality);
    $addDemo($demos, 'cfg-webp', 'WebP (Imagick, Q' . $webpQuality . ')', 'image/webp', $webpData);
} else {
    $webpData = $convertWithGd($demo_img, 'webp', $webpQuality);
    if ($webpData !== '') {
        $addDemo($demos, 'cfg-webp', 'WebP (GD, Q' . $webpQuality . ')', 'image/webp', $webpData);
    } else {
        $webpData = $convertWithImagick($demo_img, 'webp', $webpQuality);
        $addDemo($demos, 'cfg-webp', 'WebP (Imagick, Q' . $webpQuality . ')', 'image/webp', $webpData);
    }
}

if (!$disableAvif) {
    $avifData = '';
    if ($forceImagick) {
        $avifData = $convertWithImagick($demo_img, 'avif', $avifQuality);
        $addDemo($demos, 'cfg-avif', 'AVIF (Imagick, Q' . $avifQuality . ')', 'image/avif', $avifData);
    } else {
        $avifData = $convertWithGd($demo_img, 'avif', $avifQuality);
        if ($avifData !== '') {
            $addDemo($demos, 'cfg-avif', 'AVIF (GD, Q' . $avifQuality . ')', 'image/avif', $avifData);
        } else {
            $avifData = $convertWithImagick($demo_img, 'avif', $avifQuality);
            $addDemo($demos, 'cfg-avif', 'AVIF (Imagick, Q' . $avifQuality . ')', 'image/avif', $avifData);
        }
    }
}

$pct = static function (float $size, float $total): string {
    if ($total <= 0 || $size <= 0) {
        return '';
    }
    $val = $size / $total * 100;
    $cls = $val < 70 ? 'label-success' : ($val < 100 ? 'label-warning' : 'label-danger');
    return '<span class="label ' . $cls . '">' . number_format($val, 0) . '%</span>';
};

// Default selects: left = original, right = preferred format from config when available.
$defaultRight = count($demos) > 1 ? count($demos) - 1 : 0;
foreach ($demos as $idx => $demo) {
    if (($preferred === 'webp' && $demo['mime'] === 'image/webp')
        || ($preferred === 'avif' && $demo['mime'] === 'image/avif')) {
        $defaultRight = $idx;
        break;
    }
}

ob_start(); ?>
<?= rex_view::info(rex_i18n::msg('media_negotiator_setup_demo_notice')) ?>

<!-- Card grid -->
<div class="row" style="margin-bottom:20px">
    <?php foreach ($demos as $i => $demo): ?>
    <div class="col-xs-6 col-sm-4 col-md-3" style="margin-bottom:12px">
        <div class="panel panel-default" style="margin:0;overflow:hidden">
            <div style="height:130px;overflow:hidden;background:#f0f0f0;display:flex;align-items:center;justify-content:center">
                <img src="<?= $demo['src'] ?>" alt="<?= rex_escape($demo['label']) ?>"
                     style="max-height:130px;max-width:100%;width:auto;height:auto;display:block">
            </div>
            <div style="padding:8px 10px">
                <strong style="font-size:0.9em"><?= rex_escape($demo['label']) ?></strong><br>
                <span class="text-muted" style="font-size:0.82em"><?= number_format($demo['size'], 1) ?> KB</span>
                <?php if ($i > 0): ?>
                    <?= $pct($demo['size'], $size_jpeg) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Comparison slider -->
<?php if (count($demos) >= 2): ?>
<div class="row" style="margin-bottom:10px">
    <div class="col-sm-5">
        <label for="mn-sel-left"><?= rex_i18n::msg('media_negotiator_setup_compare_left') ?></label>
        <select id="mn-sel-left" class="form-control">
            <?php foreach ($demos as $i => $demo): ?>
            <option value="<?= rex_escape($demo['id']) ?>"
                    data-src="<?= rex_escape($demo['src']) ?>"
                    <?= $i === 0 ? 'selected' : '' ?>>
                <?= rex_escape($demo['label']) ?> (<?= number_format($demo['size'], 1) ?> KB)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-2 text-center" style="padding-top:26px;font-size:1.4em;color:#bbb">&#8644;</div>
    <div class="col-sm-5">
        <label for="mn-sel-right"><?= rex_i18n::msg('media_negotiator_setup_compare_right') ?></label>
        <select id="mn-sel-right" class="form-control">
            <?php foreach ($demos as $i => $demo): ?>
            <option value="<?= rex_escape($demo['id']) ?>"
                    data-src="<?= rex_escape($demo['src']) ?>"
                    <?= $i === $defaultRight ? 'selected' : '' ?>>
                <?= rex_escape($demo['label']) ?> (<?= number_format($demo['size'], 1) ?> KB)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div id="mn-compare" style="position:relative;overflow:hidden;cursor:ew-resize;user-select:none;border-radius:4px;background:#111;touch-action:pan-y">
    <!-- Right image (full, sets container height) -->
    <img class="mn-img-right"
         src="<?= rex_escape($demos[$defaultRight]['src']) ?>"
         alt=""
         style="display:block;width:100%;height:auto;opacity:0.999">

    <!-- Left image (clipped overlay) -->
    <div class="mn-clip-left" style="position:absolute;top:0;left:0;height:100%;width:50%;overflow:hidden">
        <img class="mn-img-left"
             src="<?= rex_escape($demos[0]['src']) ?>"
             alt=""
             style="display:block;position:absolute;top:0;left:0;height:100%;width:auto;max-width:none">
    </div>

    <!-- Handle -->
    <div class="mn-handle" style="position:absolute;top:0;bottom:0;left:50%;width:2px;background:rgba(255,255,255,0.9);box-shadow:0 0 6px rgba(0,0,0,0.5);transform:translateX(-50%)">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:38px;height:38px;border-radius:50%;background:white;box-shadow:0 2px 8px rgba(0,0,0,0.35);display:flex;align-items:center;justify-content:center;cursor:ew-resize;font-size:16px;color:#555;line-height:1">&#8596;</div>
    </div>

    <!-- Labels -->
    <span id="mn-lbl-left" style="position:absolute;bottom:8px;left:8px;background:rgba(0,0,0,0.62);color:#fff;padding:3px 8px;border-radius:3px;font-size:0.78em;pointer-events:none;max-width:44%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></span>
    <span id="mn-lbl-right" style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,0.62);color:#fff;padding:3px 8px;border-radius:3px;font-size:0.78em;pointer-events:none;max-width:44%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:right"></span>
</div>
<p class="text-muted text-center" style="margin-top:6px;font-size:0.85em">
    <i class="fa fa-arrows-h" aria-hidden="true"></i> <?= rex_i18n::msg('media_negotiator_setup_compare_hint') ?>
</p>
<?php endif; ?>

<?php $demoBody = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('media_negotiator_setup_section_demo'), false);
$fragment->setVar('body', $demoBody, false);
echo $fragment->parse('core/page/section.php');
