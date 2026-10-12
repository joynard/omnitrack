@extends('layouts.app')

@section('title', 'Second Brain')

@section('content')
    <div class="page-head">
        <div>
            <h1>Second Brain</h1>
            {{-- Filled by vault.js with the mounted path, or with the reason the
                 vault is unreachable. --}}
            <p class="meta" id="vault-path">Membaca vault Obsidian…</p>
        </div>
        <div class="head-actions">
            <button type="button" class="btn btn-secondary btn-sm" id="vault-refresh">Muat ulang</button>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-label">Catatan</div>
            <div class="stat-value" data-vault-stat="notes">—</div>
        </div>
        <div class="stat">
            <div class="stat-label">Folder</div>
            <div class="stat-value" data-vault-stat="folders">—</div>
        </div>
        <div class="stat">
            <div class="stat-label">Tag</div>
            <div class="stat-value" data-vault-stat="tags">—</div>
        </div>
        <div class="stat">
            <div class="stat-label">Terakhir diubah</div>
            <div class="stat-value" data-vault-stat="modified">—</div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Telusuri</h2>
            <span class="card-note">Baca saja — Omnitrack tidak pernah menulis ke vault.</span>
        </div>

        <div class="form-row">
            <label class="field" style="grid-column:span 2">
                <span class="field-label">Cari isi catatan</span>
                <input class="input" id="vault-search" type="search" autocomplete="off"
                       placeholder="mis. SPIL, refactor, jurnal…">
            </label>
            <label class="field">
                <span class="field-label">Folder</span>
                <select class="select" id="vault-folder">
                    <option value="">Semua folder</option>
                </select>
            </label>
        </div>
    </div>

    <div class="card" style="padding:0">
        <div class="card-head" style="padding:16px 16px 0;margin-bottom:10px">
            <h2 id="vault-heading">Catatan</h2>
            <span class="card-note" id="vault-count"></span>
        </div>

        <div class="table-wrap" style="border:none">
            <table class="data" id="vault-table">
                <thead>
                    <tr>
                        <th>Catatan</th>
                        <th>Folder</th>
                        <th>Tag</th>
                        <th>Diubah</th>
                    </tr>
                </thead>
                <tbody id="vault-body">
                    <tr><td class="empty" colspan="4">Memuat catatan…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="overlay overlay-dialog" id="vault-overlay" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="vault-detail-title">
            <div class="dialog-head">
                <h2 id="vault-detail-title">Catatan</h2>
                <div class="row-actions">
                    {{-- An obsidian:// URI, so the note opens in the real editor
                         rather than being edited here. --}}
                    <a href="#" id="vault-open" class="btn btn-secondary btn-sm" hidden>Buka di Obsidian</a>
                    <button type="button" class="btn btn-ghost btn-sm" id="vault-close">Tutup</button>
                </div>
            </div>

            <div class="dialog-body">
                <div class="detail-grid" id="vault-detail-grid"></div>
                <div class="vault-tags" id="vault-detail-tags"></div>
                <pre class="vault-body" id="vault-detail-body"></pre>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/vault.js') }}"></script>
@endpush
