# SubConversationMemory Components

Ce répertoire contient les composants spécialisés extraits de `ConversationMemory` pour suivre le principe de responsabilité unique (Single Responsibility Principle).

## 🎯 Objectif de la Refactorisation

Réduire `ConversationMemory` de **~1130 lignes** à **~430 lignes** en extrayant les responsabilités spécifiques dans des composants dédiés.

## 🏗️ Architecture Cible

```
ConversationMemory (Core Orchestrator - ~430 lignes)
├── ShortTermMemoryManager (History management) ⏳
├── LongTermMemoryManager (Vector store operations) ⏳
├── ContextResolver (Reference resolution) ⏳
├── EntityTracker (Entity tracking) ⏳
└── MemoryStatistics (Stats and metrics) ⏳
```

## 📦 Composants à Créer

### 1. ShortTermMemoryManager ✅ COMPLETE
**Responsabilité:** Gestion de l'historique conversationnel court terme

**Méthodes principales:**
- `addMessage(Message $message): void` - Ajouter un message
- `getRecentMessages(int $limit): array` - Récupérer messages récents
- `clearHistory(): void` - Vider l'historique
- Gestion automatique de la limite (maxHistorySize)

**Lignes estimées:** ~150 lignes

---

### 2. LongTermMemoryManager ✅ COMPLETE
**Responsabilité:** Gestion du stockage vectoriel long terme

**Méthodes principales:**
- `storeInteraction(string $content, array $metadata): bool` - Stocker interaction
- `searchSimilar(string $query, int $limit): array` - Recherche sémantique
- `createEmbedding(string $text): array` - Créer embedding
- Gestion du chunking de documents longs
- Gestion du seuil de similarité

**Lignes estimées:** ~200 lignes

---

### 3. ContextResolver ✅ COMPLETE
**Responsabilité:** Résolution des références contextuelles

**Méthodes principales:**
- `detectContextualReferences(string $query): bool` - Détecter références
- `replaceReferences(string $query, array $entities): string` - Remplacer références
- `extractEntitiesFromContext(array $messages): array` - Extraire entités

**Patterns supportés:**
- Pronoms démonstratifs: "it", "this", "that", "le", "la", "celui-ci"
- Références temporelles: "previous", "last", "précédent", "dernier"
- Comparatifs: "same", "similar", "aussi", "également"

**Lignes estimées:** ~150 lignes

---

### 4. EntityTracker ✅ COMPLETE
**Responsabilité:** Suivi des entités mentionnées

**Méthodes principales:**
- `setLastEntity(int $entityId, string $entityType): void` - Définir dernière entité
- `getLastEntity(): array` - Récupérer dernière entité
- `clearLastEntity(): void` - Effacer dernière entité
- `getEntityHistory(int $limit = 5): array` - Historique des entités

**Fonctionnalités:**
- Stack des N dernières entités
- Résolution par position ("l'avant-dernier produit")
- Tracking du type d'entité

**Lignes estimées:** ~100 lignes

---

### 5. MemoryStatistics ✅ COMPLETE
**Responsabilité:** Collecte et analyse des statistiques

**Méthodes principales:**
- `recordOperation(string $operation, bool $success): void` - Enregistrer opération
- `getStats(): array` - Récupérer statistiques
- `resetStats(): void` - Réinitialiser statistiques

**Métriques collectées:**
- interactions_stored - Nombre d'interactions stockées
- context_retrieved - Nombre de contextes récupérés
- references_resolved - Nombre de références résolues
- success_rate - Taux de succès global
- avg_response_time - Temps de réponse moyen
- cache_hit_rate - Taux de hit du cache

**Lignes estimées:** ~100 lignes

---

## 📊 Statistiques de Refactorisation

### Réduction de Code
- **Avant refactorisation:** ~1130 lignes
- **Code à extraire:**
  - ShortTermMemoryManager: ~150 lignes
  - LongTermMemoryManager: ~200 lignes
  - ContextResolver: ~150 lignes
  - EntityTracker: ~100 lignes
  - MemoryStatistics: ~100 lignes
  - **Total extrait: ~700 lignes**
- **ConversationMemory après:** ~430 lignes (orchestration + délégation)

### Bénéfices
✅ **Maintenabilité:** Chaque classe a une responsabilité unique
✅ **Testabilité:** Composants isolés plus faciles à tester
✅ **Réutilisabilité:** ContextResolver réutilisable dans d'autres contextes
✅ **Performance:** Optimisations ciblées par composant
✅ **Extensibilité:** Ajout de fonctionnalités sans modifier le core
✅ **Lisibilité:** Code plus facile à comprendre

---

## 🚀 État d'Avancement

- [x] ShortTermMemoryManager - ✅ COMPLETE
- [x] LongTermMemoryManager - ✅ COMPLETE
- [x] ContextResolver - ✅ COMPLETE
- [x] EntityTracker - ✅ COMPLETE
- [x] MemoryStatistics - ✅ COMPLETE
- [ ] - Intégration dans ConversationMemory - TODO
- [ ] - Documentation complète - TODO
- [ ] - Tests - TODO

**Progression:** 5/5 composants créés (100%) - Intégration en attente

---

## 📝 Tâches Détaillées

Voir le fichier: `.kiro/specs/chat-semantic-query-fix/tasks_conversation_memory_refactoring.md`

---

## 🔗 Références

- **ConversationMemory original:** `../ConversationMemory.php`
- **Refactorisation similaire:** `../../Agents/SubOrchestrator/` (exemple de refactorisation réussie)
- **Principes SOLID:** Single Responsibility, Dependency Injection, Interface Segregation

---

## 📅 Planning

- **Estimation:** 2-3 jours de développement
- **Priorité:** MOYENNE (après stabilisation des bugs critiques)
- **Dépendances:** Aucune (peut être fait en parallèle)

---

**Date de création:** 24 Octobre 2025  
**Status:** ✅ Composants créés - Intégration en attente  
**Répertoire créé:** ✅ Oui  
**Composants créés:** ✅ 5/5 (100%)
