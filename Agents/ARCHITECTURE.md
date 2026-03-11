# ARCHITECTURE.md — ClicShopping AI v4.20+

> Architecture core du framework PHP.
> Règles agents : `AGENTS.md` | AI : `AI_SYSTEM.md` | DB : `DATABASE.md` | Sécurité : `SECURITY.md`

---

## 1. Vue d'ensemble

ClicShopping AI est organisé en deux sites applicatifs (`Shop` et `ClicShoppingAdmin`)
partageant un core commun. La couche AI est agnostique et organisé pour utiliser l approche par domaine métier — voir `AI_SYSTEM.md`.

```
AGENTS.md          ← règles opérationnelles pour agents LLM
ARCHITECTURE.md    ← framework core, bootstrap, hooks, templates, namespaces
AI_SYSTEM.md       ← agents, RAG, LLM providers, embeddings
DATABASE.md        ← MariaDB, schéma SQL, routing, migrations
SECURITY.md        ← 10 couches sécurité, guardrails, GDPR
```

---

## 2. Bootstrap et routing

Flux d'initialisation — NE PAS altérer :

```
index.php
  → CLICSHOPPING::initialize()
  → Détermination du site (Shop | ClicShoppingAdmin)
  → Services core : Db, Session, Language
  → setPage()  — résolution du contrôleur via paramètre URL
  → Exécution contrôleur (implémente PagesInterface)
```

Structure d'une page contrôleur :
```
Core/ClicShopping/Sites/{Site}/Pages/{PageName}/
├── {PageName}.php     # Implémente PagesInterface
└── Actions/           # Actions de la page (POST, traitements)
```

---

## 3. Registry de services

Pattern de registre central — accès uniforme à tous les services.

```php
$db  = \ClicShopping\OM\Registry::get('Db');
\ClicShopping\OM\Registry::set('MonService', new MonService());
```

Services core disponibles :

| Clé | Rôle |
|---|---|
| `Db` | Service DB basé sur `\ClicShopping\OM\Db` (extends `PDO`) |
| `Session` | Redis / Database / File |
| `Language` | Support multilingue |
| `Cookies` | Gestion cookies |
| `Hooks` | Système d'événements |
| `Service` | Conteneur modulaire |
| `Template` | Rendu front-office (Shop) |
| `TemplateAdmin` | Rendu back-office (ClicShoppingAdmin) |

> **Registry vs DI :** ClicShopping utilise le Registry comme service locator.
> Ne pas créer de conteneur DI alternatif — utiliser `Registry::set/get`.

---

## 4. Système de Hooks

Mécanisme principal d'extensibilité. Toujours évaluer les hooks avant toute autre approche.

```php
// Core/ClicShopping/Apps/{Vendor}/{App}/Module/Hooks/{Site}/{HookName}/{HookName}.php
namespace ClicShopping\Apps\Vendor\App\Module\Hooks\Shop\MyHook;

class MyHook
{
    public function execute(): string
    {
        return '<!-- contenu injecté -->';
    }
}
```

Règles :
- Enregistrement via le mécanisme existant — pas d'appel manuel
- Ne pas court-circuiter le loader de hooks
- Documenter les points d'extension utilisés dans le commit

Découvrir les hooks disponibles dans une portée :
```bash
grep -r "Hooks" Core/ClicShopping/Sites/{Site}/ --include="*.php" -l
```

---

## 5. Namespaces et autoload

```
ClicShopping\OM\                   → Core/ClicShopping/OM/
ClicShopping\Apps\{Vendor}\{App}\  → Core/ClicShopping/Apps/{Vendor}/{App}/
ClicShopping\Custom\               → Core/ClicShopping/Custom/
```

Le chargement des classes est assuré par `CLICSHOPPING::autoload` (core) et Composer pour `External/vendor`.
Ne jamais créer de mécanisme d'autoload alternatif.

---

## 6. Templates — Front-office vs Back-office

Documentation complète : **`TEMPLATES.md`**

Résumé des points clés pour la navigation dans ARCHITECTURE.md :

