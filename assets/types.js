/* global document */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.getElementById('mn-check-all');
        if (!toggle) { return; }

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
    });
}());
