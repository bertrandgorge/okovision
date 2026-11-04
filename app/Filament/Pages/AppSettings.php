<?php

namespace App\Filament\Pages;

use App\Analysis\PelletCalibration;
use App\Diagnostics\ReportToken;
use App\Okofen\BoilerClient;
use App\Okofen\BoilerService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class AppSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Paramètres';

    protected static ?string $title = 'Paramètres de l\'application';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'parametres';

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    private const SECTIONS = ['boiler', 'equipment', 'pellets', 'climate', 'archive', 'alerts'];

    public function mount(): void
    {
        $data = [];
        foreach (self::SECTIONS as $s) {
            $data[$s] = settings()->section($s);
        }
        $data['boiler']['password'] = null; // jamais réaffiché
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Connexion à la chaudière')
                ->description('Adresse de l\'interface web du régulateur KEBA telle qu\'elle est vue depuis le serveur. Hébergement externe : redirection de port sur la box (ex. http://maison.ddns.net:8041), restreinte si possible à l\'IP du serveur, et changez le mot de passe par défaut.')
                ->columns(2)
                ->schema([
                    TextInput::make('boiler.url')->label('URL de la chaudière')->url()->required()->placeholder('http://192.168.1.41'),
                    TextInput::make('boiler.csv_url')->label('URL des fichiers CSV (optionnel)')->url()->placeholder('{url}/logfiles/pelletronic/')
                        ->helperText('À renseigner si les CSV sont relayés par un autre serveur.'),
                    TextInput::make('boiler.user')->label('Identifiant')->required(),
                    TextInput::make('boiler.password')->label('Mot de passe')->password()->revealable()->placeholder('inchangé'),
                    Select::make('boiler.language')->label('Langue des libellés')->options(['fr' => 'Français', 'en' => 'English', 'de' => 'Deutsch']),
                    TextInput::make('boiler.timeout')->label('Délai d\'attente')->suffix('s')->numeric(),
                    Actions::make([
                        Action::make('test')->label('Tester la connexion')->icon(Heroicon::OutlinedSignal)->action(fn () => $this->testConnection()),
                    ]),
                ]),
            Section::make('Installation')
                ->description('Ces informations servent aux calculs (puissance minimale, stockage, projections) et aux recommandations.')
                ->columns(3)
                ->schema([
                    TextInput::make('equipment.boiler_model')->label('Modèle de chaudière'),
                    TextEntry::make('boiler_power')->label('Puissance paramétrée')
                        ->state(function () {
                            $p = app(BoilerService::class)->valueOrLastKnown(BoilerService::POWER);

                            return match (true) {
                                $p['value'] === null => 'inconnue (chaudière injoignable, aucun relevé)',
                                $p['live'] => $p['value'].' kW',
                                default => $p['value'].' kW (relevé du '.$p['at']->format('d/m/Y').', chaudière injoignable)',
                            };
                        })
                        ->helperText('Lue sur la chaudière (pe_kesselleistung) ; modifiable dans Réglages chaudière › Avancés'),
                    TextInput::make('equipment.min_modulation_pct')->label('Modulation minimale')->suffix('%')->numeric()->helperText('Pellematic : ~30 %'),
                    TextInput::make('equipment.boiler_water_l')->label('Contenance en eau de la chaudière')->suffix('L')->numeric(),
                    TextInput::make('equipment.buffer_tank_l')->label('Ballon tampon')->suffix('L')->numeric()->helperText('0 = aucun'),
                    TextInput::make('equipment.dhw_tank_l')->label('Ballon d\'eau chaude')->suffix('L')->numeric(),
                    Select::make('equipment.silo_type')->label('Alimentation granulés')->options([
                        'auger' => 'Silo + vis sans fin', 'suction' => 'Silo + aspiration', 'textile' => 'Silo textile', 'hopper' => 'Trémie manuelle',
                    ]),
                    TextInput::make('equipment.silo_capacity_kg')->label('Capacité du silo')->suffix('kg')->numeric(),
                    TextInput::make('equipment.ash_box_capacity_kg')->label('Cendrier plein après')->suffix('kg de granulés')->numeric(),
                    TextInput::make('equipment.house_surface_m2')->label('Surface chauffée')->suffix('m²')->numeric(),
                    TextInput::make('equipment.house_year')->label('Année de construction')->numeric(),
                    Select::make('equipment.emitters')->label('Émetteurs')->options([
                        'convectors' => 'Convecteurs à ailettes', 'radiators' => 'Radiateurs', 'underfloor' => 'Plancher chauffant', 'mixed' => 'Mixte',
                    ]),
                    TextInput::make('equipment.turbulators_removed')->label('Ressorts turbulateurs retirés')->numeric()->minValue(0)
                        ->helperText('Ressorts (turbulateurs) enlevés des tubes de l\'échangeur'),
                    Toggle::make('equipment.room_sensor')->label('Sonde d\'ambiance raccordée'),
                    Textarea::make('equipment.notes')->label('Notes sur l\'installation')->columnSpanFull()
                        ->helperText('Modifications, particularités… (reprises dans le rapport pour LLM)'),
                ]),
            Section::make('Granulés')->columns(3)->schema([
                TextInput::make('pellets.lhv_kwh_per_kg')->label('PCI')->suffix('kWh/kg')->numeric(),
                TextInput::make('pellets.boiler_efficiency')->label('Rendement saisonnier estimé')->numeric()->helperText('0,85 à 0,92'),
                TextInput::make('pellets.price_per_ton')->label('Prix')->suffix('€/t')->numeric()->helperText('Mis à jour à chaque livraison saisie'),
                TextInput::make('pellets.grams_per_auger_minute')->label('Débit de vis par défaut')->suffix('g/min')->numeric(),
                Toggle::make('pellets.auto_calibrate')->label('Calibrer automatiquement avec les livraisons'),
            ]),
            Section::make('Climat')->columns(3)->schema([
                TextInput::make('climate.dju_base')->label('Base des DJU')->suffix('°C')->numeric(),
                TextInput::make('climate.design_outside_temp')->label('T extérieure de base')->suffix('°C')->numeric()->helperText('Température de dimensionnement de la région'),
            ]),
            Section::make('Données')->columns(3)->schema([
                TextInput::make('archive.keep_months')->label('Conserver les mesures minute en base')->suffix('mois')->numeric()->minValue(3)
                    ->helperText('Au-delà : archive CSV compressée, consultable à la demande'),
            ]),
            Section::make('Rapport pour LLM')
                ->description('Document Markdown complet (installation, réglages, loi d\'eau, fonctionnement, signature, mesures) à donner à un assistant IA pour diagnostic. Accessible avec votre session, ou par cette URL secrète. Options : &days=60 (période), &sample=5 (pas des mesures 24 h, 0 = aucune), &expert=0 (sans réglages technicien).')
                ->schema([
                    TextEntry::make('diagnostic_url')->label('URL secrète')
                        ->state(fn () => ReportToken::url())
                        ->copyable()->fontFamily('mono'),
                    Actions::make([
                        Action::make('newToken')->label('Régénérer le jeton (invalide l\'ancienne URL)')
                            ->icon(Heroicon::OutlinedKey)->color('gray')->requiresConfirmation()
                            ->action(function () {
                                ReportToken::regenerate();
                                Notification::make()->title('Nouveau jeton généré')->success()->send();
                            }),
                        Action::make('open')->label('Ouvrir le rapport')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                            ->url(fn () => ReportToken::url(), shouldOpenInNewTab: true),
                    ]),
                ]),
            Section::make('Seuils par défaut des alertes')->columns(3)->collapsed()->schema([
                TextInput::make('alerts.silo_low_kg')->label('Silo bas')->suffix('kg')->numeric(),
                TextInput::make('alerts.silo_low_days')->label('Silo vide dans')->suffix('jours')->numeric(),
                TextInput::make('alerts.ash_full_pct')->label('Cendrier plein')->suffix('%')->numeric(),
                TextInput::make('alerts.no_data_minutes')->label('Pas de données depuis')->suffix('min')->numeric(),
                TextInput::make('alerts.room_temp_low')->label('Ambiance basse')->suffix('°C')->numeric(),
                TextInput::make('alerts.cycles_per_day_high')->label('Cycles par jour')->numeric(),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        if (blank($data['boiler']['password'] ?? null)) {
            unset($data['boiler']['password']);
        }
        foreach (Arr::dot($data) as $key => $value) {
            if (is_numeric($value) && ! in_array($key, ['boiler.password', 'boiler.user'], true)) {
                $value += 0;
            }
            settings()->set($key, $value);
        }
        app(PelletCalibration::class)->flush();
        Cache::forget('okovision.dashboard.advice');
        app(BoilerClient::class)->forgetSession();
        Notification::make()->title('Paramètres enregistrés')->success()->send();
    }

    public function testConnection(): void
    {
        $this->save();
        try {
            $r = app(BoilerClient::class)->testConnection();
            Notification::make()->title('Connexion réussie')->body("{$r['version']} · T ext {$r['outside_temp']} °C")->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Connexion impossible')->body($e->getMessage())->danger()->send();
        }
    }
}
