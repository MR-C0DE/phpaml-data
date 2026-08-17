# Matrice de validation locale

Pré-requis : Docker avec Compose, PHP 8.2+ et les extensions `pdo_mysql`, `pdo_pgsql` et `mongodb`. Le package `phpaml/data-mongodb` doit avoir reçu un `composer install` afin de fournir `MongoDB\Client`.

```bash
cd runtime/build/phpaml-data-mongodb
composer install
cd ../phpaml-data
sh qa/run-matrix.sh
```

La matrice démarre des conteneurs temporaires MySQL 8.4, MariaDB 11.4, PostgreSQL 17 et MongoDB 8 en replica set. Elle utilise uniquement la base `phpaml_data_test`, puis détruit les conteneurs et volumes créés par ce fichier Compose.

Le workflow `.github/workflows/ci.yml` couvre également PHP 8.2, 8.3 et 8.4. Il ne contient aucune étape de publication, de release ou de déploiement.
