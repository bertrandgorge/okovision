<?php

use App\Models\BoilerProfile;
use App\Okofen\Catalog;
use App\Okofen\ParameterSnapshot;
use Illuminate\Database\Migrations\Migration;

/*
 * Retire des relevés automatiques les variables qui ne sont pas des réglages (snapshot: false dans le catalogue,
 * ex. l'horloge de l'automate) et supprime les relevés qui ne contenaient que ces faux changements.
 */
return new class extends Migration
{
    public function up(): void
    {
        $volatile = app(Catalog::class)->parameters()->filter(fn ($p) => ! ($p['snapshot'] ?? true))->keys()->flip()->all();
        $snapshot = app(ParameterSnapshot::class);
        $first = true;

        foreach (BoilerProfile::query()->automatic()->orderBy('id')->get() as $profile) {
            $values = array_diff_key($profile->values ?? [], $volatile);
            $changes = $profile->changes === null ? null : array_diff_key($profile->changes, $volatile);

            if (! $first && $changes === []) {
                $profile->delete();

                continue;
            }

            $profile->update([
                'values' => $values,
                'changes' => $changes,
                'description' => $first ? 'Premier relevé ('.count($values).' réglages)' : $snapshot->describe($changes),
            ]);
            $first = false;
        }
    }

    public function down(): void {}
};
