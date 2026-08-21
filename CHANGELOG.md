# Changelog

## 0.2.0-alpha.1 — 2026-08-21

- configure les projets modernes dans la section `data` de `phpaml.json` ;
- charge la configuration normalisée et les surcharges `.env` dans les
  commandes Data ;
- ne crée plus `configs/data.php` dans une application moderne ;
- conserve `configs/data.php` pour les projets historiques ;
- ajoute les tests d’intégration avec les API générées par AML CLI.

## 0.1.0-alpha.4 — 2026-08-19

- déplace migrations et seeders dans `runtime/database/` ;
- met à jour l’installateur, les générateurs, la configuration et les tests ;
- garde la base SQLite dans `runtime/storage/database.sqlite`.

## 0.1.0-alpha.3 — 2026-08-17

- conservation cohérente des identifiants SQL explicitement initialisés ;
- mise à jour vide traitée sans produire de requête SQL invalide ;
- tests d’identité et de persistance renforcés.

## 0.1.0-alpha.2 — 2026-08-17

- manifeste d'installation aligné sur la version publiée;
- exécutable AML compatible avec l'autoload Composer du projet;
- documentation actualisée après la validation multi-SGBD.

## 0.1.0-alpha.1 — 2026-08-17

- noyau typé `Entity`, `DbContext` et `DbSet`;
- CRUD, requêtes, pagination, validation et transactions;
- relations et chargement groupé;
- suivi automatique et unité de travail;
- migrations, seeders, verrouillage et constructeur de schéma;
- SQLite, MySQL, MariaDB et PostgreSQL validés sur de vrais serveurs;
- connexions nommées, diagnostics et commandes AML;
- générateurs et compatibilité avec l'ancienne connexion PHPAML;
- PHPStan au niveau maximal.
