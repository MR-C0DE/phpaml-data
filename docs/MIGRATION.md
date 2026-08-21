# Migration depuis PHPAML\Data

L'ancienne couche du framework reste utilisable pendant la transition. Les nouveaux développements doivent importer `AML\Data`.

## Connexion

```php
// Ancien code
$legacy = new PHPAML\Data\Connection($dsn, $user, $password);

// Pont temporaire : le même objet PDO est conservé.
$connection = AML\Data\Compatibility\LegacyConnectionAdapter::adapt($legacy);
```

Pour un nouveau projet, préférez directement `ConnectionManager` et la section
`data` de `phpaml.json`. Les valeurs sensibles ou propres à l'environnement
restent dans `.env`.

## Requêtes

```php
// Ancien code
$rows = (new PHPAML\Data\QueryBuilder($legacy))->all('users');

// Nouveau code typé
$users = $context->set(User::class)->orderBy('name')->all();
```

## Contextes

Les anciens contextes étendent `PHPAML\Data\DbContext`. Créez un nouveau contexte étendant `AML\Data\DbContext`, puis exposez des méthodes retournant `DbSet<Entity>`.

## Migrations

Les migrations historiques peuvent rester dans leur dossier jusqu'à leur dernière exécution. Les nouvelles migrations étendent `AML\Data\Migrations\Migration` et utilisent `AML\Data\Schema\Schema`. Ne mélangez pas les deux historiques dans une même commande : l'ancien outil utilise `aml_migrations`, le nouveau utilise `aml_data_migrations`.

## Modèles

Déplacez progressivement les modèles de `app/Models/` vers `src/models/`, sans supprimer les anciens fichiers tant que tous leurs imports n'ont pas été adaptés. Le générateur `aml make:model` utilise désormais `src/models/`.
