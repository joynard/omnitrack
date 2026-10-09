/* ==========================================================================
   Projects board.

   UX rules:
   - No full-list reload after a mutation. The local `projects` array is
     updated in place and only the affected DOM is touched.
   - Page scroll and the open detail panel survive every operation.
   - Google Sheets sync is the one exception: it runs in a background worker,
     so the list is polled a few times until the worker reports back.
   ========================================================================== */

(function () {
    'use strict';

    var tbody = document.getElementById('projects-body');
    var detailOverlay = document.getElementById('detail-overlay');
    var detailTitle = document.getElementById('detail-title');
    var detailGrid = document.getElementById('detail-grid');
    var detailModules = document.getElementById('detail-modules');
    var detailClose = document.getElementById('detail-close');
    var detailLink = document.getElementById('detail-link');
    var moduleForm = document.getElementById('module-form');
    var syncButton = document.getElementById('sync-button');
    var projectForm = document.getElementById('project-form');
    var projectsHeading = document.getElementById('projects-heading');

    var categoryBar = document.getElementById('category-bar');
    var categorySelect = document.getElementById('project-category');
    var categoryForm = document.getElementById('category-form');
    var categoryList = document.getElementById('category-list');
    var categoryManager = document.getElementById('category-manager');
    var categoryToggle = document.getElementById('category-manage-toggle');

    var API = '/board/projects';
    var COLUMNS = 9;

    var projects = [];
    var categories = [];
    var activeCategory = '';
    var currentProject = null;

    /* ------------------------------------------------------------- helpers */

    function statusChip(label, className) {
        return '<span class="' + className + '">' + window.Omnitrack.escapeHtml(label) + '</span>';
    }

    function progressCell(value) {
        var pct = Math.max(0, Math.min(100, Number(value) || 0));

        return '<div class="progress">' +
            '<div class="progress-track"><div class="progress-fill" style="width:' + pct + '%"></div></div>' +
            '<span class="progress-label">' + pct + '%</span>' +
            '</div>';
    }

    function categoryName(id) {
        for (var i = 0; i < categories.length; i++) {
            if (categories[i].id === id) {
                return categories[i].name;
            }
        }

        return null;
    }

    function valueOf(form, fieldName) {
        var field = form.querySelector('[name="' + fieldName + '"]');
        var value = field ? String(field.value).trim() : '';

        return value === '' ? null : value;
    }

    function byId(id) {
        for (var i = 0; i < projects.length; i++) {
            if (projects[i].id === id) {
                return projects[i];
            }
        }

        return null;
    }

    function visibleProjects() {
        if (!activeCategory) {
            return projects;
        }

        return projects.filter(function (project) {
            return project.category_id === activeCategory;
        });
    }

    /* -------------------------------------------------------------- render */

    function renderCategoryBar() {
        if (!categoryBar) {
            return;
        }

        var counts = {};
        projects.forEach(function (project) {
            if (project.category_id) {
                counts[project.category_id] = (counts[project.category_id] || 0) + 1;
            }
        });

        var html = '<button type="button" class="category-chip' + (activeCategory === '' ? ' is-active' : '') +
            '" data-category-filter="">Semua <span class="category-count">' + projects.length + '</span></button>';

        categories.forEach(function (category) {
            html += '<button type="button" class="category-chip' +
                (activeCategory === category.id ? ' is-active' : '') +
                '" data-category-filter="' + window.Omnitrack.escapeHtml(category.id) + '">' +
                window.Omnitrack.escapeHtml(category.name) +
                ' <span class="category-count">' + (counts[category.id] || 0) + '</span></button>';
        });

        categoryBar.innerHTML = html;

        Array.prototype.forEach.call(categoryBar.querySelectorAll('[data-category-filter]'), function (chip) {
            chip.addEventListener('click', function () {
                activeCategory = chip.dataset.categoryFilter;
                renderCategoryBar();
                renderProjects();
            });
        });
    }

    function renderProjects() {
        if (!tbody) {
            return;
        }

        var list = visibleProjects();

        if (projectsHeading) {
            projectsHeading.textContent = activeCategory
                ? (categoryName(activeCategory) || 'Kategori') + ' (' + list.length + ')'
                : 'Semua Project (' + projects.length + ')';
        }

        if (!list.length) {
            tbody.innerHTML = window.Omnitrack.emptyRow(
                COLUMNS,
                projects.length
                    ? 'Tidak ada project di kategori ini.'
                    : 'Belum ada project. Tekan Ctrl + P dan minta AI membuatkannya.'
            );
            return;
        }

        tbody.innerHTML = list.map(function (project) {
            var category = project.category_id ? categoryName(project.category_id) : null;

            return '<tr data-project-id="' + window.Omnitrack.escapeHtml(project.id) + '" draggable="true">' +
                '<td><span class="drag-handle" title="Tarik untuk mengubah urutan">&#8942;&#8942;</span></td>' +
                '<td>' +
                    '<div class="cell-title">' + window.Omnitrack.escapeHtml(project.name) + '</div>' +
                    (category ? '<div class="cell-sub"><span class="category-tag">' +
                        window.Omnitrack.escapeHtml(category) + '</span></div>' : '') +
                '</td>' +
                '<td>' + window.Omnitrack.escapeHtml(project.my_role || '&mdash;') + '</td>' +
                '<td>' + window.Omnitrack.escapeHtml(project.department || '&mdash;') + '</td>' +
                '<td>' + window.Omnitrack.escapeHtml(project.project_owner || '&mdash;') + '</td>' +
                '<td>' + statusChip(project.status_label, project.status_class) + '</td>' +
                '<td>' + progressCell(project.progress) + '</td>' +
                '<td class="meta">' + project.modules_done + '/' + project.modules_count + '</td>' +
                '<td><button type="button" class="btn btn-ghost btn-sm" data-project-delete="' +
                    window.Omnitrack.escapeHtml(project.id) + '">Hapus</button></td>' +
                '</tr>';
        }).join('');

        Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-project-id]'), function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('[data-project-delete]') || event.target.closest('.drag-handle')) {
                    return;
                }

                openProject(row.dataset.projectId);
            });

            attachRowDrag(row, row.dataset.projectId);
        });

        Array.prototype.forEach.call(tbody.querySelectorAll('[data-project-delete]'), function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                deleteProject(button.dataset.projectDelete);
            });
        });
    }

    function renderCategorySelect() {
        if (!categorySelect) {
            return;
        }

        var current = categorySelect.value;

        categorySelect.innerHTML = '<option value="">Tanpa kategori</option>' +
            categories.map(function (category) {
                return '<option value="' + window.Omnitrack.escapeHtml(category.id) + '">' +
                    window.Omnitrack.escapeHtml(category.name) + '</option>';
            }).join('');

        categorySelect.value = current;
    }

    function renderCategoryManager() {
        if (!categoryList) {
            return;
        }

        if (!categories.length) {
            categoryList.innerHTML = '<li class="check-item"><div class="check-body">' +
                '<div class="empty-hint">Belum ada kategori.</div></div></li>';
            return;
        }

        categoryList.innerHTML = categories.map(function (category) {
            return '<li class="check-item" data-category-row="' + window.Omnitrack.escapeHtml(category.id) + '">' +
                '<div class="check-body">' +
                    '<div class="check-title">' + window.Omnitrack.escapeHtml(category.name) + '</div>' +
                    '<div class="check-chips"><span class="chip chip-plain">' +
                        category.projects_count + ' project</span></div>' +
                '</div>' +
                '<div class="row-actions">' +
                    '<button type="button" class="btn btn-ghost btn-sm" data-category-rename="' +
                        window.Omnitrack.escapeHtml(category.id) + '">Ubah</button>' +
                    '<button type="button" class="btn btn-ghost btn-sm" data-category-delete="' +
                        window.Omnitrack.escapeHtml(category.id) + '">Hapus</button>' +
                '</div>' +
                '</li>';
        }).join('');

        Array.prototype.forEach.call(categoryList.querySelectorAll('[data-category-rename]'), function (button) {
            button.addEventListener('click', function () {
                renameCategory(button.dataset.categoryRename);
            });
        });

        Array.prototype.forEach.call(categoryList.querySelectorAll('[data-category-delete]'), function (button) {
            button.addEventListener('click', function () {
                deleteCategory(button.dataset.categoryDelete);
            });
        });
    }

    /* -------------------------------------------------------------- loading */

    async function loadCategories() {
        var result = await window.Omnitrack.request('/board/categories');

        if (!result.ok) {
            return;
        }

        categories = Array.isArray(result.body.categories) ? result.body.categories : [];

        renderCategoryBar();
        renderCategorySelect();
        renderCategoryManager();
    }

    async function loadProjects() {
        if (!tbody) {
            return;
        }

        var result = await window.Omnitrack.request(API);

        if (!result.ok) {
            window.Omnitrack.notify('Gagal memuat project.', 'error');
            return;
        }

        projects = Array.isArray(result.body.projects) ? result.body.projects : [];

        renderCategoryBar();
        renderProjects();
    }

    /* ------------------------------------------------------------ mutations */

    async function deleteProject(id) {
        var project = byId(id);
        var name = project ? project.name : 'project ini';

        if (!window.confirm('Hapus "' + name + '" beserta seluruh modulnya?')) {
            return;
        }

        var row = tbody.querySelector('tr[data-project-id="' + id + '"]');

        if (row) {
            row.classList.add('is-saving');
        }

        var result = await window.Omnitrack.request(API + '/' + encodeURIComponent(id), { method: 'DELETE' });

        if (!result.ok) {
            if (row) {
                row.classList.remove('is-saving');
            }

            window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal menghapus project.'), 'error');
            return;
        }

        // Remove locally: no reload, so scroll and the rest of the board stay put.
        projects = projects.filter(function (item) { return item.id !== id; });

        if (currentProject && currentProject.id === id) {
            currentProject = null;
            closeDetail();
        }

        var category = categories.filter(function (item) { return item.id === project.category_id; })[0];

        if (category && category.projects_count > 0) {
            category.projects_count--;
        }

        renderCategoryBar();
        renderProjects();
        renderCategoryManager();

        window.Omnitrack.notify('Project dihapus.', 'success');
    }

    async function deleteModule(moduleId) {
        if (!window.confirm('Hapus modul ini?')) {
            return;
        }

        var item = detailModules.querySelector('[data-module-id="' + moduleId + '"]');

        if (item) {
            item.classList.add('is-saving');
        }

        var result = await window.Omnitrack.request('/board/modules/' + encodeURIComponent(moduleId), {
            method: 'DELETE'
        });

        if (!result.ok) {
            if (item) {
                item.classList.remove('is-saving');
            }

            window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal menghapus modul.'), 'error');
            return;
        }

        if (currentProject) {
            currentProject.modules = currentProject.modules.filter(function (module) {
                return module.id !== moduleId;
            });

            currentProject.modules_count = currentProject.modules.length;
            currentProject.modules_done = currentProject.modules.filter(function (module) {
                return module.status === 'done';
            }).length;
            currentProject.progress = currentProject.modules_count
                ? Math.round((currentProject.modules_done / currentProject.modules_count) * 100)
                : currentProject.progress;

            renderDetail();
            patchProjectRow(currentProject);
        }

        window.Omnitrack.notify('Modul dihapus.', 'success');
    }

    /**
     * Repaint only one project row in place, keeping scroll and drag state.
     */
    function patchProjectRow(project) {
        var row = tbody.querySelector('tr[data-project-id="' + project.id + '"]');

        if (!row) {
            return;
        }

        var category = project.category_id ? categoryName(project.category_id) : null;
        var cells = row.children;

        if (cells.length < COLUMNS) {
            return;
        }

        cells[1].innerHTML = '<div class="cell-title">' + window.Omnitrack.escapeHtml(project.name) + '</div>' +
            (category ? '<div class="cell-sub"><span class="category-tag">' +
                window.Omnitrack.escapeHtml(category) + '</span></div>' : '');

        cells[5].innerHTML = statusChip(project.status_label, project.status_class);
        cells[6].innerHTML = progressCell(project.progress);
        cells[7].innerHTML = project.modules_done + '/' + project.modules_count;
    }

    async function toggleModule(moduleId, done) {
        var module = null;

        if (currentProject) {
            module = currentProject.modules.filter(function (item) { return item.id === moduleId; })[0];
        }

        var result = await window.Omnitrack.request('/board/modules/' + encodeURIComponent(moduleId), {
            method: 'POST',
            body: { status: done ? 'done' : 'pending' }
        });

        if (!result.ok) {
            // Put the checkbox back: the server did not accept the change.
            var box = detailModules.querySelector('[data-module-toggle="' + moduleId + '"]');

            if (box) {
                box.checked = !done;
            }

            window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal mengubah status modul.'), 'error');
            return;
        }

        if (!module || !currentProject) {
            return;
        }

        module.status = done ? 'done' : 'pending';
        module.status_label = done ? 'Done' : 'Pending';
        module.status_class = done ? 'chip chip-done' : 'chip chip-pending';

        currentProject.modules_done = currentProject.modules.filter(function (item) {
            return item.status === 'done';
        }).length;

        currentProject.modules_count = currentProject.modules.length;

        currentProject.progress = currentProject.modules_count
            ? Math.round((currentProject.modules_done / currentProject.modules_count) * 100)
            : 0;

        // Update just this module's visuals instead of re-rendering the panel.
        var title = detailModules.querySelector('[data-module-title="' + moduleId + '"]');
        var chips = detailModules.querySelector('[data-module-chips="' + moduleId + '"]');

        if (title) {
            title.classList.toggle('is-done', done);
        }

        if (chips) {
            chips.innerHTML = statusChip(module.status_label, module.status_class) +
                statusChip(module.priority_label, module.priority_class) +
                (module.due_date
                    ? '<span class="chip chip-plain">' + window.Omnitrack.escapeHtml(window.Omnitrack.formatDate(module.due_date)) + '</span>'
                    : '');
        }

        patchProjectRow(currentProject);

        // Keep the "Kategori" counters honest when a project completes.
        var project = byId(currentProject.id);

        if (project) {
            project.modules_done = currentProject.modules_done;
            project.progress = currentProject.progress;
        }
    }

    /* ----------------------------------------------------------- categories */

    async function renameCategory(id) {
        var name = categoryName(id);
        var next = window.prompt('Nama kategori baru:', name || '');

        if (next === null) {
            return;
        }

        next = next.trim();

        if (next === '' || next === name) {
            return;
        }

        var result = await window.Omnitrack.request('/board/categories/' + encodeURIComponent(id), {
            method: 'POST',
            body: { name: next }
        });

        if (!result.ok) {
            window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal mengubah kategori.'), 'error');
            return;
        }

        categories.forEach(function (category) {
            if (category.id === id) {
                category.name = next;
            }
        });

        // Projects keep the id, only the label changes.
        renderCategoryBar();
        renderCategorySelect();
        renderCategoryManager();
        renderProjects();

        if (currentProject) {
            renderDetail();
        }

        window.Omnitrack.notify('Kategori diubah.', 'success');
    }

    async function deleteCategory(id) {
        var name = categoryName(id);

        if (!window.confirm('Hapus kategori "' + name + '"? Project di dalamnya tidak terhapus.')) {
            return;
        }

        var result = await window.Omnitrack.request('/board/categories/' + encodeURIComponent(id), {
            method: 'DELETE'
        });

        if (!result.ok) {
            window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal menghapus kategori.'), 'error');
            return;
        }

        var unfiled = Number(result.body.unfiled_projects) || 0;

        categories = categories.filter(function (category) { return category.id !== id; });

        // The projects survive, they simply lose the label.
        projects.forEach(function (project) {
            if (project.category_id === id) {
                project.category_id = null;
            }
        });

        if (activeCategory === id) {
            activeCategory = '';
        }

        renderCategoryBar();
        renderCategorySelect();
        renderCategoryManager();
        renderProjects();

        if (currentProject && currentProject.category_id === id) {
            currentProject.category_id = null;
            renderDetail();
        }

        window.Omnitrack.notify(
            'Kategori "' + name + '" dihapus.' +
            (unfiled ? ' ' + unfiled + ' project jadi tanpa kategori.' : ''),
            'success'
        );
    }

    /* --------------------------------------------------------- drag & drop */

    function attachRowDrag(element, id) {
        element.addEventListener('dragstart', function (event) {
            element.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', id);
        });

        element.addEventListener('dragend', function () {
            element.classList.remove('is-dragging');
            clearDropTargets();
        });

        element.addEventListener('dragover', function (event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            if (!element.classList.contains('is-dragging')) {
                clearDropTargets();
                element.classList.add('is-drop-target');
            }
        });

        element.addEventListener('drop', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var dragging = tbody.querySelector('.is-dragging');

            clearDropTargets();

            if (!dragging || dragging === element) {
                return;
            }

            var rect = element.getBoundingClientRect();
            var after = event.clientY > rect.top + rect.height / 2;

            tbody.insertBefore(dragging, after ? element.nextSibling : element);

            persistProjectOrder();
        });
    }

    function clearDropTargets() {
        Array.prototype.forEach.call(document.querySelectorAll('.is-drop-target'), function (node) {
            node.classList.remove('is-drop-target');
        });
    }

    async function persistProjectOrder() {
        var ids = Array.prototype.map.call(
            tbody.querySelectorAll('tr[data-project-id]'),
            function (row) { return row.dataset.projectId; }
        );

        var result = await window.Omnitrack.request(API + '/reorder', {
            method: 'POST',
            body: { project_ids: ids }
        });

        if (!result.ok) {
            window.Omnitrack.notify('Gagal menyimpan urutan project.', 'error');
            return;
        }

        // Reorder the array to match the DOM so later renders agree.
        projects.sort(function (a, b) {
            return ids.indexOf(a.id) - ids.indexOf(b.id);
        });
    }

    async function persistModuleOrder() {
        if (!currentProject) {
            return;
        }

        var ids = Array.prototype.map.call(
            detailModules.querySelectorAll('[data-module-id]'),
            function (item) { return item.dataset.moduleId; }
        );

        var result = await window.Omnitrack.request(
            API + '/' + encodeURIComponent(currentProject.id) + '/modules/reorder',
            { method: 'POST', body: { module_ids: ids } }
        );

        if (!result.ok) {
            window.Omnitrack.notify('Gagal menyimpan urutan modul.', 'error');
            return;
        }

        currentProject.modules.sort(function (a, b) {
            return ids.indexOf(a.id) - ids.indexOf(b.id);
        });
    }

    /* -------------------------------------------------------------- detail */

    function isDetailOpen() {
        return detailOverlay && !detailOverlay.hidden;
    }

    function openDetail() {
        if (!detailOverlay || isDetailOpen()) {
            return;
        }

        detailOverlay.hidden = false;
        document.body.style.overflow = 'hidden';

        var dialog = detailOverlay.querySelector('.dialog');

        if (dialog) {
            dialog.setAttribute('tabindex', '-1');
            dialog.focus();
        }
    }

    function closeDetail() {
        if (!detailOverlay || !isDetailOpen()) {
            return;
        }

        detailOverlay.hidden = true;
        document.body.style.overflow = '';
        currentProject = null;
    }

    function renderDetail() {
        if (!detailOverlay || !currentProject) {
            return;
        }

        var project = currentProject;
        var modules = project.modules || [];

        openDetail();
        detailTitle.textContent = project.name;

        if (detailLink) {
            if (project.doc_link) {
                detailLink.href = project.doc_link;
                detailLink.hidden = false;
            } else {
                detailLink.hidden = true;
            }
        }

        var category = project.category_id ? categoryName(project.category_id) : null;

        var fields = [
            ['Kategori', category
                ? '<span class="category-tag">' + window.Omnitrack.escapeHtml(category) + '</span>'
                : '&mdash;'],
            ['Status', statusChip(project.status_label, project.status_class)],
            ['My Role', window.Omnitrack.escapeHtml(project.my_role || '&mdash;')],
            ['Department', window.Omnitrack.escapeHtml(project.department || '&mdash;')],
            ['User', window.Omnitrack.escapeHtml(project.target_user || '&mdash;')],
            ['Project Owner', window.Omnitrack.escapeHtml(project.project_owner || '&mdash;')],
            ['Progress', progressCell(project.progress)]
        ];

        detailGrid.innerHTML = fields.map(function (pair) {
            return '<div><div class="detail-term">' + pair[0] + '</div>' +
                '<div class="detail-value">' + pair[1] + '</div></div>';
        }).join('');

        if (project.description) {
            detailGrid.innerHTML += '<div style="grid-column:1/-1">' +
                '<div class="detail-term">Deskripsi</div>' +
                '<div class="detail-value">' + window.Omnitrack.escapeHtml(project.description) + '</div></div>';
        }

        if (!modules.length) {
            detailModules.innerHTML = '<li class="check-item"><div class="check-body">' +
                '<div class="empty-hint">Belum ada modul.</div></div></li>';
            return;
        }

        detailModules.innerHTML = modules.map(function (module) {
            return '<li class="check-item" data-module-id="' + window.Omnitrack.escapeHtml(module.id) + '" draggable="true">' +
                '<span class="drag-handle" title="Tarik untuk mengubah urutan">&#8942;&#8942;</span>' +
                '<input type="checkbox" data-module-toggle="' + window.Omnitrack.escapeHtml(module.id) + '"' +
                (module.status === 'done' ? ' checked' : '') + '>' +
                '<div class="check-body">' +
                    '<div class="check-title' + (module.status === 'done' ? ' is-done' : '') +
                        '" data-module-title="' + window.Omnitrack.escapeHtml(module.id) + '">' +
                        window.Omnitrack.escapeHtml(module.title) +
                    '</div>' +
                    (module.description
                        ? '<div class="check-notes">' + window.Omnitrack.escapeHtml(module.description) + '</div>'
                        : '') +
                    '<div class="check-chips" data-module-chips="' + window.Omnitrack.escapeHtml(module.id) + '">' +
                        statusChip(module.status_label, module.status_class) +
                        statusChip(module.priority_label, module.priority_class) +
                        (module.due_date
                            ? '<span class="chip chip-plain">' + window.Omnitrack.escapeHtml(window.Omnitrack.formatDate(module.due_date)) + '</span>'
                            : '') +
                    '</div>' +
                '</div>' +
                '<div class="row-actions">' +
                    '<button type="button" class="btn btn-ghost btn-sm" data-module-delete="' +
                        window.Omnitrack.escapeHtml(module.id) + '">Hapus</button>' +
                '</div>' +
                '</li>';
        }).join('');

        Array.prototype.forEach.call(detailModules.querySelectorAll('[data-module-toggle]'), function (box) {
            box.addEventListener('change', function () {
                toggleModule(box.dataset.moduleToggle, box.checked);
            });
        });

        Array.prototype.forEach.call(detailModules.querySelectorAll('[data-module-delete]'), function (button) {
            button.addEventListener('click', function () {
                deleteModule(button.dataset.moduleDelete);
            });
        });

        Array.prototype.forEach.call(detailModules.querySelectorAll('[data-module-id]'), function (item) {
            attachModuleDrag(item, item.dataset.moduleId);
        });
    }

    function attachModuleDrag(element, id) {
        element.addEventListener('dragstart', function (event) {
            element.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', id);
        });

        element.addEventListener('dragend', function () {
            element.classList.remove('is-dragging');
            clearDropTargets();
        });

        element.addEventListener('dragover', function (event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            if (!element.classList.contains('is-dragging')) {
                clearDropTargets();
                element.classList.add('is-drop-target');
            }
        });

        element.addEventListener('drop', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var dragging = detailModules.querySelector('.is-dragging');

            clearDropTargets();

            if (!dragging || dragging === element) {
                return;
            }

            var rect = element.getBoundingClientRect();
            var after = event.clientY > rect.top + rect.height / 2;

            detailModules.insertBefore(dragging, after ? element.nextSibling : element);

            persistModuleOrder();
        });
    }

    async function openProject(projectId) {
        var result = await window.Omnitrack.request(API + '/' + encodeURIComponent(projectId));

        if (!result.ok || !result.body.project) {
            window.Omnitrack.notify('Gagal memuat detail project.', 'error');
            return;
        }

        var fresh = result.body.project;
        var known = byId(projectId);

        // Carry over the fields the list already knows, so the panel and the
        // row never disagree.
        currentProject = Object.assign({}, known || {}, fresh, {
            modules: Array.isArray(result.body.modules) ? result.body.modules : []
        });

        renderDetail();
    }

    /* -------------------------------------------------------- create module */

    if (moduleForm) {
        moduleForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!currentProject) {
                window.Omnitrack.notify('Pilih project dulu.', 'error');
                return;
            }

            var titleInput = moduleForm.querySelector('[name="title"]');
            var priorityInput = moduleForm.querySelector('[name="priority"]');
            var dueInput = moduleForm.querySelector('[name="due_date"]');
            var title = titleInput.value.trim();

            if (!title) {
                return;
            }

            var button = moduleForm.querySelector('button[type="submit"]');
            window.Omnitrack.setBusy(button, true, 'Menyimpan...');

            var result = await window.Omnitrack.request(
                API + '/' + encodeURIComponent(currentProject.id) + '/modules',
                {
                    method: 'POST',
                    body: {
                        title: title,
                        priority: priorityInput ? priorityInput.value : 'medium',
                        due_date: dueInput && dueInput.value ? dueInput.value : null
                    }
                }
            );

            window.Omnitrack.setBusy(button, false);

            if (!result.ok) {
                window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal menambah modul.'), 'error');
                return;
            }

            // Append locally rather than refetching the whole panel.
            var created = (result.body.module && result.body.module.id)
                ? result.body.module
                : (result.body.data && result.body.data.modules && result.body.data.modules[0]);

            currentProject.modules.push({
                id: created.id,
                title: created.title || title,
                description: null,
                status: created.status || 'pending',
                status_label: 'Pending',
                status_class: 'chip chip-pending',
                priority: (priorityInput ? priorityInput.value : 'medium'),
                priority_label: (priorityInput ? priorityInput.value : 'medium').replace(/^\w/, function (c) { return c.toUpperCase(); }),
                priority_class: (priorityInput && priorityInput.value === 'high') ? 'chip chip-urgent'
                    : ((priorityInput && priorityInput.value === 'low') ? 'chip chip-pending' : 'chip chip-progress'),
                due_date: dueInput && dueInput.value ? dueInput.value : null
            });

            currentProject.modules_count = currentProject.modules.length;

            var project = byId(currentProject.id);

            if (project) {
                project.modules_count = currentProject.modules_count;
            }

            renderDetail();
            patchProjectRow(currentProject);

            titleInput.value = '';
            if (dueInput) {
                dueInput.value = '';
            }

            window.Omnitrack.notify('Modul ditambahkan.', 'success');
        });
    }

    /* ------------------------------------------------------- create project */

    if (projectForm) {
        projectForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            var nameInput = projectForm.querySelector('[name="name"]');
            var name = nameInput.value.trim();

            if (!name) {
                return;
            }

            var button = projectForm.querySelector('button[type="submit"]');
            window.Omnitrack.setBusy(button, true, 'Menyimpan...');

            var categoryId = valueOf(projectForm, 'category_id');

            var result = await window.Omnitrack.request(API, {
                method: 'POST',
                body: {
                    name: name,
                    category_id: categoryId,
                    my_role: valueOf(projectForm, 'my_role'),
                    department: valueOf(projectForm, 'department'),
                    project_owner: valueOf(projectForm, 'project_owner'),
                    status: valueOf(projectForm, 'status') || 'pending',
                    description: valueOf(projectForm, 'description')
                }
            });

            window.Omnitrack.setBusy(button, false);

            if (!result.ok) {
                window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal menambah project.'), 'error');
                return;
            }

            // Pull the canonical row so status labels and counts are correct.
            projects = [];
            await loadProjects();
            await loadCategories();

            projectForm.reset();

            window.Omnitrack.notify('Project ditambahkan.', 'success');
        });
    }

    /* ------------------------------------------------ category management UI */

    if (categoryToggle && categoryManager) {
        categoryToggle.addEventListener('click', function () {
            categoryManager.hidden = !categoryManager.hidden;
            categoryToggle.textContent = categoryManager.hidden ? 'Kelola Kategori' : 'Tutup';
        });
    }

    if (categoryForm) {
        categoryForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            var input = categoryForm.querySelector('[name="name"]');
            var name = input.value.trim();

            if (!name) {
                return;
            }

            var button = categoryForm.querySelector('button[type="submit"]');
            window.Omnitrack.setBusy(button, true, 'Menyimpan...');

            var result = await window.Omnitrack.request('/board/categories', {
                method: 'POST',
                body: { name: name }
            });

            window.Omnitrack.setBusy(button, false);

            if (!result.ok) {
                window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal menambah kategori.'), 'error');
                return;
            }

            var created = result.body.category;

            if (created && !categories.some(function (c) { return c.id === created.id; })) {
                categories.push({
                    id: created.id,
                    name: created.name,
                    slug: '',
                    order_index: categories.length,
                    projects_count: 0
                });
            }

            renderCategoryBar();
            renderCategorySelect();
            renderCategoryManager();

            input.value = '';
            window.Omnitrack.notify('Kategori "' + name + '" siap.', 'success');
        });
    }

    /* ----------------------------------------------------------------- sync */

    if (syncButton) {
        syncButton.addEventListener('click', async function () {
            window.Omnitrack.setBusy(syncButton, true, 'Menyinkronkan...');

            var result = await window.Omnitrack.request('/sync/google-sheet', { method: 'POST' });

            window.Omnitrack.setBusy(syncButton, false);

            var body = result.body || {};
            var skipped = body.status === 'skipped';

            window.Omnitrack.notify(
                body.message || 'Sync dijadwalkan.',
                skipped ? 'skipped' : (result.ok ? 'success' : 'error')
            );

            if (skipped) {
                return;
            }

            // The sheet is ingested by a background worker, so poll briefly
            // instead of blocking the button.
            var attempts = 0;
            var timer = setInterval(async function () {
                attempts++;
                await loadProjects();

                if (attempts >= 5) {
                    clearInterval(timer);
                }
            }, 2000);
        });
    }

    // The palette can change anything, so refresh the list. The detail dialog
    // is only reloaded when it is already open, so a palette command never
    // forces it open.
    document.addEventListener('omnitrack:refresh', function () {
        loadProjects();
        loadCategories();

        if (isDetailOpen() && currentProject) {
            openProject(currentProject.id);
        }
    });

    // "/<kategori>" in the palette filters this board.
    document.addEventListener('omnitrack:filter-category', function (event) {
        activeCategory = event.detail && event.detail.id ? event.detail.id : '';

        renderCategoryBar();
        renderProjects();
    });

    if (detailClose) {
        detailClose.addEventListener('click', closeDetail);
    }

    if (detailOverlay) {
        // Click on the backdrop, but not inside the dialog, closes it.
        detailOverlay.addEventListener('mousedown', function (event) {
            if (event.target === detailOverlay) {
                closeDetail();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isDetailOpen()) {
            closeDetail();
        }
    });

    loadCategories();
    loadProjects();
})();
