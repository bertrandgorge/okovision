<?php

/*
 * Valeurs par défaut de l'application. Elles sont toutes surchargeables depuis
 * l'écran « Paramètres » (table settings) : voir App\Support\Settings.
 */

return [

    'catalog_path' => resource_path('okofen/catalog.yaml'),

    'boiler' => [
        // URL de la chaudière telle que vue depuis le serveur (redirection de port possible : http://maison.ddns.net:8041)
        'url' => env('BOILER_URL', 'http://192.168.1.41'),
        'user' => env('BOILER_USER', 'oekofen'),
        'password' => env('BOILER_PASSWORD', 'oekofen'),
        // URL du répertoire des fichiers CSV (par défaut {url}/logfiles/pelletronic/)
        'csv_url' => env('BOILER_CSV_URL'),
        'language' => 'fr',
        'timeout' => 20,
        // Nombre maximum de variables par requête (l'automate KEBA sature au-delà)
        'batch_size' => 25,
    ],

    'equipment' => [
        'boiler_model' => 'Pellematic',
        'min_modulation_pct' => 30,
        'boiler_water_l' => 70,
        'buffer_tank_l' => 0,
        'dhw_tank_l' => 200,
        'silo_type' => 'auger', // auger | suction | textile | hopper
        'silo_capacity_kg' => 3500,
        'ash_box_capacity_kg' => 1500, // kg de granulés brûlés avant cendrier plein
        'house_surface_m2' => 230,
        'house_year' => 1970,
        'emitters' => 'convectors', // convectors | radiators | underfloor
        'room_sensor' => true,
        // Ressorts turbulateurs retirés de l'échangeur (modification de l'échange thermique)
        'turbulators_removed' => 0,
        'notes' => null,
    ],

    'pellets' => [
        'lhv_kwh_per_kg' => 4.8,       // PCI des granulés (EN Plus A1 : ≥ 4,6 kWh/kg)
        'boiler_efficiency' => 0.88,
        'grams_per_auger_minute' => 380, // calibré automatiquement à partir des remplissages
        'auto_calibrate' => true,
        'price_per_ton' => 450,
    ],

    'climate' => [
        'dju_base' => 18, // base de calcul des degrés-jours unifiés
        'design_outside_temp' => -5,
    ],

    'archive' => [
        // Les mesures minute plus anciennes que N mois sont archivées (CSV gzip) puis supprimées de la base
        'keep_months' => 24,
        'disk' => 'local',
        'path' => 'archives',
    ],

    'alerts' => [
        'silo_low_kg' => 500,
        'silo_low_days' => 21,
        'ash_full_pct' => 85,
        'no_data_minutes' => 60,
        'room_temp_low' => 17,
        'cycles_per_day_high' => 30,
    ],

    'optimizer' => [
        'enabled' => false,
        'dry_run' => true,
        // Chauffage par blocs : quand la demande est sous la puissance minimale de la chaudière
        'block_heating' => [
            'max_outside_temp' => 14,
            'min_outside_temp' => 4,
            'room_band_low' => -0.4,   // K sous la consigne confort → on relance un bloc
            'room_band_high' => 0.6,   // K au-dessus de la consigne confort → on coupe
            'min_block_minutes' => 120,
            'min_pause_minutes' => 60,
        ],
    ],
];
