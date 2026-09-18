<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DemoSetting;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * The single source of "now" for the whole app, plus the business-hours and
 * cutoff reads that scheduling depends on. Every time-based decision keys off
 * this service, so the persisted demo-clock override (iter-5) is layered on top
 * of now() in exactly one place and flows through every downstream check.
 */
class ClockService
{
    /**
     * The demo_settings key that holds the clock override.
     */
    public const OVERRIDE_KEY = 'clock_override';

    /**
     * Demo clock presets (config-relative instants). "before_cutoff" leaves a
     * full workday before the same-day cutoff; "after_cutoff" is past the cutoff
     * so nothing can finish same-day.
     */
    public const PRESET_BEFORE_CUTOFF = 'before_cutoff';

    public const PRESET_AFTER_CUTOFF = 'after_cutoff';

    /**
     * Per-instance memo of the override row's value, read once per resolve.
     * `false` means "not yet loaded"; `null` means "loaded, no override set".
     *
     * @var array<string, mixed>|null|false
     */
    private array|null|false $override = false;

    /**
     * The current instant in the configured demo timezone.
     *
     * When a demo override is set, real time still ticks: now() is real time
     * plus the stored offset (an advancing offset, not a frozen instant), so the
     * live map animates and ETAs count down during a demo. With no override the
     * override read is inert and this returns real (or test) time unchanged, so
     * every iter-1..4 test keeps working against the empty demo_settings table.
     */
    public function now(): CarbonImmutable
    {
        $real = CarbonImmutable::now($this->timezone());
        $override = $this->loadOverride();

        if ($override === null) {
            return $real;
        }

        return $real->addSeconds((int) ($override['offset_seconds'] ?? 0));
    }

    /**
     * Set an advancing clock override that makes now() equal $target at the
     * moment it is set, then keep ticking from there. Stores the offset (in
     * seconds) between $target and real time so later reads stay live.
     */
    public function setOverride(CarbonImmutable $target, ?string $preset = null): void
    {
        $real = CarbonImmutable::now($this->timezone());
        $offset = $target->getTimestamp() - $real->getTimestamp();

        DemoSetting::query()->updateOrCreate(
            ['key' => self::OVERRIDE_KEY],
            ['value' => [
                'offset_seconds' => $offset,
                'preset' => $preset,
                'target' => $target->toIso8601String(),
                'set_at' => $real->toIso8601String(),
            ]],
        );

        $this->override = false;
    }

    /**
     * Remove any clock override; now() returns to real time.
     */
    public function clearOverride(): void
    {
        DemoSetting::query()->where('key', self::OVERRIDE_KEY)->delete();

        $this->override = false;
    }

    /**
     * The current override descriptor (offset, preset, target, set_at), or null
     * when the clock runs at real time. Powers the demo panel banner.
     *
     * @return array<string, mixed>|null
     */
    public function override(): ?array
    {
        return $this->loadOverride();
    }

    /**
     * Whether a demo clock override is currently in effect.
     */
    public function hasOverride(): bool
    {
        return $this->loadOverride() !== null;
    }

    /**
     * Resolve a preset to a concrete instant, relative to the configured hours
     * and cutoff for the demo city. Computed from real time (ignoring any current
     * override) so setting a preset replaces the offset deterministically. The
     * day is the next open business day, so the presets behave the same whatever
     * real day the demo runs.
     */
    public function presetInstant(string $preset): CarbonImmutable
    {
        $day = $this->nextOpenDay(CarbonImmutable::now($this->timezone()));

        $time = match ($preset) {
            self::PRESET_BEFORE_CUTOFF => '09:00',
            self::PRESET_AFTER_CUTOFF => '16:00',
            default => throw new RuntimeException("Unknown clock preset [{$preset}]."),
        };

        return $this->at($day, $time);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadOverride(): ?array
    {
        if ($this->override === false) {
            $this->override = $this->readOverrideFromStore();
        }

        return $this->override;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readOverrideFromStore(): ?array
    {
        try {
            $setting = DemoSetting::query()->where('key', self::OVERRIDE_KEY)->first();
        } catch (Throwable) {
            // Before the demo_settings migration runs (or in odd bootstrap
            // states) the clock simply runs at real time.
            return null;
        }

        $value = $setting?->value;

        return is_array($value) ? $value : null;
    }

    /**
     * The nearest current-or-future day the demo city is open.
     */
    private function nextOpenDay(CarbonImmutable $from): CarbonImmutable
    {
        $day = $from;

        for ($i = 0; $i < 7; $i++) {
            if ($this->hoursFor($day->englishDayOfWeek) !== null) {
                return $day;
            }

            $day = $day->addDay();
        }

        return $from;
    }

    /**
     * Today's open/close instants, or null when the demo city is closed today.
     *
     * @return array{open: CarbonImmutable, close: CarbonImmutable}|null
     */
    public function businessHoursToday(): ?array
    {
        $now = $this->now();
        $hours = $this->hoursFor($now->englishDayOfWeek);

        if ($hours === null) {
            return null;
        }

        return [
            'open' => $this->at($now, $hours['open']),
            'close' => $this->at($now, $hours['close']),
        ];
    }

    /**
     * Today's same-day cutoff instant.
     */
    public function cutoff(): CarbonImmutable
    {
        $cutoff = config('logistics.cutoff');

        if (! is_string($cutoff) || $cutoff === '') {
            throw new RuntimeException(
                'Same-day cutoff is not configured (config/logistics.php "cutoff").'
            );
        }

        return $this->at($this->now(), $cutoff);
    }

    /**
     * Whether the current instant is before today's same-day cutoff.
     */
    public function isBeforeCutoff(): bool
    {
        return $this->now()->lessThan($this->cutoff());
    }

    /**
     * Whether the current instant falls within today's business hours.
     */
    public function isWithinHours(): bool
    {
        $hours = $this->businessHoursToday();

        if ($hours === null) {
            return false;
        }

        return $this->now()->betweenIncluded($hours['open'], $hours['close']);
    }

    private function timezone(): string
    {
        $timezone = config('logistics.timezone');

        if (! is_string($timezone) || $timezone === '') {
            throw new RuntimeException(
                'Logistics timezone is not configured (config/logistics.php "timezone").'
            );
        }

        return $timezone;
    }

    /**
     * @return array{open: string, close: string}|null
     */
    private function hoursFor(string $englishDay): ?array
    {
        $hours = config('logistics.hours');

        if (! is_array($hours)) {
            throw new RuntimeException(
                'Business hours are not configured (config/logistics.php "hours").'
            );
        }

        $day = $hours[strtolower($englishDay)] ?? null;

        if (! is_array($day)) {
            return null;
        }

        return ['open' => $day['open'], 'close' => $day['close']];
    }

    /**
     * Build an instant for the given "HH:MM" time on the same date as $ref.
     */
    private function at(CarbonImmutable $ref, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $ref->setTime($hour, $minute);
    }
}
