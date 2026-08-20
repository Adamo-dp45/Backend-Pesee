.PHONY: install deploy cron db-reset fixtures numeros test stan lint keys

## ------------------------------------------------------------------
## Déploiement
## ------------------------------------------------------------------

# deploy: -- Si on a une connexion ssh
#	ssh .. 'cd sites/api.webpesee.com && git pull origin main && make install'

install: # Recette de mise en production, '-n' pour qu'il fasse de manière silencieuse
	git pull origin main
	composer install --no-dev --optimize-autoloader
	php bin/console doctrine:migrations:migrate --no-interaction
	php bin/console cache:clear --env=prod
	php bin/console cache:warmup --env=prod

## ------------------------------------------------------------------
## Tâches planifiées
## ------------------------------------------------------------------

# Les lignes à coller dans le crontab du serveur ('crontab -e'). À faire une fois,
# à la première mise en production.
#
# Pourquoi un cron et pas le Scheduler de Symfony : celui-ci suppose un worker qui
# tourne en permanence, là où un hébergement mutualisé ne sait offrir qu'un cron.
#
# Pourquoi 'flock' : deux exécutions qui se chevauchent pourraient réinterroger le
# même versement en même temps et le rembourser deux fois. '-n' préfère sauter un
# tour plutôt que d'empiler les processus.
#
# Si ce cron s'arrête, l'écran Caisse le dit : les versements en vol s'y accumulent
# avec leur ancienneté. C'est le seul témoin — il n'y a personne pour surveiller le
# cron lui-même.
cron:
	@echo "*/5 * * * * /usr/bin/flock -n /tmp/webpesee-reconcilier.lock php $(CURDIR)/bin/console app:paiements:reconcilier --env=prod >> $(CURDIR)/var/log/cron.log 2>&1"

## ------------------------------------------------------------------
## Développement
## ------------------------------------------------------------------

# Remet la base de développement à zéro puis rejoue toutes les migrations.
# À utiliser sans hésiter tant qu'il n'y a pas de données réelles : c'est ce qui
# garantit que la migration initiale décrit bien le schéma complet.
db-reset:
	php bin/console doctrine:database:drop --force --if-exists
	php bin/console doctrine:database:create
	php bin/console doctrine:migrations:migrate --no-interaction
	php bin/console doctrine:schema:validate

# Reprise à passer UNE FOIS sur une base antérieure à la normalisation des numéros.
# Les planteurs saisis avant gardent la forme dans laquelle ils ont été tapés et
# resteraient impayables. '--dry-run' d'abord, pour voir ce qui serait réécrit.
numeros:
	php bin/console app:fournisseurs:normaliser-numeros --dry-run

# Charge le jeu de démonstration (entreprises, sites, opérateurs, pesées, caisse).
fixtures:
	php bin/console doctrine:fixtures:load --no-interaction

# Prépare la base de test puis lance la suite complète.
test:
	php bin/console doctrine:database:drop --force --if-exists --env=test
	php bin/console doctrine:database:create --env=test
	php bin/console doctrine:migrations:migrate --no-interaction --env=test
	vendor/bin/phpunit

# Analyse statique. Le conteneur doit être compilé pour que 'phpstan-symfony'
# connaisse les services, d'où le 'cache:warmup' préalable.
stan:
	php bin/console cache:warmup --env=dev
	vendor/bin/phpstan analyse --memory-limit=1G

# Vérifications rapides avant de committer.
lint:
	php bin/console lint:container
	php bin/console lint:yaml config --parse-tags
	php bin/console lint:twig templates
	php bin/console doctrine:schema:validate --skip-sync

# Régénère la paire de clés JWT en lisant JWT_PASSPHRASE depuis '.env.local'.
# Invalide tous les jetons existants.
keys:
	php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction