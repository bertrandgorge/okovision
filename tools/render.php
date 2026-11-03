<?php
// Rendu d'une page en étant authentifié (outil de développement) : php tools/render.php /journee?date=2026-01-12
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$uri = $argv[1] ?? '/';
$request = Illuminate\Http\Request::create($uri, 'GET');
$app->instance('request', $request);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::query()->first();
Illuminate\Support\Facades\Auth::login($user);
$response = $kernel->handle($request);
$status = $response->getStatusCode();
$body = $response->getContent();
echo "HTTP {$status} ".strlen($body)." octets\n";
if ($status >= 400 || str_contains($body, 'Whoops') || str_contains($body, 'exception')) {
    if (preg_match('/<title>(.*?)<\/title>/s', $body, $m)) echo trim($m[1])."\n";
    if (preg_match_all('/"message":"(.*?)"/', $body, $m)) echo implode("\n", array_slice(array_unique($m[1]), 0, 3))."\n";
}
if (in_array('--dump', $argv)) file_put_contents('/tmp/render.html', $body);
