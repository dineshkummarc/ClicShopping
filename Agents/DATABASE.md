# DATABASE.md — ClicShopping AI v4.20+

> Couche base de données : moteur, schéma, SQL routing, embeddings vectoriels, migrations.
> Règles opérationnelles agents : `AGENTS.md` — Embeddings AI : `AI_SYSTEM.md`

---

## 1. Moteur requis

**MariaDB ≥ 11.7 — obligatoire.**

MySQL 9.x est **incompatible** avec les fonctionnalités vectorielles natives du projet.

| Fonctionnalité | Exigence |
|---|---|
| Type vectoriel | `VECTOR` natif MariaDB |
| Index vectoriel | `VECTOR INDEX` natif MariaDB |
| JSON | Colonne `metadata` JSON (v4.11+) |
| Dimensions | Respecter les dimensions existantes par table (3072 ou 1536) |

Environnements cloud ou PaaS : vérifier explicitement la version MariaDB avant tout déploiement.
En cas de doute, interroger `SELECT VERSION();` et valider ≥ 11.7.

---

## 2. Accès base de données

Toujours passer par le service `Db` du Registry — jamais de connexion PDO directe.

```php
// Accès correct
$db = \ClicShopping\OM\Registry::get('Db');
$result = $db->query('SELECT ...');

// Interdit
$pdo = new \PDO('mysql:host=...'); // connexion parallèle hors Registry
```

Le service `Db` est basé sur `\ClicShopping\OM\Db` (extends `PDO`). Il gère :
- La connexion unique par requête
- Le typage des paramètres
- La compatibilité MariaDB

---

## 3. Routing SQL — Quel fichier, où

Quatre emplacements distincts avec des rôles non interchangeables :

| Emplacement | Rôle | Accès agent |
|---|---|---|
| `Core/ClicShopping/Schema/MariaDb/` | Schéma canonique de toutes les tables — référence de vérité | Lecture seule |
| `install/Db/*.sql` | Données initiales de peuplement pour installation fraîche | Lecture seule |
| `Core/ClicShopping/Apps/{Vendor}/{AppName}/Sql/MariaDb/` | SQL applicatif propre à l'App (CREATE, INSERT initiaux) | **Écriture** |
| `Core/ClicShopping/Custom/Schema/` | Tables supplémentaires liées aux surcharges Custom/ (fichiers *.txt) | **Écriture** |
| `sql_upgrade/` | Guide de migration **pour l'utilisateur final** — documentation uniquement | Lecture seule |

### Règle de décision

```
Nouvelle table pour une App         → Core/ClicShopping/Apps/{Vendor}/{AppName}/Sql/MariaDb/
Nouvelle table pour une surcharge   → Core/ClicShopping/Custom/Schema/
Comprendre le schéma existant       → Core/ClicShopping/Schema/MariaDb/ (lecture)
Migration d'une version à l'autre   → sql_upgrade/ (documentation, pas de code)
```

**Ne jamais écrire de SQL applicatif dans `sql_upgrade/`.**  
Ce répertoire est un guide documentaire pour que l'utilisateur comprenne les changements
entre versions — il n'est pas exécuté automatiquement par le système.

---

## 4. Scripts SQL d'une App — Structure réelle observée

Les Apps utilisent principalement :

```
Core/ClicShopping/Apps/{Vendor}/{AppName}/Sql/MariaDb/
├── MariaDb.php                 # installation/migration principale
├── *.sql                       # migrations ciblées (si nécessaire)
└── scripts utilitaires *.php   # install/repair ciblé selon App
```

Règles de rédaction :
- Utiliser `CREATE TABLE IF NOT EXISTS` — jamais `CREATE TABLE` seul
- Encapsuler les suppressions destructives dans des scripts explicitement dédiés
- Schéma rétrocompatible — pas de `DROP COLUMN` sans migration explicite
- Prefix de table : `clic_` (convention projet)
- Encoding : `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`

Exemple minimal de création :
```sql
CREATE TABLE IF NOT EXISTS `clic_my_feature` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label` VARCHAR(255) NOT NULL DEFAULT '',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `date_added` DATETIME NOT NULL,
  `date_modified` DATETIME,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## 5. Schéma canonique — Core/ClicShopping/Schema/MariaDb/

`Core/ClicShopping/Schema/MariaDb/` contient la définition complète de toutes les tables pour
une installation fraîche. C'est la **source de vérité** du schéma global.

