/* Media Negotiator – image comparison slider */
(function () {
    'use strict';

    var dragActive = false;

    function getParts() {
        var container = document.getElementById('mn-compare');
        if (!container) {
            return null;
        }

        var clipLeft = container.querySelector('.mn-clip-left');
        var imgLeft = container.querySelector('.mn-img-left');
        var imgRight = container.querySelector('.mn-img-right');
        var handle = container.querySelector('.mn-handle');
        var leftSel = document.getElementById('mn-sel-left');
        var rightSel = document.getElementById('mn-sel-right');

        if (!clipLeft || !imgLeft || !imgRight || !handle || !leftSel || !rightSel) {
            return null;
        }

        return {
            container: container,
            clipLeft: clipLeft,
            imgLeft: imgLeft,
            imgRight: imgRight,
            handle: handle,
            leftSel: leftSel,
            rightSel: rightSel
        };
    }

    function syncLeftWidth(parts) {
        parts.imgLeft.style.width = parts.container.offsetWidth + 'px';
    }

    function setPos(parts, ratio) {
        var pos = Math.max(0.02, Math.min(0.98, ratio));
        parts.clipLeft.style.width = (pos * 100) + '%';
        parts.handle.style.left = (pos * 100) + '%';
    }

    function updateLabels(leftOpt, rightOpt) {
        var lbl = document.getElementById('mn-lbl-left');
        var rbr = document.getElementById('mn-lbl-right');
        if (lbl && leftOpt)  { lbl.textContent = leftOpt.textContent.trim(); }
        if (rbr && rightOpt) { rbr.textContent = rightOpt.textContent.trim(); }
    }

    function applySelectedImages() {
        var parts = getParts();
        if (!parts) {
            return;
        }

        var leftOpt = parts.leftSel.options[parts.leftSel.selectedIndex];
        var rightOpt = parts.rightSel.options[parts.rightSel.selectedIndex];

        if (leftOpt && leftOpt.dataset.src) {
            parts.imgLeft.src = leftOpt.dataset.src;
        }
        if (rightOpt && rightOpt.dataset.src) {
            parts.imgRight.src = rightOpt.dataset.src;
        }

        updateLabels(leftOpt, rightOpt);
        syncLeftWidth(parts);
    }

    function jumpTo(parts, clientX) {
        var rect = parts.container.getBoundingClientRect();
        setPos(parts, (clientX - rect.left) / rect.width);
    }

    function initAll() {
        var parts = getParts();
        if (!parts) {
            return;
        }

        if (parts.container.dataset.mnInit !== '1') {
            parts.container.dataset.mnInit = '1';
            setPos(parts, 0.5);
        }

        applySelectedImages();
    }

    function bindGlobalEvents() {
        if (document.documentElement.dataset.mnCompareGlobalInit === '1') {
            return;
        }
        document.documentElement.dataset.mnCompareGlobalInit = '1';

        document.addEventListener('change', function (e) {
            if (e.target && (e.target.id === 'mn-sel-left' || e.target.id === 'mn-sel-right')) {
                applySelectedImages();
            }
        });

        document.addEventListener('mousedown', function (e) {
            var parts = getParts();
            if (!parts) {
                return;
            }
            if (!e.target.closest('#mn-compare')) {
                return;
            }

            jumpTo(parts, e.clientX);
            dragActive = true;
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            var parts;
            if (!dragActive) {
                return;
            }
            parts = getParts();
            if (!parts) {
                return;
            }
            jumpTo(parts, e.clientX);
        });

        document.addEventListener('mouseup', function () {
            dragActive = false;
        });

        document.addEventListener('touchstart', function (e) {
            var parts;
            if (!e.target.closest('#mn-compare') || e.touches.length === 0) {
                return;
            }
            parts = getParts();
            if (!parts) {
                return;
            }

            jumpTo(parts, e.touches[0].clientX);
            dragActive = true;
            e.preventDefault();
        }, { passive: false });

        document.addEventListener('touchmove', function (e) {
            var parts;
            if (!dragActive || e.touches.length === 0) {
                return;
            }
            parts = getParts();
            if (!parts) {
                return;
            }

            jumpTo(parts, e.touches[0].clientX);
        }, { passive: true });

        document.addEventListener('touchend', function () {
            dragActive = false;
        });

        window.addEventListener('resize', initAll);
    }

    bindGlobalEvents();

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
