<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class ClockServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_now_reflects_the_configured_timezone(): void
    {
        Config::set('logistics.timezone', 'America/Chicago');
        Config::set('logistics.hours', []);
        Config::set('logistics.cutoff', '14:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00', 'America/Chicago'));

        $this->assertSame('2026-09-14 09:00', (new ClockService)->now()->format('Y-m-d H:i'));
    }

    public function test_missing_hours_config_throws_a_clear_error(): void
    {
        Config::set('logistics.timezone', 'America/Chicago');
        Config::set('logistics.hours', null);
        Config::set('logistics.cutoff', '14:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00', 'America/Chicago'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Business hours');

        (new ClockService)->businessHoursToday();
    }
}
