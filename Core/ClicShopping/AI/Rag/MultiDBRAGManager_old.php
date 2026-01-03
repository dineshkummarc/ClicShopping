<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Rag;

use AllowDynamicProperties;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Hash;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt;
use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;
use ClicShopping\AI\Infrastructure\Storage\MariaDBVectorStore;
use ClicShopping\AI\Helper\Formatter\ResultFormatter;

use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;

use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use LLPhant\Query\SemanticSearch\LLMReranker;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;

/**
 * MultiDBRAGManager Class
 *
 * This class manages multiple vector databases for Retrieval-Augmented Generation (RAG).
 * It provides functionality for document management, similarity search, and question answering
 * across multiple vector stores using OpenAI embeddings.
 *
 * Key features:
 * - Multiple vector store management
 * - Document embedding and storage
 * - Similarity search across multiple databases
 * - Question answering using RAG
 * - Support for different languages and entity types
 *
 * @package ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag
 */
#[AllowDynamicProperties]
class MultiDBRAGManager
{
  public mixed $app;
  public mixed $db;
  public mixed $language;
  private mixed $embeddingGenerator;
  private array $vectorStores = [];
  private mixed $securityLogger;
  private bool $debug = false;
  private static array $tableStatsCache = [];

  private mixed $resultFormatter;
  private int $userId;
  private mixed $metadata;
  
  // Reranking properties (Task 2.14.3 - LLPhant reranking integration)
  private ?LLMReranker $reranker = null;
  private bool $useReranking = false;

