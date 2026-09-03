/**
 * Shared visual "face guide" overlay for the guided FaceEnrollment capture -
 * a radial tick-mark ring (Face-ID-style) that fills green as a pose locks
 * in, a scanning sweep line, directional turn hints for LEFT/RIGHT, and a
 * checkmark badge on capture/complete.
 *
 * This is a presentation-only companion to face-enrollment.js, not a
 * replacement for it - it never touches detection/capture logic. A host
 * page's own onStateChange handler (status text, pose-progress dots) is
 * left completely unchanged; it just also calls guide.update(state, meta)
 * alongside its existing work, using the exact same state/meta pairs
 * FaceEnrollment.start()'s onStateChange already emits.
 *
 * Usage:
 *   const guide = FaceGuideUI.create(containerEl);
 *   // containerEl must be position:relative and directly wrap the (possibly
 *   // CSS-mirrored) <video> element - the overlay is appended as a sibling
 *   // of the video and is never itself mirrored.
 *   ...
 *   onStateChange: (state, meta) => { guide.update(state, meta); ... }
 */
(function (global) {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var TICK_COUNT = 48;
    var RING_RADIUS = 42; // in the ring's 0-100 viewBox
    var TICK_LENGTH = 9;
    var DEFAULT_TARGET = 6; // matches FaceEnrollment.config.STABILITY_FRAMES

    function buildTicks(svg) {
        var ticks = [];
        for (var i = 0; i < TICK_COUNT; i++) {
            var angle = (360 / TICK_COUNT) * i;
            var line = document.createElementNS(SVG_NS, 'line');
            line.setAttribute('x1', '50');
            line.setAttribute('y1', String(50 - RING_RADIUS));
            line.setAttribute('x2', '50');
            line.setAttribute('y2', String(50 - RING_RADIUS + TICK_LENGTH));
            line.setAttribute('transform', 'rotate(' + angle + ' 50 50)');
            line.setAttribute('class', 'fg-tick');
            svg.appendChild(line);
            ticks.push(line);
        }
        return ticks;
    }

    function create(container) {
        if (!container) {
            throw new Error('FaceGuideUI.create requires a container element');
        }

        var root = document.createElement('div');
        root.className = 'fg-overlay';

        var vignette = document.createElement('div');
        vignette.className = 'fg-vignette';
        root.appendChild(vignette);

        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 100 100');
        svg.setAttribute('class', 'fg-ring');
        var ticks = buildTicks(svg);
        root.appendChild(svg);

        var scan = document.createElement('div');
        scan.className = 'fg-scan';
        root.appendChild(scan);

        var badge = document.createElement('div');
        badge.className = 'fg-badge';
        badge.innerHTML = '<svg viewBox="0 0 24 24" class="fg-badge__check">'
            + '<path d="M4 12.5l5 5L20 6.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>'
            + '</svg>';
        root.appendChild(badge);

        var hintLeft = document.createElement('div');
        hintLeft.className = 'fg-hint fg-hint--left';
        hintLeft.setAttribute('aria-hidden', 'true');
        hintLeft.textContent = '‹'; // ‹
        root.appendChild(hintLeft);

        var hintRight = document.createElement('div');
        hintRight.className = 'fg-hint fg-hint--right';
        hintRight.setAttribute('aria-hidden', 'true');
        hintRight.textContent = '›'; // ›
        root.appendChild(hintRight);

        container.appendChild(root);

        function setTicks(colorClass, filledCount) {
            for (var i = 0; i < ticks.length; i++) {
                ticks[i].setAttribute('class', 'fg-tick' + (i < filledCount ? ' ' + colorClass : ''));
            }
        }

        function setMode(mode) {
            root.setAttribute('data-mode', mode);
        }

        function showHint(direction) {
            hintLeft.classList.toggle('fg-hint--show', direction === 'left');
            hintRight.classList.toggle('fg-hint--show', direction === 'right');
        }

        function hideBadge() {
            badge.classList.remove('fg-badge--show');
        }

        function pulseBadge() {
            badge.classList.remove('fg-badge--show');
            // Force reflow so the pop animation restarts on repeated captures.
            void badge.offsetWidth;
            badge.classList.add('fg-badge--show');
        }

        function update(state, meta) {
            meta = meta || {};
            hideBadge();

            switch (state) {
                case 'IDLE':
                case 'INITIALIZING_CAMERA':
                    setMode('init');
                    setTicks('', 0);
                    showHint(null);
                    break;

                case 'DETECTING_FACE':
                    setMode('searching');
                    setTicks('', 0);
                    showHint(null);
                    break;

                case 'POSITIONING':
                    setMode('positioning');
                    setTicks('fg-tick--warn', TICK_COUNT);
                    showHint(null);
                    break;

                case 'FRONT_READY':
                case 'LEFT_READY':
                case 'RIGHT_READY': {
                    var target = meta.target || DEFAULT_TARGET;
                    var streak = meta.streak || 0;

                    if (streak > 0) {
                        setMode('locking');
                        var filled = Math.min(TICK_COUNT, Math.round((streak / target) * TICK_COUNT));
                        setTicks('fg-tick--good', filled);
                        showHint(null);
                    } else if (meta.reason === 'WRONG_POSE') {
                        setMode('wrong-pose');
                        setTicks('fg-tick--warn', TICK_COUNT);
                        showHint(meta.pose === 'LEFT' ? 'left' : (meta.pose === 'RIGHT' ? 'right' : null));
                    } else {
                        setMode('positioning');
                        setTicks('fg-tick--warn', TICK_COUNT);
                        showHint(null);
                    }
                    break;
                }

                case 'FRONT_CAPTURED':
                case 'LEFT_CAPTURED':
                case 'RIGHT_CAPTURED':
                    setMode('captured');
                    setTicks('fg-tick--good', TICK_COUNT);
                    showHint(null);
                    pulseBadge();
                    break;

                case 'PROCESSING':
                case 'DUPLICATE_CHECK':
                    setMode('processing');
                    setTicks('fg-tick--good', TICK_COUNT);
                    showHint(null);
                    break;

                case 'COMPLETE':
                    setMode('complete');
                    setTicks('fg-tick--good', TICK_COUNT);
                    showHint(null);
                    pulseBadge();
                    break;

                case 'PROCESSING_ERROR':
                    setMode('error');
                    setTicks('fg-tick--error', TICK_COUNT);
                    showHint(null);
                    break;

                default:
                    break;
            }
        }

        function reset() {
            update('IDLE', {});
        }

        function destroy() {
            if (root.parentNode) {
                root.parentNode.removeChild(root);
            }
        }

        update('IDLE', {});

        return { update: update, reset: reset, destroy: destroy };
    }

    global.FaceGuideUI = { create: create };
})(window);
