/* ==========================================================================
   Ctrl + P AI palette.
   ========================================================================== */

(function () {
    'use strict';

    var API = '/ai/command';
    var TEMPLATES_API = '/ai/templates';

    var STATIC_TEMPLATES = [
        { title: 'Kendalikan penuh', prompt: 'Pindahkan project ini ke kategori Kantor', action: 'agent' },
        { title: 'Buat task', prompt: '', action: 'parse_task' },
        { title: 'Pecah jadi 3 sub-modul', prompt: 'Pecah project ini menjadi 3 sub-modul teknis.', action: 'breakdown_project' },
        { title: 'Urutkan modul', prompt: 'Urutkan modul project ini, yang paling mendesak di atas.', action: 'agent' },
        { title: 'Ringkas board', prompt: 'Ringkas kondisi board dan sebutkan prioritas berikutnya.', action: 'summarize_board' },
        { title: 'Sinkron Sheets', prompt: '', action: 'trigger_sync' }
    ];

    var overlay = document.getElementById('palette-overlay');

    // Quick commands run client-side, with no LLM call:
    //   > task <judul> [!high|!medium|!low]   create a task instantly
    //   /<kata>                               jump to a category or project
    var QUICK_TASK = /^>\s*task\s+(.+)$/i;
    var QUICK_NAV = /^\/(.+)$/;

    if (!overlay) {
        return;
    }

    var form = document.getElementById('palette-form');
    var promptInput = document.getElementById('palette-prompt');
    var actionSelect = document.getElementById('palette-action');
    var projectSelect = document.getElementById('palette-project');
    var projectField = document.getElementById('palette-project-field');
    var feedback = document.getElementById('palette-feedback');
    var templateHost = document.getElementById('palette-templates');
    var submitButton = document.getElementById('palette-submit');

    var lastFocused = null;
    var templatesLoaded = false;
    var busy = false;

    /* ---------------------------------------------------------------- open */

    function open() {
        if (!overlay.hidden) {
            return;
        }

        lastFocused = document.activeElement;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';

        window.requestAnimationFrame(function () {
            if (promptInput) {
                promptInput.focus();
                promptInput.setSelectionRange(promptInput.value.length, promptInput.value.length);
            }
        });

        if (!templatesLoaded) {
            templatesLoaded = true;
            loadTemplates();
        }
    }

    function close() {
        if (overlay.hidden) {
            return;
        }

        overlay.hidden = true;
        document.body.style.overflow = '';

        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    function toggle() {
        if (overlay.hidden) {
            open();
        } else {
            close();
        }
    }

    /* ----------------------------------------------------------- templates */

    function renderTemplates(templates) {
        if (!templateHost) {
            return;
        }

        templateHost.innerHTML = '';

        templates.forEach(function (template) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'template-btn';
            button.textContent = template.title;

            button.addEventListener('click', function () {
                if (promptInput) {
                    promptInput.value = template.prompt || '';
                    promptInput.focus();
                    promptInput.setSelectionRange(promptInput.value.length, promptInput.value.length);
                }

                if (actionSelect && template.action) {
                    actionSelect.value = template.action;
                    syncActionUi();
                }
            });

            templateHost.appendChild(button);
        });
    }

    async function loadTemplates() {
        var result = await window.Omnitrack.request(TEMPLATES_API);

        // Server templates win when present; otherwise keep the built-in set.
        var rows = result.body && Array.isArray(result.body.templates) ? result.body.templates : [];

        if (!rows.length) {
            return;
        }

        renderTemplates(rows.map(function (row) {
            return {
                title: row.title,
                prompt: row.prompt_payload,
                action: row.action_type
            };
        }));
    }

    /* ---------------------------------------------------------------- form */

    function syncActionUi() {
        if (!actionSelect) {
            return;
        }

        var action = actionSelect.value;

        // The project picker only matters for actions scoped to one project.
        if (projectField) {
            projectField.hidden = !(action === 'breakdown_project' || action === 'agent');
        }

        if (promptInput) {
            if (action === 'trigger_sync') {
                promptInput.placeholder = 'Tidak perlu diisi untuk sync.';
            } else if (action === 'agent') {
                promptInput.placeholder = 'Tulis instruksi apa saja, mis. "pindahkan project ini ke kategori Kantor"';
            } else {
                promptInput.placeholder = 'Tulis instruksi…';
            }
        }

        if ((action === 'breakdown_project' || action === 'agent')) {
            loadProjects();
        }
    }

    function setFeedback(message, kind) {
        if (!feedback) {
            return;
        }

        feedback.hidden = false;
        feedback.className = 'feedback' + (kind ? ' is-' + kind : '');
        feedback.textContent = message;
    }

    async function loadProjects() {
        if (!projectSelect || projectSelect.dataset.loaded === '1') {
            return;
        }

        var result = await window.Omnitrack.request('/board/projects');
        var rows = result.body && Array.isArray(result.body.projects) ? result.body.projects : [];

        projectSelect.dataset.loaded = '1';

        if (!rows.length) {
            projectSelect.innerHTML = '<option value="">Belum ada project</option>';
            return;
        }

        projectSelect.innerHTML = '<option value="">Project terakhir</option>' +
            rows.map(function (project) {
                return '<option value="' + window.Omnitrack.escapeHtml(project.id) + '">' +
                    window.Omnitrack.escapeHtml(project.name) + '</option>';
            }).join('');
    }

    /**
     * Handle the instant, no-LLM commands. Returns true when the input was a
     * quick command and has been dealt with.
     */
    async function tryQuickCommand(raw) {
        var taskMatch = raw.match(QUICK_TASK);

        if (taskMatch) {
            var title = taskMatch[1].trim();
            var priority = 'medium';

            // Trailing !high / !low / !medium sets the priority.
            title = title.replace(/!(high|medium|low)\s*$/i, function (_, level) {
                priority = level.toLowerCase();

                return '';
            }).trim();

            if (title === '') {
                setFeedback('Tulis judul task setelah "> task".', 'error');

                return true;
            }

            setFeedback('Menyimpan task…', 'loading');

            var result = await window.Omnitrack.request('/board/tasks', {
                method: 'POST',
                body: { title: title, priority: priority }
            });

            if (result.ok) {
                setFeedback('Task "' + title + '" dibuat.', 'success');
                document.dispatchEvent(new CustomEvent('omnitrack:refresh'));
            } else {
                setFeedback(window.Omnitrack.errorMessage(result.body, 'Gagal membuat task.'), 'error');
            }

            if (promptInput) {
                promptInput.value = '';
            }

            return true;
        }

        var navMatch = raw.match(QUICK_NAV);

        if (navMatch) {
            await runNavigation(navMatch[1].trim());

            return true;
        }

        return false;
    }

    /**
     * "/<kata>": jump to a matching category or project, otherwise fall back to
     * a global search across projects, modules and tasks.
     */
    async function runNavigation(term) {
        if (term === '') {
            return;
        }

        setFeedback('Mencari…', 'loading');

        var result = await window.Omnitrack.request('/board/search?q=' + encodeURIComponent(term));

        if (!result.ok) {
            setFeedback(window.Omnitrack.errorMessage(result.body, 'Pencarian gagal.'), 'error');

            return;
        }

        var found = result.body.results || {};
        var categories = found.categories || [];
        var projects = found.projects || [];
        var modules = found.modules || [];
        var tasks = found.tasks || [];

        // An exact-ish category match becomes a filter on the Projects board.
        var category = categories.filter(function (item) {
            return item.name.toLowerCase().indexOf(term.toLowerCase()) !== -1;
        })[0];

        if (category && window.location.pathname === '/projects') {
            document.dispatchEvent(new CustomEvent('omnitrack:filter-category', {
                detail: { id: category.id, name: category.name }
            }));

            setFeedback('Filter ke kategori "' + category.name + '".', 'success');
            close();

            return;
        }

        var total = categories.length + projects.length + modules.length + tasks.length;

        if (!total) {
            setFeedback('Tidak ada hasil untuk "' + term + '".', 'skipped');

            return;
        }

        var lines = [];

        if (categories.length) {
            lines.push('KATEGORI\n' + categories.map(function (c) { return '  ' + c.name; }).join('\n'));
        }

        if (projects.length) {
            lines.push('PROJECT\n' + projects.map(function (p) {
                return '  ' + p.name + (p.category ? '  · ' + p.category : '');
            }).join('\n'));
        }

        if (modules.length) {
            lines.push('MODUL\n' + modules.map(function (m) {
                return '  ' + m.title + (m.project_name ? '  · ' + m.project_name : '');
            }).join('\n'));
        }

        if (tasks.length) {
            lines.push('TASK\n' + tasks.map(function (t) {
                return '  [' + t.status_label + '] ' + t.title;
            }).join('\n'));
        }

        setFeedback(lines.join('\n\n'), 'success');

        // Enter on a project result opens its detail dialog.
        if (projects.length === 1) {
            projectSelect && (projectSelect.dataset.searchHit = projects[0].id);
        }
    }

    async function submit(event) {
        event.preventDefault();

        if (busy) {
            return;
        }

        var prompt = promptInput ? promptInput.value.trim() : '';

        // Instant commands first: these must not wait on the model.
        if (prompt !== '' && await tryQuickCommand(prompt)) {
            return;
        }

        var action = actionSelect ? actionSelect.value : 'agent';

        if (!prompt && action !== 'trigger_sync') {
            setFeedback('Prompt tidak boleh kosong untuk aksi ini.', 'error');
            return;
        }

        var payload = { action_type: action, prompt: prompt };

        if (projectSelect && projectSelect.value) {
            payload.project_id = projectSelect.value;
        }

        busy = true;
        setFeedback('Memproses…', 'loading');
        window.Omnitrack.setBusy(submitButton, true, 'Memproses…');

        try {
            var result = await window.Omnitrack.request(API, { method: 'POST', body: payload });
            var body = result.body || {};
            var status = body.status || (result.ok ? 'success' : 'error');

            setFeedback(window.Omnitrack.errorMessage(body, 'Selesai tanpa pesan.'), status);

            if (status === 'success' || status === 'skipped') {
                // Let the boards repaint themselves; the modal stays open so
                // several instructions can be issued in a row.
                document.dispatchEvent(new CustomEvent('omnitrack:refresh'));
            }
        } catch (error) {
            setFeedback('Gagal menghubungi server: ' + error.message, 'error');
        } finally {
            busy = false;
            window.Omnitrack.setBusy(submitButton, false);
        }
    }

    /* ------------------------------------------------------------ listeners */

    document.addEventListener('keydown', function (event) {
        var key = (event.key || '').toLowerCase();
        var code = event.code || '';

        // Match on both key and code: key depends on the keyboard layout and on
        // held modifiers, code does not.
        var isP = key === 'p' || code === 'KeyP';

        if ((event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && isP) {
            event.preventDefault();
            toggle();
            return;
        }

        if ((key === 'escape' || code === 'Escape') && !overlay.hidden) {
            event.preventDefault();
            close();
        }
    });

    // Delegated so the trigger works even if a button is added to the DOM later
    // (core.js moves the sync button between containers on resize).
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-palette-open]') : null;

        if (trigger) {
            event.preventDefault();
            open();
        }
    });

    overlay.addEventListener('mousedown', function (event) {
        if (event.target === overlay) {
            close();
        }
    });

    var closeButton = document.getElementById('palette-close');

    if (closeButton) {
        closeButton.addEventListener('click', close);
    }

    if (form) {
        form.addEventListener('submit', submit);
    }

    if (actionSelect) {
        actionSelect.addEventListener('change', syncActionUi);
    }

    renderTemplates(STATIC_TEMPLATES);
    syncActionUi();
})();
