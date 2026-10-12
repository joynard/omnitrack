<?php

namespace App\Http\Controllers;

use App\Services\ObsidianVaultService;
use App\Support\VaultQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only browser for the Obsidian Second Brain vault.
 *
 * Split from BoardController because the vault is not workspace state: nothing
 * here touches the database, and no route on this controller mutates anything.
 * Agents get the same data through the harness mirror in Api\HarnessController.
 */
class VaultController extends Controller
{
    public function __construct(private readonly ObsidianVaultService $vault) {}

    public function index(): View
    {
        return view('vault.index');
    }

    /**
     * GET /vault/notes
     * Index (stats, folders, tags) plus the note list for the current filter.
     */
    public function notes(Request $request): JsonResponse
    {
        $validated = $request->validate(VaultQuery::listRules());

        return response()->json([
            ...$this->vault->index(),
            'notes' => $this->vault->notes(
                $validated['folder'] ?? null,
                $validated['q'] ?? null,
                (int) ($validated['limit'] ?? 200),
            ),
        ]);
    }

    /**
     * GET /vault/search
     * Full-text search. Separate from notes() because a content hit and a title
     * filter are different questions, and only one of them costs a full read.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(VaultQuery::searchRules());

        return response()->json($this->vault->search($validated['q'], (int) ($validated['limit'] ?? 50)));
    }

    /**
     * GET /vault/note?path=Folder/Note.md
     */
    public function note(Request $request): JsonResponse
    {
        $validated = $request->validate(VaultQuery::noteRules());

        $note = $this->vault->note($validated['path']);

        if ($note === null) {
            // Covers "no such note" and "path escapes the vault" with one
            // answer on purpose: telling a caller which of the two it was leaks
            // the host filesystem layout.
            return response()->json([
                'status' => 'error',
                'message' => 'Catatan tidak ditemukan di vault.',
            ], 404);
        }

        return response()->json(['status' => 'success', 'note' => $note]);
    }
}
