<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domains\Analytics\Agent;

use AllowDynamicProperties;

use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Cache as OMCache;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt;

use ClicShopping\AI\Security\InputValidator;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Security\RateLimit;
use ClicShopping\AI\Security\DbSecurity;

use ClicShopping\AI\Domains\Analytics\Agent\DatabaseSchemaManager;
use ClicShopping\AI\Domains\Analytics\Agent\ResultInterpreter;
use ClicShopping\AI\Helper\Detection\AmbiguousQueryDetector;
use ClicShopping\AI\Infrastructure\Prompt\PromptBuilder;
use ClicShopping\AI\Domains\Analytics\Agent\AmbiguityHandler;
use ClicShopping\AI\Domains\Analytics\Agent\CompoundQueryHandler;
use ClicShopping\AI\Domains\Analytics\Helper\AnalyticsErrorHandler;
use ClicShopping\AI\Agents\Query\QueryClassifier;
use ClicShopping\AI\Domains\Analytics\Executor\QueryExecutor;
use ClicShopping\AI\Domains\Analytics\Executor\SqlQueryProcessor;
use ClicShopping\AI\Helper\AgentResponseHelper;

use ClicShopping\AI\Infrastructure\Cache\Cache;
use ClicShopping\AI\Domains\Semantic\Agent\SemanticAgent;
use ClicShopping\AI\Infrastructure\Cache\QueryCache;

use ClicShopping\AI\Agents\Orchestrator\CorrectionAgent;
use ClicShopping\AI\Domain\Patterns\Ecommerce\ModificationKeywordsPattern;
use ClicShopping\AI\Utils\TypeSafetyGuard;

/**
 * Class AnalyticsAgent
 * Handles database analytics and query processing with AI assistance
 * Manages table relationships, schema validation, and query optimization
 * Implements comprehensive security measures
 */
#[AllowDynamicProperties]
class AnalyticsAgent
{
  private mixed $chat;
  private mixed $db;
  private mixed $language;
  private int $languageId;
  private array $correctionLog = [];
  private bool $enablePromptCache;
  private bool $debug = false;
  private SecurityLogger $securityLogger;
  private RateLimit $rateLimit;
  private string $userId;
  private DbSecurity $dbSecurity;

  private mixed $maxRowsForInterpretation;

  // Delegated components
  private DatabaseSchemaManager $schemaManager;
  private SqlQueryProcessor $queryProcessor;
  private QueryExecutor $queryExecutor;
  private ResultInterpreter $resultInterpreter;
  private CorrectionAgent $correctionAgent;
  private QueryCache $queryCache;
  private AmbiguousQueryDetector $ambiguityDetector;
  private PromptBuilder $promptBuilder;
  private AmbiguityHandler $ambiguityHandler;
  private CompoundQueryHandler $compoundQueryHandler;
  private AnalyticsErrorHandler $errorHandler;
  private mixed $app;
  
  private mixed $conversationMemory = null;
  private string $Usecache;

