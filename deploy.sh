#!/usr/bin/env bash
# Déploiement sur O2Switch (ou tout hébergement PHP sans Node) : à lancer dans le dossier de l'application.
#   ./deploy.sh
# Prérequis : .env configuré, PHP 8.4+ en CLI (sélectionné dans cPanel), composer disponible.
set -euo pipefail
cd "$(dirname "$0")"

PHP=${PHP:-php}
COMPOSER=${COMPOSER:-composer}

echo "→ Mise à jour du code"
git pull --ff-only

echo "→ Dépendances PHP"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "→ Maintenance"
$PHP artisan down --retry=30 || true
trap '$PHP artisan up' EXIT

echo "→ Base de données"
$PHP artisan migrate --force

echo "→ Caches"
$PHP artisan optimize:clear
$PHP artisan optimize
$PHP artisan filament:optimize

echo "✓ Déployé : $(git log -1 --format='%h %s')"
