<?php

use App\Http\Controllers\Api\HarnessController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Harness API Routes
|--------------------------------------------------------------------------
| Token protected (Bearer <HARNESS_SECRET_TOKEN>) so DeepSeek Harness or any
| agent can drive the workspace autonomously. See App\Http\Middleware\
| VerifyHarnessToken. `?token=` also works for quick manual curl checks.
|
| This surface mirrors what the AI palette can do: full CRUD over categories,
| projects, modules and tasks, including explicit ordering.
*/

Route::prefix('harness')
    ->name('harness.')
    ->middleware('harness.token')
    ->group(function () {
        // Context injection for agents.
        Route::get('/context', [HarnessController::class, 'context'])->name('context');
        Route::get('/tools', [HarnessController::class, 'tools'])->name('tools');

        // Natural language: the same full-control agent the palette uses.
        Route::post('/agent', [HarnessController::class, 'agent'])->name('agent');

        // Categories.
        Route::get('/categories', [HarnessController::class, 'categories'])->name('categories');
        Route::post('/categories', [HarnessController::class, 'storeCategory'])->name('categories.store');
        Route::post('/categories/{category}', [HarnessController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [HarnessController::class, 'destroyCategory'])->name('categories.destroy');

        // Projects. Reorder is declared before the {project} routes so the
        // literal segment is never swallowed by the wildcard.
        Route::post('/projects/reorder', [HarnessController::class, 'reorderProjects'])->name('projects.reorder');
        Route::post('/projects', [HarnessController::class, 'upsertProject'])->name('projects.store');
        Route::post('/projects/{project}', [HarnessController::class, 'updateProject'])->name('projects.update');
        Route::delete('/projects/{project}', [HarnessController::class, 'destroyProject'])->name('projects.destroy');
        Route::post('/projects/{project}/modules', [HarnessController::class, 'storeModules'])->name('projects.modules');
        Route::post('/projects/{project}/modules/reorder', [HarnessController::class, 'reorderModules'])->name('projects.modules.reorder');

        // Modules.
        Route::post('/modules/{module}', [HarnessController::class, 'updateModule'])->name('modules.update');
        Route::delete('/modules/{module}', [HarnessController::class, 'destroyModule'])->name('modules.destroy');

        // Tasks.
        Route::post('/tasks/reorder', [HarnessController::class, 'reorderTasks'])->name('tasks.reorder');
        Route::post('/tasks', [HarnessController::class, 'tasks'])->name('tasks');
        Route::post('/tasks/{task}', [HarnessController::class, 'updateTask'])->name('tasks.update');
        Route::delete('/tasks/{task}', [HarnessController::class, 'destroyTask'])->name('tasks.destroy');

        // Google Sheets ingestion.
        Route::post('/sync', [HarnessController::class, 'sync'])->name('sync');
    });
