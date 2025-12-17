<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Planning\SubPlanExecutor;

use AllowDynamicProperties;
use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Rag\MultiDBRAGManager;
use ClicShopping\AI\Agents\Planning\SubPlanExecutor\SubSemanticExecutor\SemanticSearchOrchestrator;
use ClicShopping\OM\CLICSHOPPING;

/**
 * SemanticExecutor Class
 *
 * Responsible for executing semantic searches.
 * Separated from PlanExecutor to follow Single Responsibility Principle.
 *
 * Responsibilities:
 * - Execute semantic searches
 * - Format semantic results
 * - Handle semantic errors
 * - Manage search cache
 */
#[AllowDynamicProperties]
class SemanticExecutor
{
  private SecurityLogger $logger;
  private bool $debug;
  private ?MultiDBRAGManager $ragManager = null;
  private ?SemanticSearchOrchestrator $orchestrator = null;
  private string $userId;
  private int $languageId;

  /**
   * Constructor
   *
   * @param string $userId User ID
   * @param int $languageId Language ID
   * @param bool $debug Enable debug logging
   */
  public function __construct(string $userId = 'system', int $languageId = 1, bool $debug = false)
  {
    $this->logger = new SecurityLogger();
    $this->userId = $userId;
    $this->languageId = $languageId;
    $this->debug = $debug;

    if ($this->debug) {
      $this->logger->logSecurityEvent("SemanticExecutor initialized", 'info');
    }
  }