| Aspect | Shop (Front-office) | ClicShoppingAdmin (Back-office) |
|---|---|---|
| Service Registry | `Template` | `TemplateAdmin` |
| Résolution | App → thème global (fallback) | App uniquement — pas de fallback |
| Cache | Oui — pages catalogue | Non — données fraîches |
| SEO | Critique | Non applicable |

Règle cible pour tout nouveau code : **aucune logique métier, aucun accès DB, aucune chaîne hardcodée.**  
Note : certains templates de pages historiques (`Sites/*/Pages/*/templates`) contiennent de la logique d'orchestration.
Voir `TEMPLATES.md` pour les structures, helpers, SEO, i18n et checklist complète.

---

## 7. Langues — Résolution des couches

| Couche | Chemin | Portée |
|---|---|---|
| **App / Module** | `Core/ClicShopping/Apps/*/languages/{lang}/` | Priorité haute pour les Apps (Shop et Admin) |
| **Admin core** | `ClicShoppingAdmin/Core/languages/{lang}/` | Libellés globaux du back-office |
| **Global / Thème** | `sources/languages/{lang}/` | Textes transversaux et fallback front-office |

Règles :
- Aucune chaîne visible hardcodée dans PHP ou templates
- Compatibilité minimum : EN + FR
- Format conforme aux fichiers existants dans la portée cible

---

## 8. Custom/ — Override core

`Core/ClicShopping/Custom/` permet de surcharger `OM/` sans le modifier directement.

### Ordre de priorité d'extensibilité

```
1. Hook existant     → solution prioritaire
2. Module            → Core/ClicShopping/Apps/*/Module/, auto-contenu
3. Nouvelle App      → Core/ClicShopping/Apps/{Vendor}/{AppName}/
4. Custom/           → override core, si 1-3 impossibles
5. OM/ direct        → INTERDIT sans accord du propriétaire
```

### Structure

```
Core/ClicShopping/Custom/
├── OM/      # Surcharge classes kernel (extends obligatoire)
├── Conf/    # Configuration custom
├── Sites/   # Surcharge bootstrap Shop ou Admin
└── Schema/  # Tables supplémentaires (fichiers *.txt)
```

### Exemple

```php
namespace ClicShopping\Custom\OM;

class Http extends \ClicShopping\OM\Http
{
    public private(set) string $status = 'idle'; // PHP 8.4

    public function get(string $url, array $options = []): string
    {
        return parent::get($url, $options);
    }
}

// Enregistrement
\ClicShopping\OM\Registry::set('Http', new \ClicShopping\Custom\OM\Http());
```

Règles Custom/ :
- `extends` obligatoire — jamais de copier-coller de code core
- Namespace : `ClicShopping\Custom\{Sous-espace}\{Classe}`
- Ne pas briser la rétrocompatibilité des modules existants

---

## 9. Cache — Architecture 5 tiers

| Tier | Technologie | Portée |
|---|---|---|
| 1 | OpCache | Bytecode PHP |
| 2 | Cache statique | Pages catalogue Shop pré-rendues |
| 3 | Memcached | Cache distribué multi-serveurs |
| 4 | Redis | Sessions + données applicatives |
| 5 | APCu | Cache espace utilisateur |

Ne pas introduire de sixième mécanisme sans accord explicite.

---

## 10. Sessions

Trois backends avec fallback automatique :
1. **Redis** — préféré. `localhost:6379`, prefix `sess_`, TTL = `session.gc_maxlifetime`
2. **Database** — persistant, stockage en table
3. **File** — fallback PHP natif

---

## 11. Références croisées

| Sujet | Fichier |
|---|---|
| Règles opérationnelles agents | `AGENTS.md` |
| Système AI, agents, RAG, LLM | `AI_SYSTEM.md` |
| Base de données, SQL, embeddings | `DATABASE.md` |
| Sécurité, guardrails, GDPR | `SECURITY.md` |
| Templates, rendu, SEO, i18n | `TEMPLATES.md` |
| Wiki officiel | https://github.com/ClicShopping/ClicShopping/wiki |
| DeepWiki | https://deepwiki.com/ClicShopping/ClicShopping |
