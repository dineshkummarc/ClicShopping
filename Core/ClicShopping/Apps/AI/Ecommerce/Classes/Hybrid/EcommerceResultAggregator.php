<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Classes\Hybrid;

use ClicShopping\AI\DomainsAI\Hybrid\Processor\ResultAggregator;
use ClicShopping\AI\DomainsAI\WebSearch\Tool\WebSearchTool;
use ClicShopping\AI\DomainsAI\Hybrid\Helper\Formatter\ResultFormatter;

/**
 * EcommerceResultAggregator - E-commerce specific result aggregation
 *
 * Extends ResultAggregator with e-commerce domain-specific logic:
 * - Product data extraction
 * - Price comparison aggregation
 * - Product display formatting
 *
 * This class contains all e-commerce business logic that was previously
 * in the agnostic ResultAggregator class.
 *
 * @package ClicShopping\Apps\AI\Ecommerce\Classes\Hybrid
 * @since 2026-04-28
 */
class EcommerceResultAggregator extends ResultAggregator
{
  /**
   * Entity data extractor
   *
   * @var EntityDataExtractor
   */
  private EntityDataExtractor $entityExtractor;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    parent::__construct($debug);
    $this->entityExtractor = new EntityDataExtractor($debug);
  }

  /**
   * Override to handle e-commerce specific aggregation types
   *
   * @param string $aggregationType Aggregation type
   * @param array $successfulResults Successful results
   * @param array $failedResults Failed results
   * @return array Aggregated result
   */
  protected function aggregateDomainSpecific(string $aggregationType, array $successfulResults, array $failedResults): array
  {
    return match($aggregationType) {
      'price_comparison' => $this->aggregatePriceComparison($successfulResults, $failedResults),
      default => parent::aggregateDomainSpecific($aggregationType, $successfulResults, $failedResults)
    };
  }

  /**
   * Aggregate price comparison results (analytics + web_search)
   *
   * E-commerce specific: compares internal product prices with competitor prices
   *
   * @param array $successfulResults Successful sub-query results
   * @param array $failedResults Failed sub-query results
   * @return array Aggregated result
   */
  protected function aggregatePriceComparison(array $successfulResults, array $failedResults): array
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
   *
   * @param array $successfulResults Successful results
   * @param array $failedResults Failed results
   * @return array [productData, webSearchResults, sources]
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

          // Extract product data using EntityDataExtractor
          $productData = $this->entityExtractor->extractFromRow($firstRow);
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
   *
   * @param array $productData Product data
   * @param array $webSearchResults Web search results
   * @return array|null Comparison result or null on failure
   */
  private function performPriceComparison(array $productData, array $webSearchResults): ?array
  {
    try {
      $webSearchTool = new WebSearchTool();
      
      // Extract items array with defensive checks
      $items = [];
      if (isset($webSearchResults['result'])) {
        // Handle nested result structure
        if (is_array($webSearchResults['result'])) {
          $items = $webSearchResults['result'];
        }
      } elseif (isset($webSearchResults['items'])) {
        $items = $webSearchResults['items'];
      }
      
      // Ensure items is an array of arrays, not strings
      if (!is_array($items)) {
        $items = [];
      } else {
        // Filter out non-array items
        $items = array_filter($items, 'is_array');
      }
      
      $formattedWebResults = [
        'success' => true,
        'items' => $items
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
   *
   * @param array|null $productData Product data
   * @param array|null $webSearchResults Web search results
   * @param array $sources Sources
   * @param array $successfulResults Successful results
   * @param array $failedResults Failed results
   * @return array Aggregated result
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
   * @param array $productData Product data from EntityDataExtractor
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
   * Get the entity data extractor
   *
   * @return EntityDataExtractor
   */
  public function getEntityExtractor(): EntityDataExtractor
  {
    return $this->entityExtractor;
  }
}
