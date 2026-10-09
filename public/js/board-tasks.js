/* ==========================================================================
   Tasks board.

   No full-list reload after a mutation: the `cache` array is updated in place
   and only the affected row is repainted, so scroll position survives.
   ========================================================================== */

(function () {
    'use strict';

    var list = document.getElementById('tasks-list');
    var form = document.getElementById('task-form');
    var filter = document.getElementById('task-filter');
    var API = '/board/tasks';

    var cache = [];

    /* ------------------------------------------------------------- helpers */

    function chip(label, className) {
        return '<span class="' + className + '">' + window.Omnitrack.escapeHtml(label) + '</span>';
    }

    function byId(id) {
        for (var i = 0; i < cache.length; i++) {
            if (cache[i].id === id) {
                return cache[i];
            }
        }

        return null;
    }

    function nextStatus(status) {
        if (status === 'pending') {
            return 'in_progress';
        }

        if (status === 'in_progress') {
            return 'done';
        }

        return 'pending';
    }

    function nextLabel(status) {
        return status === 'pending' ? 'Mulai' : (status === 'in_progress' ? 'Selesai' : 'Buka lagi');
    }

    /** Row markup for one task. Kept in one place so patching is consistent. */
    function rowHtml(task, draggable) {
        return '<li class="check-item" data-task-id="' + window.Omnitrack.escapeHtml(task.id) + '"' +
            (draggable ? ' draggable="true"' : '') + '>' +
            (draggable ? '<span class="drag-handle" title="Tarik untuk mengubah urutan">&#8942;&#8942;</span>' : '') +
            '<input type="checkbox" data-task-toggle="' + window.Omnitrack.escapeHtml(task.id) + '"' +
            (task.status === 'done' ? ' checked' : '') + '>' +
            '<div class="check-body">' +
                '<div class="check-title' + (task.status === 'done' ? ' is-done' : '') + '" ' +
                    'data-task-title="' + window.Omnitrack.escapeHtml(task.id) + '">' +
                    window.Omnitrack.escapeHtml(task.title) +
                '</div>' +
                (task.notes
                    ? '<div class="check-notes">' + window.Omnitrack.escapeHtml(task.notes) + '</div>'
                    : '') +
                '<div class="check-chips" data-task-chips="' + window.Omnitrack.escapeHtml(task.id) + '">' +
                    chip(task.status_label, task.status_class) +
                    chip(task.priority_label, task.priority_class) +
                    (task.due_date
                        ? '<span class="chip chip-plain">' + window.Omnitrack.escapeHtml(window.Omnitrack.formatDate(task.due_date)) + '</span>'
                        : '') +
                '</div>' +
            '</div>' +
            '<div class="row-actions">' +
                '<button type="button" class="btn btn-ghost btn-sm" data-task-next="' +
                    window.Omnitrack.escapeHtml(task.id) + '">' + nextLabel(task.status) + '</button>' +
                '<button type="button" class="btn btn-ghost btn-sm" data-task-delete="' +
                    window.Omnitrack.escapeHtml(task.id) + '">Hapus</button>' +
            '</div>' +
            '</li>';
    }

    /* -------------------------------------------------------------- render */

    function visibleTasks() {
        var active = filter ? filter.value : '';

        if (!active) {
            return cache;
        }

        return cache.filter(function (task) {
            return task.status === active;
        });
    }

    function render() {
        var tasks = visibleTasks();

        // Dragging is only meaningful on the complete list; reordering a
        // filtered subset would persist a partial id list.
        var draggable = !(filter && filter.value);

        if (!tasks.length) {
            list.innerHTML = '<li class="check-item"><div class="check-body">' +
                '<div class="empty-hint">' +
                (cache.length ? 'Tidak ada task dengan status ini.' : 'Belum ada task.') +
                '</div></div></li>';
            return;
        }

        list.innerHTML = tasks.map(function (task) {
            return rowHtml(task, draggable);
        }).join('');

        bindRows();
    }

    /**
     * Repaint a single task row in place. If a filter is active the row may no
     * longer belong in the list, in which case it is removed.
     */
    function patchRow(task) {
        var item = list.querySelector('[data-task-id="' + task.id + '"]');

        if (!item) {
            return;
        }

        var active = filter ? filter.value : '';

        if (active && task.status !== active) {
            item.remove();

            if (!list.children.length) {
                render();
            }

            return;
        }

        var draggable = !active;
        var wasDragging = item.classList.contains('is-dragging');

        item.outerHTML = rowHtml(task, draggable);
        bindRows();

        if (wasDragging) {
            var fresh = list.querySelector('[data-task-id="' + task.id + '"]');

            if (fresh) {
                fresh.classList.add('is-dragging');
            }
        }
    }

    function bindRows() {
        Array.prototype.forEach.call(list.querySelectorAll('[data-task-toggle]'), function (box) {
            box.addEventListener('change', function () {
                toggle(box.dataset.taskToggle, box.checked);
            });
        });

        Array.prototype.forEach.call(list.querySelectorAll('[data-task-next]'), function (button) {
            button.addEventListener('click', function () {
                var task = byId(button.dataset.taskNext);

                if (task) {
                    setStatus(task.id, nextStatus(task.status));
                }
            });
        });

        Array.prototype.forEach.call(list.querySelectorAll('[data-task-delete]'), function (button) {
            button.addEventListener('click', function () {
                remove(button.dataset.taskDelete);
            });
        });

        if (!(filter && filter.value)) {
            Array.prototype.forEach.call(list.querySelectorAll('[data-task-id]'), function (item) {
                attachDrag(item, item.dataset.taskId);
            });
        }
    }

    /* ----------------------------------------------------------- mutations */

    async function load() {
        var result = await window.Omnitrack.request(API);

        if (!result.ok) {
            window.Omnitrack.notify('Gagal memuat task.', 'error');
            return;
        }

        cache = Array.isArray(result.body.tasks) ? result.body.tasks : [];
        render();
    }

    async function toggle(id, done) {
        var task = byId(id);

        if (!task) {
            return;
        }

        var previous = task.status;
        var wanted = done ? 'done' : 'pending';

        // Optimistic: update the row immediately, revert if the server refuses.
        applyStatus(task, wanted);
        patchRow(task);

        var result = await window.Omnitrack.request(API + '/' + encodeURIComponent(id), {
            method: 'POST',
            body: { status: wanted }
        });

        if (!result.ok) {
            applyStatus(task, previous);
            patchRow(task);

            window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal memperbarui task.'), 'error');
        }
    }

    async function setStatus(id, status) {
        var task = byId(id);

        if (!task) {
            return;
        }

        var previous = task.status;

        applyStatus(task, status);
        patchRow(task);

        var result = await window.Omnitrack.request(API + '/' + encodeURIComponent(id), {
            method: 'POST',
            body: { status: status }
        });

        if (!result.ok) {
            applyStatus(task, previous);
            patchRow(task);

            window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal memperbarui task.'), 'error');
        }
    }

    /** Keep the local label/class in step with a raw status value. */
    function applyStatus(task, status) {
        task.status = status;

        if (status === 'done') {
            task.status_label = 'Done';
            task.status_class = 'chip chip-done';
        } else if (status === 'in_progress') {
            task.status_label = 'In Progress';
            task.status_class = 'chip chip-progress';
        } else {
            task.status_label = 'Pending';
            task.status_class = 'chip chip-pending';
        }
    }

    async function remove(id) {
        var item = list.querySelector('[data-task-id="' + id + '"]');

        if (item) {
            item.classList.add('is-saving');
        }

        var result = await window.Omnitrack.request(API + '/' + encodeURIComponent(id), { method: 'DELETE' });

        if (!result.ok) {
            if (item) {
                item.classList.remove('is-saving');
            }

            window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal menghapus task.'), 'error');
            return;
        }

        cache = cache.filter(function (task) { return task.id !== id; });

        if (item) {
            item.remove();
        }

        if (!list.children.length) {
            render();
        }

        window.Omnitrack.notify('Task dihapus.', 'success');
    }

    /* --------------------------------------------------------- drag & drop */

    function attachDrag(element, id) {
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

            var dragging = list.querySelector('.is-dragging');

            clearDropTargets();

            if (!dragging || dragging === element) {
                return;
            }

            var rect = element.getBoundingClientRect();
            var after = event.clientY > rect.top + rect.height / 2;

            list.insertBefore(dragging, after ? element.nextSibling : element);

            persistOrder();
        });
    }

    function clearDropTargets() {
        Array.prototype.forEach.call(list.querySelectorAll('.is-drop-target'), function (node) {
            node.classList.remove('is-drop-target');
        });
    }

    async function persistOrder() {
        var ids = Array.prototype.map.call(
            list.querySelectorAll('[data-task-id]'),
            function (item) { return item.dataset.taskId; }
        );

        var result = await window.Omnitrack.request(API + '/reorder', {
            method: 'POST',
            body: { task_ids: ids }
        });

        if (!result.ok) {
            window.Omnitrack.notify('Gagal menyimpan urutan task.', 'error');
            return;
        }

        // Mirror the DOM order into the array so later patches stay consistent.
        cache.sort(function (a, b) {
            return ids.indexOf(a.id) - ids.indexOf(b.id);
        });
    }

    /* -------------------------------------------------------- create task */

    if (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            var titleInput = form.querySelector('[name="title"]');
            var title = titleInput.value.trim();

            if (!title) {
                return;
            }

            var notesInput = form.querySelector('[name="notes"]');
            var priorityInput = form.querySelector('[name="priority"]');
            var dueInput = form.querySelector('[name="due_date"]');
            var button = form.querySelector('button[type="submit"]');

            var priority = priorityInput ? priorityInput.value : 'medium';

            window.Omnitrack.setBusy(button, true, 'Menyimpan…');

            var result = await window.Omnitrack.request(API, {
                method: 'POST',
                body: {
                    title: title,
                    notes: notesInput && notesInput.value.trim() !== '' ? notesInput.value.trim() : null,
                    priority: priority,
                    due_date: dueInput && dueInput.value ? dueInput.value : null
                }
            });

            window.Omnitrack.setBusy(button, false);

            if (!result.ok) {
                window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Gagal menyimpan task.'), 'error');
                return;
            }

            var created = (result.body.task && result.body.task.id)
                ? result.body.task
                : (result.body.data && result.body.data.tasks && result.body.data.tasks[0]);

            var task = {
                id: created.id,
                title: created.title || title,
                notes: notesInput && notesInput.value.trim() !== '' ? notesInput.value.trim() : null,
                status: 'pending',
                status_label: 'Pending',
                status_class: 'chip chip-pending',
                priority: priority,
                priority_label: priority.replace(/^\w/, function (c) { return c.toUpperCase(); }),
                priority_class: priority === 'high' ? 'chip chip-urgent'
                    : (priority === 'low' ? 'chip chip-pending' : 'chip chip-progress'),
                due_date: dueInput && dueInput.value ? dueInput.value : null
            };

            // Append locally: no reload, so the form and scroll stay put.
            var active = filter ? filter.value : '';

            cache.push(task);

            if (!active || task.status === active) {
                var placeholder = list.querySelector('.empty-hint');

                if (placeholder) {
                    list.innerHTML = '';
                }

                list.insertAdjacentHTML('beforeend', rowHtml(task, !active));
                bindRows();
            }

            form.reset();
            titleInput.focus();

            window.Omnitrack.notify('Task ditambahkan.', 'success');
        });
    }

    if (filter) {
        filter.addEventListener('change', render);
    }

    document.addEventListener('omnitrack:refresh', load);

    load();
})();
