<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Tools\BIexecution;

use ClicShopping\AI\Helper\AgentResponseHelper;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Agents\Orchestrator\AnalyticsAgent;
use ClicShopping\AI\Agents\Memory\ConversationMemory;
use ClicShopping\OM\Registry;

/**
 * AnalyticsQueryExecutor Class
 *
 * Responsibility: Execute analytics queries by generating and executing SQL.
 * This class is focused solely on analytics query execution and does not handle
 * other query types or orchestration logic.
 *
 * Extracted from HybridQueryProcessor as part of refactoring (Task 2.11.2)
 */
class AnalyticsQueryExecutor
{
  private SecurityLogger $logger;
  private bool $debug;
  private ?ConversationMemory $conversationMemory;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   * @param ConversationMemory|null $conversationMemory Optional conversation memory for context
   */
  public function __construct(bool $debug = false, ?ConversationMemory $conversationMemory = null)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
    $this->conversationMemory = $conversationMemory;
  }

  /**
   * Execute an analytics query
   *
   * This method generates and executes SQL queries from natural language.
   * Supports multiple SQL queries in a single request (e.g., "stock of iPhone 17 AND stock by EAN").
   * All database operations are wrapped in try-catch to prevent server 500 errors.
   *
   * @param string $query Analytics query in natural language
   * @param array $context Context information (language_id, entity_id, etc.)
   * @return array Result with structured data
   */
  public function execute(string $query, array $context = []): array
  {
    try {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "AnalyticsQueryExecutor: Executing analytics query: {$query}",
          'info'
        );
      }

      // Resolve contextual references using ConversationMemory
      $resolvedQuery = $query;
      $contextUsed = null;
      
      if ($this->conversationMemory !== null) {
        try {
          $resolutionResult = $this->conversationMemory->resolveContextualReferences($query);
          
          if ($resolutionResult['has_references'] && !empty($resolutionResult['resolved_query'])) {
            $resolvedQuery = $resolutionResult['resolved_query'];
            $contextUsed = $resolutionResult['context_used'];
            
            if ($this->debug) {
              $this->logger->logSecurityEvent(
                "Contextual references resolved in analytics: '{$query}' -> '{$resolvedQuery}'",
                'info'
              );
            }
          }
        } catch (\Exception $e) {
          $this->logger->logSecurityEvent(
            "Error resolving contextual references: " . $e->getMessage(),
            'warning'
          );
          // Continue with original query
        }
      }

      // Retrieve last entity from memory if available
      $lastEntity = null;
      if ($this->conversationMemory !== null) {
        try {
          $lastEntity = $this->conversationMemory->getLastEntity();
          
          if ($lastEntity !== null && $this->debug) {
            $this->logger->logSecurityEvent(
              "Last entity from memory for analytics: {$lastEntity['type']} (ID: {$lastEntity['id']})",
              'info'
            );
          }
        } catch (\Exception $e) {
          $this->logger->logSecurityEvent(
            "Error retrieving last entity: " . $e->getMessage(),
            'warning'
          );
        }
      }

      // Initialize AnalyticsAgent for SQL generation and execution
      $languageId = $context['language_id'] ?? null;
      if ($languageId === null && Registry::exists('Language')) {
        $languageId = Registry::get('Language')->getId();
      }

      $userId = $context['user_id'] ?? 'system';
      
      // Create AnalyticsAgent instance
      $analyticsAgent = new AnalyticsAgent(
        $languageId,
        true, // enable prompt cache
        $userId
      );

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "AnalyticsAgent initialized for query processing",
          'info'
        );
      }

      // Process the business query with resolved query and entity context
      $feedbackContext = $context['feedback_context'] ?? [];
      
      // Add last entity to context if available
      if ($lastEntity !== null) {
        $context['last_entity_id'] = $lastEntity['id'];
        $context['last_entity_type'] = $lastEntity['type'];
      }
      
      $analyticsResult = $analyticsAgent->processBusinessQuery(
        $resolvedQuery,
        true, // include SQL in response
        $feedbackContext
      );

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Analytics query processed. Type: " . ($analyticsResult['type'] ?? 'unknown'),
          'info'
        );
      }

      // Check if the result is an error or not_analytics
      if (isset($analyticsResult['type'])) {
        if ($analyticsResult['type'] === 'error') {
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Analytics query returned error: " . ($analyticsResult['error'] ?? $analyticsResult['message'] ?? 'Unknown error'),
              'warning'
            );
          }

          return AgentResponseHelper::createErrorResponse(
            $query,
            $analyticsResult['error'] ?? $analyticsResult['message'] ?? 'Analytics query execution failed',
            'analytics',
            [
              'error_details' => $analyticsResult['error_details'] ?? null,
              'error_type' => 'analytics_error',
              'component' => 'AnalyticsQueryExecutor::execute',
            ]
          );
        }
        
        if ($analyticsResult['type'] === 'not_analytics') {
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "AnalyticsAgent returned 'not_analytics'",
              'warning'
            );
          }

          return AgentResponseHelper::createErrorResponse(
            $query,
            'This query was not recognized as an analytics query by the SQL generator. Please rephrase with more specific data-related terms (e.g., "show", "count", "total", "stock", "sales").',
            'analytics',
            [
              'error_details' => 'Query classification mismatch',
              'error_type' => 'classification_error',
              'component' => 'AnalyticsQueryExecutor::execute',
            ]
          );
        }
      }

      // Extract results from analytics response
      // Handle ambiguous results specially
      if (isset($analyticsResult['type']) && $analyticsResult['type'] === 'analytics_results_ambiguous') {
        // For ambiguous results, we have multiple interpretations
        $interpretationResults = $analyticsResult['interpretation_results'] ?? [];
        
        if (!empty($interpretationResults)) {
          // Use the first interpretation as the primary result
          $primaryInterpretation = $interpretationResults[0];
          $results = $primaryInterpretation['results'] ?? [];
          $sqlQuery = $primaryInterpretation['sql_query'] ?? null;
          $interpretation = $primaryInterpretation['description'] ?? $primaryInterpretation['label'] ?? null;
          
          // Add information about other interpretations
          $otherInterpretations = array_slice($interpretationResults, 1);
          $interpretation .= "\n\nNote: This query is ambiguous. Other possible interpretations:";
          foreach ($otherInterpretations as $idx => $interp) {
            $interpretation .= "\n" . ($idx + 2) . ". " . ($interp['label'] ?? 'Interpretation ' . ($idx + 2));
          }
        } else {
          $results = [];
          $sqlQuery = null;
          $interpretation = null;
        }
        
        $entityId = $analyticsResult['entity_id'] ?? null;
        $entityType = $analyticsResult['entity_type'] ?? null;
      } else {
        // Normal single result
        $results = $analyticsResult['results'] ?? [];
        $sqlQuery = $analyticsResult['sql_query'] ?? null;
        $interpretation = $analyticsResult['interpretation'] ?? null;
        $entityId = $analyticsResult['entity_id'] ?? null;
        $entityType = $analyticsResult['entity_type'] ?? null;
      }

      // Handle multiple SQL queries (if the query contains AND/multiple parts)
      $multipleQueries = $this->detectMultipleSqlQueries($query);
      
      if ($multipleQueries && count($multipleQueries) > 1) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Detected multiple SQL queries in request: " . count($multipleQueries),
            'info'
          );
        }

        // Execute each query independently
        $allResults = [];
        $allSqlQueries = [];
        
        foreach ($multipleQueries as $index => $subQuery) {
          try {
            $subResult = $analyticsAgent->processBusinessQuery(
              $subQuery,
              true,
              $feedbackContext
            );

            if (isset($subResult['type']) && $subResult['type'] !== 'error') {
              $subData = $subResult['results'] ?? [];
              $subColumns = !empty($subData) ? array_keys($subData[0]) : [];
              
              $allResults[] = [
                'query' => $subQuery,
                'sql' => $subResult['sql_query'] ?? null,
                'columns' => $subColumns,
                'rows' => $subData,
                'row_count' => count($subData),
                'interpretation' => $subResult['interpretation'] ?? null,
              ];
              
              if (!empty($subResult['sql_query'])) {
                $allSqlQueries[] = $subResult['sql_query'];
              }
            }
          } catch (\Exception $e) {
            $this->logger->logSecurityEvent(
              "Error executing sub-query {$index}: " . $e->getMessage(),
              'warning'
            );
            
            $allResults[] = [
              'query' => $subQuery,
              'error' => $e->getMessage(),
              'success' => false,
            ];
          }
        }

        // Return combined results using standardized format
        return AgentResponseHelper::createAnalyticsResponse(
          $query,
          [
            'multiple_results' => $allResults,
            'sql_queries' => $allSqlQueries,
            'multiple_queries' => true,
            'query_count' => count($allResults),
            'entity_id' => $entityId,
            'entity_type' => $entityType,
          ],
          true,
          [
            'execution_time' => microtime(true) - ($context['start_time'] ?? microtime(true)),
            'multiple_queries' => true,
          ]
        );
      }

      // Single query result - format for consistency
      $formattedResult = [];
      
      if (!empty($results)) {
        // Extract column names from first row
        $columns = !empty($results) ? array_keys($results[0]) : [];
        
        $formattedResult = [
          'columns' => $columns,
          'rows' => $results,
          'row_count' => count($results),
          'sql' => $sqlQuery,
          'interpretation' => $interpretation,
        ];

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Analytics query returned " . count($results) . " rows with " . count($columns) . " columns",
            'info'
          );
        }
      } else {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Analytics query returned no results",
            'info'
          );
        }

        $formattedResult = [
          'columns' => [],
          'rows' => [],
          'row_count' => 0,
          'sql' => $sqlQuery,
          'interpretation' => $interpretation ?? 'No results found for this query.',
        ];
      }

      // Store entity in memory if found in results
      if ($entityId !== null && $entityType !== null && $this->conversationMemory !== null) {
        try {
          $this->conversationMemory->setLastEntity((int)$entityId, $entityType);
          
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Stored entity in memory from analytics: {$entityType} (ID: {$entityId})",
              'info'
            );
          }
        } catch (\Exception $e) {
          $this->logger->logSecurityEvent(
            "Error storing entity in memory: " . $e->getMessage(),
            'warning'
          );
        }
      }

      // Return standardized response using AgentResponseHelper
      return AgentResponseHelper::createAnalyticsResponse(
        $query,
        array_merge($formattedResult, [
          'sql_queries' => !empty($sqlQuery) ? [$sqlQuery] : [],
          'entity_id' => $entityId,
          'entity_type' => $entityType,
        ]),
        true,
        [
          'cached' => $analyticsResult['cached'] ?? false,
          'execution_time' => microtime(true) - ($context['start_time'] ?? microtime(true)),
          'context_used' => $contextUsed !== null,
          'query_resolved' => $resolvedQuery !== $query,
        ]
      );

    } catch (\Exception $e) {
      $errorId = uniqid('ana_', true);
      $this->logger->logSecurityEvent(
        "Error executing analytics query [ID: {$errorId}]: " . $e->getMessage() . "\nQuery: {$query}\nStack: " . $e->getTraceAsString(),
        'error'
      );

      return AgentResponseHelper::createErrorResponse(
        $query,
        'Unable to execute analytics query. Please try again.',
        'analytics',
        [
          'error_id' => $errorId,
          'error_type' => 'execution_error',
          'component' => 'AnalyticsQueryExecutor::execute',
        ]
      );
    }
  }

  /**
   * Detect if query contains multiple SQL queries (AND connector)
   *
   * TASK 6.2: Enhanced multi-query detection using centralized patterns
   * TASK 2.9.8.6.1: Added pattern bypass for Pure LLM mode
   * 
   * Uses MultiQueryPattern class for pattern management (English-only)
   * 
   * PATTERN BYPASS: Respects USE_PATTERN_BASED_DETECTION flag
   * - Pure LLM mode: Returns false (multi-query detection disabled)
   * - Pattern mode: Uses MultiQueryPattern for detection
   * 
   * @param string $query Query to analyze
   * @return array|false Array of sub-queries or false if single query
   */
  private function detectMultipleSqlQueries(string $query): array|false
  {
    // TASK 2.9.8.6.1: Check if pattern-based detection is enabled
    if (!defined('USE_PATTERN_BASED_DETECTION') || USE_PATTERN_BASED_DETECTION === 'False') {
      // Pure LLM mode: Multi-query detection disabled
      // LLM will handle each query independently
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Multi-query detection bypassed (Pure LLM mode)",
          'info'
        );
      }
      return false;
    }

    // Pattern mode: Use MultiQueryPattern for detection
    // TASK 6.2: Use centralized MultiQueryPattern class
    // @deprecated Pattern-based detection removed in Pure LLM mode
    // This code is never executed (USE_PATTERN_BASED_DETECTION removed in task 5.1.6)
    // TODO: Remove this dead code block in Q2 2026
    $result = MultiQueryPattern::detectMultipleQueries($query);
    
    if ($result !== false && $this->debug) {
      $this->logger->logSecurityEvent(
        "Detected multi-query: " . count($result) . " sub-queries",
        'info'
      );
    }
    
    return $result;
  }
}