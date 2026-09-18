<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * In-app notification inbox for authenticated users. Customer/rider inboxes are
 * scoped to their own rows; ops sees the shared ops-role rows (plus any addressed
 * to them). Mark-read is per-recipient. Mock SMS rows are never surfaced here.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = Notification::query()
            ->where('channel', NotificationChannel::InApp)
            ->where(fn (Builder $query) => $this->scopeToUser($query, $user))
            ->latest()
            ->limit(50)
            ->get();

        return NotificationResource::collection($notifications);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->belongsToUser($notification, $user)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => NotificationResource::make($notification)]);
    }

    /**
     * @param  Builder<Notification>  $query
     */
    private function scopeToUser(Builder $query, User $user): void
    {
        if ($user->isOps()) {
            $query->where('user_id', $user->id)->orWhere('role', UserRole::Ops);

            return;
        }

        $query->where('user_id', $user->id);
    }

    private function belongsToUser(Notification $notification, User $user): bool
    {
        if ($notification->channel !== NotificationChannel::InApp) {
            return false;
        }

        if ($notification->user_id === $user->id) {
            return true;
        }

        return $user->isOps() && $notification->role === UserRole::Ops;
    }
}
