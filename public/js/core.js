/* ==========================================================================
   Omnitrack core helpers. No framework, no build step.
   ========================================================================== */

(function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = csrfMeta ? csrfMeta.getAttribute('content') : '';

    /**
     * Minimal JSON fetch wrapper that carries the CSRF token and always
     * resolves to { ok, status, body } instead of throwing on HTTP errors.
     */
    async function request(url, options) {
        options = options || {};

        var response = await fetch(url, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: Object.assign(
                {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': CSRF
                },
                options.body ? { 'Content-Type': 'application/json' } : {},
                options.headers || {}
            ),
            body: options.body ? JSON.stringify(options.body) : undefined
        });

        var text = await response.text();
        var body = null;

        if (text) {
            try {
                body = JSON.parse(text);
            } catch (e) {
                body = { message: text };
            }
        }

        return { ok: response.ok, status: response.status, body: body || {} };
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function emptyRow(columns, message) {
        return '<tr><td class="empty" colspan="' + columns + '">' + escapeHtml(message) + '</td></tr>';
    }

    function formatDate(value) {
        if (!value) {
            return '—';
        }

        var parsed = new Date(value);

        if (isNaN(parsed.getTime())) {
            return value;
        }

        return parsed.toLocaleDateString('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
    }

    function debounce(fn, wait) {
        var timer = null;

        return function () {
            var args = arguments;
            var self = this;

            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(self, args);
            }, wait);
        };
    }

    /** Transient page-level notice shown above the board. */
    function notify(message, kind, timeoutMs) {
        var host = document.getElementById('flash-host');

        if (!host) {
            return;
        }

        host.innerHTML = '';

        var box = document.createElement('div');
        box.className = 'flash is-' + (kind || 'success');
        box.textContent = message;
        host.appendChild(box);

        if (timeoutMs !== 0) {
            setTimeout(function () {
                box.remove();
            }, timeoutMs || 5000);
        }
    }

    function setBusy(button, busy, busyLabel) {
        if (!button) {
            return;
        }

        if (busy) {
            button.dataset.originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = busyLabel || 'Working…';
        } else {
            button.disabled = false;

            if (button.dataset.originalLabel) {
                button.textContent = button.dataset.originalLabel;
                delete button.dataset.originalLabel;
            }
        }
    }

    /**
     * Extract a readable message from an error response body.
     * Laravel validation errors arrive as { message, errors: { field: [..] } }.
     */
    function errorMessage(body, fallback) {
        if (!body) {
            return fallback || 'Terjadi kesalahan.';
        }

        if (body.errors) {
            var first = Object.keys(body.errors)[0];

            if (first && body.errors[first] && body.errors[first][0]) {
                return body.errors[first][0];
            }
        }

        return body.message || fallback || 'Terjadi kesalahan.';
    }

    window.Omnitrack = {
        request: request,
        escapeHtml: escapeHtml,
        emptyRow: emptyRow,
        formatDate: formatDate,
        debounce: debounce,
        notify: notify,
        setBusy: setBusy,
        errorMessage: errorMessage
    };

    /* ---------------------------------------------------------------------
       Theme switching
       ---------------------------------------------------------------------
       The saved theme is applied pre-paint by an inline script in the layout.
       This only handles user changes and persistence.
    */
    (function () {
        var STORAGE_KEY = 'omnitrack.theme';
        var DEFAULT_THEME = 'warm';
        var AVAILABLE = ['warm', 'paper', 'slate', 'forest', 'dark'];
        var root = document.documentElement;
        var picker = document.getElementById('theme-select');

        function current() {
            var set = root.getAttribute('data-theme');

            return AVAILABLE.indexOf(set) !== -1 ? set : DEFAULT_THEME;
        }

        function apply(theme) {
            if (AVAILABLE.indexOf(theme) === -1) {
                theme = DEFAULT_THEME;
            }

            root.setAttribute('data-theme', theme);

            try {
                window.localStorage.setItem(STORAGE_KEY, theme);
            } catch (e) {
                // Storage unavailable: the theme still applies for this view.
            }

            if (picker) {
                picker.value = theme;
            }
        }

        if (picker) {
            picker.value = current();

            picker.addEventListener('change', function () {
                apply(picker.value);
            });
        }

        // Guarantee the attribute exists even with no saved preference.
        if (!root.getAttribute('data-theme')) {
            root.setAttribute('data-theme', DEFAULT_THEME);
        }
    })();

    /* ---------------------------------------------------------------------
       Responsive action placement
       ---------------------------------------------------------------------
       Buttons marked with data-mobile-to="<id>" move into that container on
       narrow screens and return to data-desktop-home="<id>" on wide screens.
       Moving the node keeps its event listeners intact, and resolving the
       desktop home by id (rather than the original parentNode) means a button
       that starts inside a topbar slot is not sent back to the topbar.
    */
    (function () {
        var query = window.matchMedia('(max-width: 700px)');
        var moves = [];

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-mobile-to]'),
            function (button) {
                var mobileTarget = document.getElementById(button.dataset.mobileTo);
                var desktopHome = button.dataset.desktopHome
                    ? document.getElementById(button.dataset.desktopHome)
                    : button.parentNode;

                if (!mobileTarget || !desktopHome) {
                    return;
                }

                moves.push({
                    button: button,
                    mobileTarget: mobileTarget,
                    desktopHome: desktopHome
                });
            }
        );

        if (!moves.length) {
            return;
        }

        function placeIn(container, button) {
            if (button.parentNode !== container) {
                container.appendChild(button);
            }
        }

        function place() {
            moves.forEach(function (move) {
                placeIn(
                    query.matches ? move.mobileTarget : move.desktopHome,
                    move.button
                );
            });
        }

        place();

        // Safari below 14 only exposes the deprecated addListener.
        if (typeof query.addEventListener === 'function') {
            query.addEventListener('change', place);
        } else if (typeof query.addListener === 'function') {
            query.addListener(place);
        }
    })();
})();
