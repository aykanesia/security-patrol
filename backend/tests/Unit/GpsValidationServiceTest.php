<?php

namespace Tests\Unit;

use App\Services\GpsValidationService;
use PHPUnit\Framework\TestCase;

class GpsValidationServiceTest extends TestCase
{
    private GpsValidationService $gps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gps = new GpsValidationService();
    }

    public function test_zero_distance_is_zero(): void
    {
        $this->assertSame(0.0, $this->gps->calculateDistance(-6.26, 106.79, -6.26, 106.79));
    }

    public function test_known_distance_approximately(): void
    {
        // ~111.2 km per degree latitude; 0.01 deg lat ~= 1112 m
        $distance = $this->gps->calculateDistance(-6.26000000, 106.79000000, -6.25000000, 106.79000000);
        $this->assertEqualsWithDelta(1112.0, $distance, 50.0);
    }

    public function test_within_radius(): void
    {
        // ~0.0001 deg lat ≈ 11 m → inside 30 m
        $this->assertTrue($this->gps->isWithinRadius(-6.260000, 106.790000, -6.260100, 106.790100, 30));
    }

    public function test_outside_radius(): void
    {
        // ~0.001 deg lat ≈ 111 m → far outside 30 m
        $this->assertFalse($this->gps->isWithinRadius(-6.260000, 106.790000, -6.261000, 106.790000, 30));
    }

    public function test_validate_checkpoint_location_returns_distance(): void
    {
        $result = $this->gps->validateCheckpointLocation(-6.260000, 106.790000, -6.261000, 106.790000, 30);

        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(100, $result['distance_meter']);
    }

    public function test_radius_boundary_just_inside(): void
    {
        // 0.0002 deg lat ≈ 22 m → inside 30 m
        $result = $this->gps->validateCheckpointLocation(-6.260000, 106.790000, -6.260200, 106.790000, 30);
        $this->assertTrue($result['valid']);
    }
}
