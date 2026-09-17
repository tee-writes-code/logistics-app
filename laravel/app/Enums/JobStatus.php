<?php

declare(strict_types=1);

namespace App\Enums;

enum JobStatus: string
{
    case Booked = 'booked';
    case Assigned = 'assigned';
    case EnRoutePickup = 'en_route_pickup';
    case AtPickup = 'at_pickup';
    case PickedUp = 'picked_up';
    case EnRouteDrop = 'en_route_drop';
    case OnSite = 'on_site';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returning = 'returning';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * Statuses at which the job is a candidate for the live map (picked_up..delivered/returned).
     *
     * @return array<int, self>
     */
    public static function liveMapStatuses(): array
    {
        return [
            self::PickedUp,
            self::EnRouteDrop,
            self::OnSite,
            self::Delivered,
            self::Returning,
            self::Returned,
        ];
    }

    /**
     * Terminal statuses that end a recipient magic-link session.
     *
     * @return array<int, self>
     */
    public static function terminalStatuses(): array
    {
        return [self::Delivered, self::Returned, self::Cancelled];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminalStatuses(), true);
    }
}
