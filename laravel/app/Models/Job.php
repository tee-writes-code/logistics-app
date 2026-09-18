<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\JobWindow;
use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'customer_id',
    'pickup_site_id',
    'drop_address',
    'drop_contact_name',
    'drop_contact_phone',
    'notes',
    'instructions',
    'window',
    'status',
    'assigned_rider_id',
    'queue_position',
    'is_active_for_rider',
    'eta_at',
    'booked_at',
    'assigned_at',
    'picked_up_at',
    'delivered_at',
    'cancelled_at',
])]
class Job extends Model
{
    /** @use HasFactory<JobFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window' => JobWindow::class,
            'status' => JobStatus::class,
            'is_active_for_rider' => 'boolean',
            'queue_position' => 'integer',
            'eta_at' => 'datetime',
            'booked_at' => 'datetime',
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Scope a job query to what the given user is allowed to see:
     * customer -> their own jobs; rider -> jobs assigned to them; ops -> all.
     *
     * Every job index/list endpoint MUST filter through Job::visibleTo($user)
     * (never Job::all()), because JobPolicy::viewAny() only gates access to the
     * list action, not the rows within it.
     *
     * @param  Builder<Job>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->isOps()) {
            return;
        }

        if ($user->isRider()) {
            $query->where('assigned_rider_id', $user->id);

            return;
        }

        if ($user->isCustomer()) {
            $query->where('customer_id', $user->id);

            return;
        }

        // Unknown role: expose nothing.
        $query->whereRaw('1 = 0');
    }

    /**
     * Drop, contact, and instruction fields are editable only before a rider is
     * assigned (i.e. while still `booked`).
     */
    public function isEditableDropStage(): bool
    {
        return $this->status === JobStatus::Booked;
    }

    /**
     * Notes stay editable through the run until the rider is on site.
     */
    public function isNotesEditable(): bool
    {
        return in_array($this->status, [
            JobStatus::Booked,
            JobStatus::Assigned,
            JobStatus::EnRoutePickup,
            JobStatus::AtPickup,
            JobStatus::PickedUp,
            JobStatus::EnRouteDrop,
        ], true);
    }

    /**
     * A job can be cancelled by the customer any time before pickup.
     */
    public function isCancelable(): bool
    {
        return in_array($this->status, [JobStatus::Booked, JobStatus::Assigned], true);
    }

    /**
     * Whether the job has reached a terminal status (delegates to the enum).
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether the job is the rider's single active job right now.
     */
    public function isActiveForRider(): bool
    {
        return $this->is_active_for_rider && ! $this->isTerminal();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedRider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_rider_id');
    }

    /**
     * @return BelongsTo<PickupSite, $this>
     */
    public function pickupSite(): BelongsTo
    {
        return $this->belongsTo(PickupSite::class);
    }

    /**
     * @return HasOne<PartLine, $this>
     */
    public function partLine(): HasOne
    {
        return $this->hasOne(PartLine::class);
    }

    /**
     * @return HasMany<JobTimelineEvent, $this>
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(JobTimelineEvent::class);
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasOne<Pod, $this>
     */
    public function pod(): HasOne
    {
        return $this->hasOne(Pod::class);
    }

    /**
     * @return HasMany<ReturnPhoto, $this>
     */
    public function returnPhotos(): HasMany
    {
        return $this->hasMany(ReturnPhoto::class);
    }

    /**
     * @return HasMany<FailRecord, $this>
     */
    public function failRecords(): HasMany
    {
        return $this->hasMany(FailRecord::class);
    }

    /**
     * @return HasOne<MagicLinkSession, $this>
     */
    public function magicLinkSession(): HasOne
    {
        return $this->hasOne(MagicLinkSession::class);
    }

    /**
     * @return HasMany<AgentActionLog, $this>
     */
    public function agentActionLogs(): HasMany
    {
        return $this->hasMany(AgentActionLog::class);
    }

    /**
     * @return HasMany<AgentAsk, $this>
     */
    public function agentAsks(): HasMany
    {
        return $this->hasMany(AgentAsk::class);
    }

    /**
     * @return HasMany<RecipientAction, $this>
     */
    public function recipientActions(): HasMany
    {
        return $this->hasMany(RecipientAction::class);
    }
}
