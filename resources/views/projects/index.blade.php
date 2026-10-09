@extends('layouts.app')

@section('title', 'Projects')

@section('content')
    <div class="page-head">
        <div>
            <h1>Projects</h1>
            <p class="meta hide-mobile">Tekan <span class="kbd-inline">Ctrl</span> + <span class="kbd-inline">P</span> untuk mengatur apa saja lewat AI.</p>
        </div>
        {{-- Where this button sits depends on the viewport:
             mobile  -> moved into the nav row, aligned with Projects / Tasks
             desktop -> stays here, in its original slot (see core.js) --}}
        <div class="head-actions" id="page-actions"></div>
        <div class="page-sync-slot">
            <button type="button" class="btn btn-primary btn-sm" id="sync-button"
                    data-mobile-to="topbar-page-actions" data-desktop-home="page-actions">
                <span class="label-wide">Sync Google Sheets</span>
                <span class="label-short">Sync Sheet</span>
            </button>
        </div>
    </div>

    <div class="stat-grid" id="stat-grid">
        <div class="stat">
            <div class="stat-label">Total Project</div>
            <div class="stat-value" data-stat="projects_total">—</div>
        </div>
        <div class="stat">
            <div class="stat-label">Selesai</div>
            <div class="stat-value" data-stat="projects_done">—</div>
        </div>
        <div class="stat">
            <div class="stat-label">Modul Pending</div>
            <div class="stat-value" data-stat="modules_pending">—</div>
        </div>
        <div class="stat">
            <div class="stat-label">Task Terbuka</div>
            <div class="stat-value" data-stat="tasks_open">—</div>
        </div>
    </div>

    <div class="category-bar" id="category-bar">
        <button type="button" class="category-chip is-active" data-category-filter="">Semua</button>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Tambah Project</h2>
            <button type="button" class="btn btn-secondary btn-sm" id="category-manage-toggle">Kelola Kategori</button>
        </div>

        <form id="project-form" autocomplete="off">
            <div class="form-row">
                <label class="field">
                    <span class="field-label">Nama project *</span>
                    <input class="input" name="name" type="text" required maxlength="255">
                </label>
                <label class="field">
                    <span class="field-label">Kategori</span>
                    <select class="select" name="category_id" id="project-category">
                        <option value="">Tanpa kategori</option>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">My role</span>
                    <input class="input" name="my_role" type="text" maxlength="255">
                </label>
                <label class="field">
                    <span class="field-label">Department</span>
                    <input class="input" name="department" type="text" maxlength="255">
                </label>
                <label class="field">
                    <span class="field-label">Project owner</span>
                    <input class="input" name="project_owner" type="text" maxlength="255">
                </label>
                <label class="field">
                    <span class="field-label">Status</span>
                    <select class="select" name="status">
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="done">Done</option>
                    </select>
                </label>
            </div>

            <label class="field">
                <span class="field-label">Deskripsi</span>
                <textarea class="textarea" name="description" rows="2"></textarea>
            </label>

            <button type="submit" class="btn btn-primary">Simpan Project</button>
        </form>

        <div id="category-manager" hidden style="margin-top:14px;border-top:1px solid var(--line-light);padding-top:14px">
            <form id="category-form" autocomplete="off" class="form-row" style="align-items:end">
                <label class="field" style="margin-bottom:0">
                    <span class="field-label">Kategori baru</span>
                    <input class="input" name="name" type="text" maxlength="255" placeholder="mis. Kuliah, Kantor, Pribadi">
                </label>
                <div class="field" style="margin-bottom:0">
                    <button type="submit" class="btn btn-secondary">Tambah</button>
                </div>
            </form>

            <ul class="check-list" id="category-list"
                style="margin-top:12px;border:1px solid var(--line-light);border-radius:4px;overflow:hidden"></ul>
        </div>
    </div>

    <div class="card" style="padding:0">
        <div class="card-head" style="padding:16px 16px 0;margin-bottom:10px">
            <h2 id="projects-heading">Semua Project</h2>
            <span class="card-note">Tarik baris untuk mengubah urutan.</span>
        </div>

        <div class="table-wrap" style="border:none">
            <table class="data" id="projects-table">
                <thead>
                    <tr>
                        <th style="width:28px"></th>
                        <th>Project</th>
                        <th>My Role</th>
                        <th>Department</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Modul</th>
                        <th style="width:64px"></th>
                    </tr>
                </thead>
                <tbody id="projects-body">
                    <tr><td class="empty" colspan="9">Memuat project…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="overlay overlay-dialog" id="detail-overlay" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="detail-title">
            <div class="dialog-head">
                <h2 id="detail-title">Detail Project</h2>
                <div class="row-actions">
                    <a href="#" id="detail-link" class="btn btn-secondary btn-sm" target="_blank" rel="noopener" hidden>Docs</a>
                    <button type="button" class="btn btn-ghost btn-sm" id="detail-close">Tutup</button>
                </div>
            </div>

            <div class="dialog-body">
                <div class="detail-grid" id="detail-grid"></div>

                <h2 style="margin:6px 0 8px">Sub-modul / Todo</h2>
                <ul class="check-list module-list" id="detail-modules"></ul>

                <form id="module-form" autocomplete="off" style="margin-top:12px">
                    <div class="form-row">
                        <label class="field" style="grid-column:span 2">
                            <span class="field-label">Modul baru</span>
                            <input class="input" name="title" type="text" required maxlength="255"
                                   placeholder="mis. Susun draft requirement">
                        </label>
                        <label class="field">
                            <span class="field-label">Prioritas</span>
                            <select class="select" name="priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </label>
                        <label class="field">
                            <span class="field-label">Due date</span>
                            <input class="input" name="due_date" type="date">
                        </label>
                    </div>
                    <button type="submit" class="btn btn-secondary">Tambah Modul</button>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/board-projects.js') }}"></script>
    <script>
        (function () {
            var stats = document.querySelectorAll('[data-stat]');

            function refresh() {
                window.Omnitrack.request('/board/summary').then(function (result) {
                    var data = (result.body && result.body.stats) || {};

                    Array.prototype.forEach.call(stats, function (node) {
                        var key = node.dataset.stat;

                        if (Object.prototype.hasOwnProperty.call(data, key)) {
                            node.textContent = data[key];
                        }
                    });
                });
            }

            refresh();
            document.addEventListener('omnitrack:refresh', refresh);
            document.getElementById('sync-button').addEventListener('click', function () {
                setTimeout(refresh, 2000);
            });
        })();
    </script>
@endpush