  /**
   * Constructor for MultiDBRAGManager
   * Initializes the RAG system with specified model and tables
   *
   * @param string|null $model OpenAI model to use (null for default configuration)
   * @param array $tableNames List of table names to use (empty for all embedding tables)
   * @param array $modelOptions Additional model options (temperature, etc.)
   * @throws \Exception If initialization fails
   */
  public function __construct(?string $model = null, array $tableNames = [], array $modelOptions = [])
  {
    // Initialisation de l'application ChatGpt via Registry
    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGpt());
    }

    $this->app = Registry::get('ChatGpt');
    // 🔧 TASK 4.4.1 PHASE 7: Removed - no longer needed (using DoctrineOrm)
    // $this->db = Registry::get('Db');
    $this->language = Registry::get('Language');
    $this->userId = AdministratorAdmin::getUserAdminId() ?? 0; // Default to 0 if no admin logged in

    // Load language definitions for ChatGpt app
    // Language definitions are now loaded from main.txt via CLICSHOPPING::getDef()
    // $this->app->loadDefinitions('Sites/ClicShoppingAdmin/rag_analytics_agent');

    if (!Registry::exists('ResultFormatter')) {
      registry::set('ResultFormatter', new ResultFormatter());
    }

    $this->resultFormatter = Registry::get('ResultFormatter');

    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    $this->securityLogger = new SecurityLogger();

    // 🔥 DEBUG CRITIQUE
    if ($this->debug) {
      error_log("═══════════════════════════════════════════════════════");
      error_log("🚀 MultiDBRAGManager::__construct() START");
      error_log("Model: " . ($model ?? 'null'));
      error_log("TableNames provided: " . print_r($tableNames, true));
      error_log("Debug enabled: " . ($this->debug ? 'YES' : 'NO'));
    }

    $parameters = null;
    $model = $model ?? (defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL') ? CLICSHOPPING_APP_CHATGPT_CH_MODEL : 'default_model');

    if (!is_null($model)) {
      $parameters['model'] = $model;
    } elseif (defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL')) {
      $parameters['model'] = CLICSHOPPING_APP_CHATGPT_CH_MODEL;
    }

    Gpt::getOpenAiGpt($parameters);

    // 🔥 APPEL CRITIQUE : Initialize vector stores
    if ($this->debug) {
      error_log("───────────────────────────────────────────────────");
      error_log("📋 About to call initializeVectorStores()...");
      error_log("TableNames param: " . (empty($tableNames) ? 'EMPTY (will auto-detect)' : implode(', ', $tableNames)));
    }

    $this->initializeVectorStores($tableNames);

    if ($this->debug) {
      error_log("───────────────────────────────────────────────────");
      error_log("📊 After initializeVectorStores():");
      error_log("VectorStores count: " . count($this->vectorStores));
      error_log("VectorStores keys: " . implode(', ', array_keys($this->vectorStores)));

      if (empty($this->vectorStores)) {
        error_log("CRITICAL WARNING: vectorStores is EMPTY!");
      }
    }

    $this->embeddingGenerator = $this->createEmbeddingGenerator();

    // Initialize LLMReranker for better document relevance (Task 2.14.3)
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_USE_RERANKING') 
        && CLICSHOPPING_APP_CHATGPT_RA_USE_RERANKING === 'True') {
      
      $this->useReranking = true;
      
      try {
        // Set OpenAI API key as environment variable (required by LLPhant)
        Gpt::getEnvironment();
        
        // Create OpenAI chat instance for reranking
        $config = new OpenAIConfig();
        $config->model = defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL') 
                         ? CLICSHOPPING_APP_CHATGPT_CH_MODEL 
                         : 'gpt-4o-mini';
        
        $chat = new OpenAIChat($config);
        
        // Number of documents to return after reranking
        $nrOfOutputDocuments = defined('CLICSHOPPING_APP_CHATGPT_RA_RERANKING_OUTPUT')  ? (int)CLICSHOPPING_APP_CHATGPT_RA_RERANKING_OUTPUT  : 5;
        
        $this->reranker = new LLMReranker($chat, $nrOfOutputDocuments);
        
        if ($this->debug) {
          error_log("✅ LLMReranker initialized with {$nrOfOutputDocuments} output documents");
        }
      } catch (\Exception $e) {
        error_log("❌ Failed to initialize LLMReranker: " . $e->getMessage());
        $this->useReranking = false;
        $this->reranker = null;
      }
    } else {
      $this->useReranking = false;
      if ($this->debug) {
        error_log("ℹ️ Reranking disabled in configuration");
      }
    }

    if ($this->debug) {
      error_log("🚀 MultiDBRAGManager::__construct() END");
      error_log("═══════════════════════════════════════════════════════");
    }
  }

  /**
   * Return known Embedding table
   *
   * @return array
   */
  /**
   * Returns all known embedding tables
   * 
   * This method dynamically detects all embedding tables in the database
   * by querying INFORMATION_SCHEMA. It also includes a static fallback list
   * for known tables in case the database query fails.
   * 
   * The method will find ANY table ending with '_embedding', making it
   * fully dynamic and extensible.
   * 
   * @param bool $useCache Whether to use cached results (default: true)
   * @return array List of all embedding table names
   */
  public function knownEmbeddingTable(bool $useCache = true): array
  {
    // Static cache to avoid repeated database queries
    static $cachedTables = null;
    
    if ($useCache && $cachedTables !== null) {
      return $cachedTables;
    }
    
    $prefix = CLICSHOPPING::getConfig('db_table_prefix');
    $dbName = CLICSHOPPING::getConfig('db_database');
    
    try {
      // Try to dynamically detect all *_embedding tables from database
      $sql = "SELECT TABLE_NAME 
              FROM INFORMATION_SCHEMA.TABLES 
              WHERE TABLE_SCHEMA = :dbName 
              AND TABLE_NAME LIKE :pattern 
              ORDER BY TABLE_NAME";
      
      // 🔧 TASK 4.4.1 PHASE 7: Migrated to DoctrineOrm
      $detectedTables = DoctrineOrm::select($sql, [
        'dbName' => $dbName,
        'pattern' => $prefix . '%_embedding'
      ]);
      
      $detectedTables = array_column($detectedTables, 'TABLE_NAME');
      
      if (!empty($detectedTables)) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Dynamically detected " . count($detectedTables) . " embedding tables from database",
            'info',
            ['tables' => $detectedTables]
          );
        }
        
        $cachedTables = $detectedTables;
        return $detectedTables;
      }
      
    } catch (\Exception $e) {
      // Log error but continue with fallback
      $this->securityLogger->logSecurityEvent(
        "Failed to dynamically detect embedding tables: " . $e->getMessage(),
        'warning'
      );
    }
    
    // Fallback: Static list of known tables
    $knownTables = [
      $prefix . 'products_embedding',
      $prefix . 'categories_embedding',
      $prefix . 'pages_manager_embedding',
      $prefix . 'orders_embedding',
      $prefix . 'manufacturers_embedding',
      $prefix . 'suppliers_embedding',
      $prefix . 'reviews_embedding',
      $prefix . 'reviews_sentiment_embedding',
      $prefix . 'return_orders_embedding',
      $prefix . 'rag_conversation_memory_embedding',
      $prefix . 'rag_correction_patterns_embedding',
      $prefix . 'rag_web_cache_embedding'
    ];
    
    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Using fallback static list of " . count($knownTables) . " embedding tables",
        'info'
      );
    }
    
    $cachedTables = $knownTables;
    return $knownTables;
  }

  /**
   * Returns the embedding generator instance
   *
   * @return EmbeddingGeneratorInterface Instance of the embedding generator
   */
  private function getEmbeddingGenerator(): EmbeddingGeneratorInterface {
    if (!isset($this->embeddingGenerator)) {
      $this->embeddingGenerator = $this->createEmbeddingGenerator();
    }

    return $this->embeddingGenerator;
  }

  /**
   * Creates an embedding generator using the specified Gpt class
   *
   * @return EmbeddingGeneratorInterface Instance of the embedding generator
   */
  private function createEmbeddingGenerator(): EmbeddingGeneratorInterface
  {
    return new class(Gpt::class) implements EmbeddingGeneratorInterface
    {
      private $gptClass;

      /**
       * Constructor for the embedding generator
       *
       * @param string $gptClass Class name of the Gpt instance
       */
      public function __construct(string $gptClass)
      {
        $this->gptClass = $gptClass;
      }

      /**
       * Embeds a single text string
       *
       * @param string $text Text to embed
       * @return array Embedding vector
       */
      public function embedText(string $text): array
      {
        $generator = NewVector::gptEmbeddingsModel();

        if (!$generator) {
          throw new \RuntimeException('Embedding generator non initialisé');
        }

        return $generator->embedText($text);
      }

      /**
       * Embeds a single document
       *
       * @param Document $document Document object to embed
       * @return Document Embedded Document object
       */
      public function embedDocument(Document $document): Document
      {
        $document->embedding = NewVector::createEmbedding(null, $document);

        return $document;
      }

      /**
       * Embeds multiple documents
       *
       * @param array $documents Array of Document objects to embed
       * @return array Array of embedded Document objects
       */
      public function embedDocuments(array $documents): array
      {
        $results = [];

        foreach ($documents as $document) {
          $results[] = $this->embedDocument($document);
        }

        return $results;
      }

      /**
       * Returns the length of the embedding vector
       *
       * @return int Length of the embedding vector
       */
      public function getEmbeddingLength(): int {
        return NewVector::getEmbeddingLength();
      }
    };
  }

  /**
   * Initializes vector stores for the specified tables
   *
   * @param array $tableNames List of table names to initialize
   */
  private function initializeVectorStores(array $tableNames): void
  {
    if ($this->debug) {
      error_log("═══════════════════════════════════════════════════════");
      error_log("🔧 initializeVectorStores() CALLED");
      error_log("Input tableNames: " . (empty($tableNames) ? 'EMPTY' : implode(', ', $tableNames)));
    }

    // 🔥 DÉCISION : Utiliser les tables fournies OU auto-détection
    if (empty($tableNames)) {
      if ($this->debug) {
        error_log("📋 No tables provided, using auto-detection...");
      }

      try {
        $tableNames = DoctrineOrm::getEmbeddingTables();

        if ($this->debug) {
          error_log(" Auto-detected tables: " . implode(", ", $tableNames));
        }
      } catch (\Exception $e) {
        if ($this->debug) {
          error_log(" Auto-detection failed: " . $e->getMessage());
        }

        $this->securityLogger->logSecurityEvent( "Error auto-detecting embedding tables: " . $e->getMessage(), 'error');

        // Fallback ultime
        $prefix = CLICSHOPPING::getConfig('db_table_prefix');
        $tableNames = [$prefix . 'pages_manager_embedding'];
      }
    }

    if ($this->debug) {
      error_log("──────────────────────────────────────────────────");
      error_log("📊 Tables to initialize: " . implode(", ", $tableNames));
    }

    if (empty($tableNames)) {
      error_log("❌❌❌ CRITICAL: No tables to initialize! ❌❌❌");
      return;
    }

    // Initialiser chaque VectorStore
    $successCount = 0;
    $failCount = 0;

    foreach ($tableNames as $tableName) {
      try {
        if ($this->debug) {
          error_log("──────────────────────────────────────────────────");
          error_log("🔧 Creating VectorStore for: {$tableName}");
        }

        // Vérifier que la table existe avant de créer le VectorStore
        if (!DoctrineOrm::checkTableStructure($tableName)) {
          if ($this->debug) {
            error_log("Table {$tableName} does not exist, skipping");
          }
	  
          $failCount++;
          continue;
        }

        
        // Création du VectorStore
        $vectorStore = new MariaDBVectorStore($this->getEmbeddingGenerator(), $tableName);

        // Stocker dans $this->vectorStores
        $this->vectorStores[$tableName] = $vectorStore;

        $successCount++;

        if ($this->debug) {
          error_log(" VectorStore created successfully");
          error_log("Current vectorStores count: " . count($this->vectorStores));
        }

      } catch (\Exception $e) {
        $failCount++;

        if ($this->debug) {
          error_log(" FAILED to create VectorStore for {$tableName}");
          error_log("Error: " . $e->getMessage());
          error_log("Trace: " . $e->getTraceAsString());
        }

       $this->securityLogger->logSecurityEvent("Error while initializing the vector store for the table {$tableName}: " . $e->getMessage(), 'error');

      }
    }

    if ($this->debug) {
      error_log("═══════════════════════════════════════════════════════");
      error_log("📊 INITIALIZATION COMPLETE");
      error_log("Tables attempted: " . count($tableNames));
      error_log("Success: {$successCount}");
      error_log("Failed: {$failCount}");
      error_log("Final vectorStores count: " . count($this->vectorStores));
      error_log("VectorStores keys: " . (empty($this->vectorStores) ? 'NONE' : implode(', ', array_keys($this->vectorStores))));

      if (empty($this->vectorStores)) {
        error_log("CRITICAL: vectorStores is STILL EMPTY! ");
      } else {
        error_log("SUCCESS: vectorStores initialized with " . count($this->vectorStores) . " stores ✅✅✅");
      }
      error_log("═══════════════════════════════════════════════════════");
    }
  }

  /**
   * Adds a document to the specified vector store
   *
   * @param string $content Document content to add
   * @param string $tableName Name of the table to store the document
   * @param string $type Document type
   * @param string $sourceType Source type of the document
   * @param string $sourceName Name of the source
   * @param string|null $entityType Entity type (page, category, product, etc.)
   * @param int|null $entityId Entity ID
   * @param int|null $languageId Language ID
   * @return bool True if successful, false otherwise
   */
  public function addDocument(string $content, string $tableName, string $type = 'text', string $sourceType = 'manual', string $sourceName = 'manual', string|null $entityType = null, int|null $entityId = null, int|null $languageId = null): bool
  {
    try {
      // Check the table if the vector exist
      if (!isset($this->vectorStores[$tableName])) {
        // If the table does not exist, chack if exist inside the db
        if (!DoctrineOrm::checkTableStructure($tableName)) {
          // Id the table does not existe, create it
          if (!DoctrineOrm::createTableStructure($tableName)) {
            throw new \Exception("Unable to create the table {$tableName}");
          }
}

        // Ajouter la table aux vector stores
        $this->vectorStores[$tableName] = new MariaDBVectorStore($this->embeddingGenerator, $tableName);
      }

      // meta data creation
      $document = new Document();
      $document->content = $content;
      $document->sourceType = $sourceType;
      $document->sourceName = $sourceName;
      $document->chunkNumber = 128;

      $document->metadata = [
        'type' => $type,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'language_id' => $languageId,
        'date_modified' => 'now()'
      ];

      $this->vectorStores[$tableName]->addDocument($document);

      return true;
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent('Error while adding the document: ' . $e->getMessage(), 'error');

      return false;
    }
  }


  /**
   * Searches for similar documents across all configured tables
   *
   * @param string $query Search query
   * @param int $limit Maximum number of results per table
   * @param float $minScore Minimum similarity score (0-1)
   * @param int|null $languageId Language ID for filtering results
   * @param string|null $entityType Entity type for filtering results
   * @return array Array of matching documents with similarity scores
   */

  public function searchDocuments(string $query, int $limit = 5, float $minScore = 0.5, int|null $languageId = null, string|null $entityType = null): array
  {
    try {
      // LOG COMPLET
      $array_log = [
        'limit' => $limit,
        'minScore' => $minScore,
        'languageId' => $languageId,
        'entityType' => $entityType
      ];

      $this->logSearchQuery($query, $array_log);

      $allResults = [];

      if ($this->debug) {
        error_log("=== searchDocuments START ===");
        error_log("Query: {$query}");
        error_log("Limit: {$limit}, MinScore: {$minScore}");
        error_log("VectorStores count: " . count($this->vectorStores));
      }

      // VÉRIFICATION CRITIQUE
      if (empty($this->vectorStores)) {
        error_log("CRITICAL: No vector stores! Attempting to reinitialize...");

        // Tenter la réinitialisation
        $this->initializeVectorStores([]);

        if (empty($this->vectorStores)) {
          error_log("FAILED: Still no vector stores after reinitialization");
          return [
            'documents' => [],
            'audit_metadata' => [
              'error' => 'No vector stores initialized',
              'attempted_reinitialization' => true
            ]
          ];
        }
      }

      // Générer l'embedding
      error_log("Generating embedding for query...");
      $queryEmbedding = $this->embeddingGenerator->embedText($query);
      error_log("Embedding generated, length: " . count($queryEmbedding));


      // Créer le filtre
      $filter = null;

      if ($languageId !== null || $entityType !== null) {
        $filter = function($metadata) use ($languageId, $entityType) {
          $match = true;
          if ($languageId !== null && isset($metadata['language_id'])) {
            $match = $match && ($metadata['language_id'] == $languageId);
          }
          if ($entityType !== null && isset($metadata['entity_type'])) {
            $match = $match && ($metadata['entity_type'] == $entityType);
          }
          return $match;
        };
      }
/*
      // 🔧 FIX: Filter tables if allowedTables is provided
      $tablesToSearch = $this->knownEmbeddingTable();
      
      if ($allowedTables !== null && !empty($allowedTables)) {
        // Only search in allowed tables
        $tablesToSearch = array_intersect($tablesToSearch, $allowedTables);
        
        if ($this->debug) {
          error_log("🔍 Table filtering applied:");
          error_log("  - Original tables: " . count($this->knownEmbeddingTable()));
          error_log("  - Allowed tables: " . implode(', ', $allowedTables));
          error_log("  - Filtered tables: " . implode(', ', $tablesToSearch));
        }
      }
*/      
      // RECHERCHE PRIORITAIRE
      foreach ($this->knownEmbeddingTable() as $priorityTable) {
        if (isset($this->vectorStores[$priorityTable])) {
          error_log("Searching in priority table: {$priorityTable}");

          try {
// Check here  $results = 0;
            $results = $this->vectorStores[$priorityTable]->similaritySearch($queryEmbedding, $limit * 2, max(0.01, $minScore - 0.15), $filter);
            $resultsArray = is_array($results) ? $results : iterator_to_array($results);

            foreach ($resultsArray as $document) {
              if (isset($document->metadata['score'])) {
                $document->metadata['score'] = min(1.0, $document->metadata['score'] * 1.15);
                $document->metadata['priority_boost'] = true;
              }
              $allResults[] = $document;
            }
          } catch (\Exception $e) {
            error_log("Priority search error in {$priorityTable}: " . $e->getMessage());
          }
        }
      }

      // Prepare audit metadata
      $auditMetadata = [
        'priority_table' => $priorityTable ?? 'none',
        'tables_searched' => count($tablesToSearch),
        'tables_filtered' => $allowedTables !== null,
        'allowed_tables' => $allowedTables ?? [],
        'initial_results_count' => count($allResults)
      ];

      // Apply LLMReranker if enabled (Task 2.14.3)
      if ($this->debug) {
        error_log("🔍 Reranking check:");
        error_log("  - useReranking: " . ($this->useReranking ? 'true' : 'false'));
        error_log("  - reranker is null: " . ($this->reranker === null ? 'true' : 'false'));
        error_log("  - allResults count: " . count($allResults));
      }
      
      if ($this->useReranking && $this->reranker !== null && count($allResults) > 0) {
        try {
          if ($this->debug) {
            error_log("🔄 Applying LLMReranker to improve relevance...");
            error_log("Query for reranking: {$query}");
            error_log("Documents before reranking: " . count($allResults));
          }
          
          // Get the configured number of output documents for reranking
          // We send 2-3x more documents than we want back to give the LLM options
          $rerankingOutputCount = defined('CLICSHOPPING_APP_CHATGPT_RA_RERANKING_OUTPUT') 
                                   ? (int)CLICSHOPPING_APP_CHATGPT_RA_RERANKING_OUTPUT 
                                   : 5;
          
          // Send 2x the output count to the reranker (but not more than available)
          $initialLimit = min(count($allResults), $rerankingOutputCount * 2);
          $documentsForReranking = array_slice($allResults, 0, $initialLimit);
          
          if ($this->debug) {
            error_log("Reranking {$initialLimit} documents to get top {$rerankingOutputCount}");
          }
          
          // Apply LLMReranker - this will reorder documents by relevance
          // transformDocuments expects: array of questions, array of documents
          $rerankedDocuments = $this->reranker->transformDocuments([$query], $documentsForReranking);
          
          if ($this->debug) {
            error_log("✅ Reranking complete: " . count($rerankedDocuments) . " documents");
            
            // Log reranked order for debugging
            foreach ($rerankedDocuments as $i => $doc) {
              $preview = substr($doc->content, 0, 100);
              $score = $doc->metadata['score'] ?? 0;
              error_log("Reranked #{$i} (score: {$score}): {$preview}...");
            }
          }
          
          // Use reranked documents
          $allResults = $rerankedDocuments;
          
          // Add reranking metadata
          $auditMetadata['reranking_applied'] = true;
          $auditMetadata['reranking_input_count'] = $initialLimit;
          $auditMetadata['reranking_output_count'] = count($rerankedDocuments);
          $auditMetadata['final_results_count'] = count($allResults);
          
        } catch (\Exception $e) {
          error_log("❌ Reranking failed: " . $e->getMessage());
          error_log("Falling back to original order");
          
          // Fallback: use original order, just take top N
          $allResults = array_slice($allResults, 0, $limit);
          $auditMetadata['reranking_failed'] = true;
          $auditMetadata['reranking_error'] = $e->getMessage();
          $auditMetadata['final_results_count'] = count($allResults);
        }
      } else {
        // No reranking, just take top N by similarity score
        $allResults = array_slice($allResults, 0, $limit);
        $auditMetadata['reranking_applied'] = false;
        $auditMetadata['final_results_count'] = count($allResults);
        
        if ($this->debug) {
          error_log("ℹ️ Reranking disabled or not available, using top {$limit} by similarity");
        }
      }

      $result = [
        'documents' => $allResults,
        'audit_metadata' => $auditMetadata
      ];

      return $result;
    } catch (\Exception $e) {
      error_log("EXCEPTION in searchDocuments: " . $e->getMessage());
      error_log("Trace: " . $e->getTraceAsString());

      return [
        'documents' => [],
        'audit_metadata' => ['error' => $e->getMessage()]
      ];
    }
  }


  /**
   * Formats the analysis results for display
   *
   * @param array $results Analysis results
   * @return string|null Formatted results for display
   */
  public function formatResults(array $results): string|null
  {
    try {
      // Validate input
      if (empty($results)) {
        return 'No results to display';
      }

      // If results don't have a 'type' key, try to infer it or set a default
      if (!isset($results['type'])) {
        // Check if it looks like analytics results
        if (isset($results['results']) && is_array($results['results'])) {
          $results['type'] = 'analytics_results';
        }
        // Check if it looks like a semantic response
        else if (isset($results['response']) || isset($results['query'])) {
          $results['type'] = 'semantic_results';
        }
        // Default to unknown
        else {
          $results['type'] = 'unknown';
        }
      }

      $result = $this->resultFormatter->format($results);

      if (is_array($result) && array_key_exists('content', $result)) {
        $result = $result['content'];
      } else {
        $result = '<span class="alert alert-warning">Error : please change or adapt your question</span>';
      }

      return $result;
    } catch (\Exception $e) {
      // Log the error for debugging
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          'Error in formatResults: ' . $e->getMessage(),
          'error',
          ['results' => $results]
        );
      }

      return 'An error occurred while formatting the results. Please try again.';
    }
  }

  /**
   * Executes an analytical query on e-commerce data
   *
   * This method is specifically designed for analytical queries
   * that require calculations, aggregations, or precise searches
   * on numerical or structured data.
   *
   * @param string $query User\'s question or query
   * @param string|null $entityType Type of entity to analyze (products, orders, etc.)
   * @return array Analysis results with structured data
   */
  public function executeAnalyticsQuery(string $query, string|null $entityType = null): array
  {
    try {
      $analyticsAgent = new AnalyticsAgent();

      //Check the request
      if (!$analyticsAgent->isAnalyticsQuery($query)) {
        return [
          'type'    => 'not_analytics',
          'message' => CLICSHOPPING::getDef('text_not_analytics')
        ];
      }

      $results = $analyticsAgent->processBusinessQuery($query);

      if ($results['type'] === 'error') {
        return [
          'type'    => 'error',
          'message' => $results['message']
        ];
      }

        $matchedCategories = $analyticsAgent->getAnalyticsCategories($query);

        $response = [
        'type'               => 'analytics_results',
        'query'              => $query,
        'matched_categories' => $matchedCategories,
        'interpretation'     => Hash::displayDecryptedDataText($results['interpretation'] ?? ''),
        'count'              => $results['count'] ?? 0,
        'results'            => $results['results'] ?? []
      ];

      // Si on a plusieurs blocs de requêtes SQL
      if (isset($results['multi_query_results'])) {
        $response['multi_query_results'] = $results['multi_query_results'];
      }
      // Sinon on renvoie la requête SQL unique
      else {
        // clé sql_query créée par processBusinessQuery
        $response['sql_query'] = $results['sql_query'] ?? '';
        // si vous conservez l’originale
        if (isset($results['original_sql_query'])) {
          $response['original_sql_query'] = $results['original_sql_query'];
        }
        // corrections éventuelles
        if (isset($results['corrections'])) {
          $response['corrections'] = $results['corrections'];
        }
      }

      return $response;

    } catch (\Exception $e) {
      return [
        'type'    => 'error',
        'message' => 'Error executing analytics query: ' . $e->getMessage()
      ];
    }
  }

  /**
   * LOGGING METHOD - Ajouter cette méthode dans MultiDBRAGManager
   * Log les détails complets d'une requête de recherche
   */
  private function logSearchQuery(string $query, array $params): void
  {
    $logMessage = "=== SEARCH QUERY LOG ===\n";
    $logMessage .= "Query: {$query}\n";
    $logMessage .= "Params:\n";
    $logMessage .= "  - limit: " . ($params['limit'] ?? 'N/A') . "\n";
    $logMessage .= "  - minScore: " . ($params['minScore'] ?? 'N/A') . "\n";
    $logMessage .= "  - languageId: " . ($params['languageId'] ?? 'N/A') . "\n";
    $logMessage .= "  - entityType: " . ($params['entityType'] ?? 'N/A') . "\n";
    $logMessage .= "Vector stores available: " . count($this->vectorStores) . "\n";
    $logMessage .= "Vector stores keys: " . implode(', ', array_keys($this->vectorStores)) . "\n";

    error_log($logMessage);
    if ($this->debug) {
      $this->securityLogger->logSecurityEvent($logMessage, 'info');
    }
  }


  /**
   * Answer a question using RAG (Retrieval-Augmented Generation)
   *
   * TASK 4.4.2 RESTORATION: This method was accidentally removed by autofix
   * Restored to fix semantic RAG regression
   *
   * This method:
   * 1. Searches for relevant documents using embeddings
   * 2. Builds context from retrieved documents
   * 3. Uses LLM to generate answer based on context
   *
   * @param string $question Question to answer
   * @param int $limit Maximum number of documents to retrieve
   * @param float $minScore Minimum similarity score
   * @param int|null $languageId Language ID for filtering
   * @param string|null $entityType Entity type for filtering
   * @param array $options Additional options (return_metadata, etc.)
   * @return array|string Answer with metadata or just answer string
   */
  public function answerQuestion(
    string $question,
    int $limit = 5,
    float $minScore = 0.5,
    ?int $languageId = null,
    ?string $entityType = null,
    array $options = []
  ): array|string
  {
    try {
      if ($this->debug) {
        error_log("=== answerQuestion() START ===");
        error_log("Question: {$question}");
        error_log("Limit: {$limit}, MinScore: {$minScore}");
      }

      // Search for relevant documents
      $searchResult = $this->searchDocuments(
        $question,
        $limit,
        $minScore,
        $languageId,
        $entityType
      );

      $documents = $searchResult['documents'] ?? [];
      $auditMetadata = $searchResult['audit_metadata'] ?? [];

      if ($this->debug) {
        error_log("Found " . count($documents) . " documents");
      }

      // If no documents found, return "no information" message
      if (empty($documents)) {
        $noInfoMessage = "I don't have that information in my knowledge base.";

        if ($options['return_metadata'] ?? false) {
          return [
            'response' => $noInfoMessage,
            'audit_metadata' => $auditMetadata,
            'documents_found' => 0
          ];
        }

        return $noInfoMessage;
      }

      // Build context from documents (with priority handling)
      $context = $this->optimizeContext($documents, 3000);

      // Generate answer using LLM with context
      $synthesisPrompt = "You are a helpful assistant for an e-commerce platform.

Answer the user's question using the documents provided below.

IMPORTANT GUIDELINES:
1. Prioritize documents that directly answer the question
2. If you see ORDER documents (with order IDs, customer names, order status) but the question is about POLICIES or GENERAL TERMS, try to extract any relevant policy information from those documents, but note if the information seems incomplete
3. If you see POLICY documents (terms, conditions, return policies) but the question is about a SPECIFIC ORDER, note that you're providing general policy information
4. Use all relevant information available, even if it's not perfect
5. If the documents truly don't contain relevant information, say so clearly

Question: {$question}

Documents:
{$context}

Provide a helpful answer based on the documents above:";

      // 🔥 CRITICAL FIX: Add language instruction to ensure response in correct language
      // This forces the LLM to respond in the user's language (French/English)
      $languageInstruction = CLICSHOPPING::getDef('text_rag_language_instruction');
      $synthesisPrompt .= $languageInstruction;

      if ($this->debug) {
        error_log("📤 Prompt with language instruction: " . strlen($synthesisPrompt) . " chars");
      }

      try {
        $answer = Gpt::getGptResponse($synthesisPrompt, 300);

        if ($this->debug) {
          error_log("Generated answer length: " . strlen($answer) . " chars");
        }
      } catch (\Exception $e) {
        if ($this->debug) {
          error_log("Error generating answer: " . $e->getMessage());
        }

        // Fallback: return first document content
        $answer = $documents[0]->content ?? "Error generating answer.";
      }

      // Return with metadata if requested
      if ($options['return_metadata'] ?? false) {
        return [
          'response' => $answer,
          'audit_metadata' => $auditMetadata,
          'documents_found' => count($documents),
          'context_length' => strlen($context)
        ];
      }

      return $answer;

    } catch (\Exception $e) {
      if ($this->debug) {
        error_log("answerQuestion() exception: " . $e->getMessage());
      }

      $errorMessage = "Error answering question: " . $e->getMessage();

      if ($options['return_metadata'] ?? false) {
        return [
          'response' => $errorMessage,
          'audit_metadata' => [],
          'error' => $e->getMessage()
        ];
      }

      return $errorMessage;
    }
  }

  /**
   * Build context with priority document handling
   * 
   * Priority documents (with priority_boost metadata) get full content
   * Other documents are truncated to maxCharsPerDoc
   * 
   * This ensures critical information from priority sources is not lost,
   * which is essential for accurate answers (e.g., "14 days" vs "7 days")
   * 
   * @param array $documents Array of Document objects
   * @param int $maxCharsPerDoc Maximum chars per non-priority document
   * @return string Formatted context string
   */
  private function optimizeContext(array $documents, int $maxCharsPerDoc = 3000): string
  {
    $context = '';
    $totalChars = 0;
    $maxTotalChars = 60000; // Global limit: ~15,000 tokens

    // Function to detect priority documents
    $isPriorityDoc = function($doc) {
      return isset($doc->metadata['priority_boost']) && $doc->metadata['priority_boost'] === true;
    };

    foreach ($documents as $i => $doc) {
      // Priority documents get FULL content (no truncation)
      if ($isPriorityDoc($doc)) {
        $docContent = $doc->content; // ✅ FULL CONTENT
        $label = "Document " . ($i + 1) . " (Priority Source)";
        
        if ($this->debug) {
          error_log("📄 Doc #{$i} PRIORITY: " . strlen($docContent) . " chars (full content)");
        }
      } else {
        // Other documents are truncated
        $docContent = $doc->content;
        if (strlen($docContent) > $maxCharsPerDoc) {
          $docContent = mb_substr($docContent, 0, $maxCharsPerDoc) . "\n[...content truncated...]";
        }
        $label = "Document " . ($i + 1);
        
        if ($this->debug) {
          error_log("📄 Doc #{$i} secondary: " . strlen($docContent) . " chars (truncated)");
        }
      }

      // Check global limit
      if ($totalChars + strlen($docContent) > $maxTotalChars) {
        if ($this->debug) {
          error_log("⚠️ Context limit reached after " . ($i + 1) . " documents");
        }
        break;
      }

      $context .= "--- {$label} ---\n";
      $context .= $docContent . "\n\n";
      $totalChars += strlen($docContent);
    }

    if ($this->debug) {
      error_log("📊 Context built: {$totalChars} chars (~" . round($totalChars/4) . " tokens)");
    }

    return $context;
  }

  /**
   * Convertit le tableau de contexte structuré en une chaîne de caractères lisible par le LLM.
   * (C'est la logique que j'avais placée dans MemoryRetentionService, mais que vous déplacez ici.)
   */
  private function formatContextArrayToLlmString(array $context): string
  {
    $formatted = "";

    // Working Memory
    if (!empty($context['working_memory']) && !empty($context['working_memory']['last_message'])) {
      $formatted .= "## IMMEDIATE WORKING MEMORY\n";
      $formatted .= "Last Question: " . substr($context['working_memory']['last_message'], 0, 200) . "\n";
      $formatted .= "Last Answer: " . substr($context['working_memory']['last_response'], 0, 200) . "\n";
      $formatted .= "---\n\n";
    }

    // Short-term (Current Session)
    if (!empty($context['short_term']) && is_array($context['short_term'])) {
      $formatted .= "## CURRENT SESSION HISTORY\n";
      foreach ($context['short_term'] as $message) {
        if (is_array($message)) {
          $role = isset($message['role']) ? ucfirst($message['role']) : 'System';
          $content = isset($message['content']) ? trim($message['content']) : '';
          if (!empty($content)) {
            $formatted .= "{$role}: " . substr($content, 0, 150) . "\n";
          }
        }
      }
      $formatted .= "---\n\n";
    }

    // Long-term (RAG Semantic)
    if (!empty($context['long_term']) && is_array($context['long_term'])) {
      $formatted .= "## RELEVANT PREVIOUS CONTEXT (SEMANTIC RAG)\n";
      foreach ($context['long_term'] as $interaction) {
        if (is_array($interaction)) {
          $userMsg = $interaction['user_message'] ?? '';
          $sysResp = $interaction['system_response'] ?? '';
          if (!empty($userMsg) && !empty($sysResp)) {
            $formatted .= "Q: " . substr($userMsg, 0, 100) . "\n";
            $formatted .= "A: " . substr($sysResp, 0, 100) . "\n";
            $formatted .= "---\n";
          }
        }
      }
      $formatted .= "\n";
    }

    return trim($formatted);
  }


