<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipient;

use App\Http\Controllers\Controller;
use App\Services\MagicLinkService;
use App\Services\RecipientViewService;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated, token-scoped recipient view of a single job. The token is the
 * only credential: it resolves exactly one live job, and never a second one.
 *
 * An invalid, expired, revoked, or terminal token returns a read-only "expired"
 * payload with HTTP 200 — never a 500 — so the SPA can render a graceful state.
 */
class RecipientJobController extends Controller
{
    public function __construct(
        private readonly MagicLinkService $magicLinks,
        private readonly RecipientViewService $view,
    ) {}

    public function show(string $token): JsonResponse
    {
        $job = $this->magicLinks->resolveByToken($token);

        if ($job === null) {
            return response()->json(['data' => $this->view->expired()]);
        }

        return response()->json(['data' => $this->view->present($job, $token)]);
    }
}
