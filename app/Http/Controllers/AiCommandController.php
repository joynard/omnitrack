<?php

namespace App\Http\Controllers;

use App\Enums\AiActionType;
use App\Services\AiOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the Ctrl + P command palette modal.
 */
class AiCommandController extends Controller
{
    public function __construct(private readonly AiOrchestrator $orchestrator) {}

    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['nullable', 'string', 'max:8000'],
            'action_type' => ['required', 'string', 'in:'.implode(',', array_column(AiActionType::cases(), 'value'))],
            'project_id' => ['nullable', 'string', 'exists:projects,id'],
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $action = AiActionType::from($validated['action_type']);
        $prompt = trim((string) ($validated['prompt'] ?? ''));

        // Every action except a pure sync needs some instruction text.
        if ($prompt === '' && $action !== AiActionType::TRIGGER_SYNC) {
            return response()->json([
                'status' => 'error',
                'message' => 'Prompt tidak boleh kosong untuk aksi ini.',
            ], 422);
        }

        $result = $this->orchestrator->run($action, $prompt, $validated);

        return response()->json($result, $result['status'] === 'error' ? 422 : 200);
    }

    public function templates(): JsonResponse
    {
        return response()->json([
            'templates' => $this->orchestrator->templates()->map(fn ($template) => [
                'id' => $template->id,
                'title' => $template->title,
                'prompt_payload' => $template->prompt_payload,
                'action_type' => $template->action_type->value,
                'action_label' => $template->action_type->label(),
            ])->all(),
        ]);
    }
}
