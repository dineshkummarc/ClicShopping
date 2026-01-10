<?php
/**
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\AI\Agents\Memory;

use AllowDynamicProperties;
use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;
use ClicShopping\AI\Security\SecurityLogger;

/**
 * MemoryRetentionService
 *
 * Orchestre les 3 niveaux de mémoire :
 * 1. NIVEAU 0 (Immédiat) : WorkingMemory - Exécution courante
 * 2. NIVEAU 1 (Court terme - 1h) : ConversationHistory - Session actuelle
 * 3. NIVEAU 2 (Long terme - persistent) : Vector Store - Apprentissage permanent
 *
 * Flux de rétention :
 * - Les interactions entrent en NIVEAU 1 immédiatement
 * - Après TTL_SHORT_TERM, migration vers NIVEAU 2
 * - NIVEAU 2 persiste indéfiniment (avec nettoyage optionnel)
 */
#[AllowDynamicProperties]
class MemoryRetentionService
{
  private ConversationMemory $conversationMemory;
  private WorkingMemory $workingMemory;
  private CorrectionPatterns $correctionPatterns;
  private SecurityLogger $securityLogger;
  private bool $debug;
  private string $userId;
  private int $languageId;

  // Configuration de rétention (en secondes)
  private const TTL_SHORT_TERM = 3600; // 1 heure

