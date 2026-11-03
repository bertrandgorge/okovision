<?php

namespace App\Okofen;

use App\Okofen\Exceptions\BoilerException;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de l'interface web du régulateur KEBA (Pelletronic Touch V2.x).
 *
 * - Authentification : POST /index.cgi (username, password, language, submit=Login) → 303 + cookie de session
 * - Lecture : POST /index.cgi?action=get&attr=1, corps = tableau JSON de noms de variables
 * - Écriture : POST /index.cgi?action=set, corps = objet JSON {variable: valeur brute}
 * L'en-tête Accept-Language est obligatoire (sinon erreur Lua 500).
 */
class BoilerClient
{
    private const COOKIE_CACHE = 'okofen.boiler.cookies';

    private ?CookieJar $jar = null;

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
    ) {}

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl ?? (string) settings('boiler.url'), '/');
    }

    public function csvUrl(): string
    {
        $url = settings('boiler.csv_url');

        return rtrim($url ?: $this->baseUrl().'/logfiles/pelletronic', '/').'/';
    }

    /**
     * Lit des variables. Les requêtes sont découpées en lots (l'automate sature au-delà de ~40 variables).
     *
     * @param  list<string>  $names
     * @return array<string, BoilerValue>
     */
    public function get(array $names): array
    {
        $names = array_values(array_unique($names));
        $result = [];

        foreach (array_chunk($names, (int) settings('boiler.batch_size', 25)) as $chunk) {
            $rows = $this->call('get&attr=1', json_encode($chunk));

            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $result[$row['name']] = BoilerValue::fromApi($row);
                }
            }
        }

        return $result;
    }

    public function getOne(string $name): ?BoilerValue
    {
        return $this->get([$name])[$name] ?? null;
    }

    /**
     * Écrit des valeurs brutes (déjà multipliées par le diviseur).
     *
     * @param  array<string, string|int>  $rawValues
     */
    public function setRaw(array $rawValues): void
    {
        if ($rawValues === []) {
            return;
        }

        $payload = json_encode(array_map('strval', $rawValues), JSON_UNESCAPED_SLASHES);
        Log::channel('boiler')->info('SET '.$payload);
        $this->call('set', $payload, expectJson: false);
    }

    public function testConnection(): array
    {
        $this->forgetSession();
        $this->login();
        $values = $this->get(['CAPPL:LOCAL.touch[0].version', 'CAPPL:LOCAL.L_aussentemperatur_ist']);

        return [
            'version' => $values['CAPPL:LOCAL.touch[0].version']?->value(),
            'outside_temp' => $values['CAPPL:LOCAL.L_aussentemperatur_ist']?->value(),
        ];
    }

    /** Liste des fichiers CSV disponibles sur la chaudière (nom => date de modification). */
    public function listCsvFiles(): array
    {
        $response = $this->http()->get($this->csvUrl());
        if (! $response->successful()) {
            throw new BoilerException('Liste des fichiers CSV inaccessible ('.$response->status().')');
        }

        preg_match_all('/href="[^"]*?(touch_(\d{8})\.csv)"/i', $response->body(), $m, PREG_SET_ORDER);
        $files = [];
        foreach ($m as $match) {
            $files[$match[1]] = $match[2];
        }
        ksort($files);

        return $files;
    }

    public function downloadCsv(string $filename): string
    {
        $response = $this->http()->timeout(60)->get($this->csvUrl().$filename);
        if (! $response->successful()) {
            throw new BoilerException("Téléchargement de {$filename} impossible (".$response->status().')');
        }

        return $response->body();
    }

    public function forgetSession(): void
    {
        $this->jar = null;
        Cache::forget(self::COOKIE_CACHE);
    }

    private function call(string $action, string $body, bool $expectJson = true, bool $retry = true): array
    {
        $this->ensureLoggedIn();

        try {
            $response = $this->apiRequest($action, $body);
        } catch (\Throwable $e) {
            throw new BoilerException('Chaudière injoignable : '.$e->getMessage(), previous: $e);
        }

        $data = $expectJson ? json_decode($response->body(), true) : [];
        $failed = ! $response->successful() || ($expectJson && ! is_array($data));

        if ($failed && $retry) {
            // Session expirée : l'automate renvoie la page de login ou une erreur de script
            $this->forgetSession();

            return $this->call($action, $body, $expectJson, false);
        }

        if ($failed) {
            throw new BoilerException('Réponse invalide de la chaudière ('.$response->status().') : '.mb_substr(strip_tags($response->body()), 0, 200));
        }

        $this->saveCookies();

        return $data ?? [];
    }

    private function apiRequest(string $action, string $body): Response
    {
        return $this->http()
            ->withOptions(['cookies' => $this->jar()])
            ->withHeaders([
                'Accept' => 'application/json',
                'Accept-Language' => settings('boiler.language', 'fr'),
            ])
            ->withBody($body, 'application/x-www-form-urlencoded; charset=UTF-8')
            ->post($this->baseUrl().'/index.cgi?action='.$action);
    }

    private function ensureLoggedIn(): void
    {
        $hasSession = collect($this->jar()->toArray())->isNotEmpty();
        if (! $hasSession) {
            $this->login();
        }
    }

    private function login(): void
    {
        try {
            $response = $this->http()
                ->withOptions(['cookies' => $this->jar(), 'allow_redirects' => false])
                ->asForm()
                ->post($this->baseUrl().'/index.cgi', [
                    'username' => $this->user ?? settings('boiler.user'),
                    'password' => $this->password ?? settings('boiler.password'),
                    'language' => settings('boiler.language', 'fr'),
                    'submit' => 'Login',
                ]);
        } catch (\Throwable $e) {
            throw new BoilerException('Chaudière injoignable : '.$e->getMessage(), previous: $e);
        }

        if ($response->status() !== 303 && ! $response->successful()) {
            throw new BoilerException('Connexion à la chaudière refusée ('.$response->status().')');
        }
        if ($response->status() !== 303) {
            throw new BoilerException('Identifiants chaudière refusés');
        }

        $this->saveCookies();
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) settings('boiler.timeout', 20))
            ->connectTimeout(5)
            ->withUserAgent('Okovision/2');
    }

    private function jar(): CookieJar
    {
        if ($this->jar) {
            return $this->jar;
        }

        $cookies = array_map(fn (array $c) => new SetCookie($c), Cache::get(self::COOKIE_CACHE, []));

        return $this->jar = new CookieJar(false, $cookies);
    }

    private function saveCookies(): void
    {
        Cache::put(self::COOKIE_CACHE, $this->jar()->toArray(), now()->addMinutes(20));
    }
}
