<?php

namespace Tests\Unit;

use App\Okofen\TimeProgram;
use PHPUnit\Framework\TestCase;

class TimeProgramTest extends TestCase
{
    private const BASE = 'CAPPL:LOCAL.hk[0].zeitprogramm[0]';

    /** Valeurs réellement lues sur la chaudière (programme 1 du chauffage, capture de l'écran du Touch). */
    private function boilerRaw(): array
    {
        $raw = [];
        $blocks = [0 => 0, 1 => 1, 2 => 1, 3 => 0, 4 => 1, 5 => 1, 6 => 0];
        foreach ($blocks as $d => $b) {
            $raw[self::BASE.".tag[$d].block"] = (string) $b;
            $raw[self::BASE.".tag[$d].zeitreihe[0,0]"] = '21600.000000';
            $raw[self::BASE.".tag[$d].zeitreihe[0,1]"] = '79200.000000';
            foreach ([1, 2] as $i) {
                $raw[self::BASE.".tag[$d].zeitreihe[$i,0]"] = '0.000000';
                $raw[self::BASE.".tag[$d].zeitreihe[$i,1]"] = '0.000000';
            }
        }

        return $raw;
    }

    public function test_decodes_boiler_values(): void
    {
        $days = TimeProgram::decode($this->boilerRaw(), self::BASE);

        $this->assertSame(array_fill(0, 7, [[360, 1320]]), $days);
        $this->assertSame('Tous les jours 06:00-22:00', TimeProgram::summary($days));
    }

    public function test_days_of_a_block_take_the_slots_of_its_first_day(): void
    {
        $raw = $this->boilerRaw();
        $raw[self::BASE.'.tag[3].zeitreihe[0,0]'] = '25200'; // mercredi désynchronisé : l'écran affiche le dimanche (même bloc)

        $this->assertSame([360, 1320], TimeProgram::decode($raw, self::BASE)[3][0]);
    }

    public function test_encodes_identical_days_into_one_block_and_empty_days_as_minus_one(): void
    {
        $days = [1 => [[360, 480], [1020, 1320]], 2 => [[360, 480], [1020, 1320]], 3 => [[420, 1320]], 0 => []];
        $raw = TimeProgram::encode(TimeProgram::normalize($days), self::BASE);

        $this->assertSame('1', $raw[self::BASE.'.tag[1].block']);
        $this->assertSame('1', $raw[self::BASE.'.tag[2].block']);
        $this->assertSame('3', $raw[self::BASE.'.tag[3].block']);
        $this->assertSame('-1', $raw[self::BASE.'.tag[0].block']);
        $this->assertSame('-1', $raw[self::BASE.'.tag[6].block']);
        $this->assertSame('61200', $raw[self::BASE.'.tag[2].zeitreihe[1,0]']);
        $this->assertSame('0', $raw[self::BASE.'.tag[0].zeitreihe[0,1]']);
        $this->assertCount(7 * 7, $raw);

        // aller-retour
        $this->assertSame(TimeProgram::normalize($days), TimeProgram::decode($raw, self::BASE));
    }

    public function test_validation(): void
    {
        $this->assertNull(TimeProgram::validate([1 => [[360, 480], [1020, 1439]]]));
        $this->assertStringContainsString('précéder', TimeProgram::validate([1 => [[480, 360]]]));
        $this->assertStringContainsString('chevauchent', TimeProgram::validate([1 => [[360, 600], [540, 700]]]));
        $this->assertStringContainsString('au plus', TimeProgram::validate([1 => [[0, 10], [20, 30], [40, 50], [60, 70]]]));
    }

    public function test_summary_groups_days(): void
    {
        $days = array_fill(0, 7, []);
        foreach ([1, 2, 4, 5] as $d) {
            $days[$d] = [[360, 1320]];
        }
        foreach ([3, 6, 0] as $d) {
            $days[$d] = [[420, 1380]];
        }

        $this->assertSame('Lu-Ma, Je-Ve 06:00-22:00 · Me, Sa-Di 07:00-23:00', TimeProgram::summary($days));
    }

    public function test_label_for_log_entries(): void
    {
        $this->assertSame('Programme horaire eau chaude 2', TimeProgram::labelFor('CAPPL:LOCAL.ww[0].zeitprogramm[1]'));
        $this->assertNull(TimeProgram::labelFor('CAPPL:LOCAL.hk[0].heizkurve_steigung'));
    }
}
