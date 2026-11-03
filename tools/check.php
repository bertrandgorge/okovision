<?php
// Outil de développement : affiche les résultats des principales analyses
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$stock = app(App\Analysis\StockEstimator::class);
echo "SILO: ".json_encode($stock->silo(), JSON_UNESCAPED_UNICODE)."\n";
echo "ASH: ".json_encode($stock->ashBox())."\n";
echo "SIGNATURE: ".json_encode(app(App\Analysis\EnergySignature::class)->model())."\n";
$boiler = app(App\Okofen\BoilerService::class);
$read = $boiler->read(array_values(App\Analysis\Advisor::P));
$params = array_map(fn ($n) => isset($read[$n]) ? $read[$n]->value() : null, App\Analysis\Advisor::P);
echo "PARAMS: ".json_encode($params)."\n";
$curve = $boiler->currentCurve();
echo "CURVE: ".json_encode($curve->toArray())."\n";
$r = app(App\Analysis\Advisor::class)->analyse($params, $curve, $argv[1] ?? null, $argv[2] ?? null);
echo "PERIOD: ".json_encode($r['period'])."\nSTATS: ".json_encode($r['stats'])."\nLOAD: ".json_encode(array_diff_key($r['load'] ?? [], ['model' => 1]))."\n";
foreach ($r['recommendations'] as $rec) {
    echo "- [{$rec['severity']}] {$rec['title']}\n    {$rec['summary']}\n    changes: ".json_encode($rec['changes'])."\n";
}
