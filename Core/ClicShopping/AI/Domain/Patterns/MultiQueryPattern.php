<?php
/**
 * MultiQueryPattern
 * 
 * TASK 6.2: Patterns for detecting and handling multi-query requests with AND connector
 * 
 * IMPORTANT: All patterns MUST be in English only.
 * French queries are translated to English BEFORE pattern matching.
 * 
 * Process: Query → Translation to English → Pattern Matching
 * 
 * @package ClicShopping\AI\Domain\Patterns
 * @author ClicShopping Team
 * @date 2025-12-06
 */

namespace ClicShopping\AI\Domain\Patterns;

class MultiQueryPattern
{
  // Metric keywords (English only)
  private const METRICS = 'stock|price|quantity|sales|revenue|profit|turnover|income|cost|amount|value|total|sum|average|min|max';
  
  // Link words (English only)
  private const LINK_WORDS = 'of|for|from';
  
  // Connectors (English only)
  private const CONNECTORS = 'and|AND|then|also';
  
  /**
   * Get patterns for detecting multi-product queries
   * 
   * These patterns detect queries like:
   * - "stock of iPhone 17 and stock of Samsung"
   * - "price of product A and price of product B"
   * 
   * @return array Array of regex patterns
   */
  public static function getMultiProductPatterns(): array
  {
    $metrics = self::METRICS;
    $linkWords = self::LINK_WORDS;
    $connectors = self::CONNECTORS;
    
    return [
      // Pattern 1: "metric of X and metric of Y"
      "/\b($metrics)\s+($linkWords)\s+([^,]+?)\s+\b($connectors)\b\s+(?:\b($metrics)\s+)?(?:\b($linkWords)\s+)?([^,]+)/i",
      
      // Pattern 2: "metric of X and Y" (metric not repeated)
      "/\b($metrics)\s+($linkWords)\s+([^,]+?)\s+\b($connectors)\b\s+([^,]+)/i",
    ];
  }
  
  /**
   * Get patterns for detecting generic multi-queries
   * 
   * These patterns detect queries like:
   * - "sales in January and sales in February"
   * - "customers in Paris and customers in Lyon"
   * 
   * @return array Array of regex patterns
   */
  public static function getGenericMultiQueryPatterns(): array
  {
    $connectors = self::CONNECTORS;
    
    return [
      // Generic AND connector
      "/\b($connectors)\b/i",
    ];
  }
  
  /**
   * Get patterns for temporal comparisons (should NOT be split)
   * 
   * These patterns detect queries like:
   * - "compare revenue may vs february"
   * - "Q1 sales vs Q2 sales"
   * 
   * @return array Array of regex patterns
   */
  public static function getTemporalComparisonPatterns(): array
  {
    return [
      '/\b(vs|versus|compared to|compare)\b/i',
      '/\bQ[1-4]\s+(vs|versus)\s+Q[1-4]\b/i',
      '/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+(vs|versus)\s+(january|february|march|april|may|june|july|august|september|october|november|december)\b/i',
    ];
  }
  
  /**
   * Get patterns for French articles after "et" (should NOT be split)
   * 
   * These patterns detect queries like:
   * - "et le produit" (and the product)
   * - "et la catégorie" (and the category)
   * 
   * Note: This is for detecting French patterns that should NOT be split,
   * even though the system operates in English-only mode.
   * 
   * @return array Array of regex patterns
   */
  public static function getFrenchArticlePatterns(): array
  {
    return [
      '/\bet\s+(le|la|l\'|les|du|de|des)\b/i',
    ];
  }
  
  /**
   * Check if query is a temporal comparison (should NOT be split)
   * 
   * @param string $query Query to check
   * @return bool True if temporal comparison
   */
  public static function isTemporalComparison(string $query): bool
  {
    $patterns = self::getTemporalComparisonPatterns();
    
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Check if query contains French article after "et" (should NOT be split)
   * 
   * @param string $query Query to check
   * @return bool True if contains French article
   */
  public static function hasFrenchArticleAfterEt(string $query): bool
  {
    $patterns = self::getFrenchArticlePatterns();
    
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Detect if query contains multiple sub-queries
   * 
   * @param string $query Query to analyze
   * @return array|false Array of sub-queries or false if single query
   */
  public static function detectMultipleQueries(string $query): array|false
  {
    // First check if this is a temporal comparison (should NOT be split)
    if (self::isTemporalComparison($query)) {
      return false;
    }
    
    // Check if this contains French article after "et" (should NOT be split)
    if (self::hasFrenchArticleAfterEt($query)) {
      return false;
    }
    
    // Try multi-product patterns first
    $multiProductPatterns = self::getMultiProductPatterns();
    
    foreach ($multiProductPatterns as $pattern) {
      if (preg_match($pattern, $query, $matches)) {
        // Extract products and metrics
        $metric1 = $matches[1] ?? 'stock';
        $product1 = trim($matches[3] ?? '');
        $product2 = trim($matches[7] ?? $matches[5] ?? '');
        
        if (!empty($product1) && !empty($product2)) {
          return [
            "Get {$metric1} of {$product1}",
            "Get {$metric1} of {$product2}"
          ];
        }
      }
    }
    
    // Try generic multi-query patterns
    $genericPatterns = self::getGenericMultiQueryPatterns();
    
    foreach ($genericPatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        // Split on the connector
        $parts = preg_split($pattern, $query, -1, PREG_SPLIT_NO_EMPTY);
        
        if (count($parts) > 1) {
          return array_map('trim', $parts);
        }
      }
    }
    
    return false;
  }
}
