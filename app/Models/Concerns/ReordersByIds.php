<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Shared explicit-ordering helper for every model that carries `order_index`.
 *
 * WHY a trait rather than three copies: they had to be fixed for the same
 * reason at the same time, and one copy drifting is exactly how the board, the
 * palette and the harness API end up disagreeing about ordering.
 */
trait ReordersByIds
{
    /**
     * Apply a complete id => position map in one statement.
     *
     * WHY NOT `upsert()`: upsert proposes a full INSERT tuple, so every NOT NULL
     * column the row does not mention -- projects.name, tasks.title,
     * project_modules.project_id -- must still be supplied. SQLite rejects the
     * proposal outright with "NOT NULL constraint failed", which is why every
     * reorder test failed before this. A CASE expression updates only rows that
     * already exist and never proposes an insert, so it is correct on SQLite and
     * PostgreSQL alike, and it is still a single statement.
     *
     * Ids that are not in the table simply do not match the WHERE clause, which
     * preserves the previous "skip unknown ids" behaviour.
     *
     * @param  array<string, int>  $positions
     */
    public static function reorderByIds(array $positions): void
    {
        if ($positions === []) {
            return;
        }

        $cases = [];
        $positionBindings = [];
        $ids = [];

        foreach ($positions as $id => $position) {
            // The CAST is not cosmetic. PostgreSQL has to resolve a CASE result
            // type before it can use the assignment target as context, so an
            // uncast parameter is inferred as text and the whole statement fails
            // with "column order_index is of type integer but expression is of
            // type text". `cast(? as integer)` is portable to SQLite too, which
            // is why it is not written as the PostgreSQL-only `?::integer`.
            $cases[] = 'when ? then cast(? as integer)';
            $positionBindings[] = (string) $id;
            $positionBindings[] = (int) $position;
            $ids[] = (string) $id;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $sql = 'update '.(new static)->getTable()
            .' set order_index = case id '.implode(' ', $cases).' end, updated_at = ?'
            .' where id in ('.$placeholders.')';

        DB::update($sql, array_merge($positionBindings, [now(), ...$ids]));
    }
}
