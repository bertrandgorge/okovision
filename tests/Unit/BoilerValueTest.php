<?php

namespace Tests\Unit;

use App\Okofen\BoilerValue;
use PHPUnit\Framework\TestCase;

class BoilerValueTest extends TestCase
{
    public function test_scaled_value_limits_and_options(): void
    {
        $v = BoilerValue::fromApi([
            'name' => 'CAPPL:LOCAL.hk[0].heizkurve_steigung', 'value' => '13', 'divisor' => '10',
            'lowerLimit' => '0', 'upperLimit' => '40', 'unitText' => '???', 'formatTexts' => '', 'shortText' => 'Courb chf', 'status' => 'OK',
        ]);

        $this->assertSame(1.3, $v->value());
        $this->assertSame(0.0, $v->min);
        $this->assertSame(4.0, $v->max);
        $this->assertNull($v->unit);
        $this->assertSame('13', BoilerValue::toRaw(1.3, $v->divisor));
    }

    public function test_enum_labels_ignore_placeholders(): void
    {
        $v = BoilerValue::fromApi([
            'name' => 'CAPPL:FA[0].L_kesselstatus', 'value' => '4', 'divisor' => '', 'formatTexts' => 'Dauerlauf|Start|Zuendung|Softstart|Leistungsbrand|1|1|Aus', 'status' => 'OK',
        ]);

        $this->assertSame(4, $v->value());
        $this->assertSame('Leistungsbrand', $v->label());
        $this->assertArrayNotHasKey(5, $v->options);
    }

    public function test_missing_sensor_and_errors_are_null(): void
    {
        $this->assertNull(BoilerValue::fromApi(['name' => 'x', 'value' => '-32768', 'divisor' => '10', 'status' => 'OK'])->value());
        $this->assertNull(BoilerValue::fromApi(['name' => 'x', 'value' => '???', 'status' => 'ERROR'])->value());
    }

    public function test_array_round_trip_for_cache(): void
    {
        $v = BoilerValue::fromApi(['name' => 'a', 'value' => '750', 'divisor' => '10', 'unitText' => '°C', 'status' => 'OK']);

        $this->assertEquals($v, BoilerValue::fromArray($v->toArray()));
    }
}
