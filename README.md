# Okovision 2

Suivi, analyse et réglage d'une chaudière à granulés **Okofen Pellematic** (régulateur KEBA / Pelletronic Touch).

- Récupère chaque minute les mesures de la chaudière (fichiers CSV) et lit/écrit ses réglages par son API web.
- Tableau de bord, vue minute par minute, historique et comparaison des saisons, signature énergétique.
- Réglage visuel de la loi d'eau, avec la formule exacte du régulateur et la courbe apprise sur les mesures.
- Détection des cycles brûleur et recommandations pour limiter les cycles courts, avec un pilotage optionnel.
- Stock de granulés estimé à partir du comptage de la vis (calibré sur les livraisons) et projection jusqu'à épuisement.
- Suivi du cendrier, de l'entretien et du ramonage.
- Rapport Markdown `/diagnostic.md` à fournir à un LLM pour diagnostic et propositions de réglages.
- Connexion sans mot de passe, par lien magique envoyé par e-mail ; on reste connecté sur l'appareil.
- Alertes configurables (e-mail, Slack, Telegram, webhook) et archivage des données anciennes.

Stack : Laravel 13, PHP 8.5, MySQL/MariaDB, Filament 5, ECharts 6.

Site de présentation, guide de réglage et guide d'installation : https://bertrandgorge.github.io/okovision/ (sources dans `site/`).

- Analyse, choix techniques, déploiement O2Switch : [docs/CONCEPTION.md](docs/CONCEPTION.md)
- Description des champs de la chaudière : [resources/okofen/catalog.yaml](resources/okofen/catalog.yaml)
- Ancienne application (pour référence) : `legacy/`

```bash
docker compose up -d                     # http://localhost:8080 (nouvelle), :8081 (ancienne), DbGate :3000
docker compose exec -u www-data app composer install
docker compose exec -u www-data app npm ci && npm run build
docker compose exec -u www-data app php artisan migrate --seed
docker compose exec -u www-data app php artisan okovision:import-legacy
docker compose exec -u www-data app php artisan okovision:user moi@example.com   # crée le compte et affiche un lien de connexion
```

## Origine

Ce dépôt est un fork d'[Okovision](https://github.com/stawen/okovision) de Stawen Dronek, projet qui n'est plus maintenu.
L'application a été entièrement réécrite (Laravel/Filament) ; le code d'origine est conservé dans `legacy/`, avec ses
mentions d'auteur, pour référence et pour l'import de l'historique.

Déploiement sur O2Switch par `git pull` : voir [docs/CONCEPTION.md](docs/CONCEPTION.md#5-déploiement-sur-o2switch-git-pull) et `deploy.sh`.
