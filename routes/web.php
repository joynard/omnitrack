<?php

use App\Http\Controllers\AiCommandController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BoardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication (public)
|--------------------------------------------------------------------------
| Single-user login. The account itself is created by the seeder from
| OMNITRACK_USER_EMAIL / OMNITRACK_USER_PASSWORD, so there is no registration.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Application (requires login)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/projects')->name('home');

    Route::view('/projects', 'projects.index')->name('projects.index');
    Route::view('/tasks', 'tasks.index')->name('tasks.index');

    // AI command palette.
    Route::post('/ai/command', [AiCommandController::class, 'handle'])->name('ai.command');
    Route::get('/ai/templates', [AiCommandController::class, 'templates'])->name('ai.templates');

    // JSON endpoints backing the Blade boards.
    Route::prefix('board')->name('board.')->group(function () {
        Route::get('/search', [BoardController::class, 'search'])->name('search');

        Route::get('/categories', [BoardController::class, 'categories'])->name('categories');
        Route::post('/categories', [BoardController::class, 'storeCategory'])->name('categories.store');
        Route::post('/categories/{category}', [BoardController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [BoardController::class, 'destroyCategory'])->name('categories.destroy');

        Route::get('/projects', [BoardController::class, 'projects'])->name('projects');
        Route::post('/projects', [BoardController::class, 'storeProject'])->name('projects.store');
        Route::post('/projects/reorder', [BoardController::class, 'reorderProjects'])->name('projects.reorder');
        Route::get('/projects/{project}', [BoardController::class, 'project'])->name('project');
        Route::post('/projects/{project}', [BoardController::class, 'updateProject'])->name('projects.update');
        Route::post('/projects/{project}/modules', [BoardController::class, 'storeModule'])->name('projects.modules');
        Route::post('/projects/{project}/modules/reorder', [BoardController::class, 'reorderModules'])->name('projects.modules.reorder');

        Route::post('/modules/{module}', [BoardController::class, 'updateModule'])->name('modules.update');
        Route::delete('/modules/{module}', [BoardController::class, 'destroyModule'])->name('modules.destroy');

        Route::get('/tasks', [BoardController::class, 'tasks'])->name('tasks');
        Route::post('/tasks', [BoardController::class, 'storeTask'])->name('tasks.store');
        Route::post('/tasks/reorder', [BoardController::class, 'reorderTasks'])->name('tasks.reorder');
        Route::post('/tasks/{task}', [BoardController::class, 'updateTask'])->name('tasks.update');
        Route::delete('/tasks/{task}', [BoardController::class, 'destroyTask'])->name('tasks.destroy');

        Route::get('/summary', [BoardController::class, 'summary'])->name('summary');
    });

    Route::post('/sync/google-sheet', [BoardController::class, 'sync'])->name('sync.google-sheet');
});
