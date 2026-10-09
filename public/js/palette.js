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
        { title: 'Ringkas board', prompt: 'Ringkas kondisi board dan sebutkan prioritas berikutnya.', action: 'summarize_board' }
        // No "Sinkron Sheets": the Projects page already has a dedicated Sync
        // button, so a suggestion chip for it was pure duplication.
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
    var projectSearch = document.getElementById('palette-project-search');
    var projectList = document.getElementById('palette-project-list');
    var projectClear = document.getElementById('palette-project-clear');
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

        // Start with no project chosen. Without this the picker would keep
        // whatever was selected during a previous instruction, and the next
        // "pindahkan project ini" could silently apply to the wrong one.
        resetProjectPicker();

        // The field may still hold the previous instruction.
        autoGrowPrompt();
    }

    function close() {
        if (overlay.hidden) {
            return;
        }

        overlay.hidden = true;
        document.body.style.overflow = '';
        closeProjectList();

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
                    autoGrowPrompt();
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

    /**
     * Grow the instruction field with its content.
     *
     * WHY height='auto' first: scrollHeight only reports the content height while
     * the element is not already taller than that content, so without the reset
     * the field could grow but never shrink again after a delete.
     */
    function autoGrowPrompt() {
        if (!promptInput || promptInput.tagName !== 'TEXTAREA') {
            return;
        }

        promptInput.style.height = 'auto';

        // scrollHeight excludes the border, and border-box sizing means setting
        // height to it alone would leave a permanent 1px scrollbar.
        var borders = promptInput.offsetHeight - promptInput.clientHeight;

        promptInput.style.height = (promptInput.scrollHeight + borders) + 'px';
    }

    function syncActionUi() {
        if (!actionSelect) {
            return;
        }

        var action = actionSelect.value;

        // The project picker only matters for actions scoped to one project.
        if (projectField) {
            projectField.hidden = !needsProject(action);
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

        if (needsProject(action)) {
            loadProjects();
        }
    }

    /**
     * Whether an action is scoped to one project, and therefore whether the
     * picker is shown -- and required. One predicate for both, so the field can
     * never be visible without also being mandatory.
     */
    function needsProject(action) {
        return action === 'breakdown_project' || action === 'agent';
    }

    function setFeedback(message, kind) {
        if (!feedback) {
            return;
        }

        feedback.hidden = false;
        feedback.className = 'feedback' + (kind ? ' is-' + kind : '');
        feedback.textContent = message;
    }

    /* ------------------------------------------------------- project picker */

    // Sentinel for "no specific project". It is a mode, never an id: project_id
    // is validated against the projects table, so this must not reach the API.
    var ALL_PROJECTS = '__all__';
    // No "(global)" suffix: the row is already set apart by weight, colour and a
    // separator line, and the shorter label stays readable once it is picked.
    var ALL_LABEL = 'Seluruh workspace';

    var allProjects = [];
    var projectsLoaded = false;
    var selectedProject = null;
    var activeOption = -1;

    /**
     * Fetch the projects once and filter them in the browser.
     *
     * There are tens of projects, not thousands, so a request per keystroke
     * would be strictly slower than filtering an array already in memory.
     */
    async function loadProjects() {
        if (projectsLoaded) {
            renderProjectOptions(projectSearch ? projectSearch.value : '');
            return;
        }

        projectsLoaded = true;

        var result = await window.Omnitrack.request('/board/projects');

        allProjects = result.body && Array.isArray(result.body.projects) ? result.body.projects : [];

        renderProjectOptions(projectSearch ? projectSearch.value : '');
    }

    function renderProjectOptions(term) {
        if (!projectList) {
            return;
        }

        term = (term || '').trim().toLowerCase();

        var rows = allProjects.filter(function (project) {
            return term === '' || project.name.toLowerCase().indexOf(term) !== -1;
        });

        projectList.innerHTML = '';
        activeOption = -1;

        // Pinned first and never filtered away: this is the escape hatch from
        // "one project only", so it has to be reachable whatever is typed.
        projectList.appendChild(buildGlobalOption());

        if (!rows.length) {
            var empty = document.createElement('li');
            empty.className = 'combobox-empty';
            empty.textContent = allProjects.length
                ? 'Tidak ada project yang cocok.'
                : 'Belum ada project.';
            projectList.appendChild(empty);

            return;
        }

        // A hard cap keeps a pathological workspace from rendering hundreds of
        // nodes while typing; the search term is the intended way to narrow.
        rows.slice(0, 50).forEach(function (project) {
            var option = document.createElement('li');
            option.className = 'combobox-option';
            option.setAttribute('role', 'option');
            option.dataset.id = project.id;
            option.dataset.name = project.name;

            // textContent, never innerHTML: project names are user input. The
            // category is deliberately not shown -- /board/projects returns it as
            // ['id' => ..., 'name' => ...], and setting textContent to it printed
            // "[object Object]" on every row. The name alone is also all the row
            // needs; the list is searched, not read.
            option.textContent = project.name;

            // mousedown, not click: the input would blur first and the list would
            // already be closed by the time the click landed.
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
                pickProject(project.id, project.name);
            });

            projectList.appendChild(option);
        });
    }

    function openProjectList() {
        if (!projectList) {
            return;
        }

        renderProjectOptions(projectSearch ? projectSearch.value : '');
        projectList.hidden = false;

        if (projectSearch) {
            projectSearch.setAttribute('aria-expanded', 'true');
        }
    }

    function closeProjectList() {
        if (!projectList) {
            return;
        }

        projectList.hidden = true;
        activeOption = -1;

        if (projectSearch) {
            projectSearch.setAttribute('aria-expanded', 'false');
        }
    }

    /** The workspace-wide row, rendered like an option but meaning "no focus". */
    function buildGlobalOption() {
        var option = document.createElement('li');
        option.className = 'combobox-option is-global';
        option.setAttribute('role', 'option');
        option.dataset.id = ALL_PROJECTS;
        option.dataset.name = ALL_LABEL;
        option.textContent = ALL_LABEL;

        option.addEventListener('mousedown', function (event) {
            event.preventDefault();
            pickProject(ALL_PROJECTS, ALL_LABEL);
        });

        return option;
    }

    function pickProject(id, name) {
        selectedProject = id || null;

        // The global mode clears the hidden field instead of carrying the
        // sentinel, so a native form post could never send project_id=__all__.
        projectSelect.value = selectedProject === ALL_PROJECTS ? '' : (selectedProject || '');

        if (projectSearch) {
            projectSearch.value = name || '';
        }

        if (projectClear) {
            projectClear.hidden = !selectedProject;
        }

        closeProjectList();
        clearProjectError();
    }

    function clearProject() {
        selectedProject = null;
        projectSelect.value = '';

        if (projectSearch) {
            projectSearch.value = '';
            projectSearch.focus();
        }

        if (projectClear) {
            projectClear.hidden = true;
        }

        closeProjectList();
    }

    /** Back to the pristine state, used every time the palette opens. */
    function resetProjectPicker() {
        clearProject();

        if (projectField) {
            projectField.classList.remove('is-required');
        }
    }

    function highlightOption(index) {
        if (!projectList) {
            return;
        }

        var options = projectList.querySelectorAll('.combobox-option');

        if (!options.length) {
            return;
        }

        activeOption = Math.max(0, Math.min(index, options.length - 1));

        Array.prototype.forEach.call(options, function (option, i) {
            option.classList.toggle('is-active', i === activeOption);
        });

        options[activeOption].scrollIntoView({ block: 'nearest' });
    }

    function markProjectError(message) {
        setFeedback(message, 'error');

        if (projectField) {
            projectField.classList.add('is-required');
        }

        if (projectSearch) {
            projectSearch.focus();
            openProjectList();
        }
    }

    function clearProjectError() {
        if (projectField) {
            projectField.classList.remove('is-required');
        }
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
                autoGrowPrompt();
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

        // Exactly one project hit is unambiguous, so adopt it as the target of
        // the next instruction. (This used to set a dataset flag nothing read.)
        if (projects.length === 1) {
            pickProject(projects[0].id, projects[0].name);
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

        // The picker is mandatory wherever it is shown. It is hidden only for the
        // workspace-wide actions, so this blocks exactly the ambiguous case:
        // "pindahkan project ini" with no project named.
        if (needsProject(action) && !selectedProject) {
            markProjectError('Pilih project dulu: ketik untuk mencari, lalu klik atau tekan Enter.');
            return;
        }

        var payload = { action_type: action, prompt: prompt };

        // Global means "send no project at all", which is how the orchestrator
        // has always read an absent project_id: no focus, free to act anywhere.
        if (selectedProject && selectedProject !== ALL_PROJECTS) {
            payload.project_id = selectedProject;
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

    if (promptInput) {
        promptInput.addEventListener('input', autoGrowPrompt);

        promptInput.addEventListener('keydown', function (event) {
            // A textarea inserts a newline on Enter, which would break the
            // palette's "Enter jalankan". Shift + Enter still inserts one.
            if (event.key !== 'Enter' || event.shiftKey) {
                return;
            }

            event.preventDefault();

            if (!form) {
                return;
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }
        });
    }

    if (projectSearch) {
        projectSearch.addEventListener('focus', openProjectList);

        projectSearch.addEventListener('input', function () {
            // Typing invalidates whatever was picked: the text no longer names it.
            selectedProject = null;
            projectSelect.value = '';

            if (projectClear) {
                projectClear.hidden = true;
            }

            clearProjectError();
            openProjectList();
        });

        projectSearch.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                openProjectList();
                highlightOption(activeOption + (event.key === 'ArrowDown' ? 1 : -1));

                return;
            }

            if (event.key === 'Enter') {
                // Without this the form submits and the highlighted project is
                // never chosen.
                event.preventDefault();

                var options = projectList ? projectList.querySelectorAll('.combobox-option') : [];
                var option = options[activeOption];

                if (option) {
                    pickProject(option.dataset.id, option.dataset.name);
                } else {
                    closeProjectList();
                }

                return;
            }

            if (event.key === 'Escape') {
                // The palette also listens for Escape on document; stop it here so
                // the first Escape only closes this list.
                event.stopPropagation();
                closeProjectList();
            }
        });

        projectSearch.addEventListener('blur', function () {
            // A picked option is handled on mousedown, so a blur here means the
            // user really left the field. The delay lets that click land.
            setTimeout(closeProjectList, 120);
        });
    }

    if (projectClear) {
        projectClear.addEventListener('click', function (event) {
            event.preventDefault();
            clearProject();
        });
    }

    renderTemplates(STATIC_TEMPLATES);
    syncActionUi();
})();
