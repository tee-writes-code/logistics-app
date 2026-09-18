<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Enums\AgentAskStatus;
use App\Enums\AgentType;
use App\Http\Controllers\Controller;
use App\Models\AgentActionLog;
use App\Models\AgentAsk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Agent workbench feed: one pane per agent (goal, last action, pending ask,
 * recent job links) plus the raw action log. Ops-only, read-only.
 */
class WorkbenchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('ops-console');

        $panes = collect(AgentType::cases())->map(function (AgentType $agent): array {
            /** @var AgentActionLog|null $latest */
            $latest = AgentActionLog::query()
                ->where('agent', $agent)
                ->latest('id')
                ->first();

            $recent = AgentActionLog::query()
                ->where('agent', $agent)
                ->whereNotNull('job_id')
                ->latest('id')
                ->limit(8)
                ->get(['id', 'job_id', 'last_action', 'created_at']);

            $pendingAsks = AgentAsk::query()
                ->where('agent', $agent)
                ->where('status', AgentAskStatus::Pending)
                ->count();

            return [
                'agent' => $agent->value,
                'goal' => $latest?->goal,
                'last_action' => $latest?->last_action,
                'pending_ask' => $latest?->pending_ask,
                'pending_ask_count' => $pendingAsks,
                'updated_at' => $latest?->created_at?->toIso8601String(),
                'job_links' => $recent->map(fn (AgentActionLog $log): array => [
                    'log_id' => $log->id,
                    'job_id' => $log->job_id,
                    'last_action' => $log->last_action,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])->all(),
            ];
        })->all();

        return response()->json(['data' => $panes]);
    }

    public function logs(Request $request): JsonResponse
    {
        $this->authorize('ops-console');

        $logs = AgentActionLog::query()
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AgentActionLog $log): array => [
                'id' => $log->id,
                'job_id' => $log->job_id,
                'agent' => $log->agent->value,
                'goal' => $log->goal,
                'last_action' => $log->last_action,
                'pending_ask' => $log->pending_ask,
                'meta' => $log->meta,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all();

        return response()->json(['data' => $logs]);
    }
}
