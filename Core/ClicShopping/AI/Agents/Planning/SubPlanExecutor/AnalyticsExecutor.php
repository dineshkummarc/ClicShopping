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
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Agents\Orchestrator\AnalyticsAgent;

/**
 * AnalyticsExecutor Class
 *
 * Responsible for executing analytics queries.
 * Separated from PlanExecutor to follow Single Responsibility Principle.
 *
 * Responsibilities:
 * - Execute analytics queries
 * - Format analytics results
 * - Handle analytics errors
 * - Extract entity metadata from results
 */
#[AllowDynamicProperties]
class AnalyticsExecutor
{
  private SecurityLogger $logger;
  private bool $debug;
  private ?AnalyticsAgent $analyticsAgent = null;
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
      $this->logger->logSecurityEvent("AnalyticsExecutor initialized", 'info');
    }
  }

  /**
   * Execute analytics query
   *
   * @param string $query Query to execute
   * @param array $context Context information
   * @return array Result
   */
  public function executeAnalyticsQuery(string $query, array $context = []): array
  {
    // 🔧 TASK 4.3.4.3: Add comprehensive logging to trace SQL generation
    error_log("\n" . str_repeat("=", 100));
    error_log("TASK 4.3.4.3: AnalyticsExecutor.executeAnalyticsQuery() CALLED");
    error_log(str_repeat("=", 100));
    error_log("Query received: '{$query}'");
    error_log("Query length: " . strlen($query));
    error_log("Query is empty: " . (empty($query) ? 'YES' : 'NO'));
    
    try {
      // Initialize analytics agent if needed
      if ($this->analyticsAgent === null) {
        error_log("Initializing AnalyticsAgent...");
        $this->analyticsAgent = new AnalyticsAgent($this->languageId, true, $this->userId);
        error_log("AnalyticsAgent initialized successfully");
      }

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Executing analytics query: {$query}",
          'info'
        );
      }

      // 🔧 TASK 4.3.4.3: Check if query is empty before calling processBusinessQuery
      if (empty($query)) {
        error_log("❌ ERROR: Query is EMPTY before calling processBusinessQuery!");
        error_log("This is the root cause - query was lost somewhere in the pipeline");
        
        return [
          'type' => 'analytics_response',
          'success' => false,
          'error' => 'Empty query received by AnalyticsExecutor',
          'question' => '',
          'interpretation' => 'Error: Empty query received',
          'results' => [],
          'sql_query' => '',
        ];
      }

      error_log("Calling AnalyticsAgent.processBusinessQuery()...");
      
      // Execute query using processBusinessQuery for proper formatting
      $rawResult = $this->analyticsAgent->processBusinessQuery($query, true);

      error_log("processBusinessQuery() returned:");
      error_log("  SQL query: " . ($rawResult['sql_query'] ?? 'EMPTY'));
      error_log("  Results count: " . count($rawResult['results'] ?? []));
      error_log("  Interpretation: " . substr($rawResult['interpretation'] ?? 'N/A', 0, 100));

      // Format result
      $formattedResult = $this->formatAnalyticsResult($rawResult);

      error_log("Formatted result SQL: " . ($formattedResult['sql_query'] ?? 'EMPTY'));
      error_log(str_repeat("=", 100) . "\n");

      return $formattedResult;

    } catch (\Exception $e) {
      error_log("❌ EXCEPTION in executeAnalyticsQuery: " . $e->getMessage());
      error_log(str_repeat("=", 100) . "\n");
      return $this->handleAnalyticsError($e, $query);
    }
  }

  /**
   * Extract table name from SQL query for source attribution
   *
   * @param string|null $sql SQL query
   * @return string Table name or 'database'
   */
  private function extractTableNameFromSql(?string $sql): string
  {
    if (empty($sql)) {
      return 'database';
    }

    // Extract table name from FROM clause
    if (preg_match('/FROM\s+([a-zA-Z0-9_]+)/i', $sql, $matches)) {
      return $matches[1];
    }

    return 'database';
  }

  /**
   * Format analytics result
   *
   * @param array $rawResult Raw result from AnalyticsAgent
   * @return array Formatted result
   */
  public function formatAnalyticsResult(array $rawResult): array
  {
    // 🔍 DEBUG: Trace results through formatting
    error_log("\n" . str_repeat("=", 100));
    error_log("DEBUG: AnalyticsExecutor.formatAnalyticsResult() CALLED");
    error_log(str_repeat("=", 100));
    error_log("Raw result type: " . ($rawResult['type'] ?? 'unknown'));
    error_log("Raw result has 'results' key: " . (isset($rawResult['results']) ? 'YES' : 'NO'));
    
    if (isset($rawResult['results'])) {
      error_log("Raw result 'results' count: " . count($rawResult['results']));
      error_log("Raw result 'results' is_array: " . (is_array($rawResult['results']) ? 'YES' : 'NO'));
      
      if (!empty($rawResult['results']) && is_array($rawResult['results'])) {
        error_log("First row keys: " . implode(', ', array_keys($rawResult['results'][0])));
        error_log("First row data: " . json_encode($rawResult['results'][0]));
      }
    }
    error_log(str_repeat("=", 100) . "\n");
    
    // 🔧 FIX: Handle ambiguous results type
    // When query is ambiguous, the system returns multiple interpretations
    // Instead of passing ambiguous results to UI (which doesn't handle them),
    // we select the best interpretation and return it as a normal result
    if (isset($rawResult['type']) && $rawResult['type'] === 'analytics_results_ambiguous') {
      error_log("✅ Detected ambiguous results - selecting best interpretation");
      
      // Get the interpretation results
      $interpretationResults = $rawResult['interpretation_results'] ?? [];
      
      if (!empty($interpretationResults)) {
        // Find the interpretation with the most results
        $bestInterpretation = null;
        $maxResults = 0;
        
        foreach ($interpretationResults as $key => $interpretation) {
          $resultCount = count($interpretation['results'] ?? []);
          error_log("  Interpretation '{$key}': {$resultCount} results");
          error_log("    Has 'interpretation' key: " . (isset($interpretation['interpretation']) ? 'YES' : 'NO'));
          if (isset($interpretation['interpretation'])) {
            error_log("    Interpretation text: " . substr($interpretation['interpretation'], 0, 100));
          }
          
          if ($resultCount > $maxResults) {
            $maxResults = $resultCount;
            $bestInterpretation = $interpretation;
          }
        }
        
        // If we found a good interpretation, use it
        if ($bestInterpretation !== null && !empty($bestInterpretation['results'])) {
          error_log("  Selected best interpretation with {$maxResults} results");
          
          // Use the interpretation from the best result, or generate one
          $interpretation = $bestInterpretation['interpretation'] ?? null;
          
          // If interpretation is empty or generic, generate a better one
          if (empty($interpretation) || $interpretation === 'Résultats trouvés') {
            error_log("  Interpretation is empty/generic, generating from results...");
            $interpretation = $this->generateInterpretationFromResults(
              $bestInterpretation['results'],
              $rawResult['query'] ?? ''
            );
            error_log("  Generated interpretation: " . substr($interpretation, 0, 100));
          }
          
          // Convert to standard analytics_response format
          return [
            'type' => 'analytics_response',
            'question' => $rawResult['query'] ?? '',
            'interpretation' => $interpretation,
            'results' => $bestInterpretation['results'],
            'sql_query' => $bestInterpretation['sql_query'] ?? '',
            'original_sql_query' => $bestInterpretation['sql_query'] ?? '',
            'entity_id' => null,
            'entity_type' => null,
            'source_attribution' => [
              'source_type' => 'Analytics Database',
              'source_icon' => '📊',
              'source_details' => 'Data retrieved from transactional database',
              'table_name' => $this->extractTableNameFromSql($bestInterpretation['sql_query'] ?? null),
            ],
          ];
        }
      }
      
      // If no good interpretation found, fall through to generate default interpretation
      error_log("  No good interpretation found, falling through");
    }
    
    // Check if result is already properly formatted as analytics_response
    if (isset($rawResult['type']) && $rawResult['type'] === 'analytics_response') {
      // Already formatted, just ensure entity metadata and source attribution are preserved
      if (isset($rawResult['entity_id']) && !isset($rawResult['_step_entity_metadata'])) {
        $rawResult['_step_entity_metadata'] = [
          'entity_id' => $rawResult['entity_id'],
          'entity_type' => $rawResult['entity_type'] ?? 'unknown',
        ];
      }
      
      // 🆕 Add source attribution if not already present
      if (!isset($rawResult['source_attribution'])) {
        $rawResult['source_attribution'] = [
          'source_type' => 'Analytics Database',
          'source_icon' => '📊',
          'source_details' => 'Data retrieved from transactional database',
          'table_name' => $this->extractTableNameFromSql($rawResult['sql_query'] ?? null),
        ];
      }
      
      return $rawResult;
    }

    // 🔧 FIX: Generate default interpretation if missing
    $interpretation = $rawResult['interpretation'] ?? null;
    
    if (empty($interpretation) || $interpretation === 'Analytics result processed') {
      $interpretation = $this->generateDefaultInterpretation($rawResult);
      
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Generated default interpretation: {$interpretation}",
          'info'
        );
      }
    }

    // Format for ResultFormatter compatibility
    $formatted = [
      'type' => 'analytics_response',
      'question' => $rawResult['question'] ?? '',
      'interpretation' => $interpretation,
      'results' => $rawResult['results'] ?? [],
      'sql_query' => $rawResult['sql_query'] ?? '',
      'original_sql_query' => $rawResult['original_sql_query'] ?? $rawResult['sql_query'] ?? '',
      'entity_id' => $rawResult['entity_id'] ?? null,
      'entity_type' => $rawResult['entity_type'] ?? null,
    ];

    // 🆕 Add source attribution for analytics queries
    $formatted['source_attribution'] = [
      'source_type' => 'Analytics Database',
      'source_icon' => '📊',
      'source_details' => 'Data retrieved from transactional database',
      'table_name' => $this->extractTableNameFromSql($formatted['sql_query']),
    ];

    // Add step entity metadata for tracking through pipeline
    if (isset($rawResult['entity_id'])) {
      $formatted['_step_entity_metadata'] = [
        'entity_id' => $rawResult['entity_id'],
        'entity_type' => $rawResult['entity_type'] ?? 'unknown',
      ];
    }

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Formatted analytics result - has entity_id: " . (isset($formatted['entity_id']) ? 'YES' : 'NO'),
        'info'
      );
    }

    return $formatted;
  }

  /**
   * Generate interpretation from results
   *
   * @param array $results Results array
   * @param string $question Original question
   * @return string Interpretation
   */
  private function generateInterpretationFromResults(array $results, string $question): string
  {
    if (empty($results)) {
      return "Aucun résultat trouvé pour : {$question}";
    }
    
    $count = count($results);
    $firstResult = $results[0];
    
    // Build interpretation based on the data
    $parts = [];
    
    // Check for product name
    if (isset($firstResult['products_name'])) {
      $parts[] = "Produit : {$firstResult['products_name']}";
    }
    
    // Check for price
    if (isset($firstResult['catalog_price'])) {
      $parts[] = "Prix : {$firstResult['catalog_price']}€";
    } elseif (isset($firstResult['products_price'])) {
      $parts[] = "Prix : {$firstResult['products_price']}€";
    }
    
    // Check for quantity
    if (isset($firstResult['products_quantity'])) {
      $parts[] = "Quantité en stock : {$firstResult['products_quantity']}";
    } elseif (isset($firstResult['total_quantity'])) {
      $parts[] = "Quantité totale : {$firstResult['total_quantity']}";
    }
    
    // Check for SKU/model
    if (isset($firstResult['products_model'])) {
      $parts[] = "Modèle : {$firstResult['products_model']}";
    } elseif (isset($firstResult['sku'])) {
      $parts[] = "SKU : {$firstResult['sku']}";
    }
    
    if (!empty($parts)) {
      return implode(', ', $parts);
    }
    
    // Fallback
    return "{$count} résultat" . ($count > 1 ? 's' : '') . " trouvé" . ($count > 1 ? 's' : '');
  }

  /**
   * Generate default interpretation when none is provided
   *
   * @param array $rawResult Raw result from AnalyticsAgent
   * @return string Default interpretation
   */
  private function generateDefaultInterpretation(array $rawResult): string
  {
    // 🔍 DEBUG: Trace why "Aucun résultat trouvé" is generated
    error_log("\n" . str_repeat("=", 100));
    error_log("DEBUG: generateDefaultInterpretation() CALLED");
    error_log(str_repeat("=", 100));
    error_log("rawResult keys: " . implode(', ', array_keys($rawResult)));
    error_log("rawResult['results'] isset: " . (isset($rawResult['results']) ? 'YES' : 'NO'));
    
    $results = $rawResult['results'] ?? [];
    error_log("results after ?? []: is_array=" . (is_array($results) ? 'YES' : 'NO'));
    error_log("results count: " . count($results));
    
    $count = count($results);
    $question = $rawResult['question'] ?? 'votre requête';
    
    error_log("count: {$count}");
    error_log("question: {$question}");
    
    // No results
    if ($count === 0) {
      error_log("❌ RETURNING: Aucun résultat trouvé (count === 0)");
      error_log("This is the message user sees!");
      error_log(str_repeat("=", 100) . "\n");
      return "Aucun résultat trouvé pour : {$question}";
    }
    
    error_log("✅ Has results, generating interpretation");
    error_log(str_repeat("=", 100) . "\n");
    
    // Single result
    if ($count === 1) {
      return "1 résultat trouvé pour : {$question}";
    }
    
    // Multiple results - try to add more context
    $interpretation = "{$count} résultats trouvés pour : {$question}";
    
    // Try to add a summary of the first result
    if (!empty($results[0]) && is_array($results[0])) {
      $firstResult = $results[0];
      $keys = array_keys($firstResult);
      
      // If there's a name field, mention it
      $nameFields = ['name', 'manufacturers_name', 'products_name', 'categories_name', 'title'];
      foreach ($nameFields as $field) {
        if (isset($firstResult[$field])) {
          $interpretation .= " (ex: {$firstResult[$field]})";
          break;
        }
      }
    }
    
    return $interpretation;
  }

  /**
   * Handle analytics error
   *
   * @param \Exception $e Exception
   * @param string $query Original query
   * @return array Error result
   */
  public function handleAnalyticsError(\Exception $e, string $query): array
  {
    $this->logger->logSecurityEvent(
      "Analytics query failed: {$query} - " . $e->getMessage(),
      'error'
    );

    return [
      'type' => 'analytics_response',
      'success' => false,
      'error' => $e->getMessage(),
      'question' => $query,
      'interpretation' => 'Error: ' . $e->getMessage(),
      'results' => [],
      'sql_query' => '',
    ];
  }

  /**
   * Get analytics agent instance
   *
   * @return AnalyticsAgent|null
   */
  public function getAnalyticsAgent(): ?AnalyticsAgent
  {
    return $this->analyticsAgent;
  }
}
