<?php

namespace App\Support;

/**
 * Validation rules for the vault endpoints, declared once.
 *
 * WHY: the browser controller (`/vault/*`) and the agent mirror
 * (`/api/harness/vault*`) are two surfaces over the same service, and their rule
 * blocks were byte-identical copies. A limit raised on one and forgotten on the
 * other is silent: the same call behaves differently depending on who asks.
 *
 * Only rules live here. What each surface DOES with the validated input still
 * differs on purpose (the browser adds stats to the note list, the agent adds
 * `available` and the vault path).
 */
final class VaultQuery
{
    /**
     * `GET .../vault/notes`: filters for the note list.
     *
     * @return array<string, list<string>>
     */
    public static function listRules(): array
    {
        return [
            'folder' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * `GET .../vault/search`: full-text search. `min:2` is deliberate; a
     * one-character query reads every note file to return almost everything.
     *
     * @return array<string, list<string>>
     */
    public static function searchRules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * `GET .../vault/note`: one note by vault-relative path.
     *
     * @return array<string, list<string>>
     */
    public static function noteRules(): array
    {
        return [
            'path' => ['required', 'string', 'max:2048'],
        ];
    }
}
