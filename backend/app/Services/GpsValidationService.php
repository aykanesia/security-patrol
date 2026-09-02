<?php

namespace App\Services;

/**
 * GPS distance & radius validation.
 *
 * Uses the Haversine formula (unit: meters).
 * The backend must ALWAYS calculate distance itself — never trust
 * a distance_meter value sent by a client.
 */
class GpsValidationService
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Haversine distance between two coordinates, in meters.
     */
    public function calculateDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2,
    ): float {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    public function isWithinRadius(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2,
        float $radiusMeters,
    ): bool {
        return $this->calculateDistance($lat1, $lon1, $lat2, $lon2) <= $radiusMeters;
    }

    /**
     * Validate that a reported position is inside a checkpoint's radius.
     *
     * @return array{valid: bool, distance_meter: float}
     */
    public function validateCheckpointLocation(
        float $checkpointLat,
        float $checkpointLon,
        float $userLat,
        float $userLon,
        float $radiusMeters,
    ): array {
        $distance = $this->calculateDistance($checkpointLat, $checkpointLon, $userLat, $userLon);

        return [
            'valid' => $distance <= $radiusMeters,
            'distance_meter' => round($distance, 2),
        ];
    }
}
