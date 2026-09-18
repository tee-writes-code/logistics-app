<?php

use App\Http\Controllers\AgentAskController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Demo\DemoController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobPositionController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Ops\AssignmentController;
use App\Http\Controllers\Ops\JobBoardController;
use App\Http\Controllers\Ops\OpsJobController;
use App\Http\Controllers\Ops\RiderBoardController;
use App\Http\Controllers\Ops\SmsOutboxController;
use App\Http\Controllers\Ops\WorkbenchController;
use App\Http\Controllers\PickupSiteController;
use App\Http\Controllers\PodImageController;
use App\Http\Controllers\Recipient\RecipientActionController;
use App\Http\Controllers\Recipient\RecipientJobController;
use App\Http\Controllers\Rider\JobActionController;
use App\Http\Controllers\Rider\QueueController;
use App\Http\Controllers\SavedDropController;
use Illuminate\Support\Facades\Route;

/*
| Sanctum SPA (cookie) authentication for first-party clients. The SPA first
| calls GET /sanctum/csrf-cookie, then these endpoints.
*/
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user'])->name('auth.user');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // Customer: saved pickup sites.
    Route::get('/pickup-sites', [PickupSiteController::class, 'index'])->name('pickup-sites.index');
    Route::post('/pickup-sites', [PickupSiteController::class, 'store'])->name('pickup-sites.store');
    Route::put('/pickup-sites/{pickupSite}', [PickupSiteController::class, 'update'])->name('pickup-sites.update');
    Route::delete('/pickup-sites/{pickupSite}', [PickupSiteController::class, 'destroy'])->name('pickup-sites.destroy');

    // Customer: saved drop addresses.
    Route::get('/saved-drops', [SavedDropController::class, 'index'])->name('saved-drops.index');
    Route::post('/saved-drops', [SavedDropController::class, 'store'])->name('saved-drops.store');
    Route::put('/saved-drops/{savedDrop}', [SavedDropController::class, 'update'])->name('saved-drops.update');
    Route::delete('/saved-drops/{savedDrop}', [SavedDropController::class, 'destroy'])->name('saved-drops.destroy');

    // Customer: jobs (booking loop).
    Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::post('/jobs', [JobController::class, 'store'])->name('jobs.store');
    Route::get('/jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
    Route::patch('/jobs/{job}', [JobController::class, 'update'])->name('jobs.update');
    Route::post('/jobs/{job}/cancel', [JobController::class, 'cancel'])->name('jobs.cancel');

    // Rider: execution queue + guarded lifecycle actions. Every action calls
    // JobStatusService::transition() (the single status writer).
    Route::get('/rider/queue', [QueueController::class, 'index'])->name('rider.queue');
    Route::post('/jobs/{job}/start', [JobActionController::class, 'start'])->name('jobs.start');
    Route::post('/jobs/{job}/arrive-pickup', [JobActionController::class, 'arrivePickup'])->name('jobs.arrive-pickup');
    Route::post('/jobs/{job}/collect', [JobActionController::class, 'collect'])->name('jobs.collect');
    Route::post('/jobs/{job}/depart-drop', [JobActionController::class, 'departDrop'])->name('jobs.depart-drop');
    Route::post('/jobs/{job}/arrive-site', [JobActionController::class, 'arriveSite'])->name('jobs.arrive-site');
    Route::post('/jobs/{job}/deliver', [JobActionController::class, 'deliver'])->name('jobs.deliver');
    Route::post('/jobs/{job}/fail', [JobActionController::class, 'fail'])->name('jobs.fail');
    Route::post('/jobs/{job}/return-complete', [JobActionController::class, 'returnComplete'])->name('jobs.return-complete');

    // POD / return image (policy-guarded).
    Route::get('/jobs/{job}/pod-image', [PodImageController::class, 'show'])->name('jobs.pod-image');

    // In-app notifications (own inbox; ops sees the shared ops-role rows).
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Ops-only mock SMS outbox (recipient links the system "sent").
    Route::get('/ops/sms-outbox', [SmsOutboxController::class, 'index'])->name('ops.sms-outbox');

    // Ops supervisory console: jobs board, on-behalf CRUD, manual assignment,
    // queue reorder, rider board, and the agent workbench. Each action is gated
    // to ops via the `ops-console` gate (or an ops-only form request).
    Route::get('/ops/jobs', [JobBoardController::class, 'index'])->name('ops.jobs.index');
    Route::post('/ops/jobs', [OpsJobController::class, 'store'])->name('ops.jobs.store');
    Route::get('/ops/jobs/{job}', [JobBoardController::class, 'show'])->name('ops.jobs.show');
    Route::patch('/ops/jobs/{job}', [OpsJobController::class, 'update'])->name('ops.jobs.update');
    Route::post('/ops/jobs/{job}/cancel', [OpsJobController::class, 'cancel'])->name('ops.jobs.cancel');
    Route::post('/ops/jobs/{job}/assign', [AssignmentController::class, 'assign'])->name('ops.jobs.assign');
    Route::post('/ops/riders/{rider}/queue/reorder', [AssignmentController::class, 'reorderQueue'])->name('ops.riders.queue.reorder');
    Route::get('/ops/riders', [RiderBoardController::class, 'index'])->name('ops.riders.index');
    Route::get('/ops/workbench', [WorkbenchController::class, 'index'])->name('ops.workbench.index');
    Route::get('/ops/agent-logs', [WorkbenchController::class, 'logs'])->name('ops.agent-logs.index');

    // Agent asks: ops sees all pending; a customer sees only their own. Confirm /
    // reject route to the Exception agent (first write wins).
    Route::get('/agent-asks', [AgentAskController::class, 'indexPending'])->name('agent-asks.index');
    Route::post('/agent-asks/{ask}/confirm', [AgentAskController::class, 'confirm'])->name('agent-asks.confirm');
    Route::post('/agent-asks/{ask}/reject', [AgentAskController::class, 'reject'])->name('agent-asks.reject');

    // Demo operator controls (iter-5). The `demo` middleware restricts the whole
    // group to Ops users and to non-production. Each endpoint is thin: it sets the
    // clock override or calls an existing iter-4 agent hook.
    Route::middleware('demo')->prefix('demo')->name('demo.')->group(function (): void {
        Route::get('/clock', [DemoController::class, 'clock'])->name('clock.show');
        Route::post('/clock', [DemoController::class, 'setClock'])->name('clock.set');
        Route::delete('/clock', [DemoController::class, 'clearClock'])->name('clock.clear');
        Route::post('/jobs/{job}/delay', [DemoController::class, 'triggerDelay'])->name('jobs.delay');
        Route::post('/jobs/{job}/unsafe', [DemoController::class, 'triggerUnsafe'])->name('jobs.unsafe');
        Route::post('/jobs/{job}/force-miss-hours', [DemoController::class, 'forceMissHours'])->name('jobs.force-miss-hours');
        Route::get('/sms-outbox', [DemoController::class, 'smsOutbox'])->name('sms-outbox');
        Route::post('/reset', [DemoController::class, 'reset'])->name('reset');
    });
});

