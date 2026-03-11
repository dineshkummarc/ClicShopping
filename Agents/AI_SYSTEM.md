# AI_SYSTEM.md — ClicShopping AI v4.20+

> Système AI agnostique du framework PHP.
> Règles opérationnelles agents : `AGENTS.md` — Architecture core : `ARCHITECTURE.md`

---

## 1. Positionnement

Le système AI est le composant de **plus haute priorité architecturale** de ClicShopping.
Il est conçu comme une couche **agnostique** : ses agents, interfaces et pipelines ne
dépendent pas du framework PHP sous-jacent et peuvent évoluer indépendamment.

Localisation : `Core/ClicShopping/AI/`, organisé par domaine métier.

---

## 2. Architecture RAG hybride

Le système combine trois modes de récupération selon l'intention détectée :

| Mode | Mécanisme | Cas d'usage |
|---|---|---|
| **Analytics** | Langage naturel → SQL généré | KPIs, rapports, agrégats |
| **Sémantique** | Recherche vectorielle `VECTOR(3072)` | Similarité produits, recommandations |
| **Web** | Recherche externe | Données temps réel hors base |
| **Hybride** | Combinaison pondérée des trois | Requêtes complexes multi-sources |

Le mode est sélectionné automatiquement par le `QueryClassifier` avant exécution.

---

## 3. Architecture multi-agents

### Agents et responsabilités

| Composant | Type | Rôle |
|---|---|---|
| `OrchestratorAgent` | Agent principal | Décomposition de tâches, coordination inter-agents |
| `ReasoningAgent` | Agent spécialisé | Raisonnement multi-étapes (modes CoT / ToT / self-consistency) |
| `CorrectionAgent` | Agent spécialisé | Détection/correction d'erreurs et amélioration de réponse |
| `ValidationAgent` | Agent spécialisé | Validation de sortie et cohérence |
| `AnalyticsAgent` | Agent domaine | Génération/exécution SQL depuis langage naturel |
| `SemanticAgent` | Agent domaine | Recherche sémantique sur tables vectorielles |
| `WebSearchTool` + `WebSearchQueryExecutor` | Outils domaine | Récupération de données externes |
| `QueryClassifier` | Classifier | Analyse d'intention et routage |
| `MemoryRetentionService` | Service mémoire | Mémoire court/moyen/long terme |

### Règle d'enregistrement

Tout nouvel agent doit s'enregistrer via le mécanisme existant dans `Core/ClicShopping/AI/` :
- Ne pas créer de registre d'agents alternatif
- Ne pas instancier les agents directement depuis les Apps PHP
- Passer par l'orchestrateur pour toute invocation inter-agents

---

## 4. Raisonnement (état réel du core)

Le core actuel n'expose pas d'interface unique `ReasoningInterface`.
Le raisonnement est implémenté dans `ReasoningAgent` avec des modes configurables :
- `chain_of_thought`
- `tree_of_thought`
- `self_consistency`

Ces modes sont orchestrés par `OrchestratorAgent` et utilisés selon le contexte de requête.

---

## 5. Providers LLM

Abstraction via **LLPhant** — ne jamais appeler les APIs LLM directement.

| Provider | Constante de configuration | Usage principal |
|---|---|---|
| OpenAI (GPT-4/5) | `CLICSHOPPING_APP_CHATGPT_CH_API_KEY` | Génération, reasoning |
| Anthropic (Claude) | `CLICSHOPPING_APP_CHATGPT_CH_API_KEY_ANTHROPIC` | Génération, reasoning |
| Mistral | `CLICSHOPPING_APP_CHATGPT_CH_API_KEY_MISTRAL` | Génération |
| VoyageAI | `CLICSHOPPING_APP_CHATGPT_RA_API_KEY_VOYAGE_AI` | Embeddings uniquement |
| Ollama / LmStudio | Configuration serveur local | LLM local/privé |

Règles :
- Clés API uniquement via constantes de configuration — jamais hardcodées
- Le choix du provider est configuré via l'interface admin, pas dans le code
- En cas d'ajout d'un nouveau provider, passer par LLPhant — pas de client HTTP direct

