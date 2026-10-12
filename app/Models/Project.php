<?php

namespace App\Models;

use App\Enums\ItemStatus;
use App\Models\Concerns\ReordersByIds;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUlids, ReordersByIds;

    protected $fillable = [
        'category_id',
        'sheet_source_id',
        'sheet_row_index',
        'name',
        'my_role',
        'department',
        'target_user',
        'project_owner',
        'description',
        'status',
        'doc_link',
        'row_hash',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'sheet_row_index' => 'integer',
            'order_index' => 'integer',
            'status' => ItemStatus::class,
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<SheetSource, $this> */
    public function sheetSource(): BelongsTo
    {
        return $this->belongsTo(SheetSource::class);
    }

    /** @return HasMany<ProjectModule, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(ProjectModule::class)->orderBy('order_index');
    }

    /** Completion ratio of child modules, used by the dashboard summary. */
    public function progress(): int
    {
        $total = $this->modules->count();

        if ($total === 0) {
            return $this->status === ItemStatus::DONE ? 100 : 0;
        }

        $done = $this->modules->where('status', ItemStatus::DONE)->count();

        return (int) round(($done / $total) * 100);
    }

    /**
     * The status a set of module statuses implies, or null when there are none.
     *
     * All-done wins; a single open module means the set is unfinished; an untouched
     * checklist is pending. Which of those three outcomes is actually written back
     * is decided by syncStatusFromModules() below -- this method only derives.
     *
     * Null (no modules) means "no opinion": an empty project keeps whatever the
     * user or the spreadsheet put there.
     *
     * @param  iterable<ItemStatus|string>  $statuses
     */
    public static function rollupStatusFromModules(iterable $statuses): ?ItemStatus
    {
        $values = [];

        foreach ($statuses as $status) {
            $values[] = $status instanceof ItemStatus ? $status->value : (string) $status;
        }

        if ($values === []) {
            return null;
        }

        $done = count(array_filter($values, fn (string $value) => $value === ItemStatus::DONE->value));

        if ($done === count($values)) {
            return ItemStatus::DONE;
        }

        foreach ($values as $value) {
            if ($value === ItemStatus::DONE->value || $value === ItemStatus::IN_PROGRESS->value) {
                return ItemStatus::IN_PROGRESS;
            }
        }

        return ItemStatus::PENDING;
    }

    /**
     * Apply the rollup to this project.
     *
     * Which transitions happen on their own, and why the third one does not:
     *
     *   all modules done          -> done, always. This is the point of the
     *                               feature: the checklist is what gets ticked,
     *                               so ticking the last box finishes the project.
     *   something is still open   -> a project that claims to be done may not have
     *                               open modules, so it reopens as in_progress.
     *   everything still pending  -> NOT applied. Whether a project with an
     *                               untouched checklist is "pending" or already
     *                               "in progress" is the user's own call, and the
     *                               rollup must not overwrite that choice.
     *
     * @return bool True when the status actually changed.
     */
    public function syncStatusFromModules(): bool
    {
        // A fresh query rather than $this->modules: the caller is usually a module
        // event handler, where the loaded relation still holds the pre-save row.
        $next = static::rollupStatusFromModules($this->modules()->pluck('status')->all());

        if ($next === null) {
            return false;
        }

        $target = match (true) {
            $next === ItemStatus::DONE => ItemStatus::DONE,
            $this->status === ItemStatus::DONE => ItemStatus::IN_PROGRESS,
            default => $this->status,
        };

        if ($this->status === $target) {
            return false;
        }

        $this->status = $target;
        $this->save();

        return true;
    }

    /**
     * Roll several projects up at once.
     *
     * WHY this exists next to the model events: a mass `update()` or `delete()`
     * through the query builder fires NO model events, so every bulk path has to
     * say which projects it touched. Forgetting this is silent -- the checklist
     * moves and the project status does not.
     *
     * @param  iterable<string>  $projectIds
     */
    public static function syncStatusesFor(iterable $projectIds): void
    {
        $ids = array_values(array_unique(array_filter((array) $projectIds)));

        if ($ids === []) {
            return;
        }

        foreach (static::query()->whereIn('id', $ids)->get() as $project) {
            $project->syncStatusFromModules();
        }
    }
}
