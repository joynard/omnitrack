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

    /* ---------------------------------------------------------------------
       Toasts
       ---------------------------------------------------------------------
       Every transient message in the app goes through notify(). Toasts stack at
       the top centre of the viewport: the newest message is always in the same
       place, and a burst of saves cannot push the board around the way an
       in-flow notice does.
    */
    var TOAST_LIMIT = 4;

    function toastHost() {
        var host = document.getElementById('toast-host');

        if (!host) {
            host = document.createElement('div');
            host.id = 'toast-host';
            host.className = 'toast-host';
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }

        return host;
    }

    function dismissToast(toast) {
        if (!toast || toast.dataset.leaving === '1') {
            return;
        }

        toast.dataset.leaving = '1';
        toast.classList.add('is-leaving');

        // Matches the CSS exit transition; remove() alone would cut it off.
        setTimeout(function () {
            toast.remove();
        }, 200);
    }

    /** Transient notice, stacked at the top centre of the viewport. */
    function notify(message, kind, timeoutMs) {
        var host = toastHost();
        var toast = document.createElement('div');

        toast.className = 'toast is-' + (kind || 'success');
        // An error interrupts on purpose; a save confirmation must not.
        toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        toast.textContent = message;
        toast.title = 'Klik untuk menutup';
        toast.addEventListener('click', function () {
            dismissToast(toast);
        });

        host.appendChild(toast);

        // Drop the oldest rather than letting a loop of failures cover the page.
        while (host.children.length > TOAST_LIMIT) {
            dismissToast(host.firstElementChild);
        }

        var ms = timeoutMs === undefined || timeoutMs === null ? 4500 : timeoutMs;

        if (ms !== 0) {
            setTimeout(function () {
                dismissToast(toast);
            }, ms);
        }

        return toast;
    }

    /* ---------------------------------------------------------------------
       Modal dialogs
       ---------------------------------------------------------------------
       window.confirm() and window.prompt() render the browser's own chrome,
       which ignores the theme and cannot be styled. These replace them with the
       app's modal and return promises, so call sites read the same way.
    */

    /**
     * Build and show one dialog.
     *
     * @param {Object} config title, message, labels, danger flag, optional field
     * @returns {Promise<?string>} null when dismissed, otherwise '' for a plain
     *          confirm or the field value for a prompt.
     */
    function openDialog(config) {
        return new Promise(function (resolve) {
            var previousFocus = document.activeElement;
            var overlay = document.createElement('div');
            overlay.className = 'overlay overlay-dialog';

            var modal = document.createElement('div');
            modal.className = 'modal' + (config.danger ? ' is-danger' : '');
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');

            var titleId = 'modal-title-' + Date.now();
            modal.setAttribute('aria-labelledby', titleId);

            var title = document.createElement('h2');
            title.className = 'modal-title';
            title.id = titleId;
            title.textContent = config.title || 'Konfirmasi';
            modal.appendChild(title);

            if (config.message) {
                var message = document.createElement('p');
                message.className = 'modal-message';
                // textContent, never innerHTML: these strings carry project,
                // module and category names typed by the user.
                message.textContent = config.message;
                modal.appendChild(message);
            }

            var input = null;

            if (config.field) {
                var field = document.createElement('label');
                field.className = 'modal-field';

                var fieldLabel = document.createElement('span');
                fieldLabel.className = 'field-label';
                fieldLabel.textContent = config.field.label;
                field.appendChild(fieldLabel);

                input = document.createElement('input');
                input.className = 'input';
                input.type = 'text';
                input.value = config.field.value || '';
                input.placeholder = config.field.placeholder || '';
                field.appendChild(input);

                modal.appendChild(field);
            }

            var actions = document.createElement('div');
            actions.className = 'modal-actions';

            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-secondary btn-sm';
            cancel.textContent = config.cancelLabel || 'Batal';

            var accept = document.createElement('button');
            accept.type = 'button';
            accept.className = 'btn ' + (config.danger ? 'btn-danger' : 'btn-primary') + ' btn-sm';
            accept.textContent = config.confirmLabel || 'OK';

            actions.appendChild(cancel);
            actions.appendChild(accept);
            modal.appendChild(actions);
            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            function close(value) {
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();

                // Return focus where it was, or the keyboard user is dropped at
                // the top of the document after every confirmation.
                if (previousFocus && typeof previousFocus.focus === 'function') {
                    previousFocus.focus();
                }

                resolve(value);
            }

            function onKey(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close(null);
                    return;
                }

                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    close(input ? input.value : '');
                }
            }

            cancel.addEventListener('click', function () {
                close(null);
            });

            accept.addEventListener('click', function () {
                close(input ? input.value : '');
            });

            // Backdrop click cancels; a click inside the modal must not bubble.
            overlay.addEventListener('mousedown', function (event) {
                if (event.target === overlay) {
                    close(null);
                }
            });

            document.addEventListener('keydown', onKey, true);

            (input || accept).focus();

            if (input) {
                input.select();
            }
        });
    }

    /** Promise-based window.confirm(). Resolves false when dismissed. */
    async function confirm(message, options) {
        options = options || {};

        var answer = await openDialog({
            title: options.title || 'Konfirmasi',
            message: message,
            confirmLabel: options.confirmLabel || 'OK',
            cancelLabel: options.cancelLabel || 'Batal',
            danger: options.danger === true
        });

        return answer !== null;
    }

    /** Promise-based window.prompt(). Resolves null when dismissed. */
    function promptDialog(label, value, options) {
        options = options || {};

        return openDialog({
            title: options.title || 'Ubah',
            message: options.message || '',
            confirmLabel: options.confirmLabel || 'Simpan',
            cancelLabel: options.cancelLabel || 'Batal',
            field: {
                label: label,
                value: value,
                placeholder: options.placeholder || ''
            }
        });
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
        confirm: confirm,
        prompt: promptDialog,
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

    /* ---------------------------------------------------------------------
       Collapsible cards
       ---------------------------------------------------------------------
       Any .card carrying data-collapse="<key>" gets a toggle injected into its
       head, and everything after that head is hidden while collapsed -- no
       markup has to be wrapped, which keeps the Blade views readable.

       The choice lives in localStorage, so an explicit open survives a reload;
       data-collapse-default only decides what a first visit looks like.
    */
    (function () {
        var STORAGE_KEY = 'omnitrack.collapsed';
        var cards = document.querySelectorAll('.card[data-collapse]');

        if (!cards.length) {
            return;
        }

        var stored = {};

        try {
            stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') || {};
        } catch (e) {
            stored = {};
        }

        function persist() {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
            } catch (e) {
                // Storage unavailable: the toggle still works for this view.
            }
        }

        Array.prototype.forEach.call(cards, function (card) {
            var head = card.querySelector('.card-head');

            if (!head) {
                return;
            }

            var key = card.dataset.collapse;
            var closed = Object.prototype.hasOwnProperty.call(stored, key)
                ? stored[key] === true
                : card.dataset.collapseDefault === 'closed';

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'card-toggle';

            var icon = document.createElement('span');
            icon.className = 'card-toggle-icon';
            icon.setAttribute('aria-hidden', 'true');

            toggle.appendChild(icon);

            function apply() {
                card.classList.toggle('is-collapsed', closed);
                toggle.setAttribute('aria-expanded', closed ? 'false' : 'true');

                // Icon-only by design, so the name has to come from somewhere:
                // without it the button reaches a screen reader as an unlabelled
                // control. Same string drives the hover tooltip.
                var name = closed ? 'Tampilkan bagian ini' : 'Sembunyikan bagian ini';

                toggle.setAttribute('aria-label', name);
                toggle.title = name;
            }

            toggle.addEventListener('click', function () {
                closed = !closed;
                stored[key] = closed;
                persist();
                apply();
            });

            apply();
            head.appendChild(toggle);
        });
    })();
})();
