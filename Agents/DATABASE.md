# DATABASE.md — ClicShopping AI v4.20+

> Database layer: engine, schema, SQL routing, vector embeddings, migrations.
> Agent operational rules: `AGENTS.md` — AI Embeddings: `AI_SYSTEM.md`

---

## 1. Engine required

**MariaDB ≥ 11.7 — required.**

MySQL 9.x is **incompatible** with the project's native vector features.

| Feature | Requirement |
|---|---|
| Vector type | MariaDB native `VECTOR` |
| Vector index | `VECTOR INDEX` native MariaDB |
| JSON | JSON `metadata` column (v4.11+) |
| Dimensions | Respect the existing dimensions per table (3072) |

Cloud or PaaS environments: explicitly check the MariaDB version before any deployment.
If in doubt, query `SELECT VERSION();` and validate ≥ 11.7.

---

## 2. Database access

Always go through the Registry `Db` service — never a direct PDO connection.

```php
// Correct access
$db = \ClicShopping\OM\Registry::get('Db');
$result = $db->query('SELECT ...');

// Forbidden
$pdo = new \PDO('mysql:host=...'); // parallel connection outside Registry
```

The `Db` service is based on `\ClicShopping\OM\Db` (extends `PDO`). It manages:
- Single connection per request
- Parameter typing
- MariaDB compatibility


### Except the directory Core/ClicShopping/AI
PARADIGM SHIFT:
    Inside Core/ClicShopping/AI/: Use Doctrine ORM only.
    Everywhere else: Use \ClicShopping\OM\Db (Registry).
    NEVER mix both paradigms in the same file. If a script moves data from AI to Core, use the Registry for the final insertion.

- Example:
  - save data :
```
    $sql_data_array = [
    'parent_id' => (int)$new_parent_id,
    'sort_order' => (int)$sort_order
    ];

    $update_sql_data = ['last_modified' => 'now()'];
    $sql_data_array = array_merge($sql_data_array, $update_sql_data);
    $this->app->db->save('categories', $sql_data_array, ['categories_id' => (int)$categories_id]);
```
- insert data :
```
    $sql_data_array = [
      'parent_id' => (int)$new_parent_id,
      'sort_order' => (int)$sort_order
      'last_modified' => 'now()'
     ];

    $this->app->db->save('categories', $sql_data_array);
```

### Only the directory Core/ClicShopping/AI
PARADIGM SHIFT:
Inside Core/ClicShopping/AI/: Use Doctrine ORM only.
Everywhere else: Use \ClicShopping\OM\Db (Registry).
NEVER mix both paradigms in the same file. If a script moves data from AI to Core, use the Registry for the final insertion.

it's only exception because the AI directory must be stay agnostic
- use the convention from Doctrine ORM framework defined in AI directory

---

## 3. SQL Routing — Which file, where

Five distinct slots with non-interchangeable roles:

| Location | Role                                                         | Agent access |
|---|--------------------------------------------------------------|---|
| `Core/ClicShopping/Schema/MariaDb/` | Canonical schema of all tables — truth reference             | Read only |
| `install/Db/*.sql` | Initial seed data for fresh installation populate the db  | Read only |
| `Core/ClicShopping/Apps/{Vendor}/{AppName}/Sql/MariaDb/` | Application SQL specific to the App (initial CREATE, INSERT) | **Writing** |
| `Core/ClicShopping/Custom/Schema/` | Additional tables related to Custom overloads/ (*.txt files) | **Writing** |
| `sql_upgrade/` | Migration guide **for end user** — documentation only        | Read only |

### Decision rule

```
New table for an App → Core/ClicShopping/Apps/{Vendor}/{AppName}/Sql/MariaDb/
New table for an overload → Core/ClicShopping/Custom/Schema/
Understanding the existing schema → Core/ClicShopping/Schema/MariaDb/ (reading)
Migrating from one version to another → sql_upgrade/ (documentation, no code)
```

**Never write application SQL in `sql_upgrade/`.**
This directory is a documentary guide for the user to understand the changes
between versions — it is not executed automatically by the system.

---

## 4. SQL Scripts of an App — Actual Structure Observed

The Apps mainly use:

```
Core/ClicShopping/Apps/{Vendor}/{AppName}/Sql/MariaDb/
├── MariaDb.php # main installation/migration when the app is activated
├── *.sql # targeted migrations (if necessary)
└── utility scripts *.php # install/repair targeted according to App
```

