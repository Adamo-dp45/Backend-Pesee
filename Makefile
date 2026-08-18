.PHONY: install deploy db-reset fixtures test stan lint keys

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