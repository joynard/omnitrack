@extends('layouts.app')

@section('title', 'Tasks')

@section('content')
    <div class="page-head">
        <div>
            <h1>Tasks</h1>
            <p class="meta">Checklist pribadi.</p>
        </div>
    </div>

    {{-- Painted by progress.js from /board/summary. --}}
    <div class="card progress-card" data-collapse="tasks-progress" data-collapse-default="closed">
        <div class="card-head">
            <h2>Penyelesaian</h2>
        </div>
        <div class="progress-panel" id="progress-panel" data-progress="tasks"></div>
    </div>

    <div class="card" data-collapse="tasks-add" data-collapse-default="closed">
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

    <div class="card" style="padding:0" data-collapse="tasks-list" data-collapse-default="open">
        <div class="card-head" style="padding:16px 16px 0;margin-bottom:10px">
            <h2>Daftar Task</h2>
            <div class="head-actions">
                <span class="card-note hide-mobile">Tarik baris untuk mengubah urutan.</span>
                {{-- Bare controls: a .field label would stack a second line into this
                     row and drop the controls below the heading's baseline. The
                     select names itself through its first option. --}}
                <input class="input input-sm" type="search" id="task-search"
                       aria-label="Cari task" placeholder="judul, status…" autocomplete="off">
                <select class="select select-sm" id="task-filter" aria-label="Filter status">
                    <option value="">Semua status</option>
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="done">Done</option>
                </select>
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
