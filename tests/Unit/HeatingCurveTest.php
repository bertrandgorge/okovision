<?php

namespace Tests\Unit;

use App\Analysis\HeatingCurve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HeatingCurveTest extends TestCase
{
    /**
     * Valeurs relevées dans l'historique de la chaudière (pente 1,3, pied 32, influence 3, consigne 20 °C) :
     * consigne de départ calculée par le régulateur pour une T ext et une T ambiante données.
     */
    public static function measured(): array
    {
        return [
            'AT 5 °C, ambiance 18 °C (relance du matin)' => [5.0, 18.0, 51.0],
            'AT 10,5 °C, ambiance 19,7 °C' => [10.5, 19.7, 38.7],
            'AT 10,8 °C, ambiance 19,8 °C' => [10.8, 19.8, 38.0],
        ];
    }

    #[DataProvider('measured')]
    public function test_formula_matches_the_controller(float $outside, float $room, float $expected): void
    {
        $curve = new HeatingCurve(slope: 1.3, foot: 32, roomComfort: 20, influence: 3);

        $this->assertEqualsWithDelta($expected, $curve->flow($outside, 20, $room), 0.15);
    }

    public function test_foot_point_is_flow_at_15_degrees_for_20_degrees_room(): void
    {
        $curve = new HeatingCurve(slope: 1.7, foot: 34, flowMin: 10);

        $this->assertSame(34.0, $curve->flow(15));
    }

    public function test_flow_is_clamped_and_off_above_heating_limit(): void
    {
        $curve = new HeatingCurve(slope: 1.3, foot: 32, flowMin: 30, flowMax: 60, limitComfort: 18);

        $this->assertSame(60.0, $curve->flow(-15));
        $this->assertSame(30.0, $curve->flow(18));
        $this->assertNull($curve->flow(18.5));
    }

    public function test_two_points_give_back_slope_and_foot(): void
    {
        $curve = new HeatingCurve(slope: 1.4, foot: 33, roomComfort: 20.5, flowMin: 0, flowMax: 90);
        $r = HeatingCurve::fromTwoPoints(-5, $curve->rawFlow(-5), 15, $curve->rawFlow(15), 20.5);

        $this->assertEqualsWithDelta(1.4, $r['slope'], 0.01);
        $this->assertEqualsWithDelta(33, $r['foot'], 0.1);
    }

    public function test_boiler_setpoint_uses_raise_and_minimum(): void
    {
        $curve = new HeatingCurve(raise: 5);

        $this->assertSame(60.0, $curve->boilerSet(40.0, 60));
        $this->assertSame(70.0, $curve->boilerSet(65.0, 60));
        $this->assertNull($curve->boilerSet(null));
    }
}
