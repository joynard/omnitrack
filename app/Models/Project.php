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
     * True when every module in the set is done, false when any is not.
     *
     * WHY a boolean and not the three-way status it used to derive: the caller
     * only ever asks whether the checklist is finished. "Some pending" and "some
     * in progress" are handled identically (the project may not claim to be done),
     * so deriving a PENDING value here was a distinction nothing read -- the
     * branch was unreachable in effect.
     *
     * The no-module case is false, never true: an empty set is "no opinion", and
     * the caller must not read it as "all done".
     *
     * @param  iterable<ItemStatus|string>  $statuses
     */
    public static function allModulesDone(iterable $statuses): bool
    {
        $seen = 0;

        foreach ($statuses as $status) {
            $seen++;
            $value = $status instanceof ItemStatus ? $status->value : (string) $status;

            if ($value !== ItemStatus::DONE->value) {
                return false;
            }
        }

        return $seen > 0;
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
        $statuses = $this->modules()->pluck('status')->all();

        // No modules means no opinion: the status stays whatever it was set to.
        if ($statuses === []) {
            return false;
        }

        $target = static::allModulesDone($statuses)
            ? ItemStatus::DONE
            : ($this->status === ItemStatus::DONE ? ItemStatus::IN_PROGRESS : $this->status);

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
