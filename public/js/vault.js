/* ==========================================================================
   Second Brain (Obsidian vault) browser.

   Read-only: every fetch here is a GET against /vault/*, and the note body is
   rendered into a <pre>, never into editable controls.
   ========================================================================== */

(function () {
    'use strict';

    var body = document.getElementById('vault-body');
    var stats = document.querySelectorAll('[data-vault-stat]');
    var searchInput = document.getElementById('vault-search');
    var folderSelect = document.getElementById('vault-folder');
    var pathNote = document.getElementById('vault-path');
    var countNote = document.getElementById('vault-count');
    var heading = document.getElementById('vault-heading');

    var overlay = document.getElementById('vault-overlay');
    var detailTitle = document.getElementById('vault-detail-title');
    var detailGrid = document.getElementById('vault-detail-grid');
    var detailTags = document.getElementById('vault-detail-tags');
    var detailBody = document.getElementById('vault-detail-body');
    var openLink = document.getElementById('vault-open');

    var vaultName = 'Second Brain';

    function stat(key, value) {
        Array.prototype.forEach.call(stats, function (node) {
            if (node.dataset.vaultStat === key) {
                node.textContent = value;
            }
        });
    }

    function chips(tags) {
        if (!tags || !tags.length) {
            return '<span class="muted">—</span>';
        }

        return tags.map(function (tag) {
            return '<span class="chip chip-plain">#' + window.Omnitrack.escapeHtml(tag) + '</span>';
        }).join(' ');
    }

    function renderFolders(folders) {
        var keep = folderSelect.value;

        folderSelect.innerHTML = '<option value="">Semua folder</option>' + folders.map(function (folder) {
            return '<option value="' + window.Omnitrack.escapeHtml(folder.name) + '">'
                + window.Omnitrack.escapeHtml(folder.name || '(root)') + ' (' + folder.count + ')</option>';
        }).join('');

        folderSelect.value = keep;
    }

    function renderNotes(notes) {
        if (!notes.length) {
            body.innerHTML = '<tr><td class="empty" colspan="4">Tidak ada catatan yang cocok.</td></tr>';
            return;
        }

        body.innerHTML = notes.map(function (note) {
            return '<tr class="vault-row" data-path="' + window.Omnitrack.escapeHtml(note.path) + '">'
                + '<td><span class="cell-title">' + window.Omnitrack.escapeHtml(note.title) + '</span>'
                + '<span class="cell-sub mono">' + window.Omnitrack.escapeHtml(note.path) + '</span></td>'
                + '<td>' + (note.folder
                    ? '<span class="category-tag">' + window.Omnitrack.escapeHtml(note.folder) + '</span>'
                    : '<span class="muted">—</span>') + '</td>'
                + '<td class="vault-tag-cell">' + chips(note.tags) + '</td>'
                + '<td class="meta">' + window.Omnitrack.escapeHtml(window.Omnitrack.formatDate(note.modified)) + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderHits(hits, query) {
        if (!hits.length) {
            body.innerHTML = '<tr><td class="empty" colspan="4">Tidak ada isi catatan yang memuat "'
                + window.Omnitrack.escapeHtml(query) + '".</td></tr>';
            return;
        }

        body.innerHTML = hits.map(function (note) {
            return '<tr class="vault-row" data-path="' + window.Omnitrack.escapeHtml(note.path) + '">'
                + '<td><span class="cell-title">' + window.Omnitrack.escapeHtml(note.title) + '</span>'
                + '<span class="vault-snippet">' + window.Omnitrack.escapeHtml(note.snippet) + '</span></td>'
                + '<td>' + (note.folder
                    ? '<span class="category-tag">' + window.Omnitrack.escapeHtml(note.folder) + '</span>'
                    : '<span class="muted">—</span>') + '</td>'
                + '<td class="vault-tag-cell">' + chips(note.tags) + '</td>'
                + '<td class="meta">' + note.matches + '×</td>'
                + '</tr>';
        }).join('');
    }

    function load() {
        var folder = folderSelect.value;
        var url = '/vault/notes?limit=300' + (folder ? '&folder=' + encodeURIComponent(folder) : '');

        return window.Omnitrack.request(url).then(function (result) {
            if (!result.ok) {
                body.innerHTML = window.Omnitrack.emptyRow(4, window.Omnitrack.errorMessage(result.body, 'Vault tidak bisa dibaca.'));
                return;
            }

            var data = result.body;
            vaultName = data.name || vaultName;

            stat('notes', data.stats.notes);
            stat('folders', data.stats.folders);
            stat('tags', data.stats.tags);
            stat('modified', data.stats.modified_at ? window.Omnitrack.formatDate(data.stats.modified_at) : '—');

            pathNote.textContent = data.available
                ? 'Vault: ' + data.path + ' · ' + data.name + ' (read-only)'
                : 'Vault belum ter-mount di ' + data.path + '. Jalankan ulang stack dev agar mount aktif.';

            renderFolders(data.folders);
            renderNotes(data.notes);
            heading.textContent = folder ? 'Catatan · ' + folder : 'Catatan';
            countNote.textContent = data.notes.length + ' ditampilkan dari ' + data.stats.notes;
        });
    }

    function search(query) {
        return window.Omnitrack.request('/vault/search?limit=100&q=' + encodeURIComponent(query)).then(function (result) {
            if (!result.ok) {
                body.innerHTML = window.Omnitrack.emptyRow(4, window.Omnitrack.errorMessage(result.body, 'Pencarian gagal.'));
                return;
            }

            renderHits(result.body.results, result.body.query);
            heading.textContent = 'Hasil pencarian';
            countNote.textContent = result.body.results.length + ' ditampilkan dari ' + result.body.matched + ' catatan cocok';
        });
    }

    function term(label, value) {
        return '<div class="detail-term">' + window.Omnitrack.escapeHtml(label) + '</div>'
            + '<div class="detail-value">' + (value || '<span class="muted">—</span>') + '</div>';
    }

    function openNote(path) {
        return window.Omnitrack.request('/vault/note?path=' + encodeURIComponent(path)).then(function (result) {
            if (!result.ok) {
                window.Omnitrack.notify(window.Omnitrack.errorMessage(result.body, 'Catatan tidak bisa dibuka.'), 'error');
                return;
            }

            var note = result.body.note;

            detailTitle.textContent = note.title;
            detailGrid.innerHTML = term('Path', '<span class="mono">' + window.Omnitrack.escapeHtml(note.path) + '</span>')
                + term('Folder', note.folder ? window.Omnitrack.escapeHtml(note.folder) : null)
                + term('Diubah', window.Omnitrack.escapeHtml(window.Omnitrack.formatDate(note.modified)))
                + term('Ukuran', note.words + ' kata · ' + note.size + ' byte')
                + term('Backlink', note.backlinks.length
                    ? note.backlinks.map(function (link) {
                        return '<span class="category-tag">' + window.Omnitrack.escapeHtml(link) + '</span>';
                    }).join(' ')
                    : null)
                + term('Wikilink', note.links.length
                    ? note.links.map(function (link) {
                        return '<span class="category-tag">' + window.Omnitrack.escapeHtml(link) + '</span>';
                    }).join(' ')
                    : null);

            detailTags.innerHTML = chips(note.tags);
            detailBody.textContent = note.content;

            // obsidian://open needs the vault NAME and the note path without its
            // .md extension; the vault name comes from the configured label.
            openLink.href = 'obsidian://open?vault=' + encodeURIComponent(vaultName)
                + '&file=' + encodeURIComponent(note.path.replace(/\.md$/i, ''));
            openLink.hidden = false;

            overlay.hidden = false;
            document.getElementById('vault-close').focus();
        });
    }

    function closeNote() {
        overlay.hidden = true;
    }

    body.addEventListener('click', function (event) {
        var row = event.target.closest('.vault-row');

        if (row) {
            openNote(row.dataset.path);
        }
    });

    folderSelect.addEventListener('change', function () {
        searchInput.value = '';
        load();
    });

    document.getElementById('vault-refresh').addEventListener('click', function () {
        searchInput.value = '';
        load();
    });

    searchInput.addEventListener('input', window.Omnitrack.debounce(function () {
        var query = searchInput.value.trim();

        // One character matches almost every note; the content scan is only
        // worth running from two.
        if (query.length < 2) {
            load();
            return;
        }

        search(query);
    }, 300));

    document.getElementById('vault-close').addEventListener('click', closeNote);

    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
            closeNote();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !overlay.hidden) {
            closeNote();
        }
    });

    load();
})();