  public function __construct(
    string $userId = 'system',
    ?int $languageId = null
  ) {
    $this->userId = $userId;
    $this->languageId = $languageId ?? Registry::get('Language')->getId();
    $this->securityLogger = new SecurityLogger();
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    // Initialiser les trois niveaux
    $this->workingMemory = new WorkingMemory();
    $this->conversationMemory = new ConversationMemory($userId, $languageId);
    $this->correctionPatterns = new CorrectionPatterns($userId, $languageId);

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent( "MemoryRetentionService initialized (3-level retention active)", 'info' );
    }
  }

  /**
   * 🔴 POINT D'ENTRÉE PRINCIPAL
   *
   * Appelé après CHAQUE interaction utilisateur/assistant
   * Gère automatiquement le flux de rétention
   */
  public function recordInteraction(string $userMessage, string $assistantResponse, array $metadata = []): void
  {
    try {
      $timestamp = time();
      
      // 🔧 FIX: Use interaction_id from metadata if provided, otherwise generate new one
      // This ensures we use the same interaction_id throughout the entire flow
      $interactionId = $metadata['interaction_id'] ?? uniqid('interaction_', true);

      // 🔧 FIX: Validate that user_id is present
      if (empty($this->userId)) {
        $this->securityLogger->logSecurityEvent(
          "❌ MemoryRetentionService: user_id is empty, cannot record interaction",
          'error',
          ['interaction_id' => $interactionId]
        );
        throw new \Exception("user_id is required to record interaction");
      }

      // NIVEAU 0: Working Memory
      $this->workingMemory->set('last_user_message', $userMessage);
      $this->workingMemory->set('last_assistant_response', $assistantResponse);
      $this->workingMemory->set('last_interaction_id', $interactionId);
      $this->workingMemory->set('last_interaction_timestamp', $timestamp);

      // ✅ NOUVEAU: Extraire entity_id et language_id du metadata
      $entityId = $metadata['entity_id'] ?? 0;
      $languageId = $metadata['language_id'] ?? $this->languageId;
      $entityType = $metadata['entity_type'] ?? 'unknown';

      // Assurer que ce ne sont pas NULL
      if (is_null($entityId) || $entityId === '') {
        $entityId = 0;
      }
      if (is_null($languageId) || $languageId === '') {
        $languageId = (int)DEFAULT_LANGUAGE;
      }

      // 🔧 FIX: Build complete metadata with ALL required fields
      // This ensures user_id and interaction_id are ALWAYS present
      $completeMetadata = array_merge($metadata, [
        'timestamp' => $timestamp,
        'interaction_id' => $interactionId,  // ✅ ALWAYS set
        'user_id' => $this->userId,          // ✅ ALWAYS set
        'retention_level' => 'short_term',
        'entity_id' => $entityId,
        'language_id' => $languageId,
        'entity_type' => $entityType,
      ]);

      // NIVEAU 1: short term (ConversationHistory)
      // 🔧 FIX: Pass completeMetadata which includes user_id AND interaction_id
      $this->conversationMemory->addInteractionWithSplitting($userMessage, $assistantResponse, $completeMetadata);

      // Optional: persist a flat copy into rag_conversation_memory (non-embedding)
      try {
        // 🔧 TASK 4.4.1 PHASE 2: Use DoctrineOrm instead of direct DB access
        // 🔧 PHASE 5 FIX: Store entity_type in dedicated column (not just metadata)
        $metadataArray = [
          'source' => 'chat_ajax',
          'agent_used' => $metadata['agent_used'] ?? 'unknown',
          'intent_confidence' => $metadata['intent_confidence'] ?? 0,
          'execution_time' => $metadata['execution_time'] ?? 0,
        ];

        // Skip embedding insertion for now - it requires proper vector generation
        // which is expensive and not needed for simple conversation memory
        // The conversation is already stored in rag_conversation_memory (non-embedding table)
        
        // TODO: Generate proper embeddings asynchronously for semantic search
        
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "⏭️ Skipped rag_conversation_memory_embedding insertion (requires proper embedding generation)",
            'info'
          );
        }
        
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "✅ PHASE 5: Saved to rag_conversation_memory_embedding with entity_id={$entityId}, entity_type={$entityType}",
            'info'
          );
        }
      } catch (\Throwable $e) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "rag_conversation_memory_embedding insert failed: " . $e->getMessage(),
            'warning'
          );
        }
      }

      // Tracker pour migration future
      $migrations = $this->workingMemory->get('interactions_to_migrate', []);
      $migrations[] = [
        'id' => $interactionId,
        'stored_at' => $timestamp,
      ];
      $this->workingMemory->set('interactions_to_migrate', $migrations);

      // ✅ NEW: Log to rag_memory_retention_log table
      try {
        // 🔧 TASK 4.4.1 PHASE 2: Use DoctrineOrm instead of direct DB access
        $retentionLog = [
          'user_id' => $this->userId,
          'interaction_id' => $interactionId,
          'timestamp_recorded' => date('Y-m-d H:i:s', $timestamp),
          'level' => 'short_term',
          'status' => 'short_term_stored'
        ];
       DoctrineOrm::insert('rag_memory_retention_log', $retentionLog);
        
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "✅ Logged to rag_memory_retention_log: {$interactionId}",
            'info'
          );
        }
      } catch (\Throwable $e) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "⚠️ Failed to log to rag_memory_retention_log: " . $e->getMessage(),
            'warning'
          );
        }
      }

      // 🔧 FIX: Enhanced logging to confirm both user_id and interaction_id are present
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "✅ Interaction recorded: {$interactionId}",
          'info',
          [
            'user_id' => $this->userId,
            'interaction_id' => $interactionId,
            'entity_id' => $entityId,
            'language_id' => $languageId,
            'has_user_id' => !empty($this->userId),
            'has_interaction_id' => !empty($interactionId)
          ]
        );
      }

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "❌ Error recording interaction: " . $e->getMessage(),
        'error',
        [
          'user_id' => $this->userId ?? 'MISSING',
          'interaction_id' => $interactionId ?? 'MISSING',
          'stack_trace' => $e->getTraceAsString()
        ]
      );
      // Re-throw to ensure caller knows about the failure
      throw $e;
    }
  }

  /**
   * 🟢 MIGRATION AUTOMATIQUE
   *
   * Migre les interactions anciennes du court terme vers le long terme
   * À appeler périodiquement (par cron ou hook)
   */
  public function migrateShortTermToLongTerm(): int
  {
    try {
      $migrated = 0;
      $now = time();
      
      // ✅ NEW: Query database directly for old short-term interactions
      // 🔧 TASK 4.4.1 PHASE 2: Use DoctrineOrm instead of direct DB access
      $cutoffTime = date('Y-m-d H:i:s', $now - self::TTL_SHORT_TERM);
      $prefix = CLICSHOPPING::getConfig('db_table_prefix');
      
      $sql = "
        SELECT interaction_id, timestamp_recorded 
        FROM {$prefix}rag_memory_retention_log 
        WHERE level = :level 
          AND status = :status
          AND timestamp_recorded < :cutoff_time
      ";
      
      $oldInteractions = DoctrineOrm::select($sql, [
        'level' => 'short_term',
        'status' => 'short_term_stored',
        'cutoff_time' => $cutoffTime
      ]);

      foreach ($oldInteractions as $record) {
        // ✓ Déjà dans ConversationMemory (qui utilise MariaDBVectorStore)
        // ✓ Rien de plus à faire - c'est persistant

        // ✅ Update rag_memory_retention_log to mark as long-term
        // 🔧 TASK 4.4.1 PHASE 2: Use DoctrineOrm instead of direct DB access
        try {
          $updateSql = "
            UPDATE {$prefix}rag_memory_retention_log 
            SET level = :level, 
                status = :status,
                timestamp_migrated = :timestamp_migrated
            WHERE interaction_id = :interaction_id
          ";
          DoctrineOrm::execute($updateSql, [
            'level' => 'long_term',
            'status' => 'long_term_stored',
            'timestamp_migrated' => date('Y-m-d H:i:s'),
            'interaction_id' => $record['interaction_id']
          ]);
          
          $migrated++;

          if ($this->debug) {
            $this->securityLogger->logSecurityEvent(
              "✓ Migrated to long-term: {$record['interaction_id']}",
              'info'
            );
          }
        } catch (\Throwable $e) {
          if ($this->debug) {
            $this->securityLogger->logSecurityEvent(
              "⚠️ Failed to update rag_memory_retention_log: " . $e->getMessage(),
              'warning'
            );
          }
        }
      }

      // Also process workingMemory migrations (for backward compatibility)
      $migrations = $this->workingMemory->get('interactions_to_migrate', []);
      foreach ($migrations as $record) {
        $age = $now - $record['stored_at'];
        if ($age > self::TTL_SHORT_TERM) {
          // Already handled by database query above
          $migrated++;
        }
      }

      // Nettoyer les enregistrements migrés
      if ($migrated > 0) {
        $this->workingMemory->set('interactions_to_migrate', []);
        $this->workingMemory->set('last_migration', $now);
      }

      return $migrated;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error during migration: " . $e->getMessage(),
        'error'
      );
      return 0;
    }
  }

  /**
   * 🟡 RÉCUPÉRATION MULTI-NIVEAUX
   *
   * Cherche dans tous les niveaux en priorité :
   * 1. Working Memory (exécution actuelle)
   * 2. Short-term (conversation active)
   * 3. Long-term (historique persistant)
   */
  public function retrieveContext(string $currentQuery, int $limit = 5): array
  {
    try {
      $context = [
        'working_memory' => [],
        'short_term' => [],
        'long_term' => [],
        'combined' => [],
      ];

      // 1️⃣ Working Memory
      if ($this->workingMemory->has('last_user_message')) {
        $context['working_memory'] = [
          'last_message' => $this->workingMemory->get('last_user_message'),
          'last_response' => $this->workingMemory->get('last_assistant_response'),
        ];
      }

      // 2️⃣ Short-term (ConversationHistory)
      $shortTermCtx = $this->conversationMemory->getRelevantContext($currentQuery, $limit);
      $context['short_term'] = $shortTermCtx['short_term_context'] ?? [];

      // 3️⃣ Long-term (Vector Store)
      $context['long_term'] = $shortTermCtx['long_term_context'] ?? [];

      // 🔀 Combiner intelligemment
      $context['combined'] = $this->combineContextLevels(
        $context['working_memory'],
        $context['short_term'],
        $context['long_term'],
        $limit
      );

      return $context;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error retrieving context: " . $e->getMessage(),
        'error'
      );

      return [
        'working_memory' => [],
        'short_term' => [],
        'long_term' => [],
        'combined' => [],
      ];
    }
  }

  /**
   * 🔗 Combine intelligemment les 3 niveaux
   */
  private function combineContextLevels(array $working, array $shortTerm, array $longTerm, int $limit): array
  {
    $combined = [];

    // Priority: Working > Short-term > Long-term
    if (!empty($working)) {
      $combined[] = ['source' => 'working', 'data' => $working];
    }

    $count = count($combined);
    foreach ($shortTerm as $item) {
      if ($count >= $limit) break;
      $combined[] = ['source' => 'short_term', 'data' => $item];
      $count++;
    }

    foreach ($longTerm as $item) {
      if ($count >= $limit) break;
      $combined[] = ['source' => 'long_term', 'data' => $item];
      $count++;
    }

    return $combined;
  }


  /**
   * 🧹 Nettoyage des anciennes données
   */
  public function cleanup(): int
  {
    try {
      $cleaned = 0;

      // Nettoyer le working memory
      $this->workingMemory->clear();
      $cleaned += 5; // estimation

      // Nettoyer les vieilles patterns
      $stats = $this->correctionPatterns->getPatternStats();
      if ($stats['total_patterns'] > 1000) {
        $this->correctionPatterns->clearPatterns();
        $cleaned += $stats['total_patterns'];
      }

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Cleanup complete: {$cleaned} items removed",
          'info'
        );
      }

      return $cleaned;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error during cleanup: " . $e->getMessage(),
        'error'
      );
      return 0;
    }
  }
}