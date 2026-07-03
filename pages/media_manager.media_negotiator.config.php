<?php

$form = rex_config_form::factory('media_negotiator');
$avifDisabled = (bool) rex_config::get('media_negotiator', 'disable_avif', false);

if ($avifDisabled) {
	echo rex_view::info(rex_i18n::msg('media_negotiator_config_avif_disabled_conflict_hint'));
}

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

$field = $form->addSelectField('avif_converter_preference');
$field->setLabel(rex_i18n::msg('media_negotiator_config_avif_converter_preference_label'));
$avifPipelineNotice = rex_i18n::msg('media_negotiator_config_avif_converter_preference_notice');
if ($avifDisabled) {
	$avifPipelineNotice .= ' ' . rex_i18n::msg('media_negotiator_config_avif_disabled_field_notice');
}
$field->setNotice($avifPipelineNotice);
$select = $field->getSelect();
$rawMsg = static function (string $key): string {
	if (method_exists(rex_i18n::class, 'rawMsg')) {
		/** @phpstan-ignore-next-line */
		return rex_i18n::rawMsg($key);
	}

	return html_entity_decode(rex_i18n::msg($key), ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

$select->addOption($rawMsg('media_negotiator_config_avif_converter_preference_auto'), 'auto');
$select->addOption($rawMsg('media_negotiator_config_avif_converter_preference_vips'), 'vips');
$select->addOption($rawMsg('media_negotiator_config_avif_converter_preference_gd'), 'gd');
$select->addOption($rawMsg('media_negotiator_config_avif_converter_preference_imagick'), 'imagick');

$field = $form->addRadioField('ua_fallback');
$field->setLabel(rex_i18n::msg('media_negotiator_config_ua_fallback_label'));
$field->setNotice(rex_i18n::msg('media_negotiator_config_ua_fallback_notice'));
$field->addOption(rex_i18n::msg('media_negotiator_yes'), 1);
$field->addOption(rex_i18n::msg('media_negotiator_no'), 0);

$field = $form->addSelectField('preferred_format');
$field->setLabel(rex_i18n::msg('media_negotiator_config_preferred_format_label'));
$preferredFormatNotice = rex_i18n::msg('media_negotiator_config_preferred_format_notice');
if ($avifDisabled) {
	$preferredFormatNotice .= ' ' . rex_i18n::msg('media_negotiator_config_avif_disabled_field_notice');
}
$field->setNotice($preferredFormatNotice);
$select = $field->getSelect();
$select->addOption(rex_i18n::msg('media_negotiator_config_preferred_format_avif'), 'avif');
$select->addOption(rex_i18n::msg('media_negotiator_config_preferred_format_webp'), 'webp');

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('media_negotiator_config_title'), false);
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');

$scriptNonceAttr = '';
if (method_exists(rex_response::class, 'getNonce')) {
	/** @phpstan-ignore-next-line */
	$nonce = (string) rex_response::getNonce();
	if ($nonce !== '') {
		$scriptNonceAttr = ' nonce="' . rex_escape($nonce) . '"';
	}
}

?>
<script<?= $scriptNonceAttr ?>>
(function () {
	function ensurePreferredFormatMirror(selectEl, enabled) {
		var marker = 'data-mn-preferred-format-mirror';
		var existing = document.querySelector('input[' + marker + '="1"]');

		if (enabled) {
			if (existing) {
				existing.remove();
			}
			return;
		}

		if (!selectEl || !selectEl.name) {
			return;
		}

		if (!existing) {
			existing = document.createElement('input');
			existing.type = 'hidden';
			existing.name = selectEl.name;
			existing.setAttribute(marker, '1');
			selectEl.parentNode.appendChild(existing);
		}

		existing.value = 'webp';
	}

	function getCheckedDisableAvifValue() {
		var checked = document.querySelector('input[type="radio"][name$="[disable_avif]"]:checked');
		return checked ? checked.value : null;
	}

	function toggleByDisableAvif() {
		var avifDisabled = getCheckedDisableAvifValue() === '1';

		var preferredFormat = document.querySelector('select[name$="[preferred_format]"]');
		if (preferredFormat) {
			var avifOption = preferredFormat.querySelector('option[value="avif"]');
			if (avifOption) {
				avifOption.disabled = avifDisabled;
			}

			if (avifDisabled && preferredFormat.value === 'avif') {
				preferredFormat.value = 'webp';
				preferredFormat.dispatchEvent(new Event('change', { bubbles: true }));
			}

			preferredFormat.disabled = avifDisabled;
			ensurePreferredFormatMirror(preferredFormat, !avifDisabled);
		}

		var avifPipeline = document.querySelector('select[name$="[avif_converter_preference]"]');
		if (avifPipeline) {
			avifPipeline.disabled = avifDisabled;
		}
	}

	document.addEventListener('change', function (event) {
		var target = event.target;
		if (!target || target.name === undefined) {
			return;
		}
		if (String(target.name).indexOf('[disable_avif]') !== -1) {
			toggleByDisableAvif();
		}
	});

	toggleByDisableAvif();
})();
</script>
