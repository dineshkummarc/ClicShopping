<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domain\Patterns\Analytics;

/**
 * FinancialMetricsPattern
 *
 * Provides patterns for detecting financial metrics in queries.
 * Extracted from UnifiedQueryAnalyzer for reusability.
 *
 * @package ClicShopping\AI\Domain\Patterns\Analytics
  *
 * @deprecated Pattern-based logic superseded by Pure LLM Mode
 *             Scheduled for removal in Q3 2026
 *             Use UnifiedQueryAnalyzer for intent classification instead
 *             See Domain/Patterns/DEPRECATED.md for migration guide
 **/
// DEPRECATED: Pattern-based logic superseded by Pure LLM Mode. Scheduled for removal in Q3 2026.

class FinancialMetricsPattern
{
  /**
   * Get financial metrics patterns
   *
   * Returns an ordered array of financial metric patterns.
   * Order matters - more specific patterns should come first.
   *
   * @return array Associative array of pattern => metric
   */
  public static function getMetricsPatterns(): array
  {
    return [
      'total revenue' => 'revenue',
      'gross revenue' => 'revenue',
      'net revenue' => 'revenue',
      'total sales' => 'sales',
      'gross sales' => 'sales',
      'net sales' => 'sales',
      'revenue' => 'revenue',
      'sales' => 'sales',
      'turnover' => 'turnover',
      'profit' => 'profit',
      'margin' => 'margin',
      'income' => 'income',
      'earnings' => 'earnings',
      'expenses' => 'expenses',
      'costs' => 'costs',
      'orders' => 'orders',
      'order count' => 'orders',
      'order total' => 'orders',
    ];
  }

  /**
   * Extract base metric from query
   *
   * Identifies the financial metric being queried (revenue, sales, profit, etc.)
   *
   * @param string $query The query (should be in English and lowercase)
   * @return string|null The base metric or null if not found
   */
  public static function extractBaseMetric(string $query): ?string
  {
    $query = strtolower($query);
    $metrics = self::getMetricsPatterns();
    
    foreach ($metrics as $pattern => $metric) {
      if (strpos($query, $pattern) !== false) {
        return $metric;
      }
    }
    
    return null;
  }

  /**
   * Get all unique metric types
   *
   * @return array List of unique metric types
   */
  public static function getMetricTypes(): array
  {
    return array_values(array_unique(self::getMetricsPatterns()));
  }

  /**
   * Check if query contains a financial metric
   *
   * @param string $query The query to check
   * @return bool True if a financial metric is detected
   */
  public static function hasFinancialMetric(string $query): bool
  {
    return self::extractBaseMetric($query) !== null;
  }

  /**
   * Get metadata about financial metrics
   *
   * @return array Metadata about the pattern
   */
  public static function getMetadata(): array
  {
    return [
      'name' => 'FinancialMetricsPattern',
      'description' => 'Detects financial metrics in queries',
      'domain' => 'Analytics',
      'metrics_count' => count(self::getMetricsPatterns()),
      'unique_metrics' => count(self::getMetricTypes()),
    ];
  }
}
