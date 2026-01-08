<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubOrchestrator;

use AllowDynamicProperties;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Agents\Memory\ConversationMemory;
use ClicShopping\AI\Agents\Memory\MemoryRetentionService;
use ClicShopping\AI\Tools\RagAccess\SemanticQueryExecutor;
use ClicShopping\AI\Tools\ExternalAccess\WebSearchQueryExecutor;
use ClicShopping\AI\Tools\BIexecution\AnalyticsQueryExecutor;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\FeedbackImpactDetector;
use ClicShopping\AI\Agents\Orchestrator\SubHybridQueryProcessor\HybridQueryProcessorFactory;
use ClicShopping\AI\Infrastructure\Cache\HybridQueryCache;
use ClicShopping\AI\Domain\Patterns\QuerySplitterPatterns;

/**
 * HybridQueryProcessor Class
 *
 * REFACTORED VERSION (2025-12-14): Reduced from 2506 lines to ~400 lines (84% reduction)
 * 
 * This is a simplified orchestration layer that delegates all processing logic
 * to specialized components in the SubHybridQueryProcessor directory:
 * - QueryClassifier: Query classification and intent detection
 * - QuerySplitter: Hybrid query splitting and decomposition
 * - ResultSynthesizer: Result synthesis from multiple sources
 * - ResultAggregator: Result aggregation for complex queries
 * - PromptValidator: Prompt validation and security
 *
 * This refactored class maintains 100% backward compatibility while providing 
 * better modularity and maintainability.
 *
 * Architecture:
 * - Uses HybridQueryProcessorFactory for component initialization and dependency injection
 * - Delegates all classification logic to QueryClassifier component
 * - Delegates all splitting logic to QuerySplitter component
 * - Delegates all synthesis logic to ResultSynthesizer component
 * - Delegates all aggregation logic to ResultAggregator component
 * - Delegates all validation logic to PromptValidator component
 * - Maintains direct integration with specialized executors (Analytics, Semantic, WebSearch)
 * - Integrates with ConversationMemory for contextual query processing
 * - Integrates with FeedbackImpactDetector for feedback-influenced responses
 *
 * Public API Methods:
 * - classifyQueryType(): Classify query type (analytics, semantic, web_search, hybrid)
 * - executeAnalyticsQuery(): Execute analytics query via AnalyticsQueryExecutor
 * - executeSemanticQuery(): Execute semantic query via SemanticQueryExecutor
 * - executeWebSearchQuery(): Execute web search query via WebSearchQueryExecutor
 * - splitHybridQuery(): Split hybrid query into sub-queries
 * - synthesizeResults(): Synthesize results from multiple sub-queries
 * - detectMultipleIntents(): Detect if query requires multiple agents
 * - splitComplexQuery(): Split complex query into sub-queries
 * - handleComplexQuery(): Orchestrate complex query processing pipeline
 * - storeInteraction(): Store interaction in conversation memory
 * - getConversationContext(): Get relevant context for current query
 * - getLastEntity(): Get last entity from conversation memory
 * - setLastEntity(): Set last entity in conversation memory
 *
 * Responsibilities:
 * - Initialize and orchestrate SubHybridQueryProcessor components via factory
 * - Delegate query execution to specialized executors (Analytics, Semantic, WebSearch)
 * - Manage conversation memory and feedback detection
 * - Maintain public API compatibility with original HybridQueryProcessor
 * - Provide unified interface for complex query processing
 *
 * Requirements: 
 * - REQ-1.1: Code organization and modularity
 * - REQ-1.3: Single Responsibility Principle compliance
 * - REQ-1.5: Backward compatibility with existing API
 * - REQ-6.1: Delegate analytics queries to AnalyticsQueryExecutor
 * - REQ-6.2: Delegate semantic queries to SemanticQueryExecutor
 * - REQ-6.3: Delegate web search queries to WebSearchQueryExecutor
 * - REQ-6.4: Maintain references to all executors
 * - REQ-6.5: Handle executor failures gracefully
 * - REQ-7.1: Store interactions in conversation memory
 * - REQ-7.2: Retrieve conversation context for contextual queries
 * - REQ-7.3: Track last entity in memory
 * - REQ-7.4: Detect feedback impact from previous interactions
 * - REQ-7.5: Enhance responses with feedback information
 * - REQ-10.1: Comprehensive architecture documentation
 * - REQ-10.2: Component responsibility documentation
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubOrchestrator
 * @since 2025-12-14
 * @version 1.0.0
 */
#[AllowDynamicProperties]
class HybridQueryProcessor
{
  private SecurityLogger $logger;
  private bool $debug;
  private ?ConversationMemory $conversationMemory = null;
  private ?MemoryRetentionService $memoryRetentionService = null;
  private ?FeedbackImpactDetector $feedbackDetector = null;
  
  // Specialized executors
  private ?SemanticQueryExecutor $semanticExecutor = null;
  private ?AnalyticsQueryExecutor $analyticsExecutor = null;
  private ?WebSearchQueryExecutor $webSearchExecutor = null;

  // Component factory
  private HybridQueryProcessorFactory $factory;
  
  // TASK 8: Hybrid query cache for multi-temporal queries
  private ?HybridQueryCache $hybridCache = null;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   * @param string|null $userId User identifier for memory tracking
   * @param int|null $languageId Language ID for memory tracking
   * @param int $entityId Entity ID for memory context
   */
  public function __construct(bool $debug = false, ?string $userId = null, ?int $languageId = null, int $entityId = 0)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;

    // Initialize component factory
    $this->factory = new HybridQueryProcessorFactory($debug);

