<?php

use FriendsOfRedaxo\MediaNegotiator\MediaTypeManager;

$package = rex_addon::get('media_negotiator');

rex_view::addJsFile(rex_url::addonAssets('media_negotiator', 'types.js'));

$effectName = 'srgb_preprocess';
$i18nPrefix = 'media_negotiator_icc_fix';

$csrfToken = rex_csrf_token::factory('media_negotiator_icc_fix');
$messages = [];

if (rex_server('REQUEST_METHOD', 'string', '') === 'POST') {
    if (!$csrfToken->isValid()) {
        $messages[] = ['type' => 'error', 'text' => rex_i18n::msg('csrf_token_invalid')];
    } else {
        /** @var list<string> $rawIds */
        $rawIds = (array) rex_post('type_ids', 'array', []);
        $action = rex_post('action', 'string', '');

        if (count($rawIds) === 0) {
            $messages[] = ['type' => 'warning', 'text' => rex_i18n::msg($i18nPrefix . '_error_none_selected')];
        } elseif ($action === 'add') {
            /** @var list<int> $typeIds */
            $typeIds = array_map('intval', $rawIds);
            $result = MediaTypeManager::bulkAddEffect($typeIds, $effectName, 'prepend');
            $msg = rex_i18n::msg($i18nPrefix . '_success_add', (string) $result['added'], (string) $result['skipped']);
            $messages[] = ['type' => $result['added'] > 0 ? 'success' : 'info', 'text' => $msg];
        } elseif ($action === 'remove') {
            /** @var list<int> $typeIds */
            $typeIds = array_map('intval', $rawIds);
            $result = MediaTypeManager::bulkRemoveEffect($typeIds, $effectName);
            $msg = rex_i18n::msg($i18nPrefix . '_success_remove', (string) $result['removed'], (string) $result['skipped']);
            $messages[] = ['type' => $result['removed'] > 0 ? 'success' : 'info', 'text' => $msg];
        }
    }
}

$types = MediaTypeManager::getTypesWithStatusForEffect($effectName);

foreach ($messages as $msg) {
    if ($msg['type'] === 'success') {
        echo rex_view::success(rex_escape($msg['text']));
    } elseif ($msg['type'] === 'warning') {
        echo rex_view::warning(rex_escape($msg['text']));
    } elseif ($msg['type'] === 'error') {
        echo rex_view::error(rex_escape($msg['text']));
    } else {
        echo rex_view::info(rex_escape($msg['text']));
    }
}

ob_start(); ?>

<p class="text-muted"><?= rex_i18n::msg($i18nPrefix . '_description') ?></p>

<form action="<?= rex_url::currentBackendPage() ?>" method="post">
    <?= $csrfToken->getHiddenField() ?>

    <table class="table table-hover" id="mn-types-table">
        <thead>
            <tr>
                <th style="width:36px">
                    <input type="checkbox" id="mn-check-all" title="<?= rex_i18n::msg($i18nPrefix . '_select_all') ?>">
                </th>
                <th><?= rex_i18n::msg($i18nPrefix . '_table_type') ?></th>
                <th class="hidden-xs"><?= rex_i18n::msg($i18nPrefix . '_table_description') ?></th>
                <th><?= rex_i18n::msg($i18nPrefix . '_table_status') ?></th>
                <th class="hidden-xs text-right" style="width:90px"><?= rex_i18n::msg($i18nPrefix . '_table_effects') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($types) === 0): ?>
            <tr>
                <td colspan="5" class="text-center text-muted">
                    <?= rex_i18n::msg($i18nPrefix . '_no_types') ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($types as $type): ?>
            <tr>
                <td>
                    <input type="checkbox" class="mn-type-check" name="type_ids[]"
                           value="<?= $type['id'] ?>"
                           <?= $type['has_effect'] ? 'data-has-effect="1"' : '' ?>>
                </td>
                <td>
                    <strong><?= rex_escape($type['name']) ?></strong>
                </td>
                <td class="hidden-xs text-muted">
                    <?= rex_escape($type['description']) ?>
                </td>
                <td>
                    <?php if ($type['has_effect']): ?>
                    <span class="label label-success">
                        <i class="fa fa-check" aria-hidden="true"></i>
                        <?= rex_i18n::msg($i18nPrefix . '_has_effect') ?>
                    </span>
                    <small class="text-muted" style="margin-left:5px">
                        <?= rex_i18n::msg($i18nPrefix . '_priority', (string) $type['priority']) ?>
                    </small>
                    <?php else: ?>
                    <span class="label label-default">
                        <?= rex_i18n::msg($i18nPrefix . '_no_effect') ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="hidden-xs text-right text-muted">
                    <?= $type['total_effects'] ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="row" style="margin-top:16px;align-items:flex-end">
        <div class="col-sm-4">
            <p class="text-muted" style="margin:0;padding-top:8px">
                <?= rex_i18n::msg($i18nPrefix . '_fixed_position_notice') ?>
            </p>
        </div>
        <div class="col-sm-8 text-right" style="padding-top:10px">
            <button type="submit" name="action" value="remove"
                    class="btn btn-danger"
                    onclick="return confirm('<?= rex_i18n::msg($i18nPrefix . '_confirm_remove') ?>')">
                <i class="fa fa-minus-circle" aria-hidden="true"></i>
                <?= rex_i18n::msg($i18nPrefix . '_action_remove') ?>
            </button>
            &nbsp;
            <button type="submit" name="action" value="add" class="btn btn-success">
                <i class="fa fa-plus-circle" aria-hidden="true"></i>
                <?= rex_i18n::msg($i18nPrefix . '_action_add') ?>
            </button>
        </div>
    </div>
</form>

<?php $body = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg($i18nPrefix . '_section_title'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');