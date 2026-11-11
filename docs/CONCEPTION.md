# Okovision 2 : analyse et conception

Refonte de l'application de suivi et de réglage d'une chaudière à granulés **Okofen Pellematic** pilotée par un
régulateur **KEBA CP21 / Pelletronic Touch V02.00j** (192.168.1.41). Maison de 230 m² des années 70 isolée,
convecteurs à ailettes, un circuit de chauffage avec vanne mélangeuse et sonde d'ambiance, un ballon d'eau chaude,
silo d'environ 3,5 t alimenté par vis sans fin, pas de ballon tampon.

## 1. Ce que disent les données (2014 → 2026)

Les 3,4 millions de mesures minute de l'ancienne base ont été importées et analysées.

### 1.1 La chaudière fonctionne par à-coups à mi-saison

Journée type du 12 janvier 2026 (T ext ≈ 10 °C l'après-midi) :

| Phase | Durée | Détail |
|---|---|---|
| Démarrage, puis combustion à puissance mini | ~17 min | vis à 6–12 % du temps, ventilateur 15–25 % ; T chaudière 72 → 80 °C |
| Coupure à **80 °C** (température de coupure) | | |
| Post-combustion / post-ventilation | ~15 min | les ventilateurs évacuent la chaleur du foyer par la cheminée |
| Arrêt | **2 à 4 min** | redémarrage dès que T chaudière < 80 − 5 = **75 °C** |

Sur l'hiver 2025-2026 : **13 cycles par jour**, combustion médiane de **19 min**, arrêt médian de **3 min**,
76 % des cycles durent moins de 30 min et 38 % du temps de fonctionnement du brûleur est consacré à l'allumage ou à la post-ventilation.
Les compteurs de la chaudière confirment : 51 320 démarrages pour 23 245 h de brûleur.

### 1.2 Cause : la chaudière est trop puissante pour les besoins réels

- Signature énergétique (consommation par jour en fonction de la T ext moyenne, 2 dernières années, R² = 0,83) :
  ~37 kg/j à 8 °C, ~25 kg/j à 12 °C, ~15 kg/j à 15 °C, arrêt au-dessus de ~17 °C.
- Besoin moyen en saison de chauffe ≈ **4,2 kW** (T ext moyenne 12,3 °C), ≈ 12 kW à −5 °C.
- Puissance de la chaudière : 21 kW, modulation mini ≈ 30 % soit **≈ 6,3 kW**.
- **81 % des jours de chauffe, le besoin moyen est inférieur à la puissance minimale** : la chaudière ne peut pas
  fonctionner en continu. Elle monte à sa température de coupure, s'arrête, puis redémarre.
- Sans ballon tampon, la seule réserve d'énergie est l'eau de la chaudière, avec une plage de 5 K (75 → 80 °C).

### 1.3 Autres constats

- **Eau chaude à 40 °C** (mini 30 °C) et cycle anti-légionelles désactivé : c'est la zone où les légionelles prolifèrent.
- **Loi d'eau** : pente 1,3, pied 32 °C, influence de la sonde d'ambiance 3. En hiver, la sonde d'ambiance ajoute
  en moyenne +4,9 °C au départ par 0 °C extérieur : la courbe est trop basse par temps froid et la sonde compense.
- **Débit de la vis** calibré sur les livraisons : 349 à 409 g par minute de vis selon les années (377 g/min retenus).

### 1.4 Formule de la loi d'eau (identifiée sur l'historique)

Aucune documentation ne donne la formule exacte. Elle a été déduite de 12 ans de consignes calculées par le régulateur
(écart résiduel < 0,05 °C sur les réglages actuels, et cohérente avec les 4 périodes de réglages de l'historique) :

```
VL consigne = Pied + (RT consigne − 20) + Pente × (RT consigne − 5 − T ext) + Influence × (RT consigne − RT mesurée)
```

Elle est ensuite bornée entre T départ mini et T départ maxi. **Le pied de courbe correspond donc au départ pour
15 °C extérieur (avec 20 °C de consigne)**. La consigne chaudière vaut max(VL + surélévation, T chaudière mini).

## 2. Recommandations (calculées en continu par la page *Optimisation*)

Par ordre de facilité :

1. **Eau chaude à 52 °C** et cycle anti-légionelles hebdomadaire (dimanche). Une consigne plus haute stocke aussi
   plus d'énergie, donc moins de charges en été.
2. **Hystérésis de redémarrage de 5 K à 12 K** (`pe_einschalthysterese_smart`) : redémarrage à 68 °C au lieu de 75 °C.
   La plage de stockage passe de 5 K à 12 K, avec environ 58 % de redémarrages en moins attendus à mi-saison. La température reste au-dessus
   de la T chaudière mini (60 °C) et de la T de libération de la pompe (60 °C) + 5 K. Le manuel Okofen confirme
   le principe : le brûleur redémarre sous « T coupure − hystérésis ».
3. **Réduire la puissance paramétrée** (`pe_kesselleistung`, 21 → ~15 kW) : la puissance mini descend vers 4,5 kW,
   ce qui permet une combustion continue la majeure partie de la saison. C'est un réglage technicien, à valider avec l'installateur.
4. **Ajuster la loi d'eau** pour que la sonde d'ambiance n'ait plus qu'à affiner (proposition apprise : pente ≈ 1,6).
   Ajuster au plus une fois par jour : entre +5 et +15 °C on règle le pied, en dessous de +5 °C on règle la pente
   (méthode Okofen et guide « Le réglage des courbes de chauffe »).
5. **Regrouper l'eau chaude** : hystérésis ECS de 10 K et 1 à 2 plages horaires alignées sur les démarrages du chauffage.
6. **Pilotage « chauffage par blocs »** (expérimental, désactivé et en simulation par défaut) : à mi-saison,
   l'application alterne des blocs de chauffe (consigne +0,5 K, combustion longue) et des pauses (circuit arrêté)
   tant que l'ambiance reste dans la bande de confort. L'inertie de la maison sert de stockage.
7. **Ballon tampon d'environ 600 L** (25 à 50 L/kW), qui stocke ~14 kWh entre 60 et 80 °C, soit environ 3 h d'autonomie au besoin moyen.
   C'est la solution de fond au problème des cycles. Les études de terrain (Autriche) mesurent des rendements réels inférieurs de 7 à 24 points
   aux rendements nominaux à cause des cycles, et des émissions concentrées dans les phases de démarrage et d'arrêt.

Sources : manuel technicien Pelletronic Touch (Okofen) ; « Le réglage des courbes de chauffe » (guide fourni) ;
OSTI 1426787 et 1668663 (stockage thermique et chaudières à granulés) ; KIT Apello ; retours d'expérience
du forum Futura-Sciences « chaudières OKOFEN » ; energiesparhaus.at.

## 3. Architecture

- **Laravel 13 / PHP 8.5 / MariaDB-MySQL**, interface **Filament 5** (Livewire), graphiques **ECharts 6**.
- Les assets sont compilés en local (Vite) : il n'y a pas de Node sur le serveur.
- Une seule tâche cron sur O2Switch : `php artisan schedule:run` (toutes les 5 minutes recommandé).

```
app/Okofen/     Catalog (YAML), BoilerClient (API KEBA), BoilerService (lecture/écriture contrôlée), CsvParser, MeasurementImporter
app/Analysis/   DayAggregator (cycles + synthèse jour), PelletCalibration, StockEstimator, EnergySignature, HeatingCurve, Advisor
app/Alerts/     AlertType, AlertEvaluator, Notifier (e-mail, Slack, Telegram, webhook signé HMAC)
app/Archive/    MeasurementArchiver (CSV gzip mensuels)
app/Optimizer/  BlockHeating (pilotage optionnel)
app/Charts/     ChartFactory (options ECharts côté serveur)
resources/okofen/catalog.yaml   description de tous les champs (35 mesures CSV, 223 réglages, libellés FR/EN/DE, unités, bornes, aide)
```

### API de la chaudière (Touch V02.00j)

- Connexion : `POST /index.cgi` (`username`, `password`, `language`, `submit=Login`), qui répond 303 avec un cookie de session.
- Lecture : `POST /index.cgi?action=get&attr=1`, corps = tableau JSON de variables (`CAPPL:LOCAL.hk[0].heizkurve_steigung`…).
  **L'en-tête `Accept-Language` est obligatoire**, sinon l'automate renvoie une erreur Lua 500.
  On envoie au plus ~25 variables par requête, sinon l'automate sature. Valeur affichée = valeur brute / `divisor`.
- Écriture : `POST /index.cgi?action=set`, corps = `{"variable": "valeur brute"}`. Chaque écriture est validée
  (bornes, liste de choix), relue pour vérification et journalisée (`boiler_parameter_changes`).
- Fichiers : `GET /logfiles/pelletronic/touch_AAAAMMJJ.csv` (ISO-8859-1, `;`, virgule décimale, une ligne par minute).

### Données

| Table | Contenu | Volume |
|---|---|---|
| `measurements` | mesures minute (colonnes typées, issues du catalogue) | ~525 000 lignes/an |
| `burner_cycles` | cycles détectés : allumage à froid/chaud, durées, kg, ECS/chauffage | ~4 000/an |
| `daily_stats` | synthèse journalière : kg (chauffage/ECS), DJU, cycles, temps de combustion… | 365/an |
| `stock_events` | livraisons, contrôles de niveau, cendrier, ramonage, entretien | |
| `boiler_parameter_changes` | journal des écritures faites par l'application | |
| `boiler_profiles` | instantanés de réglages : profils manuels, imports de l'ancienne appli, et **relevés automatiques** (chaque nuit et après chaque écriture, une ligne seulement si un réglage a changé : historique compact, valeurs de repli hors ligne) | quelques lignes/an |
| `boiler_counters` | compteurs internes quotidiens | |
| `alert_rules`, `notification_channels`, `alert_events` | alertes | |
| `measurement_archives` | index des archives | |

**Archivage** : au-delà de N mois (24 par défaut), les mesures minute sont exportées chaque mois en CSV gzip
(≈ 0,5 Mo/mois contre ≈ 3 Mo en base), contrôlées par relecture complète puis supprimées. Synthèses et cycles restent en
base : historique, signature et comparaisons de saisons ne sont pas affectés, et la journée détaillée du tableau de bord relit l'archive
à la volée. Un mois archivé peut être restauré ou téléchargé.

### Stock de granulés et cendrier

- La consommation est calculée minute par minute : taux de marche de la vis (marche / (marche + pause)) × débit (g/min).
- Le débit est **recalibré automatiquement** entre deux niveaux connus (livraison avec le reste estimé, ou contrôle de niveau) :
  quantité consommée / minutes de vis, sur les périodes où les données couvrent au moins 90 % du temps.
- Le stock actuel est le dernier niveau connu, plus les livraisons, moins la consommation calculée.
  La projection jusqu'à épuisement utilise la signature énergétique et les températures normales de saison.
- Le cendrier est suivi en kg de granulés brûlés depuis le dernier vidage, rapportés à une capacité paramétrable.

## 4. Pages

| Page | Contenu |
|---|---|
| Tableau de bord | état live, indicateurs (T ext/ambiance/chaudière/ECS, kg et cycles du jour, silo, cendrier), alertes, puis la journée choisie (sélecteur de date en haut à gauche) : bilan du jour, courbes minute par minute synchronisées (températures, frise des états brûleur/pompes, combustion), tableau des cycles ; recommandations |
| Historique | consommation chauffage/ECS, cycles et allumages à froid, signature énergétique par saison, distribution des durées de cycle, comparaison des saisons (kg, €, DJU, kg/DJU) |
| Chauffage (loi d'eau) | éditeur visuel (poignées déplaçables et curseurs), nuage des consignes réellement calculées coloré par l'écart d'ambiance, courbe apprise, application à la chaudière |
| Eau chaude | indicateurs (ballon, dernière désinfection ≥ 10 min à 60 °C, prochain cycle anti-légionelles, charges/jour), points d'attention légionelles, journée minute par minute : T ballon, plages ECS, consigne, T mini hors plages et seuil de relance (− hystérésis) avec les réglages en vigueur à chaque instant (reconstitués depuis le journal des modifications), réglages ECS modifiables, charge immédiate, éditeurs des programmes horaires de l'eau chaude et du bouclage |
| Programmes horaires | chauffage uniquement : programme soirée (confort jusqu'à une heure donnée) et vacances (dates, T ambiante), programmes 1 et 2 avec aperçu de la semaine, ajout / suppression de plages, alerte sur les coupures et plages de moins de 2 h |
| Réglages chaudière | tous les réglages du catalogue (essentiels / avancés), recherche, diff avant écriture, profils, journal |
| Optimisation | diagnostic chiffré, besoin vs puissance mini, recommandations applicables en un clic, configuration du pilotage par blocs |
| Silo & cendrier | jauges, stock reconstitué et projection, calibration, saisie rapide (livraison, niveau, cendrier) |
| Alertes | règles (défaut, injoignable, plus de données, silo, cendrier, ambiance, ECS, cycles, entretien, ramonage), canaux, historique |
| Rapport pour LLM | `GET /diagnostic.md` : document Markdown autoportant (installation, réglages avec plages, loi d'eau et observations, fonctionnement jour par jour et cycles, signature, saisons, silo, recommandations, dictionnaire des mesures, mesures 24 h). Accès par session ou `?token=` (Paramètres) ; options `days`, `sample`, `expert=0` |
| Administration | paramètres (connexion, équipements de l'installation, granulés, climat, rétention), données & archives, import de CSV |

## 5. Déploiement sur O2Switch (git pull)

Les assets front (`public/build`) sont compilés en local et **versionnés** : le serveur n'a besoin que de PHP, Composer et Git.

### Première installation

1. **cPanel › Sélectionner une version de PHP** : PHP 8.4 ou plus récent, extensions `pdo_mysql`, `intl`, `zip`, `gd`, `bcmath`.
2. **cPanel › Bases de données MySQL** : créer la base et l'utilisateur, avec tous les privilèges.
3. **Accès SSH (Terminal cPanel)** :
   ```bash
   cd ~
   git clone https://github.com/bertrandgorge/okovision.git okovision
   cd okovision
   composer install --no-dev --optimize-autoloader
   cp .env.example .env    # puis éditer : APP_ENV=production, APP_DEBUG=false, APP_URL, DB_*, MAIL_* (indispensable : la connexion se fait par lien envoyé par e-mail), BOILER_URL
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force          # règles d'alerte par défaut
   php artisan okovision:user moi@mondomaine.fr   # crée le compte et affiche un lien de connexion
   php artisan storage:link
   ```
4. **cPanel › Domaines** : créer le sous-domaine (ex. `chaudiere.mondomaine.fr`) avec comme **racine `okovision/public`**, puis activer le certificat SSL (AutoSSL / Let's Encrypt).
5. **cPanel › Tâches Cron** (une seule) : `*/5 * * * * cd ~/okovision && php artisan schedule:run >> /dev/null 2>&1`.
   Un cron toutes les 30 min ou toutes les heures fonctionne aussi (les tâches quotidiennes sont sur des heures pleines),
   avec un décalage équivalent sur les alertes, et sans pilotage par blocs. Les CSV de la chaudière sont récupérés
   toutes les 30 min (bouton « Actualiser » du tableau de bord pour forcer).
6. **Accès à la chaudière** : redirection d'un port de la box vers `192.168.1.41:80` (idéalement filtrée sur l'IP du serveur O2Switch), changer le mot de passe par défaut du Touch, puis renseigner l'URL publique dans *Paramètres* et cliquer sur « Tester la connexion ».
7. **Historique de l'ancienne application** (une fois) : importer le dump dans une seconde base, renseigner `LEGACY_DB_*` dans `.env`, puis lancer `php artisan okovision:import-legacy` (environ 20 minutes pour 12 ans de données).

### Mises à jour

En local : `npm run build` si le front a changé, puis commit et push. Sur le serveur :

```bash
cd ~/okovision && ./deploy.sh
```

Le script fait `git pull --ff-only`, `composer install --no-dev`, passe en maintenance, lance les migrations, puis reconstruit les caches.

À sauvegarder (en plus de la base) : `.env` et `storage/app/` (archives des mesures).

## 6. Développement local

```bash
docker compose up -d                 # app :8080, ancienne appli :8081, MariaDB :3306, DbGate :3000, Mailpit :8025
docker compose exec -u www-data app php artisan okovision:import-legacy
docker compose exec -u www-data app php artisan test
docker compose --profile scheduler up -d scheduler   # ordonnanceur
```

Commandes : `okovision:fetch`, `okovision:aggregate {from} {to}`, `okovision:recalibrate`, `okovision:alerts`,
`okovision:optimize`, `okovision:snapshot`, `okovision:archive [mois] [--restore] [--keep-in-db]`, `okovision:counters`, `okovision:import-legacy`.
