<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agents\ExceptionAgent;
use App\Enums\AgentAskStatus;
use App\Http\Requests\ConfirmAgentAskRequest;
use App\Models\AgentAsk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AgentAsk confirm/reject flow. Ops sees and resolves every pending ask; a
 * customer sees and resolves only asks on their own jobs. Resolution routes to
 * the Exception agent, which re-checks feasibility and wins on first write.
 */
class AgentAskController extends Controller
{
    public function __construct(private readonly ExceptionAgent $exception) {}

    public function indexPending(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AgentAsk::class);

        $user = $request->user();

        $asks = AgentAsk::query()
            ->where('status', AgentAskStatus::Pending)
            ->when(
                ! $user->isOps(),
                fn ($query) => $query->whereHas('job', fn ($q) => $q->where('customer_id', $user->id)),
            )
            ->with(['job.partLine', 'job.pickupSite'])
            ->latest('id')
            ->get();

        return response()->json(['data' => $asks->map(fn (AgentAsk $ask): array => $this->present($ask))->all()]);
    }

    public function confirm(ConfirmAgentAskRequest $request, AgentAsk $ask): JsonResponse
    {
        $this->authorize('resolve', $ask);

        $resolved = $this->exception->confirm($ask, $request->user(), $request->validated('decision'));

        return response()->json(['data' => $this->present($resolved->fresh(['job']))]);
    }

    public function reject(Request $request, AgentAsk $ask): JsonResponse
    {
        $this->authorize('resolve', $ask);

        $resolved = $this->exception->reject($ask, $request->user());

        return response()->json(['data' => $this->present($resolved->fresh(['job']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AgentAsk $ask): array
    {
        return [
            'id' => $ask->id,
            'agent' => $ask->agent->value,
            'type' => $ask->type->value,
            'status' => $ask->status->value,
            'payload' => $ask->payload,
            'job_id' => $ask->job_id,
            'job' => $ask->job === null ? null : [
                'id' => $ask->job->id,
                'status' => $ask->job->status->value,
                'drop_address' => $ask->job->drop_address,
                'customer_id' => $ask->job->customer_id,
            ],
            'resolved_by' => $ask->resolved_by,
            'resolved_at' => $ask->resolved_at?->toIso8601String(),
            'created_at' => $ask->created_at?->toIso8601String(),
        ];
    }
}
