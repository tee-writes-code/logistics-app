<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Saved pickup sites owned by this customer.
     *
     * @return HasMany<PickupSite, $this>
     */
    public function pickupSites(): HasMany
    {
        return $this->hasMany(PickupSite::class);
    }

    /**
     * Saved drop addresses owned by this customer.
     *
     * @return HasMany<SavedDrop, $this>
     */
    public function savedDrops(): HasMany
    {
        return $this->hasMany(SavedDrop::class);
    }

    /**
     * Jobs this user booked as a customer.
     *
     * @return HasMany<Job, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'customer_id');
    }

    /**
     * Jobs currently assigned to this user as a rider.
     *
     * @return HasMany<Job, $this>
     */
    public function assignedJobs(): HasMany
    {
        return $this->hasMany(Job::class, 'assigned_rider_id');
    }

    public function isOps(): bool
    {
        return $this->role === UserRole::Ops;
    }

    public function isRider(): bool
    {
        return $this->role === UserRole::Rider;
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Customer;
    }

    /**
     * Scope to rider accounts.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function riders(Builder $query): void
    {
        $query->where('role', UserRole::Rider);
    }

    /**
     * Scope to customer accounts.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function customers(Builder $query): void
    {
        $query->where('role', UserRole::Customer);
    }

    /**
     * Scope to ops accounts.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function ops(Builder $query): void
    {
        $query->where('role', UserRole::Ops);
    }
}
