@extends('layouts.app')

@section('title', 'Tasks')

@section('content')
    <div class="page-head">
        <div>
            <h1>Tasks</h1>
            <p class="meta">Checklist pribadi, terpisah dari project.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Tambah Task</h2>
        </div>

        <form id="task-form" autocomplete="off">
            <div class="form-row">
                <label class="field" style="grid-column:span 2">
                    <span class="field-label">Judul *</span>
                    <input class="input" name="title" type="text" required maxlength="255">
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

            <label class="field">
                <span class="field-label">Catatan</span>
                <textarea class="textarea" name="notes" rows="2"></textarea>
            </label>

            <button type="submit" class="btn btn-primary">Simpan Task</button>
        </form>
    </div>

    <div class="card" style="padding:0">
        <div class="card-head" style="padding:16px 16px 0;margin-bottom:10px">
            <h2>Daftar Task</h2>
            <div class="head-actions">
                <span class="card-note">Tarik baris untuk mengubah urutan.</span>
                <label class="field" style="margin-bottom:0">
                    <span class="field-label">Filter status</span>
                    <select class="select select-sm" id="task-filter">
                        <option value="">Semua</option>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="done">Done</option>
                    </select>
                </label>
            </div>
        </div>

        <ul class="check-list" id="tasks-list" style="border-top:1px solid var(--line-light)">
            <li class="check-item"><div class="check-body"><div class="empty-hint">Memuat task…</div></div></li>
        </ul>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/board-tasks.js') }}"></script>
@endpush