//***********************
// not used
//***********************

  /**
   * Analyse le contenu réel d'une table pour comprendre ce qu'elle contient
   *
   * @param string $tableName Nom de la table à analyser
   * @return array Statistiques et échantillons de contenu
   */
  private function analyzeTableContent(string $tableName): array
  {
    // Utiliser le cache si disponible
    if (isset(self::$tableStatsCache[$tableName])) {
      return self::$tableStatsCache[$tableName];
    }

    try {
      // 🔧 TASK 4.4.1 PHASE 7: Migrated to DoctrineOrm
      
      // Compter les documents
      $count = DoctrineOrm::selectOne("SELECT COUNT(*) as total FROM {$tableName}");

      if ($count['total'] == 0) {
        self::$tableStatsCache[$tableName] = [
          'total_documents' => 0,
          'has_content' => false,
          'types' => [],
          'sample_content' => ''
        ];
        return self::$tableStatsCache[$tableName];
      }

      // Récupérer les types de documents présents
      $typesRows = DoctrineOrm::select("
        SELECT DISTINCT type, COUNT(*) as count 
        FROM {$tableName} 
        GROUP BY type
      ");
      
      $types = [];
      foreach ($typesRows as $row) {
        $types[$row['type']] = $row['count'];
      }

      // Récupérer un échantillon de contenu pour analyse sémantique
      $sample = DoctrineOrm::selectOne("
        SELECT GROUP_CONCAT(LEFT(content, 200) SEPARATOR ' ') as sample
        FROM (
          SELECT content FROM {$tableName} 
          ORDER BY RAND() 
          LIMIT 5
        ) as samples
      ");

      $stats = [
        'total_documents' => $count['total'],
        'has_content' => true,
        'types' => $types,
        'sample_content' => $sample['sample'] ?? ''
      ];

      self::$tableStatsCache[$tableName] = $stats;

      if ($this->debug) {
        error_log("📊 Table {$tableName} stats: " . json_encode($stats));
      }

      return $stats;

    } catch (\Exception $e) {
      if ($this->debug) {
        error_log("️ Error analyzing table {$tableName}: " . $e->getMessage());
      }

      return [
        'total_documents' => 0,
        'has_content' => false,
        'types' => [],
        'sample_content' => ''
      ];
    }
  }

/**
* Construit le prompt avec le contexte récupéré par le MemoryService.
*
* @param string $prompt La requête utilisateur originale.
* @param array $context Le contexte structuré (array) retourné par MemoryRetentionService.
* @return string Le prompt final formaté pour le LLM.
   */
  private function buildPromptWithContext(string $userQuery, array $context): string
  {
    if (empty(array_filter($context, fn($v) => !empty($v)))) {
      return $userQuery;
    }

    $contextString = $this->formatContextArrayToLlmString($context);

    if (empty(trim($contextString))) {
      return $userQuery;
    }

    return "RAG Context (Previous Interactions):\n---\n{$contextString}\n---\n\nCurrent Request: {$userQuery}";
  }



  /**
   * DIAGNOSTIC METHOD - Ajouter cette méthode dans MultiDBRAGManager
   * Vérifie l'état de toutes les tables d'embedding et le contenu
   */
  public function performDiagnostics(): array
  {
    error_log("=== DIAGNOSTIC START ===");
    $diagnostics = [
      'timestamp' => date('Y-m-d H:i:s'),
      'vector_stores' => [],
      'tables_status' => [],
      'content_samples' => []
    ];

    // 1. Vérifier chaque VectorStore initialisé
    error_log("Step 1: Checking initialized VectorStores");
    foreach ($this->vectorStores as $tableName => $vectorStore) {
      error_log("  - VectorStore found: {$tableName}");
      $diagnostics['vector_stores'][] = $tableName;
    }

    if (empty($this->vectorStores)) {
      error_log("  WARNING: No VectorStores initialized!");
      $diagnostics['vector_stores'] = ['ERROR' => 'No VectorStores'];
    }

    // 2. Vérifier les tables d'embedding dans la base
    error_log("Step 2: Checking embedding tables in database");
    // 🔧 TASK 4.4.1 PHASE 7: Migrated to DoctrineOrm

    foreach ($this->knownEmbeddingTable() as $tableName) {
      try {
        $result = DoctrineOrm::selectOne("SELECT COUNT(*) as cnt FROM {$tableName}");
        $count = $result['cnt'] ?? 0;

        error_log("  - Table {$tableName}: {$count} documents");
        $diagnostics['tables_status'][$tableName] = [
          'exists' => true,
          'document_count' => $count
        ];

        // Si la table a du contenu, prendre un échantillon
        if ($count > 0) {
          $sample = DoctrineOrm::selectOne(
            "SELECT content FROM {$tableName} LIMIT 1"
          );

          if ($sample) {
            $preview = substr($sample['content'], 0, 200);
            error_log("    Sample preview: {$preview}...");
            $diagnostics['content_samples'][$tableName] = $preview;
          }
        }

      } catch (\Exception $e) {
        error_log("  - Table {$tableName}: NOT FOUND - {$e->getMessage()}");
        $diagnostics['tables_status'][$tableName] = [
          'exists' => false,
          'error' => $e->getMessage()
        ];
      }
    }

    // 3. Tenter une recherche test
    error_log("Step 3: Testing search with simple query");
    try {
      // Recherche très permissive
      $testResults = $this->searchDocuments(
        "Strauss produit prix",
        limit: 10,
        minScore: 0.01  // Très bas pour voir tous les résultats
      );

      $docCount = count($testResults['documents'] ?? []);
      error_log("  - Search returned: {$docCount} documents");
      $diagnostics['search_test'] = [
        'query' => 'Strauss produit prix',
        'results_count' => $docCount,
        'audit_metadata' => $testResults['audit_metadata'] ?? []
      ];

    } catch (\Exception $e) {
      error_log("  - Search failed: {$e->getMessage()}");
      $diagnostics['search_test'] = [
        'error' => $e->getMessage()
      ];
    }

    error_log("=== DIAGNOSTIC END ===");
    return $diagnostics;
  }


//*****************************************
// do not use
//*****************************************
  /**
   * Add document with separated taxonomy (Task 4.1)
   *
   * This method stores content, metadata, and taxonomy in separate fields
   * to eliminate semantic collision in vector searches. The content is validated
   * to ensure it's taxonomy-free before embedding generation.
   *
   * @param string $content Pure content (no taxonomy markers)
   * @param array $metadata Document metadata (entity_type, entity_id, document_type, etc.)
   * @param array|null $taxonomy Structured taxonomy data (domaine, type_produit, etc.)
   * @param string $tableName Name of the table to store the document
   * @param string $entityType Entity type (page, product, order, etc.)
   * @param int $entityId Entity ID
   * @param int|null $languageId Language ID (default: 1)
   * @param int $chunkNumber Chunk number for multi-chunk documents (default: 0)
   * @return bool True if successful, false otherwise
   */
  public function addDocumentWithTaxonomy(
    string $content,
    array $metadata,
    ?array $taxonomy,
    string $tableName,
    string $entityType,
    int $entityId,
    ?int $languageId = null,
    int $chunkNumber = 0
  ): bool
  {
    try {
      // Validate content purity (Task 4.4)
      $validation = $this->validateContentPurity($content, $entityType, $entityId);
      if (!$validation['valid']) {
        $this->securityLogger->logSecurityEvent(
          'Content purity validation failed - cannot add document',
          'error',
          [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'table' => $tableName,
            'errors' => $validation['errors']
          ]
        );
        return false;
      }

      // Ensure vector store exists for this table
      if (!isset($this->vectorStores[$tableName])) {
        if (!DoctrineOrm::checkTableStructure($tableName)) {
          if (!DoctrineOrm::createTableStructure($tableName)) {
            throw new \Exception("Unable to create table {$tableName}");
          }
        }
        $this->vectorStores[$tableName] = new MariaDBVectorStore($this->embeddingGenerator, $tableName);
      }

      // Set default language if not provided
      $languageId = $languageId ?? 1;

      // Ensure required metadata fields are present
      $metadata = array_merge([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'language_id' => $languageId,
        'chunk_number' => $chunkNumber,
        'date_added' => date('Y-m-d H:i:s'),
        'date_modified' => date('Y-m-d H:i:s')
      ], $metadata);

      // Validate metadata contains required fields
      $requiredFields = ['entity_type', 'entity_id', 'document_type'];
      foreach ($requiredFields as $field) {
        if (!isset($metadata[$field])) {
          $this->securityLogger->logSecurityEvent(
            "Missing required metadata field: {$field}",
            'error',
            ['metadata' => $metadata]
          );
          return false;
        }
      }

      // Validate taxonomy structure if provided
      if ($taxonomy !== null) {
        $taxonomyExtractor = new TaxonomyExtractor();
        $taxonomyValidation = $taxonomyExtractor->validate($taxonomy);
        if (!$taxonomyValidation['valid']) {
          $this->securityLogger->logSecurityEvent(
            'Invalid taxonomy structure',
            'warning',
            [
              'errors' => $taxonomyValidation['errors'],
              'taxonomy' => $taxonomy
            ]
          );
          // Continue with null taxonomy rather than failing
          $taxonomy = null;
        }
      }

      // Create document object for embedding
      $document = new Document();
      $document->content = $content;
      $document->sourceType = 'taxonomy_separated';
      $document->sourceName = $entityType;
      $document->chunkNumber = $chunkNumber;
      $document->metadata = $metadata;

      // Store document with separated fields
      // The MariaDBVectorStore will handle storing content, embedding, metadata, and taxonomy
      $success = $this->vectorStores[$tableName]->addDocumentWithTaxonomy(
        $document,
        $taxonomy
      );

      // Audit logging for embedding generation (Task 4.4)
      if ($success) {
        $this->logEmbeddingGeneration(
          $entityType,
          $entityId,
          $tableName,
          strlen($content),
          $taxonomy !== null,
          array_keys($metadata)
        );
      }

      return $success;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        'Error adding document with taxonomy: ' . $e->getMessage(),
        'error',
        [
          'entity_type' => $entityType,
          'entity_id' => $entityId,
          'table' => $tableName,
          'trace' => $e->getTraceAsString()
        ]
      );
      return false;
    }
  }

  /**
   * Get document with taxonomy (Task 4.2)
   *
   * This method retrieves a document with content, metadata, and taxonomy
   * as separate properties. It ensures metadata contains required fields.
   *
   * @param int $id Document ID
   * @param string|null $tableName Table name (if null, searches all tables)
   * @return array|null Document data ['content', 'metadata', 'taxonomy', 'embedding'] or null if not found
   */
  public function getDocumentWithTaxonomy(int $id, ?string $tableName = null): ?array
  {
    try {
      // If table name provided, search only that table
      if ($tableName !== null) {
        $tables = [$tableName];
      } else {
        // Search all embedding tables
        $tables = $this->knownEmbeddingTable();
      }

      foreach ($tables as $table) {
        try {
          // Check if table has taxonomy column
          $hasTaxonomyColumn = DoctrineOrm::selectOne(
            "SELECT COUNT(*) as count 
             FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = ? 
             AND COLUMN_NAME = 'taxonomy'",
            [$table]
          );

          $hasTaxonomy = ($hasTaxonomyColumn['count'] ?? 0) > 0;

          // Build SELECT query
          $taxonomySelect = $hasTaxonomy ? ', taxonomy' : '';
          
          $sql = "SELECT id, content, metadata, embedding{$taxonomySelect}, 
                         entity_id, language_id, date_modified
                  FROM {$table}
                  WHERE id = ?";

          $result = DoctrineOrm::selectOne($sql, [$id]);

          if ($result) {
            // Parse metadata JSON
            $metadata = [];
            if (!empty($result['metadata'])) {
              $metadata = json_decode($result['metadata'], true) ?? [];
            }

            // Ensure required metadata fields
            // Note: entity_type comes from metadata JSON, not from a column
            $metadata = array_merge([
              'entity_type' => $metadata['entity_type'] ?? null,
              'entity_id' => $result['entity_id'] ?? null,
              'language_id' => $result['language_id'] ?? 1,
              'document_type' => $metadata['document_type'] ?? 'unknown',
              'date_modified' => $result['date_modified'] ?? null,
            ], $metadata);

            // Validate required fields are present
            $requiredFields = ['entity_type', 'entity_id', 'document_type'];
            foreach ($requiredFields as $field) {
              if (!isset($metadata[$field]) || $metadata[$field] === null) {
                $this->securityLogger->logSecurityEvent(
                  "Document {$id} missing required metadata field: {$field}",
                  'warning',
                  ['table' => $table, 'metadata' => $metadata]
                );
              }
            }

            // Parse taxonomy JSON if present
            $taxonomy = null;
            if ($hasTaxonomy && !empty($result['taxonomy'])) {
              $taxonomy = json_decode($result['taxonomy'], true);
            }

            return [
              'id' => $result['id'],
              'content' => $result['content'],
              'metadata' => $metadata,
              'taxonomy' => $taxonomy,
              'embedding' => $result['embedding'],
              'table_name' => $table
            ];
          }

        } catch (\Exception $e) {
          // Continue to next table if this one fails
          if ($this->debug) {
            $this->securityLogger->logSecurityEvent(
              "Error searching table {$table}: " . $e->getMessage(),
              'debug'
            );
          }
          continue;
        }
      }

      // Document not found in any table
      return null;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        'Error retrieving document with taxonomy: ' . $e->getMessage(),
        'error',
        ['id' => $id, 'table' => $tableName]
      );
      return null;
    }
  }

  /**
   * Search documents with metadata filtering (Task 4.3)
   *
   * This method performs similarity search with additional metadata filtering.
   * It uses JSON indexes for efficient filtering by document_type and other metadata fields.
   *
   * @param string $query Search query
   * @param array $metadataFilter Filter by metadata fields (e.g., ['document_type' => 'policy_page'])
   * @param int $limit Maximum number of results
   * @param float $minScore Minimum similarity score (0-1)
   * @param int|null $languageId Language ID for filtering
   * @param string|null $entityType Entity type for filtering
   * @return array Search results with taxonomy ['documents' => array, 'audit_metadata' => array]
   */
  public function searchWithMetadataFilter(
    string $query,
    array $metadataFilter = [],
    int $limit = 10,
    float $minScore = 0.5,
    ?int $languageId = null,
    ?string $entityType = null
  ): array
  {
    try {
      if ($this->debug) {
        error_log("=== searchWithMetadataFilter START ===");
        error_log("Query: {$query}");
        error_log("Metadata filter: " . json_encode($metadataFilter));
        error_log("Limit: {$limit}, MinScore: {$minScore}");
      }

      // Generate embedding for query
      $queryEmbedding = $this->embeddingGenerator->embedText($query);

      $allResults = [];
      $tablesSearched = 0;
      $documentsFiltered = 0;

      // Search across all vector stores
      foreach ($this->vectorStores as $tableName => $vectorStore) {
        try {
          $tablesSearched++;

          // Perform similarity search
          $results = $vectorStore->similaritySearch(
            $queryEmbedding,
            $limit * 2, // Get more results for filtering
            max(0.01, $minScore - 0.15)
          );

          $resultsArray = is_array($results) ? $results : iterator_to_array($results);

          // Apply metadata filtering
          foreach ($resultsArray as $document) {
            $docMetadata = $document->metadata ?? [];

            // Apply language filter
            if ($languageId !== null && isset($docMetadata['language_id'])) {
              if ($docMetadata['language_id'] != $languageId) {
                $documentsFiltered++;
                continue;
              }
            }

            // Apply entity type filter
            if ($entityType !== null && isset($docMetadata['entity_type'])) {
              if ($docMetadata['entity_type'] != $entityType) {
                $documentsFiltered++;
                continue;
              }
            }

            // Apply custom metadata filters
            $passesFilter = true;
            foreach ($metadataFilter as $key => $value) {
              // Support nested JSON path queries (e.g., 'document_type')
              if (isset($docMetadata[$key])) {
                if ($docMetadata[$key] != $value) {
                  $passesFilter = false;
                  break;
                }
              } else {
                // If metadata field doesn't exist, document doesn't match filter
                $passesFilter = false;
                break;
              }
            }

            if (!$passesFilter) {
              $documentsFiltered++;
              continue;
            }

            // Apply minimum score filter
            $score = $docMetadata['score'] ?? 0;
            if ($score < $minScore) {
              $documentsFiltered++;
              continue;
            }

            // Add table name to metadata
            $document->metadata['table_name'] = $tableName;

            $allResults[] = $document;
          }

        } catch (\Exception $e) {
          if ($this->debug) {
            error_log("Error searching table {$tableName}: " . $e->getMessage());
          }
          continue;
        }
      }

      // Sort by score descending
      usort($allResults, function($a, $b) {
        $scoreA = $a->metadata['score'] ?? 0;
        $scoreB = $b->metadata['score'] ?? 0;
        return $scoreB <=> $scoreA;
      });

      // Limit results
      $allResults = array_slice($allResults, 0, $limit);

      // Prepare audit metadata
      $auditMetadata = [
        'tables_searched' => $tablesSearched,
        'metadata_filter_applied' => !empty($metadataFilter),
        'filter_criteria' => $metadataFilter,
        'documents_filtered' => $documentsFiltered,
        'initial_results_count' => count($allResults) + $documentsFiltered,
        'final_results_count' => count($allResults)
      ];

      if ($this->debug) {
        error_log("=== searchWithMetadataFilter END ===");
        error_log("Tables searched: {$tablesSearched}");
        error_log("Documents filtered: {$documentsFiltered}");
        error_log("Final results: " . count($allResults));
      }

      return [
        'documents' => $allResults,
        'audit_metadata' => $auditMetadata
      ];

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        'Error in searchWithMetadataFilter: ' . $e->getMessage(),
        'error',
        [
          'query' => $query,
          'filter' => $metadataFilter,
          'trace' => $e->getTraceAsString()
        ]
      );

      return [
        'documents' => [],
        'audit_metadata' => [
          'error' => $e->getMessage(),
          'metadata_filter_applied' => !empty($metadataFilter)
        ]
      ];
    }
  }

  /**
   * Validate content purity before embedding (Task 4.4)
   *
   * This method ensures content is free of taxonomy markers before embedding generation.
   * It also provides audit logging for embedding generation.
   *
   * @param string $content Content to validate
   * @param string $entityType Entity type for logging
   * @param int $entityId Entity ID for logging
   * @return array Validation result ['valid' => bool, 'errors' => array]
   */
  private function validateContentPurity(string $content, string $entityType, int $entityId): array
  {
    $errors = [];
    $taxonomyExtractor = new TaxonomyExtractor();

    // Check for taxonomy markers
    if ($taxonomyExtractor->hasTaxonomy($content)) {
      $errors[] = 'Content contains taxonomy markers ([Taxonomy]: or [taxonomy]:)';
    }

    // Check for common taxonomy patterns that might have been missed
    $taxonomyPatterns = [
      '/\[Taxonomy\]/i',
      '/\[taxonomy\]/i',
      '/domaine:\s*"[^"]*"/i',
      '/type_produit:\s*"[^"]*"/i',
    ];

    foreach ($taxonomyPatterns as $pattern) {
      if (preg_match($pattern, $content)) {
        $errors[] = "Content contains taxonomy pattern: {$pattern}";
      }
    }

    $isValid = empty($errors);

    // Audit logging
    if ($this->debug || !$isValid) {
      $this->securityLogger->logSecurityEvent(
        $isValid ? 'Content purity validated' : 'Content purity validation failed',
        $isValid ? 'info' : 'warning',
        [
          'entity_type' => $entityType,
          'entity_id' => $entityId,
          'content_length' => strlen($content),
          'valid' => $isValid,
          'errors' => $errors
        ]
      );
    }

    return [
      'valid' => $isValid,
      'errors' => $errors
    ];
  }

  /**
   * Log embedding generation for audit (Task 4.4)
   *
   * This method logs the separation of content, metadata, and taxonomy
   * for audit purposes during embedding generation.
   *
   * @param string $entityType Entity type
   * @param int $entityId Entity ID
   * @param string $tableName Table name
   * @param int $contentLength Length of content
   * @param bool $hasTaxonomy Whether taxonomy was provided
   * @param array $metadataFields Metadata field names
   * @return void
   */
  private function logEmbeddingGeneration(
    string $entityType,
    int $entityId,
    string $tableName,
    int $contentLength,
    bool $hasTaxonomy,
    array $metadataFields
  ): void
  {
    $this->securityLogger->logSecurityEvent(
      'Embedding generated with separated taxonomy',
      'info',
      [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'table' => $tableName,
        'content_length' => $contentLength,
        'has_taxonomy' => $hasTaxonomy,
        'metadata_fields' => $metadataFields,
        'timestamp' => date('Y-m-d H:i:s'),
        'embedding_source' => 'content_only' // Confirms embedding from content only
      ]
    );
  }

  /**
   * Detect data structure version (Task 4.5)
   *
   * This method detects whether a document uses the old structure (taxonomy in content)
   * or the new structure (taxonomy in separate column).
   *
   * @param array $document Document data from database
   * @return string 'old' or 'new'
   */
  private function detectDataStructureVersion(array $document): string
  {
    // Check if taxonomy column exists and has data
    if (isset($document['taxonomy']) && !empty($document['taxonomy'])) {
      return 'new';
    }

    // Check if content contains taxonomy markers
    if (isset($document['content'])) {
      $taxonomyExtractor = new TaxonomyExtractor();
      if ($taxonomyExtractor->hasTaxonomy($document['content'])) {
        return 'old';
      }
    }

    // Default to new structure if no taxonomy found
    return 'new';
  }

  /**
   * Get document with backward compatibility (Task 4.5)
   *
   * This method retrieves a document and handles both old and new data structures.
   * For old structure, it extracts taxonomy from content on-the-fly.
   *
   * @param int $id Document ID
   * @param string|null $tableName Table name
   * @return array|null Document data with normalized structure
   */
  public function getDocumentWithBackwardCompatibility(int $id, ?string $tableName = null): ?array
  {
    try {
      // Get document using new method
      $document = $this->getDocumentWithTaxonomy($id, $tableName);

      if ($document === null) {
        return null;
      }

      // Detect structure version
      $version = $this->detectDataStructureVersion($document);

      if ($version === 'old') {
        // Extract taxonomy from content for old structure
        $taxonomyExtractor = new TaxonomyExtractor();
        $extracted = $taxonomyExtractor->extract($document['content']);

        // Update document with extracted taxonomy
        $document['content'] = $extracted['content'];
        $document['taxonomy'] = $extracted['taxonomy'];

        // Add metadata flag indicating extraction
        $document['metadata']['taxonomy_extracted_on_read'] = true;
        $document['metadata']['data_structure_version'] = 'old';

        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            'Taxonomy extracted from old structure on read',
            'info',
            [
              'document_id' => $id,
              'table' => $document['table_name'],
              'has_taxonomy' => $document['taxonomy'] !== null
            ]
          );
        }
      } else {
        $document['metadata']['data_structure_version'] = 'new';
      }

      return $document;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        'Error in getDocumentWithBackwardCompatibility: ' . $e->getMessage(),
        'error',
        ['id' => $id, 'table' => $tableName]
      );
      return null;
    }
  }

  /**
   * Update taxonomy without re-embedding (Task 4.5)
   *
   * This method updates only the taxonomy field without regenerating embeddings.
   * Useful for correcting taxonomy data without expensive re-embedding.
   *
   * @param int $id Document ID
   * @param array $taxonomy New taxonomy data
   * @param string|null $tableName Table name
   * @return bool True if successful
   */
  public function updateTaxonomy(int $id, array $taxonomy, ?string $tableName = null): bool
  {
    try {
      // Validate taxonomy structure
      $taxonomyExtractor = new TaxonomyExtractor();
      $validation = $taxonomyExtractor->validate($taxonomy);

      if (!$validation['valid']) {
        $this->securityLogger->logSecurityEvent(
          'Invalid taxonomy structure for update',
          'error',
          [
            'document_id' => $id,
            'errors' => $validation['errors']
          ]
        );
        return false;
      }

      // Find the document to get table name if not provided
      if ($tableName === null) {
        $document = $this->getDocumentWithTaxonomy($id);
        if ($document === null) {
          return false;
        }
        $tableName = $document['table_name'];
      }

      // Check if table has taxonomy column
      $hasTaxonomyColumn = DoctrineOrm::selectOne(
        "SELECT COUNT(*) as count 
         FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = ? 
         AND COLUMN_NAME = 'taxonomy'",
        [$tableName]
      );

      if (($hasTaxonomyColumn['count'] ?? 0) === 0) {
        $this->securityLogger->logSecurityEvent(
          'Cannot update taxonomy - column does not exist',
          'error',
          ['table' => $tableName]
        );
        return false;
      }

      // Update taxonomy column
      $taxonomyJson = json_encode($taxonomy);
      DoctrineOrm::execute(
        "UPDATE {$tableName} SET taxonomy = ?, date_modified = ? WHERE id = ?",
        [$taxonomyJson, date('Y-m-d H:i:s'), $id]
      );

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          'Taxonomy updated without re-embedding',
          'info',
          [
            'document_id' => $id,
            'table' => $tableName
          ]
        );
      }

      return true;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        'Error updating taxonomy: ' . $e->getMessage(),
        'error',
        ['id' => $id, 'table' => $tableName]
      );
      return false;
    }
  }




}
