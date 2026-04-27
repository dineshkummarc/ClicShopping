<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\DomainsAI\Hybrid\Processor;


use ClicShopping\AI\DomainsAI\WebSearch\Tool\WebSearchTool;
use ClicShopping\AI\DomainsAI\Hybrid\Helper\Formatter\ResultFormatter;
use ClicShopping\AI\Config\DomainConfig;
use ClicShopping\AI\Config\DomainFields;

/**
 * ResultAggregator - Aggregates results from different query type combinations
 *
 * Responsibilities:
 * - Provide specialized aggregation for price comparison (analytics + web_search)
 * - Provide specialized aggregation for semantic + analytics combinations
 * - Provide default aggregation for other query combinations
 * - Collect and deduplicate sources from all sub-queries
 * - Handle failed sub-queries gracefully with warnings
 *
 * Requirements:
 * - REQ-5.1: Specialized aggregation for price comparison
 * - REQ-5.2: Specialized aggregation for semantic + analytics
 * - REQ-5.3: Default aggregation for other combinations
 * - REQ-5.4: Source deduplication
 * - REQ-5.5: Failed sub-query handling
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubHybridQueryProcessor
 * @since 2025-12-14
 */

class ResultAggregator extends BaseQueryProcessor
{
  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    parent::__construct($debug, 'ResultAggregator');
  }

  /**
   * Process: Aggregate results based on query type combination
   *
   * @param array $input Array with 'successful' and 'failed' results
   * @param array $context Context information (query_type, etc.)
   * @return array Aggregated result
   */
  public function process($input, array $context = []): array
  {
    if (!$this->validate($input)) {
      return $this->handleError(
        "Invalid input for aggregation",
        null,
        ['success' => false, 'text_response' => 'Invalid input for result aggregation']
      );
    }

    $successfulResults = $input['successful'] ?? [];
    $failedResults = $input['failed'] ?? [];

    try {
      if ($this->debug) {
        $this->logInfo("Aggregating results", [
          'successful' => count($successfulResults),
          'failed' => count($failedResults)
        ]);
      }

      // Determine aggregation strategy based on query types
      $queryTypes = $this->extractQueryTypes($successfulResults);
      $aggregationType = $this->determineAggregationType($queryTypes);

      if ($this->debug) {
        $this->logInfo("Aggregation type determined: {$aggregationType}", ['types' => $queryTypes]);
      }

      // Aggregate based on type
      return match($aggregationType) {
        'price_comparison' => $this->aggregatePriceComparison($successfulResults, $failedResults),
        'semantic_analytics' => $this->aggregateSemanticWithAnalytics($successfulResults, $failedResults),
        default => $this->aggregateDefault($successfulResults, $failedResults)
      };

    } catch (\Exception $e) {
      return $this->handleError(
        "Error aggregating results",
        $e,
        ['success' => false, 'text_response' => 'Failed to aggregate results: ' . $e->getMessage()]
      );
    }
  }

  /**
   * Validate input
   *
   * @param mixed $input Input to validate
   * @return bool True if valid
   */
  public function validate($input): bool
  {
    return is_array($input) && isset($input['successful']) && is_array($input['successful']);
  }

  /**
   * Extract query types from successful results
   *
   * @param array $successfulResults Successful sub-query results
   * @return array Array of query types
   */
  private function extractQueryTypes(array $successfulResults): array
  {
    $types = [];
    foreach ($successfulResults as $result) {
      if (isset($result['type'])) {
        $types[] = $result['type'];
      }
    }
    return array_unique($types);
  }

  /**
   * Determine aggregation type based on query types
   *
   * @param array $queryTypes Array of query types
   * @return string Aggregation type
   */
  private function determineAggregationType(array $queryTypes): string
  {
    sort($queryTypes);
    $typeKey = implode('_', $queryTypes);

    // Price comparison: analytics + web_search
    if (in_array('analytics', $queryTypes, true) && in_array('web_search', $queryTypes, true)) {
      return 'price_comparison';
    }

    // Semantic + analytics
    if (in_array('semantic', $queryTypes, true) && in_array('analytics', $queryTypes, true)) {
      return 'semantic_analytics';
    }

    // Default for all other combinations
    return 'default';
  }

  /**
   * Aggregate price comparison results (analytics + web_search)
   * REQ-5.1: Specialized aggregation for price comparison
   */
  private function aggregatePriceComparison(array $successfulResults, array $failedResults): array
  {
    list($productData, $webSearchResults, $sources) = $this->extractPriceComparisonData($successfulResults, $failedResults);

    // If we have both product data and web results, use comparePrice()
    if ($productData !== null && $webSearchResults !== null) {
      $comparison = $this->performPriceComparison($productData, $webSearchResults);
      if ($comparison !== null) {
        $aggregatedText = ResultFormatter::formatPriceComparisonAsText($comparison);
        if ($this->debug) {
          $this->logInfo("Price comparison successful", ['product' => $productData['name']]);
        }
        return $this->formatAggregatedResult(
          'price_comparison',
          $aggregatedText,
          ['comparison_data' => $comparison, 'product' => $productData],
          $sources,
          $successfulResults,
          $failedResults
        );
      }
    }

    // Fallback: Basic aggregation
    return $this->buildBasicPriceComparison($productData, $webSearchResults, $sources, $successfulResults, $failedResults);
  }

  /**
   * Extract product data and web search results for price comparison
   * Uses EntityConfig to dynamically discover field names instead of hardcoding
   */
  private function extractPriceComparisonData(array $successfulResults, array $failedResults): array
  {
    $productData = null;
    $webSearchResults = null;
    $sources = [];

    foreach ($successfulResults as $subResult) {
      $type = $subResult['type'] ?? '';
      $result = $subResult['result'] ?? [];

      if ($type === 'analytics') {
        $data = $result['result']['data'] ?? [];
        if (!empty($data)) {
          $firstRow = $data[0];
          
          // Extract product data using dynamic field discovery
          $productData = $this->extractProductDataFromRow($firstRow);
        }
      } elseif ($type === 'web_search') {
        // Check if already formatted
        if (isset($result['is_price_comparison']) && $result['is_price_comparison']) {
          return [null, null, []]; // Signal to return early
        }
        $webSearchResults = $result;
        $sources = $this->collectSources($result, $sources);
      }
    }

    return [$productData, $webSearchResults, $sources];
  }

  /**
   * Extract product data from a database row using dynamic field discovery
   * 
   * This method uses EntityConfig to discover available fields instead of
   * hardcoding field names like "products_name" or "products_price".
   * 
   * Fallback strategy:
   * 1. Try to detect entity type from row keys
   * 2. Use EntityConfig to get description fields for that entity
   * 3. Map common field patterns (id, name, price, model)
   * 4. Use generic fallbacks if specific fields not found
   * 
   * @param array $row Database row data
   * @return array Product data with standardized keys
   */
  private function extractProductDataFromRow(array $row): array
  {
    // Detect entity type from row keys (e.g., "products_id" -> "products")
    $entityType = $this->detectEntityTypeFromRow($row);
    
    // Get description fields for this entity type (if domain configured)
    $descriptionFields = [];
    
    if (!empty($entityType) && DomainConfig::getActivities() !== '') {
      $entityConfigClass = DomainFields::resolveAppClass(DomainConfig::getActivities(), 'EntityConfig');
      if ($entityConfigClass !== null && method_exists($entityConfigClass, 'getDescriptionFields')) {
        $descriptionFields = $entityConfigClass::getDescriptionFields($entityType);
      }
    }
    
    // Extract fields using dynamic discovery with fallbacks
    return [
      'product_id' => $this->extractField($row, ['id', 'product_id'], $entityType, 0),
      'name' => $this->extractField($row, ['name', 'title', 'description'], $entityType, 'Unknown Item'),
      'price' => (float)$this->extractField($row, ['price', 'cost', 'amount'], $entityType, 0),
      'model' => $this->extractField($row, ['model', 'sku', 'code', 'reference'], $entityType, ''),
      'entity_type' => $entityType,
      'available_fields' => array_keys($row)
    ];
  }

  /**
   * Detect entity type from row keys
   * 
   * Looks for patterns like "products_id", "orders_id", "customers_id"
   * and extracts the entity type prefix.
   * 
   * @param array $row Database row
   * @return string|null Entity type or null if not detected
   */
  private function detectEntityTypeFromRow(array $row): ?string
  {
    foreach (array_keys($row) as $key) {
      // Look for pattern: {entity}_id or {entity}_{field}
      if (preg_match('/^([a-z_]+)_(id|name|price|model)$/i', $key, $matches)) {
        return $matches[1]; // Return entity prefix (e.g., "products", "orders")
      }
    }
    
    // Fallback: check if we can get entity types from EntityConfig
    if (DomainConfig::getActivities() !== '') {
      $entityConfigClass = DomainFields::resolveAppClass(DomainConfig::getActivities(), 'EntityConfig');
      if ($entityConfigClass !== null && method_exists($entityConfigClass, 'getEntityTypes')) {
        $entityTypes = $entityConfigClass::getEntityTypes();
        foreach ($entityTypes as $entityType) {
          if (method_exists($entityConfigClass, 'getIdColumn')) {
            $idColumn = $entityConfigClass::getIdColumn($entityType);
            if (isset($row[$idColumn])) {
              return $entityType;
            }
          }
        }
      }
    }
    
    return null;
  }

  /**
   * Extract a field value from row using multiple possible field names
   * 
   * Tries multiple field name patterns with entity type prefix:
   * - {entity}_{fieldName} (e.g., "products_name")
   * - {fieldName} (e.g., "name")
   * 
   * @param array $row Database row
   * @param array $fieldNames Possible field names to try
   * @param string|null $entityType Entity type prefix
   * @param mixed $default Default value if field not found
   * @return mixed Field value or default
   */
  private function extractField(array $row, array $fieldNames, ?string $entityType, $default)
  {
    // Try with entity prefix first (e.g., "products_name")
    if (!empty($entityType)) {
      foreach ($fieldNames as $fieldName) {
        $prefixedKey = $entityType . '_' . $fieldName;
        if (isset($row[$prefixedKey])) {
          return $row[$prefixedKey];
        }
      }
    }
    
    // Try without prefix (e.g., "name")
    foreach ($fieldNames as $fieldName) {
      if (isset($row[$fieldName])) {
        return $row[$fieldName];
      }
    }
    
    return $default;
  }

  /**
   * Perform price comparison using WebSearchTool
   */
  private function performPriceComparison(array $productData, array $webSearchResults): ?array
  {
    try {
      $webSearchTool = new WebSearchTool();
      $formattedWebResults = [
        'success' => true,
        'items' => $webSearchResults['result'] ?? $webSearchResults['items'] ?? []
      ];
      $comparison = $webSearchTool->comparePrice($productData, $formattedWebResults);
      return $comparison['success'] ? $comparison : null;
    } catch (\Exception $e) {
      $this->logWarning("Error in price comparison", ['error' => $e->getMessage()]);
      return null;
    }
  }

  /**
   * Build basic price comparison when WebSearchTool is unavailable
   * Uses fallback display strategies for missing fields
   */
  private function buildBasicPriceComparison(?array $productData, ?array $webSearchResults, array $sources, array $successfulResults, array $failedResults): array
  {
    $text = "";

    // Display product information with fallback strategies
    if ($productData !== null) {
      $text .= $this->formatProductDisplay($productData);
    } else {
      $text .= "Product information not available.\n\n";
    }

    // Display competitor information
    $competitorInfo = [];
    if ($webSearchResults !== null) {
      $response = $webSearchResults['result']['text_response'] ?? $webSearchResults['response'] ?? '';
      if (!empty($response)) {
        $competitorInfo[] = $response;
      }
    }

    if (!empty($competitorInfo)) {
      $text .= "Competitor Information:\n" . implode("\n", $competitorInfo) . "\n";
    }

    $text = $this->addFailedQueryWarning($text, $failedResults);

    return $this->formatAggregatedResult(
      'price_comparison',
      trim($text),
      [
        'product' => $productData,
        'competitor_info' => $competitorInfo
      ],
      $sources,
      $successfulResults,
      $failedResults
    );
  }

  /**
   * Format product display with fallback strategies for missing fields
   *
   * This method provides intelligent display formatting that adapts to
   * available fields instead of assuming specific field names.
   *
   * Display strategy:
   * 1. Show name/title if available
   * 2. Show price if available
   * 3. Show model/SKU if available
   * 4. Show entity type if detected
   * 5. Gracefully handle missing fields
   *
   * @param array $productData Product data from extractProductDataFromRow()
   * @return string Formatted product display text
   */
  private function formatProductDisplay(array $productData): string
  {
    $lines = [];

    // Display name/title
    if (!empty($productData['name']) && $productData['name'] !== 'Unknown Item') {
      $lines[] = "Item: " . $productData['name'];
    }

    // Display price
    if (!empty($productData['price'])) {
      $lines[] = "Our Price: $" . number_format($productData['price'], 2);
    }

    // Display model/SKU
    if (!empty($productData['model'])) {
      $lines[] = "Model: " . $productData['model'];
    }

    // Display entity type if detected (helps with debugging)
    if ($this->debug && !empty($productData['entity_type'])) {
      $lines[] = "Type: " . $productData['entity_type'];
    }

    // Fallback if no useful information
    if (empty($lines)) {
      return "Item information available but fields not recognized.\n" .
             "Available fields: " . implode(', ', $productData['available_fields'] ?? []) . "\n\n";
    }

    return implode("\n", $lines) . "\n\n";
  }

  /**
   * Aggregate semantic + analytics results
   * REQ-5.2: Specialized aggregation for semantic + analytics
   */
  private function aggregateSemanticWithAnalytics(array $successfulResults, array $failedResults): array
  {
    $semanticResponse = "";
    $analyticsData = [];
    $sources = [];

    foreach ($successfulResults as $subResult) {
      $type = $subResult['type'] ?? '';
      $result = $subResult['result'] ?? [];

      if ($type === 'semantic') {
        $semanticResponse = $result['result']['response'] ?? $result['response'] ?? '';
      } elseif ($type === 'analytics') {
        $interpretation = $result['result']['interpretation'] ?? '';
        if (!empty($interpretation)) $analyticsData[] = $interpretation;
      }
      $sources = $this->collectSources($result, $sources);
    }

    $aggregatedText = $semanticResponse;
    
    if (!empty($analyticsData)) {
      $aggregatedText .= "\n\n📊 Related Data:\n- " . implode("\n- ", $analyticsData) . "\n";
    }
    
    $aggregatedText = $this->addFailedQueryWarning($aggregatedText, $failedResults);

    if ($this->debug) {
      $this->logInfo("Semantic + analytics aggregation complete", [
        'has_semantic' => !empty($semanticResponse),
        'analytics_count' => count($analyticsData)
      ]);
    }

    return $this->formatAggregatedResult(
      'semantic_analytics',
      trim($aggregatedText),
      $analyticsData,
      $sources,
      $successfulResults,
      $failedResults
    );
  }

  /**
   * Default aggregation for other query combinations
   * REQ-5.3: Default aggregation for other combinations
   */
  private function aggregateDefault(array $successfulResults, array $failedResults): array
  {
    $aggregatedText = "";
    $aggregatedData = [];
    $sources = [];

    foreach ($successfulResults as $index => $subResult) {
      $result = $subResult['result'] ?? [];

      // Extract text response
      $response = $result['result']['response'] ?? $result['result']['interpretation'] ?? $result['response'] ?? '';
      if (!empty($response)) {
        $aggregatedText .= ($index + 1) . ". " . $response . "\n\n";
      }

      // Collect data
      if (!empty($result['result']['data'])) {
        $aggregatedData[] = ['sub_query' => $subResult['query'], 'data' => $result['result']['data']];
      }
      $sources = $this->collectSources($result, $sources);
    }

    $aggregatedText = $this->addFailedQueryWarning($aggregatedText, $failedResults);

    if ($this->debug) {
      $this->logInfo("Default aggregation complete", [
        'results' => count($successfulResults),
        'data_items' => count($aggregatedData)
      ]);
    }

    return $this->formatAggregatedResult(
      'complex_query',
      trim($aggregatedText),
      $aggregatedData,
      $sources,
      $successfulResults,
      $failedResults
    );
  }

  /**
   * Collect sources from result - REQ-5.4: Source collection
   */
  private function collectSources(array $result, array $sources): array
  {
    if (!empty($result['result']['sources'])) {
      return array_merge($sources, $result['result']['sources']);
    }
    if (!empty($result['sources'])) {
      return array_merge($sources, $result['sources']);
    }
    return $sources;
  }

  /**
   * Deduplicate sources - REQ-5.4: Source deduplication
   * Converts Document objects to strings and removes duplicates
   */
  private function deduplicateSources(array $sources): array
  {
    if (empty($sources)) return [];

    $uniqueSources = array_unique(array_map(function($doc) {
      if (is_object($doc) && method_exists($doc, 'getContent')) return $doc->getContent();
      if (is_object($doc)) return method_exists($doc, '__toString') ? (string)$doc : get_class($doc);
      return (string)$doc;
    }, $sources));

    if ($this->debug) {
      $this->logInfo("Sources deduplicated", ['original' => count($sources), 'unique' => count($uniqueSources)]);
    }
    return $uniqueSources;
  }

  /**
   * Add failed sub-query warning to text
   * REQ-5.5: Failed sub-query handling
   * 
   * Adds a user-friendly warning message when some sub-queries fail.
   * The message is localized and provides clear information about partial results.
   */
  private function addFailedQueryWarning(string $text, array $failedResults): string
  {
    if (!empty($failedResults)) {
      $failedCount = count($failedResults);
      
      // User-friendly message in French (primary language)
      $warningMessage = $failedCount === 1
        ? "\n⚠️ Note: Certaines informations n'ont pas pu être récupérées. Les résultats affichés sont partiels.\n"
        : "\n⚠️ Note: Certaines informations n'ont pas pu être récupérées ({$failedCount} requêtes ont échoué). Les résultats affichés sont partiels.\n";
      
      $text .= $warningMessage;
      
      if ($this->debug) {
        $this->logWarning("Failed sub-queries detected", [
          'count' => $failedCount,
          'failed_queries' => array_map(fn($r) => $r['query'] ?? 'unknown', $failedResults)
        ]);
      }
    }
    return $text;
  }

  /**
   * Format aggregated result with standard structure
   */
  private function formatAggregatedResult(string $type, string $textResponse, $data, array $sources, array $successfulResults, array $failedResults): array
  {
    return [
      'success' => !empty($successfulResults),
      'text_response' => $textResponse,
      'result' => [
        'type' => $type,
        'response' => $textResponse,
        'text_response' => $textResponse,
        'data' => $data,
        'sources' => $this->deduplicateSources($sources),
        'sub_results' => array_merge($successfulResults, $failedResults),
        'successful_count' => count($successfulResults),
        'failed_count' => count($failedResults)
      ]
    ];
  }
}
