<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubHybridQueryProcessor;

use ClicShopping\AI\Domain\Search\WebSearchTool;
use ClicShopping\AI\Helper\Formatter\ResultFormatter;

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
    if (in_array('analytics', $queryTypes) && in_array('web_search', $queryTypes)) {
      return 'price_comparison';
    }

    // Semantic + analytics
    if (in_array('semantic', $queryTypes) && in_array('analytics', $queryTypes)) {
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
          $productData = [
            'product_id' => $firstRow['products_id'] ?? $firstRow['product_id'] ?? 0,
            'name' => $firstRow['products_name'] ?? $firstRow['name'] ?? 'Unknown Product',
            'price' => floatval($firstRow['products_price'] ?? $firstRow['price'] ?? 0),
            'model' => $firstRow['products_model'] ?? $firstRow['model'] ?? '',
          ];
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
   */
  private function buildBasicPriceComparison(?array $productData, ?array $webSearchResults, array $sources, array $successfulResults, array $failedResults): array
  {
    $ourPrice = $productData['price'] ?? null;
    $competitorInfo = [];
    
    if ($webSearchResults !== null) {
      $response = $webSearchResults['result']['text_response'] ?? $webSearchResults['response'] ?? '';
      if (!empty($response)) $competitorInfo[] = $response;
    }

    $text = ($ourPrice !== null ? "Our Price: $" . number_format($ourPrice, 2) . "\n\n" : "");
    $text .= (!empty($competitorInfo) ? "Competitor Information:\n" . implode("\n", $competitorInfo) . "\n" : "");
    $text = $this->addFailedQueryWarning($text, $failedResults);
    
    return $this->formatAggregatedResult('price_comparison', trim($text), 
      ['our_price' => $ourPrice, 'competitor_info' => $competitorInfo], $sources, $successfulResults, $failedResults);
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