/*
| Recipient magic-link SPA access: unauthenticated and scoped entirely by the
| token in the URL. Every action re-validates the token and the job's status
| window; an invalid/expired/terminal token yields a read-only state, not a 500.
*/
Route::prefix('track/{token}')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/', [RecipientJobController::class, 'show'])->name('recipient.track.show');
    Route::post('/instructions', [RecipientActionController::class, 'instructions'])->name('recipient.track.instructions');
    Route::post('/hold', [RecipientActionController::class, 'hold'])->name('recipient.track.hold');
    Route::delete('/hold', [RecipientActionController::class, 'releaseHold'])->name('recipient.track.hold.release');
    Route::post('/receive-confirm', [RecipientActionController::class, 'receiveConfirm'])->name('recipient.track.receive-confirm');
    Route::post('/refuse', [RecipientActionController::class, 'refuse'])->name('recipient.track.refuse');
});

/*
| Mock live-map position. Not behind auth:sanctum so the recipient can poll it
| with their token; logged-in audiences resolve through the sanctum guard. The
| controller enforces the audience (owner/assigned rider/ops/recipient) and the
| status window (null outside picked_up..terminal).
*/
Route::get('/jobs/{job}/position', [JobPositionController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('jobs.position');

/*
| Recipient magic-link access: a signed URL scoped to a single job. The
| `signed` middleware rejects tampered or expired signatures with a 403.
*/
Route::get('/recipient/jobs/{job}', [MagicLinkController::class, 'show'])
    ->middleware('signed')
    ->name('recipient.jobs.show');
