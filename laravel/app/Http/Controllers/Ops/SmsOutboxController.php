<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Ops-visible mock SMS outbox: the recipient text messages the system "sent",
 * each carrying the magic link. There is no real SMS delivery — this panel is how
 * ops (and the demo) inspect what a recipient would receive.
 */
class SmsOutboxController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        if (! $request->user()->isOps()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $messages = Notification::query()
            ->where('channel', NotificationChannel::Sms)
            ->with('job:id,status')
            ->latest()
            ->limit(100)
            ->get();

        return NotificationResource::collection($messages);
    }
}