  /**
   * Execute semantic search
   *
   * @param string $query Query to search
   * @param array $context Context information
   * @return array Result
   */
  public function executeSemanticSearch(string $query, array $context = []): array
  {
    // ALWAYS log entry (even if debug is off) to track execution
    $this->logger->logSecurityEvent(
      "🔍 SemanticExecutor.executeSemanticSearch() CALLED - Query: {$query}",
      'info'
    );
    
    try {
      // Initialize orchestrator if needed (lazy loading)
      if ($this->orchestrator === null) {
        $this->logger->logSecurityEvent(
          "🆕 Initializing SemanticSearchOrchestrator (userId: {$this->userId}, langId: {$this->languageId})",
          'info'
        );
        
        $this->orchestrator = new SemanticSearchOrchestrator(
          $this->userId,
          $this->languageId,
          $this->debug
        );
        
        $this->logger->logSecurityEvent(
          "✅ SemanticSearchOrchestrator initialized successfully",
          'info'
        );
      }

      $this->logger->logSecurityEvent(
        "➡️  Delegating to SemanticSearchOrchestrator for query: {$query}",
        'info'
      );

      // Delegate to orchestrator with fallback chain
      $rawResult = $this->orchestrator->search($query, $context);

      $this->logger->logSecurityEvent(
        "✅ SemanticSearchOrchestrator returned result",
        'info'
      );

      // Format result (maintain backward compatibility)
      $formattedResult = $this->formatSemanticResult($rawResult);

      return $formattedResult;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "❌ Exception in executeSemanticSearch: " . $e->getMessage(),
        'error'
      );
      return $this->handleSemanticError($e, $query);
    }
  }

  /**
   * Format semantic result
   *
   * 🔧 TASK 4.3.7.1: Extract entity information from embedding metadata
   *
   * @param array $rawResult Raw result from MultiDBRAGManager
   * @return array Formatted result
   */
  public function formatSemanticResult(array $rawResult): array
  {
    // Extract the actual answer/response
    $answer = $rawResult['answer'] ?? $rawResult['response'] ?? $rawResult['text_response'] ?? '';
    
    $formatted = [
      'type' => 'semantic',
      'success' => $rawResult['success'] ?? true,
      'text_response' => $answer,
      'response' => $answer,  // 🔧 TASK 2.17.2: Add 'response' field for OrchestratorAgent extraction
      'sources' => $rawResult['sources'] ?? [],
    ];

    // Add metadata if present
    if (isset($rawResult['metadata'])) {
      $formatted['metadata'] = $rawResult['metadata'];
    }
    
    // Add audit_metadata if present (from RAG search)
    if (isset($rawResult['audit_metadata'])) {
      $formatted['audit_metadata'] = $rawResult['audit_metadata'];
    }

    // 🔧 TASK 4.3.7.1: Extract entity information from embedding metadata
    $entityInfo = $this->extractEntityFromDocuments($rawResult);
    if ($entityInfo !== null) {
      // Add _step_entity_metadata for EntityExtractor to find
      $formatted['_step_entity_metadata'] = $entityInfo;
      
      // Also add to top level for backward compatibility
      $formatted['entity_id'] = $entityInfo['entity_id'];
      $formatted['entity_type'] = $entityInfo['entity_type'];
      
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "TASK 4.3.7.1: Extracted entity from semantic query - entity_type: {$entityInfo['entity_type']}, entity_id: {$entityInfo['entity_id']}",
          'info'
        );
      }
    } else {
      // No entity found - this is a general knowledge query
      $formatted['_step_entity_metadata'] = [
        'entity_id' => 0,
        'entity_type' => 'general',
        'source' => 'semantic_query_no_entity'
      ];
      $formatted['entity_id'] = 0;
      $formatted['entity_type'] = 'general';
      
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "TASK 4.3.7.1: No entity found in semantic query - setting entity_type='general'",
          'info'
        );
      }
    }

    // 🆕 Add source attribution for semantic queries
    $documentCount = is_array($formatted['sources']) ? count($formatted['sources']) : 0;
    
    // Determine source type based on where the answer came from
    $source = $rawResult['source'] ?? 'documents';
    
    if ($source === 'llm') {
      // LLM fallback (general knowledge)
      $formatted['source_attribution'] = [
        'source_type' => 'LLM General Knowledge',
        'source_icon' => '🤖',
        'source_details' => 'Answer generated by AI language model',
        'fallback_reason' => 'No relevant documents found in knowledge base',
      ];
    } elseif ($source === 'conversation_memory') {
      // From conversation memory
      $formatted['source_attribution'] = [
        'source_type' => 'Conversation Memory',
        'source_icon' => '💭',
        'source_details' => 'Information retrieved from recent conversation history',
        'document_count' => $documentCount,
      ];
    } else {
      // From document stores (RAG)
      $formatted['source_attribution'] = [
        'source_type' => 'RAG Knowledge Base',
        'source_icon' => '📚',
        'source_details' => 'Information retrieved from vector embeddings database',
        'document_count' => $documentCount,
      ];
    }

    return $formatted;
  }

  /**
   * Extract entity information from document metadata
   *
   * 🔧 TASK 4.3.7.1: Extract entity_id and entity_type from embedding metadata
   *
   * This method examines the documents returned from semantic search and extracts
   * entity information from their metadata. It infers entity_type from the source table name.
   *
   * @param array $rawResult Raw result containing documents
   * @return array|null Entity information or null if no entity found
   */
  private function extractEntityFromDocuments(array $rawResult): ?array
  {
    // Check if we have documents
    $documents = $rawResult['documents'] ?? [];

    if (empty($documents)) {
      return null;
    }

    // Iterate through documents to find one with entity metadata
    foreach ($documents as $doc) {
      $metadata = null;
      
      // Handle both object and array document formats
      if (is_object($doc) && isset($doc->metadata)) {
        $metadata = $doc->metadata;
      } elseif (is_array($doc) && isset($doc['metadata'])) {
        $metadata = $doc['metadata'];
      }
      
      if ($metadata === null) {
        continue;
      }

      // Extract entity_id from metadata
      $entityId = null;
      if (isset($metadata['entity_id']) && $metadata['entity_id'] > 0) {
        $entityId = (int)$metadata['entity_id'];
      }

      // Extract or infer entity_type
      $entityType = null;
      
      // First, check if entity_type is explicitly set in metadata
      if (isset($metadata['entity_type']) && !empty($metadata['entity_type'])) {
        $entityType = $metadata['entity_type'];
      }
      // Otherwise, infer from source_table or type
      elseif (isset($metadata['source_table']) && !empty($metadata['source_table'])) {
        $entityType = $this->inferEntityTypeFromTable($metadata['source_table']);
      }
      elseif (isset($metadata['type']) && !empty($metadata['type'])) {
        $entityType = $metadata['type'];
      }

      // If we found both entity_id and entity_type, return them
      if ($entityId !== null && $entityId > 0 && $entityType !== null) {
        return [
          'entity_id' => $entityId,
          'entity_type' => $entityType,
          'source' => 'embedding_metadata',
          'source_table' => $metadata['source_table'] ?? 'unknown'
        ];
      }
    }

    // No entity found in any document
    return null;
  }

  /**
   * Infer entity type from table name
   *
   * 🔧 TASK 4.3.7.1: Map embedding table names to entity types
   *
   * Examples:
   * - pages_manager_description_embedding → 'page_manager'
   * - products_embedding → 'product'
   * - categories_embedding → 'category'
   *
   * @param string $tableName Table name (with or without prefix)
   * @return string Entity type
   */
  private function inferEntityTypeFromTable(string $tableName): string
  {
    // Remove table prefix if present
    $prefix = CLICSHOPPING::getConfig('db_table_prefix');
    if (!empty($prefix) && strpos($tableName, $prefix) === 0) {
      $tableName = substr($tableName, strlen($prefix));
    }

    // Remove '_embedding' suffix if present
    $tableName = str_replace('_embedding', '', $tableName);

    // Map table names to entity types
    // Must be convert automatically : See MuiltidbRAGManager::storeDocumentEmbedding()
    // not sur all point are correct. to check
    $tableToEntityMap = [
      'products' => 'product',
      'categories' => 'category',
      'pages_manager' => 'page_manager',
      'pages_manager_description' => 'page_manager',
      'orders' => 'order',
      'customers' => 'customer',
      'suppliers' => 'supplier',
      'manufacturers' => 'manufacturer',
      'reviews' => 'review',
      'reviews_sentiment' => 'review_sentiment',
      'return_orders' => 'return_order',
      // RAG system tables - IMPORTANT: Use correct names with '_embedding' suffix
      // See docs/RAG_TABLE_NAMING_CONVENTION.md for complete documentation
      'rag_conversation_memory_embedding' => 'general',  // Embedding table: conversation history
      'rag_correction_patterns_embedding' => 'general',  // Embedding table: correction patterns
      'rag_web_cache_embedding' => 'general',            // Embedding table: web cache
    ];

    // Return mapped entity type or use table name as fallback
    return $tableToEntityMap[$tableName] ?? $tableName;
  }

  /**
   * Handle semantic error
   *
   * @param \Exception $e Exception
   * @param string $query Original query
   * @return array Error result
   */
  public function handleSemanticError(\Exception $e, string $query): array
  {
    $this->logger->logSecurityEvent(
      "Semantic search failed: {$query} - " . $e->getMessage(),
      'error'
    );

    return [
      'type' => 'semantic',
      'success' => false,
      'error' => $e->getMessage(),
      'text_response' => "Semantic search failed: " . $e->getMessage(),
    ];
  }

  /**
   * Get RAG manager instance
   *
   * @return MultiDBRAGManager|null
   */
  public function getRagManager(): ?MultiDBRAGManager
  {
    return $this->ragManager;
  }
}