---

## 6. Pipeline d'embeddings

### Tables d'embedding (état réel du schéma)

Noyau e-commerce (3072) :
- `clic_products_embedding`
- `clic_categories_embedding`
- `clic_reviews_embedding`
- `clic_reviews_sentiment_embedding`
- `clic_orders_embedding`
- `clic_pages_manager_embedding`
- `clic_manufacturers_embedding`
- `clic_suppliers_embedding`
- `clic_return_orders_embedding`

Tables mémoire/sécurité complémentaires :
- `clic_conversation_memory_embedding` (1536)
- `clic_correction_pattern_embedding` (1536)

### Structure commune des tables

| Colonne | Type | Rôle |
|---|---|---|
| `embedding` | `VECTOR(3072)` ou `VECTOR(1536)` | Vecteur sémantique |
| `entity_id` | INT | Lien vers la table source |
| `content` | TEXT | Texte original indexé |
| `metadata` | JSON | Enrichissement contextuel (ajouté v4.11) |
| `chunknumber` | INT | Numéro de chunk (défaut : 128 tokens) |
| `date_modified` | DATETIME | Horodatage de dernière mise à jour |

### Règles pipeline

- La génération des embeddings est gérée par les **crons existants** — ne pas recréer ce mécanisme
- Les dimensions vectorielles sont pilotées par le schéma existant (majoritairement 3072, certains cas 1536)
- MariaDB ≥ 11.7 est impératif pour `VECTOR INDEX` natif
- Ne pas modifier la structure des tables d'embedding sans accord du propriétaire

---

## 7. Sécurité AI (Guardrails)

Le système inclut une couche de sécurité dédiée aux interactions LLM.

Mécanismes actifs :
- **Détection d'injection de prompt** — contrôles de sécurité appliqués via les composants AI/API existants avant exécution complète
- **Scan d'obfuscation** — détection de tentatives de contournement encodées
- **Rate limiting AI** — fenêtre 900s, 20 requêtes max par identifiant
- **Audit** — table `rag_security_events` pour traçabilité

Constantes :
```
CLICSHOPPING_APP_API_AI_RATE_LIMIT_WINDOW       = 900
CLICSHOPPING_APP_API_AI_MAX_REQUEST_PER_WINDOW  = 20
CLICSHOPPING_APP_API_AI_MAX_LOGIN_ATTEMPTS      = 5
CLICSHOPPING_APP_API_AI_ACCOUNT_LOCK_DURATION   = 1800
```

**Ne pas contourner les guardrails en code applicatif** ; pour les tests, utiliser les configurations prévues et un environnement dédié.

---

## 8. MCP — Model Context Protocol

ClicShopping expose un serveur MCP pour le commerce agentique (port 3001 par défaut).

Rôles :
- Monitoring santé du système AI
- Métriques de performance (latence, taux d'erreur, uptime)
- Intégration avec des agents externes via protocole MCP standardisé

Table de stockage : `clic_mcp_performance_history`  
Ne pas modifier le protocole MCP sans accord du propriétaire.

---

## 9. Règles de développement AI

```
✓ Toujours passer par LLPhant pour les appels LLM
✓ S'appuyer sur `ReasoningAgent` et l'orchestration existante
✓ Enregistrer les nouveaux agents via Core/ClicShopping/AI/
✓ Clés API via constantes de configuration uniquement
✓ Respecter les guardrails existants

✗ Appels directs aux APIs LLM (OpenAI, Anthropic, etc.)
✗ Recréer le pipeline d'embeddings ou les crons associés
✗ Modifier la structure des tables *_embedding sans accord
✗ Modifier les dimensions vectorielles existantes sans validation propriétaire
✗ Contourner les guardrails pour les tests
✗ Instancier les agents directement depuis les Apps PHP
```

---

## 10. Références

- Architecture framework : `ARCHITECTURE.md`
- Base de données vectorielle : `DATABASE.md`
- Sécurité guardrails : `SECURITY.md`
- DeepWiki AI section : https://deepwiki.com/ClicShopping/ClicShopping/5-ai-integration-and-rag-system