    // Initialize ConversationMemory if user context is provided
    if ($userId !== null) {
      try {
        $this->conversationMemory = new ConversationMemory($userId, $languageId, 'rag_conversation_memory_embedding', $entityId);
        $this->memoryRetentionService = new MemoryRetentionService($userId, $languageId);

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "ConversationMemory initialized for user: {$userId}, language: {$languageId}, entity: {$entityId}",
            'info'
          );
        }
      } catch (\Exception $e) {
        $this->logger->logSecurityEvent(
          "Failed to initialize ConversationMemory: " . $e->getMessage(),
          'warning'
        );
        $this->conversationMemory = null;
        $this->memoryRetentionService = null;
      }
    }

    // Initialize FeedbackImpactDetector
    $this->feedbackDetector = new FeedbackImpactDetector($this->debug);

    // Initialize specialized executors
    $this->semanticExecutor = new SemanticQueryExecutor($this->debug, $this->conversationMemory);
    $this->analyticsExecutor = new AnalyticsQueryExecutor($this->debug, $this->conversationMemory);
    $this->webSearchExecutor = new WebSearchQueryExecutor($this->debug, $this->conversationMemory);
    
    // TASK 8: Initialize hybrid query cache for multi-temporal queries
    // Cache TTL: 60 minutes (configurable)
    $this->hybridCache = new HybridQueryCache(60, $this->debug);

    if ($this->debug) {
      $this->logger->logSecurityEvent("HybridQueryProcessor initialized", 'info');
    }
  }

  /**
   * Classify query type: analytic, semantic, web, or hybrid
   *
   * DELEGATED to QueryClassifier component via HybridQueryProcessorFactory.
   * This method maintains backward compatibility with the original HybridQueryProcessor API.
   *
   * The QueryClassifier uses centralized pattern classes (AnalyticsPattern, SemanticsPattern,
   * WebSearchPattern) to determine query type with confidence scores.
   *
   * @param string $query Query to classify
   * @return array Classification result with structure:
   *   - type: string (analytics|semantic|web_search|hybrid)
   *   - confidence: float (0.0-1.0)
   *   - reasoning: string (explanation of classification)
   *   - patterns_matched: array (patterns that matched)
   *   - is_hybrid: bool (true if multiple intents detected)
   * 
   * @see QueryClassifier::process() for implementation details
   * @see REQ-2.1, REQ-2.2, REQ-2.3, REQ-2.4, REQ-2.5
   */
  public function classifyQueryType(string $query): array
  {
    return $this->factory->getQueryClassifier()->process($query);
  }

  /**
   * Execute an analytics query
   *
   * DELEGATED to AnalyticsQueryExecutor. This method converts natural language
   * queries into SQL and executes them against the database.
   *
   * The AnalyticsQueryExecutor handles:
   * - Natural language to SQL conversion via LLM
   * - SQL validation and security checks
   * - Query execution with error handling
   * - Result formatting and metadata
   *
   * @param string $query Analytics query in natural language (e.g., "show me sales for last month")
   * @param array $context Context information with keys:
   *   - language_id: int (language identifier)
   *   - entity_id: int (entity context)
   *   - user_id: string (user identifier)
   * @return array Result with structure:
   *   - success: bool (execution status)
   *   - type: string (always 'analytics')
   *   - data: array (query results)
   *   - sql: string (generated SQL query)
   *   - metadata: array (execution metadata)
   *   - error: string (error message if failed)
   * 
   * @see AnalyticsQueryExecutor::execute() for implementation details
   * @see REQ-6.1: Delegate analytics queries to AnalyticsQueryExecutor
   */
  public function executeAnalyticsQuery(string $query, array $context = []): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Delegating analytics query to AnalyticsQueryExecutor",
        'info'
      );
    }
    
    return $this->analyticsExecutor->execute($query, $context);
  }

  /**
   * Execute a semantic search query
   *
   * DELEGATED to SemanticQueryExecutor. This method performs vector similarity
   * search against embedded knowledge base to find relevant information.
   *
   * The SemanticQueryExecutor handles:
   * - Query embedding generation
   * - Vector similarity search against knowledge base
   * - Result ranking by relevance score
   * - Context extraction from embeddings
   * - No LLM generation (pure retrieval)
   *
   * @param string $query Semantic query (e.g., "how does checkout work", "what is return policy")
   * @param array $context Context information with keys:
   *   - language_id: int (language identifier)
   *   - entity_id: int (entity context)
   *   - user_id: string (user identifier)
   *   - limit: int (max results to return, default 5)
   * @return array Result with structure:
   *   - success: bool (execution status)
   *   - type: string (always 'semantic')
   *   - results: array (embedding search results)
   *   - sources: array (source documents)
   *   - relevance_scores: array (similarity scores)
   *   - metadata: array (execution metadata)
   *   - error: string (error message if failed)
   * 
   * @see SemanticQueryExecutor::execute() for implementation details
   * @see REQ-6.2: Delegate semantic queries to SemanticQueryExecutor
   */
  public function executeSemanticQuery(string $query, array $context = []): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Delegating semantic query to SemanticQueryExecutor",
        'info'
      );
    }
    
    return $this->semanticExecutor->execute($query, $context);
  }

  /**
   * Execute a web search query
   *
   * DELEGATED to WebSearchQueryExecutor. This method performs external web searches
   * to retrieve information not available in the internal knowledge base.
   *
   * The WebSearchQueryExecutor handles:
   * - External search API integration (SerpAPI, etc.)
   * - Result filtering and ranking
   * - Source citation and attribution
   * - Content extraction and summarization
   * - Rate limiting and error handling
   *
   * @param string $query Web search query (e.g., "competitor prices", "industry trends")
   * @param array $context Context information with keys:
   *   - language_id: int (language identifier)
   *   - entity_id: int (entity context)
   *   - user_id: string (user identifier)
   *   - max_results: int (max results to return, default 5)
   * @return array Result with structure:
   *   - success: bool (execution status)
   *   - type: string (always 'web_search')
   *   - results: array (search results)
   *   - sources: array (external sources with URLs)
   *   - citations: array (citation information)
   *   - metadata: array (execution metadata)
   *   - error: string (error message if failed)
   * 
   * @see WebSearchQueryExecutor::execute() for implementation details
   * @see REQ-6.3: Delegate web search queries to WebSearchQueryExecutor
   */
  public function executeWebSearchQuery(string $query, array $context = []): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Delegating web search query to WebSearchQueryExecutor",
        'info'
      );
    }
    
    return $this->webSearchExecutor->execute($query, $context);
  }

  /**
   * Split hybrid query into sub-queries
   *
   * DELEGATED to QuerySplitter component via HybridQueryProcessorFactory.
   * This method decomposes complex queries with multiple intents into separate
   * sub-queries that can be processed independently.
   *
   * The QuerySplitter handles:
   * - **Temporal splitting** (NEW): Multi-temporal queries split by temporal periods
   * - Report/analysis query splitting (analytics + semantic + web_search)
   * - Comma-separated intent splitting
   * - "and then" pattern splitting (sequential)
   * - Multiple question splitting (multiple "?")
   * - Analytics + analytics combinations
   * - LLM-based intelligent splitting with fallback
   *
   * **Temporal Splitting (Requirements 4.1, 4.2, 4.3, 4.4)**:
   * When the intent contains temporal metadata (temporal_periods, temporal_connectors),
   * the QuerySplitter creates one sub-query per temporal period, preserving:
   * - Base metric (revenue, sales, etc.)
   * - Time range (year 2025, this year, etc.)
   * - Assigns correct temporal period to each sub-query
   *
   * **Error Handling (Requirement 8.2)**:
   * - Detects > 5 temporal periods and warns user
   * - Suggests simplification for complex queries
   * - Allows user to proceed or modify
   *
   * @param string $query Original query to split
   * @param array $intent Intent analysis from QueryClassifier/UnifiedQueryAnalyzer with keys:
   *   - type: string (query type)
   *   - confidence: float (classification confidence)
   *   - is_hybrid: bool (multiple intents detected)
   *   - is_multi_temporal: bool (multiple temporal periods detected)
   *   - temporal_periods: array (list of temporal periods: month, quarter, etc.)
   *   - temporal_connectors: array (list of connectors: then, and, etc.)
   *   - base_metric: string|null (revenue, sales, etc.)
   *   - time_range: string|null (year 2025, this year, etc.)
   *   - patterns_matched: array (matched patterns)
   * @return array Array of sub-queries with structure:
   *   [
   *     ['query' => string, 'type' => string, 'confidence' => float, 'priority' => int, 
   *      'temporal_period' => string|null, 'base_metric' => string|null, 'time_range' => string|null],
   *     ...
   *   ]
   *   OR if too many temporal periods:
   *   [
   *     'warning' => true,
   *     'warning_type' => 'too_many_temporal_periods',
   *     'message' => string,
   *     'temporal_period_count' => int,
   *     'max_allowed' => int,
   *     'suggested_action' => string,
   *     'sub_queries' => array (limited to max_allowed)
   *   ]
   * 
   * @see QuerySplitter::process() for implementation details
   * @see QuerySplitter::splitByTemporalPeriods() for temporal splitting
   * @see REQ-3.1, REQ-3.2, REQ-3.3, REQ-3.4, REQ-3.5, REQ-3.6
   * @see REQ-4.1, REQ-4.2, REQ-4.3, REQ-4.4 (temporal splitting)
   * @see REQ-8.2 (too many temporal aggregations)
   */
  public function splitHybridQuery(string $query, array $intent): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Delegating query splitting to QuerySplitter",
        'info'
      );
      
      // Log temporal metadata if present
      if (!empty($intent['temporal_periods'])) {
        $this->logger->logSecurityEvent(
          "Temporal metadata detected: periods=" . implode(',', $intent['temporal_periods']) . 
          ", connectors=" . implode(',', $intent['temporal_connectors'] ?? []) .
          ", base_metric=" . ($intent['base_metric'] ?? 'none') .
          ", time_range=" . ($intent['time_range'] ?? 'none'),
          'info'
        );
      }
    }

    // **Requirement 8.2**: Check for too many temporal aggregations (> 5)
    $temporalPeriods = $intent['temporal_periods'] ?? [];
    $temporalPeriodCount = count($temporalPeriods);
    $maxAllowedPeriods = 5;

    if ($temporalPeriodCount > $maxAllowedPeriods) {
      $this->logger->logSecurityEvent(
        "Too many temporal aggregations detected: {$temporalPeriodCount} (max: {$maxAllowedPeriods})",
        'warning',
        [
          'query' => $query,
          'temporal_periods' => $temporalPeriods,
          'count' => $temporalPeriodCount,
        ]
      );

      // Return warning with limited sub-queries
      $limitedIntent = $intent;
      $limitedIntent['temporal_periods'] = array_slice($temporalPeriods, 0, $maxAllowedPeriods);
      $limitedIntent['temporal_period_count'] = $maxAllowedPeriods;

      $limitedSubQueries = $this->factory->getQuerySplitter()->process([
        'query' => $query,
        'intent' => $limitedIntent
      ]);

      return [
        'warning' => true,
        'warning_type' => 'too_many_temporal_periods',
        'message' => "Your query contains {$temporalPeriodCount} temporal aggregations, which exceeds the maximum of {$maxAllowedPeriods}. " .
                     "For better performance and clarity, consider splitting this into multiple queries. " .
                     "Proceeding with the first {$maxAllowedPeriods} temporal periods: " . 
                     implode(', ', array_slice($temporalPeriods, 0, $maxAllowedPeriods)) . ".",
        'temporal_period_count' => $temporalPeriodCount,
        'max_allowed' => $maxAllowedPeriods,
        'suggested_action' => 'Consider breaking your query into smaller parts, each with fewer temporal aggregations.',
        'skipped_periods' => array_slice($temporalPeriods, $maxAllowedPeriods),
        'sub_queries' => $limitedSubQueries,
      ];
    }

    return $this->factory->getQuerySplitter()->process([
      'query' => $query,
      'intent' => $intent
    ]);
  }

  /**
   * Synthesize results from multiple sub-queries or single query
   *
   * DELEGATED to ResultSynthesizer component via HybridQueryProcessorFactory.
   * This method combines results from multiple sub-queries into a coherent response.
   *
   * The ResultSynthesizer handles:
   * - Synthesis type detection (semantic, analytics, web_search, hybrid)
   * - Semantic synthesis: Extract from embeddings only (no LLM generation)
   * - Analytics synthesis: Format SQL results in tables with actual data
   * - Web search synthesis: Present external sources with citations
   * - Hybrid synthesis: Combine results with source attribution using LLM
   * - Entity aggregation from sub-queries
   *
   * @param array $subQueryResults Array of results from sub-queries, each with structure:
   *   - success: bool (execution status)
   *   - type: string (query type)
   *   - result: mixed (query result)
   *   - metadata: array (execution metadata)
   * @param string $originalQuery Original query before splitting
   * @return array Synthesized result with structure:
   *   - type: string (synthesis type)
   *   - result: string (synthesized response)
   *   - sources: array (all sources from sub-queries)
   *   - entities: array (aggregated entities)
   *   - metadata: array (synthesis metadata)
   * 
   * @see ResultSynthesizer::process() for implementation details
   * @see REQ-4.1, REQ-4.2, REQ-4.3, REQ-4.4, REQ-4.5, REQ-4.6
   */
  public function synthesizeResults(array $subQueryResults, string $originalQuery): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Delegating result synthesis to ResultSynthesizer",
        'info'
      );
    }

    return $this->factory->synthesizeResults($subQueryResults, $originalQuery, [
      'start_time' => microtime(true)
    ]);
  }

  /**
   * Detect if query requires multiple agents (hybrid query)
   *
   * DELEGATED to QuerySplitter component via HybridQueryProcessorFactory.
   * This method determines if a query contains multiple intents that require
   * processing by different agents (analytics, semantic, web_search).
   *
   * Detection criteria:
   * - Multiple question marks (multiple questions)
   * - Comma-separated intents
   * - "and then" patterns (sequential operations)
   * - Report/analysis keywords with multiple data sources
   * - Analytics + analytics combinations
   * - Intent classification indicates hybrid type
   *
   * @param string $query Query to analyze for multiple intents
   * @param array $intent Intent analysis result from QueryClassifier (optional)
   *   If provided, uses classification to inform detection
   * @return bool True if hybrid query detected (requires multiple agents)
   * 
   * @see QuerySplitter::detectMultipleIntents() for implementation details
   * @see REQ-3.1, REQ-3.2, REQ-3.3, REQ-3.4, REQ-3.5
   */
  public function detectMultipleIntents(string $query, array $intent = []): bool
  {
    return $this->factory->getQuerySplitter()->detectMultipleIntents($query, $intent);
  }

  /**
   * Split complex query into sub-queries
   *
   * DELEGATED to QuerySplitter component via HybridQueryProcessorFactory.
   * This is a convenience method that performs classification and splitting in one call.
   *
   * The method:
   * 1. Classifies the query to determine type and intents
   * 2. Detects if splitting is needed (multiple intents)
   * 3. Splits into sub-queries if needed
   * 4. Returns array of sub-queries with types and priorities
   *
   * @param string $query Original query to split
   * @return array Array of sub-queries with structure:
   *   [
   *     [
   *       'query' => string (sub-query text),
   *       'type' => string (analytics|semantic|web_search),
   *       'confidence' => float (classification confidence),
   *       'priority' => int (execution priority, 1=highest)
   *     ],
   *     ...
   *   ]
   * 
   * @see QuerySplitter::splitComplexQuery() for implementation details
   * @see REQ-3.1, REQ-3.2, REQ-3.3, REQ-3.4, REQ-3.5, REQ-3.6
   */
  public function splitComplexQuery(string $query): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Delegating complex query splitting to QuerySplitter",
        'info'
      );
    }

    return $this->factory->getQuerySplitter()->splitComplexQuery($query);
  }

  /**
   * Handle complex queries by decomposing and executing sub-queries
   *
   * This method orchestrates the entire complex query processing pipeline:
   * 1. Detect feedback impact
   * 2. Split query into sub-queries
   * 3. Execute each sub-query
   * 4. Aggregate results
   * 5. Enhance with feedback
   *
   * @param string $translatedQuery Translated query
   * @param string $originalQuery Original query (non-translated)
   * @param array $complexityDetection Detection result from ComplexQueryHandler
   * @param object $complexQueryHandler ComplexQueryHandler instance
   * @param object $taskPlanner TaskPlanner instance
   * @param object $planExecutor PlanExecutor instance
   * @return array Aggregated result
   */
  public function handleComplexQuery(
    string $translatedQuery,
    string $originalQuery,
    array $complexityDetection,
    object $complexQueryHandler,
    object $taskPlanner,
    object $planExecutor
  ): array {
    $startTime = microtime(true);

    try {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "HybridQueryProcessor::handleComplexQuery - Query type: " . ($complexityDetection['query_type'] ?? 'unknown'),
          'info'
        );
      }

      // Detect feedback impact before processing query
      $feedbackImpact = $this->detectFeedbackImpact($originalQuery);
      
      if ($feedbackImpact['feedback_influenced'] && $this->debug) {
        $this->logger->logSecurityEvent(
          "💡 Feedback influence detected: {$feedbackImpact['feedback_type']} (score: {$feedbackImpact['feedback_relevance_score']})",
          'info'
        );
      }

      // Split query into sub-queries using QuerySplitter
      $subQueries = $this->splitComplexQuery($translatedQuery);

      if (empty($subQueries)) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Complex query decomposition failed - no sub-queries generated",
            'warning'
          );
        }

        return ['success' => false, 'error' => 'Decomposition failed'];
      }

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Decomposed into " . count($subQueries) . " sub-queries",
          'info'
        );
      }

      // Execute each sub-query
      $subResults = [];
      $context = [
        'language_id' => $complexityDetection['language_id'] ?? 1,
        'entity_id' => $complexityDetection['entity_id'] ?? 0,
      ];

      foreach ($subQueries as $subQuery) {
        $result = $this->executeSubQuery($subQuery, $context, $taskPlanner, $planExecutor);
        $subResults[] = $result;
      }

      // Aggregate results using ResultAggregator
      $aggregatedResult = $this->factory->aggregateResults(
        array_filter($subResults, fn($r) => $r['success'] ?? false),
        array_filter($subResults, fn($r) => !($r['success'] ?? false)),
        ['complexity_detection' => $complexityDetection]
      );

      // Enhance with feedback if influenced
      if ($feedbackImpact['feedback_influenced']) {
        $aggregatedResult = $this->enhanceResponseWithFeedback($aggregatedResult, $feedbackImpact);
      }

      // Add execution time
      $aggregatedResult['execution_time'] = microtime(true) - $startTime;

      // ✅ CRITICAL: Set query_type to 'hybrid' for statistics recording
      // This ensures that hybrid queries are properly tracked in rag_statistics table
      // Requirement: Test Suite 3.1 - Statistiques enregistrées avec type='hybrid'
      if (!isset($aggregatedResult['metadata'])) {
        $aggregatedResult['metadata'] = [];
      }
      $aggregatedResult['metadata']['query_type'] = 'hybrid';
      $aggregatedResult['metadata']['sub_query_count'] = count($subQueries);
      $aggregatedResult['metadata']['successful_sub_queries'] = count(array_filter($subResults, fn($r) => $r['success'] ?? false));
      $aggregatedResult['metadata']['failed_sub_queries'] = count(array_filter($subResults, fn($r) => !($r['success'] ?? false)));

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "✅ Hybrid query metadata set: query_type='hybrid', sub_queries=" . count($subQueries),
          'info'
        );
      }

      return $aggregatedResult;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error in handleComplexQuery: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'error' => 'Complex query processing failed: ' . $e->getMessage(),
        'execution_time' => microtime(true) - $startTime,
      ];
    }
  }

  /**
   * Execute a single sub-query
   *
   * Executes a single sub-query from a complex query decomposition.
   * This is a helper method used by handleComplexQuery() and processHybridQuery() 
   * to process each sub-query independently.
   *
   * The method:
   * 1. Creates a simple intent for the sub-query
   * 2. Creates an execution plan via TaskPlanner
   * 3. Executes the plan via PlanExecutor
   * 4. Generates text_response from result using ResultFormatter
   * 5. Returns result with success status and text_response
   *
   * @param array $subQuery Sub-query with structure:
   *   - query: string (sub-query text)
   *   - type: string (analytics|semantic|web_search)
   *   - confidence: float (classification confidence)
   *   - priority: int (execution priority)
   * @param array $context Context information with keys:
   *   - language_id: int (language identifier)
   *   - entity_id: int (entity context)
   *   - user_id: string (user identifier)
   * @param object $taskPlanner TaskPlanner instance for plan creation
   * @param object $planExecutor PlanExecutor instance for plan execution
   * @return array Execution result with structure:
   *   - success: bool (execution status)
   *   - type: string (query type)
   *   - query: string (sub-query text)
   *   - result: mixed (execution result)
   *   - text_response: string (formatted text response)
   *   - error: string (error message if failed)
   * 
   * @see handleComplexQuery() for usage context
   * @see processHybridQuery() for usage context
   */
  private function executeSubQuery(array $subQuery, array $context, object $taskPlanner, object $planExecutor): array
  {
    try {
      $queryText = $subQuery['query'];
      $queryType = $subQuery['type'];

      // Create a simple intent for this sub-query
      $subIntent = [
        'type' => $queryType,
        'confidence' => 0.8,
        'translated_query' => $queryText,
        'is_hybrid' => false
      ];

      // Create plan for this sub-query
      $subPlan = $taskPlanner->createPlan($subIntent, $queryText, $context);

      // Execute the plan - returns array, not ExecutionPlan object
      $executionResult = $planExecutor->execute($subPlan);

      // ✅ TASK 5.2.1.1: Generate text_response from result
      // The ResultSynthesizer expects text_response for synthesis
      $textResponse = '';
      
      if (isset($executionResult['result'])) {
        $formatter = new \ClicShopping\AI\Helper\Formatter\ResultFormatter();
        
        // Prepare result for formatting - add type and query if missing
        $resultToFormat = $executionResult['result'];
        if (is_array($resultToFormat)) {
          if (!isset($resultToFormat['type'])) {
            $resultToFormat['type'] = $queryType;
          }
          // ✅ Add query field so formatters can display the correct title
          if (!isset($resultToFormat['query']) && !isset($resultToFormat['question'])) {
            $resultToFormat['query'] = $queryText;
          }
        }
        
        $formatted = $formatter->format($resultToFormat);
        
        // Extract the 'content' field from formatted result (it's an array)
        if (is_array($formatted) && isset($formatted['content'])) {
          $textResponse = $formatted['content'];
        } elseif (is_string($formatted)) {
          $textResponse = $formatted;
        } else {
          // Fallback: convert to string
          $textResponse = is_array($executionResult['result']) 
            ? json_encode($executionResult['result'], JSON_UNESCAPED_UNICODE) 
            : (string)$executionResult['result'];
        }
      }

      return array_merge($executionResult, [
        'type' => $queryType,
        'query' => $queryText,
        'text_response' => $textResponse
      ]);

    } catch (\Exception $e) {
      return [
        'success' => false,
        'type' => $subQuery['type'] ?? 'unknown',
        'query' => $subQuery['query'] ?? '',
        'error' => $e->getMessage(),
        'text_response' => ''
      ];
    }
  }

  /**
   * Store interaction in conversation memory
   *
   * Stores the user query and system response in conversation memory for future
   * context retrieval. This enables contextual query processing and entity tracking.
   *
   * The method:
   * 1. Stores interaction in ConversationMemory (short-term memory)
   * 2. Records interaction in MemoryRetentionService (long-term memory)
   * 3. Extracts and persists important entities
   * 4. Handles errors gracefully with logging
   *
   * @param string $userQuery Original user query
   * @param string $systemResponse System response to the query
   * @param array $metadata Additional metadata with keys:
   *   - query_type: string (analytics|semantic|web_search|hybrid)
   *   - execution_time: float (query execution time)
   *   - entity_id: int (entity context)
   *   - entity_type: string (entity type)
   *   - sources: array (data sources used)
   *   - feedback_type: string (feedback type if applicable)
   * @return bool True if interaction stored successfully, false otherwise
   * 
   * @see ConversationMemory::addInteraction() for short-term storage
   * @see MemoryRetentionService::recordInteraction() for long-term storage
   * @see REQ-7.1: Store interactions in conversation memory
   */
  public function storeInteraction(string $userQuery, string $systemResponse, array $metadata = []): bool
  {
    if ($this->conversationMemory === null) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "ConversationMemory not initialized, skipping interaction storage",
          'info'
        );
      }
      return false;
    }

    try {
      $success = $this->conversationMemory->addInteraction($userQuery, $systemResponse, $metadata);

      if ($success && $this->debug) {
        $this->logger->logSecurityEvent(
          "Interaction stored in conversation memory",
          'info'
        );
      }

      // Use MemoryRetentionService to persist important entities
      if ($this->memoryRetentionService !== null) {
        try {
          $this->memoryRetentionService->recordInteraction($userQuery, $systemResponse, $metadata);
          
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Interaction recorded in MemoryRetentionService",
              'info'
            );
          }
        } catch (\Exception $e) {
          $this->logger->logSecurityEvent(
            "Error recording interaction in MemoryRetentionService: " . $e->getMessage(),
            'warning'
          );
        }
      }

      return $success;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error storing interaction: " . $e->getMessage(),
        'error'
      );
      return false;
    }
  }

  /**
   * Get relevant context for current query
   *
   * Retrieves relevant conversation context from memory to enable contextual
   * query processing. This includes recent interactions, long-term memory,
   * and feedback context.
   *
   * The method:
   * 1. Queries ConversationMemory for relevant context
   * 2. Retrieves short-term context (recent interactions)
   * 3. Retrieves long-term context (persistent entities)
   * 4. Retrieves feedback context (user feedback)
   * 5. Returns combined context with relevance scores
   *
   * @param string $currentQuery Current user query to find context for
   * @param int $limit Maximum number of context items to retrieve (default 3)
   * @return array Context data with structure:
   *   - short_term_context: array (recent interactions)
   *   - long_term_context: array (persistent entities)
   *   - feedback_context: array (user feedback)
   *   - has_context: bool (true if context available)
   *   - relevance_scores: array (context relevance scores)
   *   - error: string (error message if failed)
   * 
   * @see ConversationMemory::getRelevantContext() for implementation details
   * @see REQ-7.2: Retrieve conversation context for contextual queries
   */
  public function getConversationContext(string $currentQuery, int $limit = 3): array
  {
    if ($this->conversationMemory === null) {
      return [
        'short_term_context' => [],
        'long_term_context' => [],
        'feedback_context' => [],
        'has_context' => false,
      ];
    }

    try {
      $context = $this->conversationMemory->getRelevantContext($currentQuery, $limit);

      if ($this->debug && $context['has_context']) {
        $this->logger->logSecurityEvent(
          "Retrieved conversation context: " . count($context['short_term_context']) . " short-term, " . 
          count($context['long_term_context']) . " long-term items",
          'info'
        );
      }

      return $context;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error retrieving conversation context: " . $e->getMessage(),
        'error'
      );

      return [
        'short_term_context' => [],
        'long_term_context' => [],
        'feedback_context' => [],
        'has_context' => false,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Get last entity from conversation memory
   *
   * Retrieves the most recently accessed entity from conversation memory.
   * This enables entity-aware query processing where queries can reference
   * "it", "this product", etc. without explicit entity identification.
   *
   * Example usage:
   * - User: "Show me product 123"
   * - System: [stores entity_id=123, entity_type=product]
   * - User: "What are the reviews for it?"
   * - System: [retrieves entity_id=123, entity_type=product]
   *
   * @return array|null Entity data with structure:
   *   - id: int (entity identifier)
   *   - type: string (entity type: product, category, order, etc.)
   *   - timestamp: string (when entity was set)
   * Returns null if no entity is stored or ConversationMemory not initialized
   * 
   * @see ConversationMemory::getLastEntity() for implementation details
   * @see REQ-7.3: Track last entity in memory
   */
  public function getLastEntity(): ?array
  {
    if ($this->conversationMemory === null) {
      return null;
    }

    try {
      return $this->conversationMemory->getLastEntity();
    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error getting last entity: " . $e->getMessage(),
        'warning'
      );
      return null;
    }
  }

  /**
   * Set last entity in conversation memory
   *
   * Stores the current entity in conversation memory for future reference.
   * This enables entity-aware query processing where subsequent queries can
   * reference the entity without explicit identification.
   *
   * Example usage:
   * - After executing "Show me product 123", call setLastEntity(123, 'product')
   * - Next query "What are the reviews?" can retrieve entity via getLastEntity()
   *
   * @param int $entityId Entity identifier (e.g., product_id, category_id)
   * @param string $entityType Entity type (product, category, order, customer, etc.)
   * @return void
   * 
   * @see ConversationMemory::setLastEntity() for implementation details
   * @see REQ-7.3: Track last entity in memory
   */
  public function setLastEntity(int $entityId, string $entityType): void
  {
    if ($this->conversationMemory === null) {
      return;
    }

    try {
      $this->conversationMemory->setLastEntity($entityId, $entityType);
      
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Set last entity: {$entityType} (ID: {$entityId})",
          'info'
        );
      }
    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error setting last entity: " . $e->getMessage(),
        'warning'
      );
    }
  }

  /**
   * Detect feedback impact for current query
   *
   * Analyzes whether the current query is influenced by previous user feedback.
   * This enables feedback-aware responses that acknowledge and address user concerns.
   *
   * The method:
   * 1. Retrieves feedback context from ConversationMemory
   * 2. Analyzes query for feedback-related patterns
   * 3. Calculates feedback relevance score
   * 4. Determines if response should be influenced by feedback
   *
   * Feedback types detected:
   * - correction: User corrects previous response
   * - clarification: User asks for more details
   * - dissatisfaction: User expresses dissatisfaction
   * - confirmation: User confirms understanding
   *
   * @param string $currentQuery Current user query to analyze
   * @return array Feedback impact decision with structure:
   *   - feedback_influenced: bool (true if feedback detected)
   *   - feedback_type: string|null (correction|clarification|dissatisfaction|confirmation)
   *   - feedback_relevance_score: float (0.0-1.0, relevance of feedback)
   *   - feedback_interaction_id: int|null (ID of feedback interaction)
   *   - feedback_message: string|null (message to prepend to response)
   *   - feedback_data: array|null (additional feedback data)
   * 
   * @see FeedbackImpactDetector::detectFeedbackImpact() for implementation details
   * @see REQ-7.4: Detect feedback impact from previous interactions
   */
  private function detectFeedbackImpact(string $currentQuery): array
  {
    $defaultResult = [
      'feedback_influenced' => false,
      'feedback_type' => null,
      'feedback_relevance_score' => 0.0,
      'feedback_interaction_id' => null,
      'feedback_message' => null,
      'feedback_data' => null,
    ];

    if ($this->feedbackDetector === null || $this->conversationMemory === null) {
      return $defaultResult;
    }

    try {
      $feedbackContext = $this->conversationMemory->getFeedbackContext($currentQuery, 5);

      if (empty($feedbackContext)) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "No feedback context available for query: {$currentQuery}",
            'info'
          );
        }
        return $defaultResult;
      }

      return $this->feedbackDetector->detectFeedbackImpact($currentQuery, $feedbackContext);

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error detecting feedback impact: " . $e->getMessage(),
        'error'
      );
      return $defaultResult;
    }
  }

  /**
   * Enhance response with feedback information
   *
   * Adds feedback information to the response to acknowledge user feedback
   * and provide context-aware responses.
   *
   * The method:
   * 1. Adds feedback metadata to response
   * 2. Prepends feedback message to text response
   * 3. Updates result response with feedback acknowledgment
   * 4. Logs feedback enhancement for debugging
   *
   * Example enhancement:
   * - Original: "Here are the sales results..."
   * - Enhanced: "I understand you wanted more detail. Here are the sales results..."
   *
   * @param array $response Original response array with keys:
   *   - text_response: string (response text)
   *   - result: array (result data)
   *   - metadata: array (response metadata)
   * @param array $feedbackImpact Feedback impact data from detectFeedbackImpact()
   * @return array Enhanced response with added feedback information:
   *   - feedback_influenced: bool (true)
   *   - feedback_type: string (feedback type)
   *   - feedback_relevance_score: float (relevance score)
   *   - feedback_interaction_id: int (feedback interaction ID)
   *   - feedback_message: string (feedback message)
   *   - text_response: string (enhanced with feedback message)
   *   - result: array (enhanced with feedback message)
   * 
   * @see REQ-7.5: Enhance responses with feedback information
   */
  private function enhanceResponseWithFeedback(array $response, array $feedbackImpact): array
  {
    $response['feedback_influenced'] = $feedbackImpact['feedback_influenced'];
    $response['feedback_type'] = $feedbackImpact['feedback_type'];
    $response['feedback_relevance_score'] = $feedbackImpact['feedback_relevance_score'];
    $response['feedback_interaction_id'] = $feedbackImpact['feedback_interaction_id'];

    if ($feedbackImpact['feedback_influenced'] && !empty($feedbackImpact['feedback_message'])) {
      $response['feedback_message'] = $feedbackImpact['feedback_message'];

      if (isset($response['text_response'])) {
        $response['text_response'] = $feedbackImpact['feedback_message'] . "\n\n" . $response['text_response'];
      }

      if (isset($response['result']['response'])) {
        $response['result']['response'] = $feedbackImpact['feedback_message'] . "\n\n" . $response['result']['response'];
      }

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Response enhanced with feedback message: {$feedbackImpact['feedback_message']}",
          'info'
        );
      }
    }

    return $response;
  }

  /**
   * TASK 5.2.1.1: Process hybrid query by splitting and executing sub-queries
   *
   * This method orchestrates the complete hybrid query processing pipeline:
   * 1. Split query into sub-queries using QuerySplitter
   * 2. Execute each sub-query via TaskPlanner and PlanExecutor
   * 3. Synthesize results from all sub-queries
   * 4. Store interaction in memory
   * 5. Return final response
   *
   * @param string $query Query to process (already translated to English)
   * @param array $intent Intent analysis from UnifiedQueryAnalyzer
   * @param array $context Enriched context with conversation history
   * @param object $taskPlanner TaskPlanner instance for plan creation
   * @param object $planExecutor PlanExecutor instance for plan execution
   * @param object $responseProcessor ResponseProcessor for building final response
   * @param object $memoryManager MemoryManager for storing results
   * @param string $userId User identifier
   * @param int $languageId Language identifier
   * @param float $startTime Start time for performance tracking
   * @return array Final response with structure:
   *   - success: bool (execution status)
   *   - text_response: string (synthesized response)
   *   - result: array (combined results)
   *   - metadata: array (execution metadata including query_type='hybrid')
   *   - execution_time: float (total execution time)
   */
  public function processHybridQuery(
    string $query,
    array $intent,
    array $context,
    object $taskPlanner,
    object $planExecutor,
    object $responseProcessor,
    object $memoryManager,
    string $userId,
    int $languageId,
    float $startTime
  ): array {
    try {
      if ($this->debug) {
        $this->logger->logStructured('info', 'HybridQueryProcessor', 'processHybridQuery_start', [
          'query' => substr($query, 0, 100),
          'intent_type' => $intent['type'] ?? 'unknown',
          'is_hybrid' => $intent['is_hybrid'] ?? false,
          // ✅ Log temporal metadata for debugging
          'is_multi_temporal' => $intent['is_multi_temporal'] ?? false,
          'temporal_periods' => $intent['temporal_periods'] ?? [],
          'temporal_connectors' => $intent['temporal_connectors'] ?? [],
          'base_metric' => $intent['base_metric'] ?? null,
          'time_range' => $intent['time_range'] ?? null,
        ]);
      }

      // 1. Split query into sub-queries
      $subQueries = $this->splitHybridQuery($query, $intent);

      // **Requirement 8.2**: Handle too many temporal aggregations warning
      if (isset($subQueries['warning']) && $subQueries['warning'] === true) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Too many temporal periods warning: " . $subQueries['message'],
            'warning'
          );
        }

        // Extract the limited sub-queries and proceed with warning metadata
        $warningMetadata = [
          'warning_type' => $subQueries['warning_type'],
          'warning_message' => $subQueries['message'],
          'temporal_period_count' => $subQueries['temporal_period_count'],
          'max_allowed' => $subQueries['max_allowed'],
          'skipped_periods' => $subQueries['skipped_periods'] ?? [],
          'suggested_action' => $subQueries['suggested_action'],
        ];

        // Use the limited sub-queries
        $subQueries = $subQueries['sub_queries'];

        // Store warning for later inclusion in response
        $intent['_temporal_warning'] = $warningMetadata;
      }

      if (empty($subQueries)) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Query splitting failed - no sub-queries generated",
            'warning'
          );
        }

        return [
          'success' => false,
          'error' => 'Failed to split hybrid query',
          'execution_time' => microtime(true) - $startTime
        ];
      }

      if ($this->debug) {
        $this->logger->logStructured('info', 'HybridQueryProcessor', 'query_split', [
          'sub_query_count' => count($subQueries),
          'sub_queries' => array_map(fn($sq) => [
            'query' => substr($sq['query'], 0, 50),
            'type' => $sq['type'],
            'priority' => $sq['priority'] ?? 1
          ], $subQueries)
        ]);
      }

      // TASK 8: Check cache for multi-temporal queries (Requirements 9.1, 9.2, 9.3)
      $cacheContext = [
        'base_metric' => $intent['base_metric'] ?? null,
        'time_range' => $intent['time_range'] ?? null,
        'language_id' => $languageId,
      ];
      
      $cacheResult = null;
      $cachedSubResults = [];
      $uncachedSubQueries = $subQueries;
      
      if ($this->hybridCache !== null && $this->hybridCache->isEnabled()) {
        $cacheResult = $this->hybridCache->getMultipleSubQueryResults($subQueries, $cacheContext);
        
        if ($cacheResult['all_cached']) {
          // All sub-queries are cached - use cached results
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "TASK 8: All sub-queries found in cache - using cached results",
              'info'
            );
          }
          $subResults = array_values($cacheResult['cached']);
        } else {
          // Partial cache hit or complete miss
          $cachedSubResults = $cacheResult['cached'];
          $uncachedSubQueries = $cacheResult['uncached'];
          
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "TASK 8: Partial cache - cached: " . count($cachedSubResults) . ", uncached: " . count($uncachedSubQueries),
              'info'
            );
          }
        }
      }

      // 2. Execute uncached sub-queries only
      $subResults = [];
      
      if (!empty($cachedSubResults)) {
        // Start with cached results
        foreach ($cachedSubResults as $index => $cachedResult) {
          $subResults[$index] = $cachedResult;
        }
      }
      
      // Execute uncached sub-queries
      $newResults = [];
      foreach ($uncachedSubQueries as $index => $subQuery) {
        $result = $this->executeSubQuery($subQuery, $context, $taskPlanner, $planExecutor);
        $subResults[$index] = $result;
        
        // TASK 8: Cache the new result (Requirements 9.1, 9.2)
        if ($this->hybridCache !== null && $this->hybridCache->isEnabled() && ($result['success'] ?? false)) {
          $temporalPeriod = $subQuery['temporal_period'] ?? 'unknown';
          $this->hybridCache->cacheSubQueryResult(
            $subQuery['query'],
            $temporalPeriod,
            $result,
            $cacheContext
          );
          
          $newResults[] = [
            'query' => $subQuery['query'],
            'temporal_period' => $temporalPeriod,
            'result' => $result
          ];
        }
      }
      
      // Sort results by index to maintain order
      ksort($subResults);
      $subResults = array_values($subResults);
      
      if ($this->debug && !empty($newResults)) {
        $this->logger->logSecurityEvent(
          "TASK 8: Cached " . count($newResults) . " new sub-query results",
          'info'
        );
      }

      // 3. Synthesize results
      $synthesizedResult = $this->synthesizeResults($subResults, $query);

      // 4. Build final response
      // ✅ TASK 5.2.1.1: Pass synthesizedResult directly as executionResult
      // The synthesizedResult already has the correct structure with text_response at top level
      $response = $responseProcessor->buildOrchestrationResponse(
        $synthesizedResult, // Pass directly, not wrapped in ['result' => ...]
        $intent,
        $query,
        $startTime,
        $synthesizedResult['entity_id'] ?? 0, // Extract entity_id from synthesized result
        $synthesizedResult['entity_type'] ?? 'hybrid', // Extract entity_type from synthesized result
        null // llmResponseProcessor (not needed for hybrid)
      );

      // 5. Set metadata to indicate hybrid query
      if (!isset($response['metadata'])) {
        $response['metadata'] = [];
      }
      $response['metadata']['query_type'] = 'hybrid';
      $response['metadata']['sub_query_count'] = count($subQueries);
      $response['metadata']['successful_sub_queries'] = count(array_filter($subResults, fn($r) => $r['success'] ?? false));
      
      // **Requirement 8.2**: Include temporal warning in metadata if present
      if (isset($intent['_temporal_warning'])) {
        $response['metadata']['temporal_warning'] = $intent['_temporal_warning'];
        
        // Prepend warning message to text_response
        if (isset($response['text_response'])) {
          $warningHtml = '<div class="alert alert-warning" role="alert">' .
                         '<strong>⚠️ Note:</strong> ' . htmlspecialchars($intent['_temporal_warning']['warning_message']) .
                         '</div>';
          $response['text_response'] = $warningHtml . $response['text_response'];
        }
      }
      
      // TASK 8: Add cache statistics to metadata (Requirements 9.1, 9.2, 9.3)
      if ($cacheResult !== null) {
        $response['metadata']['cache_hits'] = count($cachedSubResults);
        $response['metadata']['cache_misses'] = count($uncachedSubQueries);
        $response['metadata']['all_from_cache'] = $cacheResult['all_cached'] ?? false;
        $response['metadata']['partial_cache_hit'] = $cacheResult['partial_hit'] ?? false;
      }

      // 6. Store in memory
      $memoryManager->storeOrchestrationResult(
        $query,
        $query,
        $response,
        $intent,
        ['is_related_to_context' => false],
        null, // plan (not available for hybrid)
        [],   // validationResults
        0,    // entityId
        'hybrid', // entityType
        $userId,
        $languageId,
        null, // queryAnalyzer
        $responseProcessor
      );

      if ($this->debug) {
        $this->logger->logStructured('info', 'HybridQueryProcessor', 'processHybridQuery_complete', [
          'success' => true,
          'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ]);
      }

      return $response;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error in processHybridQuery: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'error' => 'Hybrid query processing failed: ' . $e->getMessage(),
        'execution_time' => microtime(true) - $startTime,
        'metadata' => ['query_type' => 'hybrid']
      ];
    }
  }
  
  /**
   * TASK 8: Get hybrid query cache statistics
   * 
   * @return array Cache statistics
   */
  public function getHybridCacheStats(): array
  {
    if ($this->hybridCache === null) {
      return ['enabled' => false, 'message' => 'Hybrid cache not initialized'];
    }
    
    return $this->hybridCache->getStatistics();
  }
  
  /**
   * TASK 8: Clear all hybrid query cache
   * 
   * @return int Number of cache entries deleted
   */
  public function clearHybridCache(): int
  {
    if ($this->hybridCache === null) {
      return 0;
    }
    
    return $this->hybridCache->clearAll();
  }
  
  /**
   * TASK 8: Invalidate cache for a specific multi-temporal query
   * 
   * @param string $query Original query
   * @param array $temporalPeriods List of temporal periods
   * @param array $context Additional context
   * @return bool Success
   */
  public function invalidateHybridCache(string $query, array $temporalPeriods, array $context = []): bool
  {
    if ($this->hybridCache === null) {
      return false;
    }
    
    return $this->hybridCache->invalidateMultiTemporalQuery($query, $temporalPeriods, $context);
  }

  /**
   * Detect mixed temporal and non-temporal aggregations
   *
   * **Requirement 8.6**: Handle mixed temporal and non-temporal aggregations
   *
   * Detects when a query contains both temporal aggregations (by month, by quarter)
   * AND non-temporal aggregations (by product, by category, by region).
   *
   * Examples:
   * - "revenue by month and by product category" → mixed
   * - "sales by quarter and by region" → mixed
   * - "orders by week and by customer type" → mixed
   *
   * @param string $query The query to analyze
   * @param array $intent Intent analysis with temporal metadata
   * @return array Detection result with structure:
   *   - is_mixed: bool (true if mixed aggregations detected)
   *   - temporal_dimensions: array (temporal aggregation dimensions)
   *   - non_temporal_dimensions: array (non-temporal aggregation dimensions)
   *   - aggregation_count: int (total number of aggregation dimensions)
   *   - suggested_approach: string (how to handle the query)
   */
  public function detectMixedAggregations(string $query, array $intent = []): array
  {
    $defaultResult = [
      'is_mixed' => false,
      'temporal_dimensions' => [],
      'non_temporal_dimensions' => [],
      'aggregation_count' => 0,
      'suggested_approach' => 'standard',
    ];

    $queryLower = strtolower($query);

    // Extract temporal dimensions from intent or detect from query
    $temporalDimensions = $intent['temporal_periods'] ?? [];
    
    // If no temporal periods in intent, try to detect from query
    if (empty($temporalDimensions)) {
      $temporalDimensions = $this->detectTemporalDimensionsFromQuery($queryLower);
    }

    // Detect non-temporal aggregation dimensions
    $nonTemporalDimensions = $this->detectNonTemporalDimensionsFromQuery($queryLower);

    // Determine if mixed
    $isMixed = !empty($temporalDimensions) && !empty($nonTemporalDimensions);
    $totalDimensions = count($temporalDimensions) + count($nonTemporalDimensions);

    // Determine suggested approach
    $suggestedApproach = $this->determineMixedAggregationApproach(
      $temporalDimensions,
      $nonTemporalDimensions,
      $totalDimensions
    );

    // Log detection
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Mixed aggregation detection: " . ($isMixed ? 'MIXED' : 'NOT MIXED'),
        'info',
        [
          'query' => $query,
          'temporal_dimensions' => $temporalDimensions,
          'non_temporal_dimensions' => $nonTemporalDimensions,
          'total_dimensions' => $totalDimensions,
          'suggested_approach' => $suggestedApproach,
        ]
      );
    }

    return [
      'is_mixed' => $isMixed,
      'temporal_dimensions' => $temporalDimensions,
      'non_temporal_dimensions' => $nonTemporalDimensions,
      'aggregation_count' => $totalDimensions,
      'suggested_approach' => $suggestedApproach,
    ];
  }

  /**
   * Detect temporal dimensions from query text
   *
   * @param string $query Lowercase query
   * @return array List of temporal dimensions
   */
  private function detectTemporalDimensionsFromQuery(string $query): array
  {
    $temporalPatterns = [
      'month' => '/\b(by\s+)?month(ly)?\b/i',
      'quarter' => '/\b(by\s+)?quarter(ly)?\b/i',
      'semester' => '/\b(by\s+)?semester\b/i',
      'year' => '/\b(by\s+)?year(ly)?\b/i',
      'week' => '/\b(by\s+)?week(ly)?\b/i',
      'day' => '/\b(by\s+)?day|daily\b/i',
    ];

    $detected = [];
    foreach ($temporalPatterns as $period => $pattern) {
      if (preg_match($pattern, $query)) {
        $detected[] = $period;
      }
    }

    return $detected;
  }

  /**
   * Detect non-temporal aggregation dimensions from query text
   *
   * @param string $query Lowercase query
   * @return array List of non-temporal dimensions
   */
  private function detectNonTemporalDimensionsFromQuery(string $query): array
  {
    $nonTemporalPatterns = [
      'product' => '/\b(by\s+)?product(s)?\b/i',
      'category' => '/\b(by\s+)?categor(y|ies)\b/i',
      'product_category' => '/\b(by\s+)?product\s+categor(y|ies)\b/i',
      'region' => '/\b(by\s+)?region(s)?\b/i',
      'country' => '/\b(by\s+)?countr(y|ies)\b/i',
      'customer' => '/\b(by\s+)?customer(s)?\b/i',
      'customer_type' => '/\b(by\s+)?customer\s+type(s)?\b/i',
      'supplier' => '/\b(by\s+)?supplier(s)?\b/i',
      'manufacturer' => '/\b(by\s+)?manufacturer(s)?\b/i',
      'brand' => '/\b(by\s+)?brand(s)?\b/i',
      'channel' => '/\b(by\s+)?channel(s)?\b/i',
      'sales_channel' => '/\b(by\s+)?sales\s+channel(s)?\b/i',
      'store' => '/\b(by\s+)?store(s)?\b/i',
      'department' => '/\b(by\s+)?department(s)?\b/i',
      'status' => '/\b(by\s+)?status\b/i',
      'order_status' => '/\b(by\s+)?order\s+status\b/i',
    ];

    $detected = [];
    foreach ($nonTemporalPatterns as $dimension => $pattern) {
      if (preg_match($pattern, $query)) {
        $detected[] = $dimension;
      }
    }

    // Remove duplicates (e.g., if both 'product' and 'product_category' match)
    return array_unique($detected);
  }

  /**
   * Determine approach for handling mixed aggregations
   *
   * @param array $temporalDimensions Temporal dimensions
   * @param array $nonTemporalDimensions Non-temporal dimensions
   * @param int $totalDimensions Total dimension count
   * @return string Suggested approach
   */
  private function determineMixedAggregationApproach(
    array $temporalDimensions,
    array $nonTemporalDimensions,
    int $totalDimensions
  ): string {
    // If only temporal or only non-temporal, use standard approach
    if (empty($temporalDimensions) || empty($nonTemporalDimensions)) {
      return 'standard';
    }

    // If too many dimensions, suggest simplification
    if ($totalDimensions > 4) {
      return 'simplify';
    }

    // If 2-4 dimensions, use nested approach (temporal first, then non-temporal)
    if ($totalDimensions <= 4) {
      return 'nested';
    }

    return 'standard';
  }

  /**
   * Handle mixed temporal and non-temporal aggregations
   *
   * **Requirement 8.6**: Handle mixed temporal and non-temporal aggregations
   *
   * Creates sub-queries for each dimension combination:
   * - For each temporal period, create a sub-query
   * - For each non-temporal dimension, create a sub-query
   * - Optionally create combined sub-queries (temporal + non-temporal)
   *
   * @param string $query Original query
   * @param array $intent Intent analysis
   * @param array $mixedDetection Result from detectMixedAggregations()
   * @return array Array of sub-queries for all dimensions
   */
  public function handleMixedAggregations(
    string $query,
    array $intent,
    array $mixedDetection
  ): array {
    if (!$mixedDetection['is_mixed']) {
      // Not mixed, use standard splitting
      return $this->splitHybridQuery($query, $intent);
    }

    $temporalDimensions = $mixedDetection['temporal_dimensions'];
    $nonTemporalDimensions = $mixedDetection['non_temporal_dimensions'];
    $approach = $mixedDetection['suggested_approach'];

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Handling mixed aggregations with approach: {$approach}",
        'info',
        [
          'temporal' => $temporalDimensions,
          'non_temporal' => $nonTemporalDimensions,
        ]
      );
    }

    $subQueries = [];
    $baseMetric = $intent['base_metric'] ?? $this->extractBaseMetricFromQuery($query);
    $timeRange = $intent['time_range'] ?? $this->extractTimeRangeFromQuery($query);
    $priority = 1;

    switch ($approach) {
      case 'nested':
        // Create nested sub-queries: temporal first, then non-temporal within each
        foreach ($temporalDimensions as $temporal) {
          // First, create temporal-only sub-query
          $subQueries[] = [
            'query' => "{$baseMetric} for {$timeRange} by {$temporal}",
            'type' => 'analytics',
            'confidence' => 0.9,
            'priority' => $priority++,
            'temporal_period' => $temporal,
            'base_metric' => $baseMetric,
            'time_range' => $timeRange,
            'aggregation_type' => 'temporal',
          ];

          // Then, create combined sub-queries for each non-temporal dimension
          foreach ($nonTemporalDimensions as $nonTemporal) {
            $subQueries[] = [
              'query' => "{$baseMetric} for {$timeRange} by {$temporal} and by {$nonTemporal}",
              'type' => 'analytics',
              'confidence' => 0.85,
              'priority' => $priority++,
              'temporal_period' => $temporal,
              'non_temporal_dimension' => $nonTemporal,
              'base_metric' => $baseMetric,
              'time_range' => $timeRange,
              'aggregation_type' => 'mixed',
            ];
          }
        }
        break;

      case 'simplify':
        // Too many dimensions - create separate sub-queries and warn user
        // Temporal sub-queries
        foreach ($temporalDimensions as $temporal) {
          $subQueries[] = [
            'query' => "{$baseMetric} for {$timeRange} by {$temporal}",
            'type' => 'analytics',
            'confidence' => 0.9,
            'priority' => $priority++,
            'temporal_period' => $temporal,
            'base_metric' => $baseMetric,
            'time_range' => $timeRange,
            'aggregation_type' => 'temporal',
          ];
        }

        // Non-temporal sub-queries
        foreach ($nonTemporalDimensions as $nonTemporal) {
          $subQueries[] = [
            'query' => "{$baseMetric} for {$timeRange} by {$nonTemporal}",
            'type' => 'analytics',
            'confidence' => 0.85,
            'priority' => $priority++,
            'non_temporal_dimension' => $nonTemporal,
            'base_metric' => $baseMetric,
            'time_range' => $timeRange,
            'aggregation_type' => 'non_temporal',
          ];
        }

        // Add warning to first sub-query
        if (!empty($subQueries)) {
          $subQueries[0]['warning'] = 'Query contains many aggregation dimensions. Results are shown separately for clarity.';
        }
        break;

      default:
        // Standard approach - use regular splitting
        return $this->splitHybridQuery($query, $intent);
    }

    // Log the result
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Mixed aggregation handling complete",
        'info',
        [
          'sub_query_count' => count($subQueries),
          'approach' => $approach,
        ]
      );
    }

    return $subQueries;
  }

  /**
   * Extract base metric from query (helper method)
   *
   * @param string $query Query text
   * @return string Base metric or 'revenue' as default
   */
  private function extractBaseMetricFromQuery(string $query): string
  {
    return QuerySplitterPatterns::extractBaseMetricWithRegex($query, 'revenue');
  }

  /**
   * Extract time range from query (helper method)
   * 
   * NOTE: Pattern logic moved to QuerySplitterPatterns class.
   *
   * @param string $query Query text
   * @return string Time range or 'this year' as default
   */
  private function extractTimeRangeFromQuery(string $query): string
  {
    return QuerySplitterPatterns::extractTimeRangeWithRegex($query, 'this year');
  }
}
