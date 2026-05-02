/* global document */
(function () {
    'use strict';

    function initAll() {
        var toggle = document.getElementById('mn-check-all');
        if (!toggle) { return; }
        if (toggle.dataset.mnBound === '1') { return; }

        toggle.dataset.mnBound = '1';

        function updateToggle() {
            var all     = document.querySelectorAll('.mn-type-check');
            var checked = document.querySelectorAll('.mn-type-check:checked');
            toggle.checked       = all.length > 0 && all.length === checked.length;
            toggle.indeterminate = checked.length > 0 && checked.length < all.length;
        }

        toggle.addEventListener('change', function () {
            document.querySelectorAll('.mn-type-check').forEach(function (box) {
                box.checked = toggle.checked;
            });
        });

        document.querySelectorAll('.mn-type-check').forEach(function (box) {
            box.addEventListener('change', updateToggle);
        });

        updateToggle();
    }

    if (document.readyState !== 'loading') {
        initAll();
    } else {
        document.addEventListener('DOMContentLoaded', initAll);
    }

    if (window.jQuery) {
        window.jQuery(document).on('rex:ready', function () {
            window.setTimeout(initAll, 0);
        });
    }
}());
