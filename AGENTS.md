# AGENTS.md — ClicShopping AI v4.20+

Plateforme e-commerce PHP **native AI-first** (B2B / B2C / hybride).  
Ce projet **n'est pas** osCommerce ni un fork legacy ClicShopping V2/V3.

---

## Règle absolue

```
INTERDIT : générer du code sans avoir lu les fichiers existants dans la portée cible.
INTERDIT : supposer une structure sans vérification dans le dépôt réel.
```

Avant tout code : lire les fichiers existants → vérifier les hooks → modification minimale.

---

## Documentation de référence

Toute la documentation technique est dans le répertoire `Agents/`.

| Fichier | Contenu |
|---|---|
| `Agents/ARCHITECTURE.md` | Bootstrap, routing, Registry, hooks, namespaces, Custom/, cache |
| `Agents/AI_SYSTEM.md` | Agents, RAG, LLM providers, raisonnement, embeddings, MCP |
| `Agents/DATABASE.md` | MariaDB 11.7+, schéma SQL, routing des fichiers SQL, migrations |
| `Agents/SECURITY.md` | 10 couches sécurité, guardrails AI, rate limiting, GDPR |
| `Agents/TEMPLATES.md` | Rendu front-office vs back-office, helpers, SEO, i18n |

---

## Ordre de priorité d'extensibilité

```
1. Hook existant     → toujours vérifier en premier
2. Module            → Core/ClicShopping/Apps/*/Module/
3. Nouvelle App      → Core/ClicShopping/Apps/{Vendor}/{AppName}/
4. Custom/           → override core sans modifier Core/ClicShopping/OM/
5. OM/ direct        → INTERDIT sans accord du propriétaire
```

---

## Stack — contraintes critiques

- **PHP ≥ 8.4** — `public private(set)` sur propriétés de services critiques
- **MariaDB ≥ 11.7** — MySQL incompatible avec `VECTOR(3072)`
- **LLPhant** — seule couche d'accès aux LLMs, pas d'appel direct API
- **Autoload** : `CLICSHOPPING::autoload` + Composer vendor (`Core/ClicShopping/External/vendor`) — pas de mécanisme d'autoload alternatif

---

## Interdictions absolues

```
✗ Legacy osCommerce / ClicShopping V2/V3
✗ Modifier Core/ClicShopping/OM/ sans accord du propriétaire
✗ Modifier Core/ClicShopping/Work/ ou Core/ClicShopping/External/vendor/
✗ SQL applicatif dans sql_upgrade/
✗ Logique métier ou accès DB dans les templates
✗ Chaînes visibles hardcodées
✗ Clés API hardcodées
✗ Appels LLM directs sans LLPhant
✗ MySQL 9.x
```

---

## Références externes

- Wiki : https://github.com/ClicShopping/ClicShopping/wiki
- DeepWiki : https://deepwiki.com/ClicShopping/ClicShopping
- Forum : https://www.clicshopping.org
