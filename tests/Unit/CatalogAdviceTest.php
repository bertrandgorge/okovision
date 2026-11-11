<?php

namespace Tests\Unit;

use App\Okofen\Catalog;
use Tests\TestCase;

class CatalogAdviceTest extends TestCase
{
    public function test_range_advice_is_formatted_and_compared_to_the_value(): void
    {
        $catalog = app(Catalog::class);
        $p = $catalog->parameter('CAPPL:LOCAL.hk[0].raumtemp_plus');

        $a = $catalog->advice($p, 0);
        $this->assertSame('1 à 2 K', $a['text']);
        $this->assertSame('0 K', $a['factory']);
        $this->assertTrue($a['outside']);
        $this->assertFalse($catalog->advice($p, 1.5)['outside']);
        $this->assertFalse($catalog->advice($p, null)['outside']);
    }

    public function test_allowed_values_advice_on_an_enum(): void
    {
        $catalog = app(Catalog::class);
        $p = $catalog->parameter('CAPPL:LOCAL.ww[0].legionellen_wochentag');

        $this->assertSame('autre que Arrêt', $catalog->advice($p, 5)['text']);
        $this->assertTrue($catalog->advice($p, 7)['outside']);
        $this->assertFalse($catalog->advice($p, 0)['outside']);
    }

    public function test_every_advice_block_is_well_formed(): void
    {
        foreach (app(Catalog::class)->parameters()->filter(fn ($p) => isset($p['advice'])) as $name => $p) {
            $a = $p['advice'];
            $this->assertNotEmpty($a['why'] ?? null, "$name : explication manquante");
            if (isset($a['range'])) {
                $this->assertCount(2, $a['range'], $name);
                $this->assertLessThanOrEqual($a['range'][1], $a['range'][0], $name);
                if (isset($p['min'], $p['max'])) {
                    $this->assertGreaterThanOrEqual($p['min'], $a['range'][0], "$name : conseil sous le minimum");
                    $this->assertLessThanOrEqual($p['max'], $a['range'][1], "$name : conseil au-dessus du maximum");
                }
            }
        }
    }
}
