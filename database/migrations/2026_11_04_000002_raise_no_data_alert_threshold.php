<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * La chaudière n'écrit son fichier CSV que toutes les ~2 h : le seuil initial de 60 min de l'alerte
 * « Plus de données reçues » déclenchait de fausses alertes. On le relève à 180 min s'il est plus bas.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('alert_rules')->where('type', 'no_data')->get() as $rule) {
            $params = json_decode((string) $rule->params, true) ?: [];
            if ((int) ($params['minutes'] ?? 0) < 180) {
                $params['minutes'] = 180;
                DB::table('alert_rules')->where('id', $rule->id)->update(['params' => json_encode($params)]);
            }
        }
    }

    public function down(): void {}
};
