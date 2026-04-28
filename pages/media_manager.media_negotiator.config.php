<?php

$form = rex_config_form::factory('media_negotiator');

$field = $form->addRadioField('force_imagick');
$field->setLabel(rex_i18n::msg('media_negotiator_config_force_imagick_label'));
$field->addOption(rex_i18n::msg('media_negotiator_yes'), 1);
$field->addOption(rex_i18n::msg('media_negotiator_no'), 0);

$field = $form->addRadioField('disable_avif');
$field->setLabel(rex_i18n::msg('media_negotiator_config_disable_avif_label'));
$field->setNotice(rex_i18n::msg('media_negotiator_config_disable_avif_notice'));
$field->addOption(rex_i18n::msg('media_negotiator_yes'), 1);
$field->addOption(rex_i18n::msg('media_negotiator_no'), 0);

$field = $form->addTextField('webp_quality');
$field->setLabel(rex_i18n::msg('media_negotiator_config_webp_quality_label'));
$field->setNotice(rex_i18n::msg('media_negotiator_config_webp_quality_notice'));
$field->setAttribute('type', 'number');
$field->setAttribute('min', '0');
$field->setAttribute('max', '100');

$field = $form->addTextField('avif_quality');
$field->setLabel(rex_i18n::msg('media_negotiator_config_avif_quality_label'));
$field->setNotice(rex_i18n::msg('media_negotiator_config_avif_quality_notice'));
$field->setAttribute('type', 'number');
$field->setAttribute('min', '0');
$field->setAttribute('max', '100');

$field = $form->addRadioField('ua_fallback');
$field->setLabel(rex_i18n::msg('media_negotiator_config_ua_fallback_label'));
$field->setNotice(rex_i18n::msg('media_negotiator_config_ua_fallback_notice'));
$field->addOption(rex_i18n::msg('media_negotiator_yes'), 1);
$field->addOption(rex_i18n::msg('media_negotiator_no'), 0);

$field = $form->addSelectField('preferred_format');
$field->setLabel(rex_i18n::msg('media_negotiator_config_preferred_format_label'));
$field->setNotice(rex_i18n::msg('media_negotiator_config_preferred_format_notice'));
$select = $field->getSelect();
$select->addOption(rex_i18n::msg('media_negotiator_config_preferred_format_avif'), 'avif');
$select->addOption(rex_i18n::msg('media_negotiator_config_preferred_format_webp'), 'webp');

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('media_negotiator_config_title'), false);
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');