Writing rules:
- Use `CREATE TABLE IF NOT EXISTS` — never `CREATE TABLE` alone
- Encapsulate destructive deletions in explicitly dedicated scripts
- Backwards compatible schema — no `DROP COLUMN` without explicit migration
- Table prefix: `clic_` (project convention)
- Encoding: `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
  Minimal example of creation:
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

## 5. Canonical schema — Core/ClicShopping/Schema/MariaDb/

`Core/ClicShopping/Schema/MariaDb/` contains the complete definition of all tables for
a fresh installation. This is the **source of truth** of the overall schema.

Use for agents:
- Read before creating a new table (avoid duplicates or naming conflicts)
- Check the structure of existing tables before writing queries
- Never modify these files without the human coder consent

---

## 6. AI embedding tables

These tables are managed by the AI pipeline — do not modify them manually.

### Embedding tables present in the schema

| Table | Entity |
|---|---|
| `clic_products_embedding` | Products |
| `clic_categories_embedding` | Categories |
| `clic_reviews_embedding` | Customer reviews |
| `clic_reviews_sentiment_embedding` | Review Sentiment |
| `clic_orders_embedding` | Orders |
| `clic_pages_manager_embedding` | CMS Pages |
| `clic_manufacturers_embedding` | Manufacturers |
| `clic_suppliers_embedding` | Suppliers |
| `clic_return_orders_embedding` | Order returns |
| `clic_conversation_memory_embedding` | Conversational memory |
| `clic_correction_pattern_embedding` | Correction Patterns |

### Common structure

```sql
CREATE TABLE `clic_*_embedding` ( 
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, 
`entity_id` INT UNSIGNED NOT NULL, -- FK to source table 
`embedding` VECTOR(3072) NOT NULL, -- semantic vector
`content` TEXT NOT NULL, -- indexed original text 
`metadata` JSON, -- enrichment (v4.11+) 
`chunknumber` INT UNSIGNED NOT NULL DEFAULT 0, -- Sequential index of this chunk within the entity (chunk size = 128 tokens) 
`date_modified` DATETIME NOT NULL, 
PRIMARY KEY (`id`), 
VECTOR INDEX `vec_idx` (`embedding`)
) ENGINE=InnoDB;
```

### Embedding rules

- Vector dimensions: respect the existing diagram (3072)
- Generation via existing crons — do not recreate this pipeline
- Do not modify the sql structure without agreement from the human coder
- `metadata` JSON is optional but must remain present in the schema

---

## 7. Migrations and Schema Updates

### General rules

- Any schema change must be **backwards compatible**
- Never delete a column without checking all dependencies
- Never rename an existing table or column without a migration script
- Prefer `ADD COLUMN` + data migration rather than destructive `MODIFY COLUMN`

### sql_upgrade/ — correct usage

`sql_upgrade/` contains **documentary** text files listing SQL changes
between two versions. This is not a directory of automatically executable scripts.

Expected use:
- User reads `sql_upgrade/updateX_XX.txt` to understand which ALTER TABLEs to apply
- The agent must **never** generate a file in this directory

---

## 8. Security and monitoring tables

These tables are managed by the security and monitoring layers — do not modify them:

| Table                          | Role |
|--------------------------------|---|
| `clic_api_rate_limit`          | Tracking requests by identifier + timestamp |
| `clic_api_failed_attempts`     | Failed login attempts |
| `clic_rag_security_events`     | AI Security Event Audit |
| `clic_mcp_performance_history` | MCP metrics (latency, uptime, errors) |

---

## 9. Absolute prohibitions

```
✗ Direct PDO connection outside the Registry Db service
✗ MySQL 9.x (VECTOR incompatible)
✗ MariaDB < 11.7
✗ Automatic DROP TABLE outside explicit maintenance script
✗ Application SQL in sql_upgrade/
✗ Modify the structure of *_embedding tables without human coder agreement
✗ Modify an existing vector dimension without without human coder agreement
✗ CREATE TABLE without IF NOT EXISTS
✗ Schema not backwards compatible
✗ Table prefix different from clic_
```

---

## 10. References

- Architecture framework: `ARCHITECTURE.md`
- Pipeline embeddings: `AI_SYSTEM.md` §6
- Security audit tables: `SECURITY.md` §5
- DeepWiki DB: https://deepwiki.com/ClicShopping/ClicShopping/2.2-database-schema-and-version-migrations
- Db architecture : https://github.com/ClicShopping/ClicShopping/wiki/Tech-Database