  /**
   * Constructor for AnalyticsAgent
   * Initializes database connection, language settings, and AI chat interface
   * Sets up schema caching, table relationships, and security components
   *
   * @param int|null $languageId Language ID for filtering results
   * @param bool $enablePromptCache Whether to enable local prompt caching
   * @param string $userId User identifier for rate limiting and auditing
   */
  public function __construct(?int $languageId = null, bool $enablePromptCache = true, string $userId = 'system')
  {
    $this->db = Registry::get('Db');
    $this->language = Registry::get('Language');

    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGpt());
    }

    $this->app = Registry::get('ChatGpt');

    // This replaces the duplicated model detection logic with a single, maintainable function
    $model = CLICSHOPPING_APP_CHATGPT_CH_MODEL;
    
    try {
      $this->chat = Gpt::getChatForModel($model);
    } catch (\Exception $e) {
      // Log error and fallback to default
      error_log("AnalyticsAgent: Error getting chat for model {$model}: " . $e->getMessage());
      // Fallback to OpenAI GPT-4 as default
      $this->chat = Gpt::getOpenAiGpt(['model' => 'gpt-4-mini']);
    }

    $this->userId = $userId;
    $this->languageId = $this->language->getId();

    // Initialize security components
    $this->securityLogger = new SecurityLogger();
    $this->rateLimit = new RateLimit('analytics_agent', 50, 60); // 50 requests per minute
    $this->dbSecurity = new DbSecurity();

    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';
    $this->Usecache = defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True';

    $this->enablePromptCache = $enablePromptCache;

    // Log initialization
    $this->securityLogger->logSecurityEvent("AnalyticsAgent initialized for user {$this->userId}", 'info');

    // Initialize PromptBuilder and set system message
    $this->promptBuilder = new PromptBuilder($this->language, $this->languageId, $this->debug);
    $this->chat->setSystemMessage($this->promptBuilder->getSystemMessage());

    $this->maxRowsForInterpretation = defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_ROWS_FOR_LLM_INTERPRETATION') ? (int) CLICSHOPPING_APP_CHATGPT_RA_MAX_ROWS_FOR_LLM_INTERPRETATION : 150;

    // Initialize delegated components
    $this->schemaManager = new DatabaseSchemaManager(
      $this->db,
      $this->securityLogger,
      $this->debug
    );

    $this->queryProcessor = new SqlQueryProcessor(
      $this->securityLogger,
      $this->languageId,
      $this->debug
    );

    $this->queryExecutor = new QueryExecutor(
      $this->db,
      $this->securityLogger,
      $this->dbSecurity,
      $this->debug
    );

    $this->resultInterpreter = new ResultInterpreter(
      $this->chat,
      new Cache($enablePromptCache),  // ResultInterpreter has its own cache instance
      $this->securityLogger,
      $this->app,
      $this->maxRowsForInterpretation,
      $this->enablePromptCache,
      $this->debug
    );
    $this->correctionAgent = new CorrectionAgent($userId, $languageId);
    
    // Initialize QueryCache
    $this->queryCache = new QueryCache();
    
    // Initialize AmbiguousQueryDetector with chat instance for LLM-based detection
    $this->ambiguityDetector = new AmbiguousQueryDetector($this->chat, $this->securityLogger, $this->debug);
    
    // Initialize AmbiguityHandler for handling ambiguous queries
    $this->ambiguityHandler = new AmbiguityHandler(
      $this->ambiguityDetector,
      $this->queryProcessor,
      $this->queryExecutor,
      $this->debug
    );
    
    // Initialize CompoundQueryHandler for handling compound queries (multiple questions)
    $this->compoundQueryHandler = new CompoundQueryHandler(
      $this->chat,
      $this->securityLogger,
      $this->debug
    );
    
    // Initialize AnalyticsErrorHandler for error recovery and messaging
    $this->errorHandler = new AnalyticsErrorHandler(
      $this->db,
      $this->correctionAgent,
      $this->queryExecutor,
      $this->debug
    );

    try {
      $this->schemaManager->initializeTableRelationships();
      $this->schemaManager->buildDatabaseSchema();
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent("Error during AnalyticsAgent initialization: " . $e->getMessage(), 'error');

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Error during AnalyticsAgent initialization: " . $e->getMessage(), 'error');
      }
    }
  }

  /**
   * Executes the generated SQL query and handles errors
   * Implements error recovery mechanisms
   * Logs errors when debug mode is enabled
   * Provides fallback responses on complete failure
   *
   * @param string $question The business question in natural language
   * @return array Results array containing:
   *               - type: 'success' or 'error'
   *               - message: Result message or error description
   *               - query: Original question
   *               - suggestion: Error fix suggestion if applicable
   *               - recovery_attempted: Boolean indicating if recovery was attempted
   */
  public function executeQuery(string $question, array $feedbackContext = []): array
  {
    error_log("\n" . str_repeat("-", 100));
    error_log("DEBUG: AnalyticsAgent.executeQuery() - START");
    error_log("-" . str_repeat("-", 99));
    error_log("Question: '{$question}'");
    error_log("Feedback context items: " . count($feedbackContext));

    if (!$this->rateLimit->checkLimit($this->userId)) {
      error_log("RATE LIMIT EXCEEDED");
      return [
        'type' => 'error',
        'message' => 'Rate limit exceeded',
        'query' => $question,
      ];
    }

    $safeQuestion = InputValidator::validateParameter($question, 'string');
    if ($safeQuestion !== $question) {
      error_log("Question was sanitized");
      $question = $safeQuestion;
    }

    try {
      error_log("\nCalling processAnalyticsQuery()...");
      $result = $this->processAnalyticsQuery($question, $feedbackContext);

      error_log("processAnalyticsQuery() returned:");
      error_log("  type: " . ($result['type'] ?? 'unknown'));
      error_log("  sql_query: " . ($result['sql_query'] ?? 'N/A'));
      error_log("  count: " . ($result['count'] ?? 0));

      error_log("-" . str_repeat("-", 99) . "\n");
      return $result;

    } catch (\Exception $e) {
      error_log("EXCEPTION: " . $e->getMessage());
      error_log("-" . str_repeat("-", 99) . "\n");

      return [
        'type' => 'error',
        'message' => $e->getMessage(),
        'query' => $question,
      ];
    }
  }

  /**
   * Executes a query with error recovery mechanisms
   * Implements caching, query generation, validation, and error handling
   * Supports multiple query execution and result aggregation
   *
   * @param string $question The business question to process
   * @return array Results containing:
   *               - type: 'analytics_results'
   *               - query: Original question
   *               - sql_query: Executed SQL query
   *               - original_sql_query: Pre-correction SQL query
   *               - corrections: Array of applied corrections
   *               - results: Query results
   *               - count: Number of results
   * @throws \Exception When query execution fails after recovery attempts
   */
  private function processAnalyticsQuery(string $question, array $feedbackContext = []): array
  {
    $this->debugLog(str_repeat(".", 100));
    $this->debugLog("AnalyticsAgent.processAnalyticsQuery() - START", "QUERY");
    $this->debugLog("Feedback context items: " . count($feedbackContext), "QUERY");

    try {
      $this->debugLog("--- STEP 0: Translate query for ambiguity detection ---", "TRANSLATION");
      
      // CRITICAL FIX: Translate query to English for ambiguity detection
      // This ensures the LLM can properly detect explicit keywords in any language
      // Use a simple, fast translation that focuses on keywords
      $queryForAmbiguity = $this->translateQueryForAmbiguity($question);
      $this->debugLog("Original query: {$question}", "TRANSLATION");
      $this->debugLog("Translated for ambiguity: {$queryForAmbiguity}", "TRANSLATION");
      
      // STEP 0.25: DISABLED - Compound query detection
      // Compound queries (e.g., "pending orders and revenue") are now classified as 'hybrid'
      // and routed to HybridQueryProcessor which has proper handling and formatting.
      // The CompoundQueryHandler produced incorrect output format not compatible with formatters.
      // See: HybridQueryProcessor.splitHybridQuery() and HybridQueryProcessor.handleComplexQuery()
      $this->debugLog("--- STEP 0.25: Compound query detection DISABLED ---", "COMPOUND");
      $this->debugLog("Hybrid queries are handled by HybridQueryProcessor", "COMPOUND");
      
      $this->debugLog("--- STEP 0.5: Check for ambiguous query ---", "AMBIGUITY");
      
      // 🚀 OPTIMIZATION: Skip ambiguity detection for high-confidence analytics queries
      // Get classification confidence from isAnalyticsQuery (already called in processBusinessQuery)
      // If confidence >= 0.9, skip ambiguity detection to save 1-2 seconds
      $skipAmbiguity = false;
      $classificationConfidence = 0.0;
      
      // Re-classify to get confidence (cached, so very fast)
      $translatedForClassification = SemanticAgent::translateToEnglish($question, 80);
      $cleanTranslation = $this->resultInterpreter->extractCleanTranslation($translatedForClassification);
      $classifier = new QueryClassifier($this->debug);
      $classificationResult = $classifier->classify($cleanTranslation, $cleanTranslation);
      
      $classificationConfidence = $classificationResult['confidence'] ?? 0.0;
      
      if ($classificationResult['type'] === 'analytics' && $classificationConfidence >= 0.9) {
        $skipAmbiguity = true;
        $this->debugLog("⚡ SKIPPING ambiguity detection (high confidence: {$classificationConfidence})", "OPTIMIZATION");
        $this->securityLogger->logSecurityEvent(
          "Ambiguity detection skipped for high-confidence analytics query",
          'info',
          [
            'query' => substr($question, 0, 100),
            'confidence' => $classificationConfidence,
            'time_saved_estimate' => '1-2 seconds'
          ]
        );
      }
      
      // TASK 6.1: Detect ambiguous queries using translated query (only if not skipped)
      $ambiguityAnalysis = $skipAmbiguity 
        ? ['is_ambiguous' => false, 'skipped' => true, 'reason' => 'high_confidence_analytics', 'confidence' => $classificationConfidence]
        : $this->ambiguityDetector->detectAmbiguity($queryForAmbiguity);
      
      if ($ambiguityAnalysis['is_ambiguous']) {
        $this->debugLog("AMBIGUOUS QUERY DETECTED!", "AMBIGUITY");
        $this->debugLog("Type: " . $ambiguityAnalysis['ambiguity_type'], "AMBIGUITY");
        $this->debugLog("Recommendation: " . $ambiguityAnalysis['recommendation'], "AMBIGUITY");
        $this->debugLog("Interpretations: " . json_encode(array_keys($ambiguityAnalysis['interpretations'])), "AMBIGUITY");
        
        // Handle based on recommendation
        if ($ambiguityAnalysis['recommendation'] === 'generate_both') {
          $this->debugLog("→ Generating multiple interpretations", "AMBIGUITY");
          
          // Create SQL generator closure for AmbiguityHandler
          $sqlGenerator = function(string $modifiedQuery) use ($feedbackContext) {
            // Enrich question with feedback context
            $enrichedQuestion = $this->enrichQuestionWithFeedback($modifiedQuery, $feedbackContext);
            
            // Generate SQL using LLM
            $rawResponse = $this->chat->generateText($enrichedQuestion);
            
            // Extract SQL
            $sqlQueries = $this->queryProcessor->extractSqlQueries($rawResponse);
            
            if (empty($sqlQueries)) {
              $sqlQueries = [$this->queryProcessor->cleanSqlResponse($rawResponse)];
            }
            
            return $sqlQueries[0] ?? '';
          };
          
          return $this->ambiguityHandler->handleAmbiguousQuery($question, $ambiguityAnalysis, $sqlGenerator);
        } elseif ($ambiguityAnalysis['recommendation'] === 'clarify') {
          $this->debugLog("→ Requesting clarification from user", "AMBIGUITY");
          return $this->ambiguityHandler->requestClarification($question, $ambiguityAnalysis);
        } else {
          $this->debugLog("→ Using default interpretation: " . $ambiguityAnalysis['default_interpretation'], "AMBIGUITY");
          // Continue with default interpretation
        }
      } else {
        if (isset($ambiguityAnalysis['skipped']) && $ambiguityAnalysis['skipped']) {
          $this->debugLog("⚡ Ambiguity detection SKIPPED (reason: {$ambiguityAnalysis['reason']}, confidence: {$ambiguityAnalysis['confidence']})", "OPTIMIZATION");
        } else {
          $this->debugLog("No ambiguity detected - proceeding normally", "AMBIGUITY");
        }
      }
      
      $this->debugLog("--- STEP 1: Check QueryCache ---", "CACHE");
      
      // Check QueryCache FIRST
      $cacheResult = $this->queryCache->get($question);
      if ($cacheResult !== null) {
        $this->debugLog("CACHE HIT! Returning cached results", "CACHE");
        $this->debugLog("Cache entry age: " . (time() - strtotime($cacheResult['created_at'])) . " seconds", "CACHE");
        
        return [
          'type' => 'analytics_results',
          'query' => $question,
          'sql_query' => $cacheResult['sql_query'],
          'original_sql_query' => $cacheResult['sql_query'],
          'corrections' => [],
          'results' => $cacheResult['results'],
          'count' => $cacheResult['result_count'],
          'entity_id' => $cacheResult['entity_id'] ?? null,
          'entity_type' => $cacheResult['entity_type'] ?? null,
          'interpretation' => $cacheResult['interpretation'] ?? null,  // 🆕 Return cached interpretation
          'ambiguous' => $ambiguityAnalysis['is_ambiguous'],  // TASK 6.1: Add ambiguity metadata
          'ambiguity_type' => $ambiguityAnalysis['ambiguity_type'] ?? null,
          'cached' => true,
          'cache_age' => time() - strtotime($cacheResult['created_at'])
        ];
      }
      $this->debugLog("CACHE MISS - Generating new query", "CACHE");
      
      $this->debugLog("--- STEP 2: Generate SQL from question ---", "SQL");
      
      // Update system message with Schema RAG if enabled
      $this->updateSystemMessageForQuery($question);
      
      // Enrich question with feedback context for learning
      $enrichedQuestion = $this->enrichQuestionWithFeedback($question, $feedbackContext);
      
      $this->debugLog("Calling chat.generateText()...", "SQL");
      $rawResponse = $this->chat->generateText($enrichedQuestion);
      $this->debugLog("Raw response from GPT (first 500 chars): " . substr($rawResponse, 0, 500), "SQL");

      // Extract SQL queries (skip if we already have template SQL)
      if (!isset($sqlQueries)) {
        $this->debugLog("Extracting SQL from response...", "SQL");
        $sqlQueries = $this->queryProcessor->extractSqlQueries($rawResponse);
        $this->debugLog("Extracted SQL queries count: " . count($sqlQueries), "SQL");
      }

      foreach ($sqlQueries as $idx => $sql) {
        $this->debugLog("SQL Query " . ($idx + 1) . ": " . substr($sql, 0, 200) . "...", "SQL");
      }

      if (empty($sqlQueries)) {
        $this->debugLog("NO SQL EXTRACTED - Trying to clean response", "SQL");
        $sqlQueries = [$this->queryProcessor->cleanSqlResponse($rawResponse)];
        $this->debugLog("After cleaning: " . substr($sqlQueries[0], 0, 200), "SQL");
      }

      if (empty($sqlQueries[0])) {
        $this->debugLog("ERROR: No valid SQL query extracted", "SQL");
        throw new \Exception('No valid SQL query could be extracted');
      }

      $this->debugLog("--- STEP 3: Execute SQL queries ---", "EXECUTION");
      $results = [];
      $this->correctionLog = [];

      foreach ($sqlQueries as $idx => $sqlQuery) {
        $this->debugLog("Processing SQL query " . ($idx + 1), "EXECUTION");
        $this->debugLog("Original: " . substr($sqlQuery, 0, 150) . "...", "EXECUTION");

        $resolvedQuery = $this->queryProcessor->resolvePlaceholders($sqlQuery);
        $this->debugLog("After placeholder resolution: " . substr($resolvedQuery, 0, 150) . "...", "EXECUTION");

        // TASK 3: Validate LIKE patterns
        $likeValidation = $this->queryProcessor->validateLikePatterns($resolvedQuery);
        if (!empty($likeValidation['warnings'])) {
          $this->debugLog("LIKE pattern warnings: " . count($likeValidation['warnings']), "VALIDATION");
          
          // Log warnings using security logger
          foreach ($likeValidation['warnings'] as $warning) {
            $this->securityLogger->logSecurityEvent(
              "LIKE pattern validation warning: " . $warning,
              'warning',
              [
                'sql_snippet' => substr($resolvedQuery, 0, 200),
                'like_count' => $likeValidation['like_count'],
                'patterns' => $likeValidation['patterns']
              ]
            );
          }
          
          // Log suggestions if available
          if (!empty($likeValidation['suggestions'])) {
            $this->debugLog("Suggestions: " . implode('; ', $likeValidation['suggestions']), "VALIDATION");
          }
        } else {
          $this->debugLog("LIKE pattern validation: PASSED (" . $likeValidation['like_count'] . " patterns checked)", "VALIDATION");
        }

        $validation = InputValidator::validateSqlQuery($resolvedQuery);
        $this->debugLog("SQL validation: " . ($validation['valid'] ? 'VALID' : 'INVALID'), "VALIDATION");

        if (!$validation['valid']) {
          error_log("  Validation issues: " . implode(', ', $validation['issues']));
          continue;
        }

        $finalQuery = $validation['valid'] ? $resolvedQuery : $sqlQuery;
        
        // TASK 10.6: Fix date filters to include YEAR() when MONTH() is used
        $finalQuery = $this->queryProcessor->fixDateFilters($finalQuery);
        
        error_log("  Final query to execute: " . substr($finalQuery, 0, 150) . "...");

        try {
          error_log("  Executing query...");
          $executionResult = $this->queryExecutor->execute($finalQuery);

          if (!$executionResult['success']) {
            throw new \Exception($executionResult['error'] ?? 'Query execution failed');
          }

          $queryResults = $executionResult['data'];

          error_log("  Query executed successfully!");
          error_log("  Rows returned: " . count($queryResults));

          if (!empty($queryResults)) {
            error_log("  First row keys: " . implode(', ', array_keys($queryResults[0])));
            error_log("  First row preview: " . json_encode(array_slice($queryResults[0], 0, 3)));
          }

          // Extract entity_id using QueryExecutor
          $entityInfo = $this->queryExecutor->extractEntityIdFromResults($queryResults);
          $entityId = $entityInfo['entity_id'];
          $entityType = $entityInfo['entity_type'];

          if ($entityId !== null) {
            error_log("  Entity extracted: ID={$entityId}, Type={$entityType}");
          }

          $results = [
            'type' => 'analytics_results',
            'query' => $question,
            'sql_query' => $finalQuery,
            'original_sql_query' => $sqlQuery,
            'corrections' => $this->correctionLog,
            'results' => $queryResults,
            'count' => count($queryResults),
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'ambiguous' => $ambiguityAnalysis['is_ambiguous'] ?? false,  // TASK 6.1: Add ambiguity metadata
            'ambiguity_type' => $ambiguityAnalysis['ambiguity_type'] ?? null,
            'interpretations' => $ambiguityAnalysis['is_ambiguous'] ? array_keys($ambiguityAnalysis['interpretations']) : [],
          ];
          
          // 🆕 CACHE THE SUCCESSFUL RESULT
          error_log("  💾 Caching successful query result in QueryCache");
          $this->queryCache->set(
            $question,
            $finalQuery,
            $queryResults,
            [
              'entity_id' => $entityId,
              'entity_type' => $entityType
            ]
          );

        } catch (\Exception $e) {
          error_log("  QUERY EXECUTION FAILED: " . $e->getMessage());
          error_log("  Attempting intelligent correction...");

          $correctionResult = $this->errorHandler->attemptIntelligentCorrection($e, $finalQuery, $sqlQuery, $question);

          if ($correctionResult['success']) {
            error_log("  Correction successful!");
            
            // Use the corrected data as the main result (not append to array)
            $correctedData = $correctionResult['data'];
            
            // Extract entity info from corrected results
            $entityInfo = $this->queryExecutor->extractEntityIdFromResults($correctedData['results']);
            
            $results = [
              'type' => 'analytics_results',
              'query' => $question,
              'sql_query' => $correctedData['executed_query'],
              'original_sql_query' => $sqlQuery,
              'corrections' => $correctedData['corrections'] ?? [],
              'results' => $correctedData['results'],
              'count' => count($correctedData['results']),
              'entity_id' => $entityInfo['entity_id'],
              'entity_type' => $entityInfo['entity_type'],
              'ambiguous' => $ambiguityAnalysis['is_ambiguous'] ?? false,  // TASK 6.1: Add ambiguity metadata
              'ambiguity_type' => $ambiguityAnalysis['ambiguity_type'] ?? null,
              'interpretations' => $ambiguityAnalysis['is_ambiguous'] ? array_keys($ambiguityAnalysis['interpretations']) : [],
            ];
            
            // 🆕 CACHE THE CORRECTED RESULT
            if (!empty($correctedData['results'])) {
              error_log("  💾 Caching corrected query result");
              $this->queryCache->set(
                $question,
                $correctedData['executed_query'],
                $correctedData['results'],
                [
                  'entity_id' => $entityInfo['entity_id'],
                  'entity_type' => $entityInfo['entity_type']
                ]
              );
            }
          } else {
            error_log("  Correction failed");
            throw new \Exception("Execution failed after intelligent correction attempt: " . $e->getMessage());
          }
        }
      }

      error_log("\n" . "." . str_repeat(".", 99) . "\n");
      return $results;

    } catch (\Exception $e) {
      error_log("\nFINAL EXCEPTION: " . $e->getMessage());
      error_log("." . str_repeat(".", 99) . "\n");
      throw $e;
    }
  }

  /**
   * Processes a complete business query including SQL generation, execution, and interpretation
   * Handles multiple query results and provides natural language interpretation
   * Includes error handling and recovery mechanisms
   *
   * @param string $question The business question in natural language
   * @param bool $includeSQL Whether to include SQL queries in the response (default: true)
   * @return array Response containing:
   *               - type: 'analytics_response' or 'error'
   *               - question: Original question
   *               - interpretation: Natural language interpretation of results
   *               - count: Number of results
   *               - sql_query: Executed SQL (if includeSQL is true)
   *               - results: Query results
   *               - corrections: Any applied corrections
   */
  public function processBusinessQuery(string $question, bool $includeSQL = true, array $feedbackContext = []): array
  {
    error_log("\n" . str_repeat("=", 100));
    error_log("DEBUG: AnalyticsAgent.processBusinessQuery() - START");
    error_log(str_repeat("=", 100));
    error_log("Question: '{$question}'");
    error_log("includeSQL: " . ($includeSQL ? 'true' : 'false'));
    error_log("feedbackContext items: " . count($feedbackContext));

    try {
      // 0. 🆕 Detect if it's a modification and enrich with the last SQL query
      if ($this->isModificationRequest($question) && $this->conversationMemory) {
        $lastSQL = $this->conversationMemory->getLastSQLQuery();
        if ($lastSQL) {
          error_log("\n--- STEP 0: Modification detected, enriching with last SQL ---");
          $question = $this->enrichQuestionWithLastSQL($question, $lastSQL);
        }
      }
      
      // 1. Check if it's an analytics query
      error_log("\n--- STEP 1: Check if analytics query ---");
      $isAnalytics = $this->isAnalyticsQuery($question);

      error_log("isAnalyticsQuery() returned: " . ($isAnalytics ? 'TRUE' : 'FALSE'));

      if (!$isAnalytics) {
        error_log("NOT AN ANALYTICS QUERY - Returning early");
        return [
          'type' => 'not_analytics',
          'message' => 'This is not an analytics query',
          'question' => $question
        ];
      }

      // 2. Execute the query
      error_log("\n--- STEP 2: Execute query ---");
      error_log("Calling executeQuery()...");

      $results = $this->executeQuery($question, $feedbackContext);

      error_log("executeQuery() returned:");
      error_log("  type: " . ($results['type'] ?? 'unknown'));
      error_log("  has error: " . (isset($results['error']) ? 'YES' : 'NO'));
      error_log("  has results: " . (isset($results['results']) ? 'YES (' . count($results['results']) . ' rows)' : 'NO'));

      if ($results['type'] === 'error') {
        error_log("ERROR in executeQuery: " . ($results['error'] ?? 'unknown'));
        return $results;
      }

      // Handle unknown or incomplete results
      // ✅ FIX: Allow ambiguous results which use 'interpretation_results' instead of 'results'
      $isAmbiguous = isset($results['type']) && $results['type'] === 'analytics_results_ambiguous';
      $isClarification = isset($results['type']) && $results['type'] === 'clarification_needed';
      $hasResults = isset($results['results']) && $results['results'] !== null;
      $hasInterpretationResults = isset($results['interpretation_results']) && !empty($results['interpretation_results']);
      
      // ✅ FIX: For clarification requests, return them directly
      if ($isClarification) {
        error_log("✅ Clarification needed - returning directly");
        return $results;
      }
      
      // ✅ FIX: For ambiguous results, return them directly without interpretation
      if ($isAmbiguous && $hasInterpretationResults) {
        error_log("✅ Ambiguous results detected - returning directly");
        return $results;
      }
      
      if (!$hasResults && !$hasInterpretationResults && !$isAmbiguous && !$isClarification) {
        error_log("WARNING: No results array in executeQuery response");
        return [
          'type' => 'error',
          'error' => 'Query execution failed to return results',
          'question' => $question,
          'details' => $results
        ];
      }

      // 3. Interpret the results
      error_log("\n--- STEP 3: Interpret results ---");
      
      // 🆕 Check if interpretation is already in cache
      if (isset($results['interpretation']) && !empty($results['interpretation'])) {
        $interpretation = $results['interpretation'];
        error_log("✅ Using cached interpretation");
        
        // Type-safe logging with TypeSafetyGuard
        if (is_array($interpretation)) {
          error_log(" WARNING: Cached interpretation is an array, not a string");
        }
	
        $logSnippet = TypeSafetyGuard::safeSubstr($interpretation, 0, 200);
        error_log("Interpretation: " . $logSnippet . "...");
      } else {
        // 🔧 TASK 4.3.4.2: Handle empty results gracefully
        if (empty($results['results'])) {
          error_log("⚠️  WARNING: No results to interpret, generating empty results message");
          $interpretation = $this->errorHandler->generateEmptyResultsMessage($question, $results, $this->debug);
          error_log("📝 Empty results message: " . $interpretation);
        } else {
          // Generate new interpretation only if we have data
          $interpretation = $this->resultInterpreter->interpretResults($question, $results['results']);
          error_log("🔄 Generated new interpretation");
          
          // Type-safe logging with TypeSafetyGuard
          if (is_array($interpretation)) {
            error_log(" WARNING: interpretResults() returned an array, not a string");
          }
	  
          $logSnippet = TypeSafetyGuard::safeSubstr($interpretation, 0, 200);
          error_log("Interpretation: " . $logSnippet . "...");
        }
      }

      // 3.5. 🆕 Update cache with interpretation
      if (!empty($results['sql_query']) && !($results['cached'] ?? false)) {
        error_log("\n--- STEP 3.5: Update cache with interpretation ---");
        try {
          $this->queryCache->set(
            $question,
            $results['sql_query'],
            $results['results'],
            [
              'entity_id' => $results['entity_id'] ?? null,
              'entity_type' => $results['entity_type'] ?? null,
              'interpretation' => $interpretation
            ]
          );
          error_log("✅ Cache updated with interpretation");
        } catch (\Exception $e) {
          error_log("⚠️ Failed to update cache with interpretation: " . $e->getMessage());
        }
      } elseif ($results['cached'] ?? false) {
        error_log("ℹ️ Skipping cache update (result was from cache)");
      }

      // 4. Construire la réponse
      error_log("\n--- STEP 4: Build response ---");
      $response = [
        'type' => 'analytics_response',
        'question' => $question,
        'interpretation' => $interpretation,
        'count' => $results['count'],
        'results' => $results['results'],
        'cached' => $results['cached'] ?? false,  // 🆕 Propagate cached flag
      ];
      
      // Add cache metadata if available
      if (isset($results['cache_age'])) {
        $response['cache_age'] = $results['cache_age'];
      }

      if ($includeSQL) {
        $response['sql_query'] = $results['sql_query'] ?? 'N/A';
        $response['original_sql_query'] = $results['original_sql_query'] ?? $results['sql_query'] ?? 'N/A';
        if (!empty($results['corrections'])) {
          $response['corrections'] = $results['corrections'];
        }
      }

      // 5. Extraire entity_id si présent
      error_log("\n--- STEP 5: Extract entity info ---");
      if (!empty($results['results'])) {
        $extracted = $this->queryExecutor->extractEntityIdFromResults($results['results']);
        if ($extracted['entity_id'] !== null) {
          $response['entity_id'] = $extracted['entity_id'];
          $response['entity_type'] = $extracted['entity_type'];
          error_log("Extracted entity_id: {$extracted['entity_id']}, type: {$extracted['entity_type']}");
        } else {
          error_log("No entity_id extracted from results");
        }
      }

      error_log("\n--- FINAL RESPONSE ---");
      error_log(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      error_log(str_repeat("=", 100) . "\n");

      return $response;

    } catch (\Exception $e) {
      error_log("\n--- EXCEPTION ---");
      error_log("Error: " . $e->getMessage());
      error_log("Trace: " . $e->getTraceAsString());
      error_log(str_repeat("=", 100) . "\n");

      return [
        'type' => 'error',
        'message' => 'Error processing business query: ' . $e->getMessage(),
        'question' => $question,
      ];
    }
  }

  /**
   * Determines if a query is analytical in nature
   * Uses semantic analysis to classify query type
   * Checks against predefined analytical patterns
   *
   * @param string $query Query to analyze
   * @return bool True if query is analytical, false otherwise
   */
  public function isAnalyticsQuery(string $query): bool
  {
    error_log("\n=== ANALYTICS AGENT: isAnalyticsQuery() ===");
    error_log("Input query: '{$query}'");

    // CRITICAL FIX: Translate the query to English FIRST
    $translatedQuery = SemanticAgent::translateToEnglish($query, 80);
    error_log("Translated query: '{$translatedQuery}'");

    // Extract only the clean translation (not the descriptive text)
    $cleanTranslation = $this->resultInterpreter->extractCleanTranslation($translatedQuery);
    error_log("Clean translation: '{$cleanTranslation}'");

    // NOW classify the translated query using centralized QueryClassifier
    $classifier = new QueryClassifier($this->debug);
    $classificationResult = $classifier->classify($cleanTranslation, $cleanTranslation);
    
    error_log("Classification result: '{$classificationResult['type']}' (confidence: {$classificationResult['confidence']})");
    if (!empty($classificationResult['reasoning'])) {
      error_log("Reasoning: " . implode('; ', $classificationResult['reasoning']));
    }

    // REVERTED: Only accept 'analytics' type, NOT 'hybrid'
    // Hybrid queries should be handled by HybridQueryProcessor, not AnalyticsAgent
    // The CompoundQueryHandler in AnalyticsAgent produces incorrect output format
    // HybridQueryProcessor has proper handling for multi-intent queries
    $isAnalytics = $classificationResult['type'] === 'analytics';
    error_log("Is analytics? " . ($isAnalytics ? 'YES' : 'NO'));
    error_log("=== END isAnalyticsQuery() ===\n");

    return $isAnalytics;
  }

  /**
   * Identifies the analytical categories of a query
   * Matches query against predefined pattern categories
   * Supports multiple category classification
   *
   * @param string $query Query to analyze
   * @return array List of matched analytical categories
   *               Returns empty array if no categories match
   */
  public function getAnalyticsCategories(string $query): array
  {
    $analyticsPatterns = SemanticAgent::analyticsPatterns();
    $matchedCategories = [];

    foreach ($analyticsPatterns as $category => $patterns) {
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $query)) {
          $matchedCategories[] = $category;
          break; // éviter les doublons
        }
      }
    }

    return array_unique($matchedCategories);
  }



  /**
   * 🆕 NEW METHOD: Get query cache statistics
   * Enriches statistics with calculated metrics for the dashboard
   */
  public function getQueryCacheStats(): array
  {
    $baseStats = $this->queryCache->getStats();

    // Calculate additional metrics for dashboard
    $totalRequests = ($baseStats['total_hits'] ?? 0) + ($baseStats['total_misses'] ?? 0);
    $hitRate = $totalRequests > 0 ? round(($baseStats['total_hits'] / $totalRequests) * 100, 1) : 0;

    // Estimate time saved (assuming ~10s saved per cache hit vs full query)
    $avgTimeSavedMs = 10000; // 10 seconds in ms
    $totalTimeSavedMs = ($baseStats['total_hits'] ?? 0) * $avgTimeSavedMs;

    // Estimate average result count (default to 1 if not available)
    $avgResultCount = $baseStats['avg_result_count'] ?? 1;

    return array_merge($baseStats, [
      'hit_rate' => $hitRate,
      'total_misses' => $totalRequests - ($baseStats['total_hits'] ?? 0),
      'total_time_saved_ms' => $totalTimeSavedMs,
      'avg_time_saved_ms' => $avgTimeSavedMs,
      'avg_result_count' => $avgResultCount,
      'total_requests' => $totalRequests
    ]);
  }

  /**
   * Enriches the question with feedback context for learning
   * Adds examples from previous corrections to help the LLM generate better SQL
   *
   * @param string $question Original question
   * @param array $feedbackContext Feedback items with corrections
   * @return string Enriched question with learning examples
   */
  private function enrichQuestionWithFeedback(string $question, array $feedbackContext): string
  {
    return $this->promptBuilder->enrichWithFeedback($question, $feedbackContext);
  }

  /**
   * Translate query to English for ambiguity detection
   * Uses a lightweight, cached translation focused on keywords
   * 
   * @param string $question Original question in any language
   * @return string Translated question in English
   */
  private function translateQueryForAmbiguity(string $question): string
  {
    // FULL LLM MODE: Always translate using LLM, no pattern-based shortcuts
    // This ensures consistent behavior (Pure LLM mode - no pattern matching)
    
    // Check cache first
    $cacheKey = 'translation_ambiguity_' . md5($question);
    $cacheDir = CLICSHOPPING::BASE_DIR . 'Work/Cache/Rag/Translation/';
    $cacheFile = $cacheDir . $cacheKey . '.cache';
    
    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
      @mkdir($cacheDir, 0755, true);
    }
    
    if (file_exists($cacheFile)) {
      $cached = file_get_contents($cacheFile);
      if ($cached !== false) {
        if ($this->debug) {
          error_log("Using cached translation for ambiguity detection");
        }
        return $cached;
      }
    }
    
    // Use SemanticAgent::translateToEnglish for actual translation
    try {
      $translated = SemanticAgent::translateToEnglish($question, 50);
      
      // Extract clean translation (remove descriptive text)
      $cleanTranslation = $this->resultInterpreter->extractCleanTranslation($translated);
      
      // Cache the result
      @file_put_contents($cacheFile, $cleanTranslation);
      
      if ($this->debug) {
        error_log("Translated and cached: {$question} -> {$cleanTranslation}");
      }
      
      return $cleanTranslation;
    } catch (\Exception $e) {
      // If translation fails, return original query
      if ($this->debug) {
        error_log("Translation failed: " . $e->getMessage() . ", using original query");
      }
      return $question;
    }
  }

  /**
   * Detects if the query is a modification of a previous query
   * Note: Questions are translated to English before processing
   *
   * TASK 5.1.1: Refactored to use ModificationKeywordsPattern
   * TASK 7.1.2.2: Updated to use centralized pattern class with getAllKeywords() support
   *
   * @param string $question The user's question (in English after translation)
   * @return bool True if it's a modification query
   */
  private function isModificationRequest(string $question): bool
  {
    // Use centralized pattern class (uses getAllKeywords() internally)
    $isModification = ModificationKeywordsPattern::isModificationRequest($question);

    if ($isModification && $this->debug) {
      $keyword = ModificationKeywordsPattern::getModificationKeyword($question);
      error_log("🔄 Modification request detected with keyword: {$keyword}");
    }

    return $isModification;
  }

  /**
   * Enriches the question with the previous SQL query for modifications
   * Note: The prompt is in English because all processing is in English
   *
   * @param string $question The user's question (already translated to English)
   * @param string $lastSQL The last executed SQL query
   * @return string The enriched question
   */
  private function enrichQuestionWithLastSQL(string $question, string $lastSQL): string
  {
    return $this->promptBuilder->enrichWithLastSQL($question, $lastSQL);
  }

  /**
   * @return bool Flushes the SQL query cache
   */
  public function flushQueryCache(): bool
  {
    return $this->queryCache->flush();
  }

  /**
   * Update system message for query (Schema RAG)
   * 
   * If Schema RAG is enabled, updates the system message with only relevant
   * table schemas based on the query, reducing context size for small models
   * 
   * @param string $query User query
   * @return void
   */
  private function updateSystemMessageForQuery(string $query): void
  {
    $useSchemaRAG = CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG;
    
    if (!$useSchemaRAG) {
      return; // Schema RAG disabled, use cached system message
    }
    
    try {
      // Get model name from chat instance
      $modelName = 'gpt-4o'; // default
      
      // Try to get actual model name from chat config
      if (method_exists($this->chat, 'getModel')) {
        $modelName = $this->chat->getModel();
      }
      
      $this->debugLog("Updating system message with Schema RAG", "SCHEMA_RAG");
      $this->debugLog("Model: {$modelName}", "SCHEMA_RAG");
      
      // Get query-specific system message
      $systemMessage = $this->promptBuilder->getSystemMessage($query, $modelName);
      
      // Update chat system message
      $this->chat->setSystemMessage($systemMessage);
      
      $tokenCount = (int)ceil(strlen($systemMessage) / 4);
      $this->debugLog("System message updated: " . strlen($systemMessage) . " chars (~{$tokenCount} tokens)", "SCHEMA_RAG");
      
    } catch (\Exception $e) {
      // Log error but don't fail the query
      error_log("[AnalyticsAgent] Schema RAG update failed: " . $e->getMessage());
      
      // Fallback: system message remains unchanged (uses cached full schema)
      $this->debugLog("Schema RAG failed, using cached system message", "SCHEMA_RAG");
    }
  }
  
  /**
   * Execute a sub-query from a compound query
   * 
   * This method executes a single sub-query through the normal analytics flow
   * but skips compound query detection to prevent infinite recursion.
   * 
   * @param string $subQuery The sub-query to execute
   * @param array $feedbackContext Feedback context for learning
   * @return array Query results
   */
  private function executeSubQuery(string $subQuery, array $feedbackContext = []): array
  {
    $this->debugLog("Executing sub-query: " . substr($subQuery, 0, 80), "COMPOUND_SUB");
    
    try {
      // Use executeQuery which will go through the normal flow
      // The sub-query will be processed individually
      $result = $this->executeQuery($subQuery, $feedbackContext);
      
      // If successful, try to get interpretation
      if ($result['type'] !== 'error' && !empty($result['results'])) {
        $interpretation = $this->resultInterpreter->interpretResults($subQuery, $result['results']);
        $result['interpretation'] = $interpretation;
      }
      
      return $result;
      
    } catch (\Exception $e) {
      $this->debugLog("Sub-query execution failed: " . $e->getMessage(), "COMPOUND_SUB");
      
      return [
        'type' => 'error',
        'error' => $e->getMessage(),
        'query' => $subQuery
      ];
    }
  }
  
  /**
   * Helper method for debug logging
   * Only logs when debug mode is enabled
   * Uses structured logging format with timestamp and context
   *
   * @param string $message Log message
   * @param string $context Optional context identifier (e.g., 'CACHE', 'SQL', 'VALIDATION')
   * @param array $data Optional structured data to log
   * @return void
   */
  private function debugLog(string $message, string $context = '', array $data = []): void
  {
    if (!$this->debug) {
      return;
    }

    $logMessage = $message;
    
    if (!empty($context)) {
      $logMessage = "[{$context}] {$message}";
    }
    
    if (!empty($data)) {
      $logMessage .= " | Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    
    error_log($logMessage);
  }
}
