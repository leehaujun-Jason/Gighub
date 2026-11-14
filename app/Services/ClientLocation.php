<?php
declare(strict_types=1);
namespace GigHub\Services;

final class ClientLocation
{
    public const STATES = [
        'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang',
        'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor',
        'Terengganu', 'Kuala Lumpur', 'Labuan', 'Putrajaya',
    ];

    public static function validState(string $state): bool
    {
        return in_array($state, self::STATES, true);
    }
}