Usage pour les agents :
- Lire avant de créer une nouvelle table (éviter les doublons ou conflits de nommage)
- Vérifier la structure des tables existantes avant d'écrire des requêtes
- Ne jamais modifier ces fichiers sans accord du propriétaire

---

## 6. Tables d'embedding AI

Ces tables sont gérées par le pipeline AI — ne pas les modifier manuellement.

### Tables d'embedding présentes dans le schéma

| Table | Entité |
|---|---|
| `clic_products_embedding` | Produits |
| `clic_categories_embedding` | Catégories |
| `clic_reviews_embedding` | Avis clients |
| `clic_reviews_sentiment_embedding` | Sentiment des avis |
| `clic_orders_embedding` | Commandes |
| `clic_pages_manager_embedding` | Pages CMS |
| `clic_manufacturers_embedding` | Fabricants |
| `clic_suppliers_embedding` | Fournisseurs |
| `clic_return_orders_embedding` | Retours commandes |
| `clic_conversation_memory_embedding` | Mémoire conversationnelle |
| `clic_correction_pattern_embedding` | Patterns de correction |

### Structure commune

```sql
CREATE TABLE `clic_*_embedding` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_id`     INT UNSIGNED NOT NULL,        -- FK vers table source
  `embedding`     VECTOR(3072) NOT NULL,         -- vecteur sémantique (certaines tables sont en 1536)
  `content`       TEXT NOT NULL,                 -- texte original indexé
  `metadata`      JSON,                          -- enrichissement (v4.11+)
  `chunknumber`   INT UNSIGNED NOT NULL DEFAULT 0, -- numéro de chunk (128 tokens)
  `date_modified` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  VECTOR INDEX `vec_idx` (`embedding`)
) ENGINE=InnoDB;
```

### Règles embedding

- Dimensions vectorielles : respecter le schéma existant (3072 et 1536 selon table)
- Génération via les crons existants — ne pas recréer ce pipeline
- Ne pas modifier la structure sans accord du propriétaire
- `metadata` JSON est optionnel mais doit rester présent dans le schéma

---

## 7. Migrations et mises à jour de schéma

### Règles générales

- Tout changement de schéma doit être **rétrocompatible**
- Ne jamais supprimer une colonne sans vérifier toutes les dépendances
- Ne jamais renommer une table ou colonne existante sans script de migration
- Préférer `ADD COLUMN` + migration de données plutôt que `MODIFY COLUMN` destructif

### sql_upgrade/ — usage correct

`sql_upgrade/` contient des fichiers texte **documentaires** listant les changements SQL
entre deux versions. Ce n'est pas un répertoire de scripts exécutables automatiquement.

Usage attendu :
- L'utilisateur lit `sql_upgrade/updateX_XX.txt` pour comprendre quels ALTER TABLE appliquer
- L'agent ne doit **jamais** générer de fichier dans ce répertoire

---

## 8. Tables de sécurité et monitoring

Ces tables sont gérées par les couches sécurité et monitoring — ne pas les modifier :

| Table | Rôle |
|---|---|
| `clic_api_rate_limit` | Suivi des requêtes par identifiant + timestamp |
| `clic_api_failed_attempts` | Tentatives de login échouées |
| `rag_security_events` | Audit des événements de sécurité AI |
| `clic_mcp_performance_history` | Métriques MCP (latence, uptime, erreurs) |

---

## 9. Interdictions absolues

```
✗ Connexion PDO directe hors du service Db du Registry
✗ MySQL 9.x (incompatible VECTOR)
✗ MariaDB < 11.7
✗ DROP TABLE automatique hors script de maintenance explicite
✗ SQL applicatif dans sql_upgrade/
✗ Modifier la structure des tables *_embedding sans accord
✗ Modifier une dimension vectorielle existante sans validation propriétaire
✗ CREATE TABLE sans IF NOT EXISTS
✗ Schéma non rétrocompatible
✗ Prefix de table différent de clic_
```

---

## 10. Références

- Architecture framework : `ARCHITECTURE.md`
- Pipeline embeddings : `AI_SYSTEM.md` §6
- Sécurité tables audit : `SECURITY.md` §5
- DeepWiki DB : https://deepwiki.com/ClicShopping/ClicShopping/2.2-database-schema-and-version-migrations
