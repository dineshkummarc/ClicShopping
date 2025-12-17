<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domain\Patterns;

/**
 * DependencyPattern - Centralized patterns for detecting query dependencies
 *
 * This class provides patterns and keywords for detecting when one sub-query
 * depends on the results of another sub-query. Dependencies can be:
 * - Explicit: Using pronouns or references (leur, their, it, etc.)
 * - Implicit: Sharing entities or using aggregation keywords
 *
 * Used by QuerySplitter to detect and mark dependencies between sub-queries.
 *
 * @package ClicShopping\AI\Domain\Patterns
 * @since 2025-12-15
 */
class DependencyPattern
{
  /**
   * Get explicit dependency indicators (pronouns and references)
   *
   * These words indicate that a query explicitly references something
   * from a previous query.
   *
   * MULTILINGUAL: Returns indicators for all supported languages.
   *
   * Examples:
   * - "show products. What is THEIR average price?" (English)
   * - "top 5 produits. Quel est LEUR prix moyen?" (French)
   * - "mostrar productos. Cuál es SU precio medio?" (Spanish)
   *
   * @param string|null $language Optional language code (en, fr, es, etc.)
   *                              If null, returns all languages
   * @return array List of explicit dependency indicators
   */
  public static function getExplicitDependencyIndicators(?string $language = null): array
  {
    $indicators = [
      'en' => [
        'their', 'its', 'those', 'these', 'that', 'this',
        'them', 'it', 'the same', 'such', 'said',
      ],      
    ];
    
    if ($language !== null && isset($indicators[$language])) {
      return $indicators[$language];
    }
    
    // Return all indicators from all languages
    return array_merge(...array_values($indicators));
  }

  /**
   * Get entity keywords for implicit dependency detection
   *
   * IMPORTANT: English-only patterns. All queries are translated to English
   * before pattern matching (see Semantics::translateToEnglish()).
   *
   * Examples:
   * - "top 5 PRODUCTS. average price of PRODUCTS" (shared: products)
   * - "list ORDERS. total of ORDERS" (shared: orders)
   *
   * @return array List of entity keywords (singular form, will match plural)
   */
  public static function getEntityKeywords(): array
  {
    return [
      // Products
      'product',
      'item',
      'article',
      
      // Orders
      'order',
      'sale',
      'purchase',
      
      // Customers
      'customer',
      'client',
      'user',
      
      // Categories
      'category',
      
      // Suppliers
      'supplier',
      'vendor',
      
      // Manufacturers
      'manufacturer',
      'brand',
      
      // Inventory
      'stock',
      'inventory',
    ];
  }

  /**
   * Get aggregation keywords for implicit dependency detection
   *
   * These keywords indicate that a query is performing an aggregation
   * or calculation, which typically depends on results from a previous query.
   *
   * IMPORTANT: English-only patterns. All queries are translated to English
   * before pattern matching (see Semantics::translateToEnglish()).
   *
   * Examples:
   * - "top 5 products. AVERAGE price" (aggregation: average)
   * - "list orders. TOTAL amount" (aggregation: total)
   * - "show products. COUNT them" (aggregation: count)
   *
   * @return array List of aggregation keywords (English only)
   */
  public static function getAggregationKeywords(): array
  {
    return [
      // Average
      'average',
      'avg',
      'mean',
      
      // Total/Sum
      'total',
      'sum',
      
      // Count
      'count',
      'number',
      'how many',
      
      // Min/Max
      'minimum',
      'min',
      'maximum',
      'max',
      'smallest',
      'largest',
      
      // Price-related (often used in aggregations)
      'price',
      'cost',
      'amount',
      
      // Statistical
      'median',
      'deviation',
      'variance',
    ];
  }

  /**
   * Get sequential connectors that indicate dependencies
   *
   * These connectors indicate that queries should be executed sequentially,
   * with later queries potentially depending on earlier ones.
   *
   * IMPORTANT: English-only patterns. All queries are translated to English
   * before pattern matching (see Semantics::translateToEnglish()).
   *
   * Examples:
   * - "query1. THEN query2" (sequential: then)
   * - "query1. AFTER THAT query2" (sequential: after that)
   *
   * @return array List of sequential connectors (English only)
   */
  public static function getSequentialConnectors(): array
  {
    return [
      'then',
      'after that',
      'next',
      'following',
      'subsequently',
    ];
  }

  /**
   * Detect if a query has explicit dependency on previous query
   *
   * Checks if the query contains pronouns or references that indicate
   * it depends on results from a previous query.
   *
   * @param string $query Query to check
   * @return bool True if explicit dependency detected
   */
  public static function hasExplicitDependency(string $query): bool
  {
    $queryLower = strtolower($query);
    $indicators = self::getExplicitDependencyIndicators();
    
    foreach ($indicators as $indicator) {
      if (preg_match('/\b' . preg_quote($indicator, '/') . '\b/i', $queryLower)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Detect if two queries share entities (implicit dependency)
   *
   * Checks if both queries reference the same entity, which indicates
   * an implicit dependency.
   *
   * @param string $query1 First query
   * @param string $query2 Second query
   * @return array|false Array with 'entity' key if shared entity found, false otherwise
   */
  public static function hasSharedEntity(string $query1, string $query2): array|false
  {
    $query1Lower = strtolower($query1);
    $query2Lower = strtolower($query2);
    $entities = self::getEntityKeywords();
    
    foreach ($entities as $entity) {
      // Match singular or plural form
      $pattern = '/\b' . preg_quote($entity, '/') . 's?\b/i';
      
      if (preg_match($pattern, $query1Lower) && preg_match($pattern, $query2Lower)) {
        return ['entity' => $entity];
      }
    }
    
    return false;
  }

  /**
   * Detect if a query contains aggregation keywords (implicit dependency)
   *
   * Checks if the query contains aggregation or calculation keywords,
   * which typically indicate it depends on results from a previous query.
   *
   * @param string $query Query to check
   * @return array|false Array with 'keyword' key if aggregation found, false otherwise
   */
  public static function hasAggregation(string $query): array|false
  {
    $queryLower = strtolower($query);
    $keywords = self::getAggregationKeywords();
    
    foreach ($keywords as $keyword) {
      if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $queryLower)) {
        return ['keyword' => $keyword];
      }
    }
    
    return false;
  }

  /**
   * Detect dependency type between two queries
   *
   * Analyzes two queries and determines if the second depends on the first,
   * and what type of dependency it is.
   *
   * @param string $query1 First query (potential dependency source)
   * @param string $query2 Second query (potential dependent)
   * @return array Dependency information with keys:
   *   - has_dependency: bool
   *   - type: string|null (explicit|implicit_entity|implicit_aggregation)
   *   - indicator: string|null (what triggered the detection)
   */
  public static function detectDependency(string $query1, string $query2): array
  {
    // Check for explicit dependency
    if (self::hasExplicitDependency($query2)) {
      $indicators = self::getExplicitDependencyIndicators();
      $query2Lower = strtolower($query2);
      
      foreach ($indicators as $indicator) {
        if (preg_match('/\b' . preg_quote($indicator, '/') . '\b/i', $query2Lower)) {
          return [
            'has_dependency' => true,
            'type' => 'explicit',
            'indicator' => $indicator,
          ];
        }
      }
    }
    
    // Check for shared entity
    $sharedEntity = self::hasSharedEntity($query1, $query2);
    if ($sharedEntity !== false) {
      return [
        'has_dependency' => true,
        'type' => 'implicit_entity',
        'indicator' => $sharedEntity['entity'],
      ];
    }
    
    // Check for aggregation
    $aggregation = self::hasAggregation($query2);
    if ($aggregation !== false) {
      return [
        'has_dependency' => true,
        'type' => 'implicit_aggregation',
        'indicator' => $aggregation['keyword'],
      ];
    }
    
    // No dependency detected
    return [
      'has_dependency' => false,
      'type' => null,
      'indicator' => null,
    ];
  }

  /**
   * Get period delimiter pattern for splitting queries
   *
   * Returns regex pattern for detecting period-delimited queries
   * that should be split into sub-queries.
   *
   * @return string Regex pattern
   */
  public static function getPeriodDelimiterPattern(): string
  {
    return '/\.\s+/i';
  }

  /**
   * Detect if query contains period-delimited sentences with dependencies
   *
   * Checks if a query has multiple sentences separated by periods,
   * where later sentences depend on earlier ones.
   *
   * @param string $query Query to check
   * @return bool True if period-delimited with dependencies
   */
  public static function hasPeriodDelimitedDependencies(string $query): bool
  {
    // Check for period followed by space and a new sentence
    if (!preg_match('/\.\s+[A-Z]/', $query) && !preg_match('/\.\s+\w+/u', $query)) {
      return false;
    }
    
    // Split by period and check if we have multiple meaningful parts
    $parts = array_filter(
      array_map('trim', preg_split(self::getPeriodDelimiterPattern(), $query)),
      fn($p) => strlen($p) >= 5
    );
    
    if (count($parts) < 2) {
      return false;
    }
    
    // Check if second part has dependency on first
    $dependency = self::detectDependency($parts[0], $parts[1]);
    return $dependency['has_dependency'];
  }

  /**
   * Generate ASCII dependency graph visualization
   *
   * Creates a visual representation of sub-query dependencies for debugging
   * and monitoring purposes.
   *
   * @param array $subQueries Array of sub-queries with dependency information
   * @param string $originalQuery Original query before splitting
   * @return string ASCII art dependency graph
   */
  public static function visualizeDependencyGraph(array $subQueries, string $originalQuery = ''): string
  {
    $output = [];
    
    // Header
    if (!empty($originalQuery)) {
      $output[] = "Original Query: \"" . self::truncate($originalQuery, 60) . "\"";
      $output[] = "";
    }
    
    $output[] = "Dependency Graph:";
    $output[] = str_repeat("=", 70);
    $output[] = "";
    
    // Build dependency map
    $dependencyMap = [];
    foreach ($subQueries as $index => $sq) {
      $dependencyMap[$index + 1] = $sq['depends_on'] ?? null;
    }
    
    // Render each sub-query
    foreach ($subQueries as $index => $sq) {
      $num = $index + 1;
      $query = $sq['query'] ?? 'N/A';
      $type = $sq['type'] ?? 'unknown';
      $priority = $sq['priority'] ?? $num;
      $dependsOn = $sq['depends_on'] ?? null;
      $dependencyType = $sq['dependency_type'] ?? null;
      
      // Box top
      $output[] = "┌" . str_repeat("─", 68) . "┐";
      
      // Query number and priority
      $output[] = "│ " . str_pad("#{$num} (Priority: {$priority})", 67) . "│";
      
      // Query text
      $queryLines = self::wrapText($query, 64);
      foreach ($queryLines as $line) {
        $output[] = "│   " . str_pad($line, 65) . "│";
      }
      
      // Type
      $output[] = "│   " . str_pad("Type: {$type}", 65) . "│";
      
      // Dependency info
      if ($dependsOn !== null) {
        $depInfo = "Depends on: #{$dependsOn}";
        if ($dependencyType) {
          $depInfo .= " ({$dependencyType})";
        }
        $output[] = "│   " . str_pad($depInfo, 65) . "│";
      }
      
      // Box bottom
      $output[] = "└" . str_repeat("─", 68) . "┘";
      
      // Arrow to next if there's a dependency
      if ($dependsOn !== null) {
        $output[] = "           ↑ depends on";
      } else if ($index < count($subQueries) - 1) {
        // Check if next query depends on this one
        $nextDependsOn = $subQueries[$index + 1]['depends_on'] ?? null;
        if ($nextDependsOn === $num) {
          $output[] = "           ↓";
        } else {
          $output[] = "";
        }
      }
      
      $output[] = "";
    }
    
    // Summary
    $output[] = str_repeat("=", 70);
    $output[] = "Summary:";
    $output[] = "  Total sub-queries: " . count($subQueries);
    
    $withDeps = count(array_filter($subQueries, fn($sq) => isset($sq['depends_on'])));
    $output[] = "  With dependencies: {$withDeps}";
    
    $independent = count($subQueries) - $withDeps;
    $output[] = "  Independent: {$independent}";
    
    return implode("\n", $output);
  }

  /**
   * Generate compact dependency graph (one-line per query)
   *
   * @param array $subQueries Array of sub-queries
   * @return string Compact dependency representation
   */
  public static function visualizeDependencyGraphCompact(array $subQueries): string
  {
    $output = [];
    
    foreach ($subQueries as $index => $sq) {
      $num = $index + 1;
      $query = self::truncate($sq['query'] ?? 'N/A', 40);
      $type = $sq['type'] ?? 'unknown';
      $dependsOn = $sq['depends_on'] ?? null;
      
      $line = "#{$num}: {$query} ({$type})";
      
      if ($dependsOn !== null) {
        $depType = $sq['dependency_type'] ?? 'unknown';
        $line .= " → depends on #{$dependsOn} ({$depType})";
      }
      
      $output[] = $line;
    }
    
    return implode("\n", $output);
  }

  /**
   * Truncate text to specified length
   *
   * @param string $text Text to truncate
   * @param int $length Maximum length
   * @return string Truncated text
   */
  private static function truncate(string $text, int $length): string
  {
    if (strlen($text) <= $length) {
      return $text;
    }
    
    return substr($text, 0, $length - 3) . '...';
  }

  /**
   * Wrap text to specified width
   *
   * @param string $text Text to wrap
   * @param int $width Maximum width
   * @return array Array of wrapped lines
   */
  private static function wrapText(string $text, int $width): array
  {
    if (strlen($text) <= $width) {
      return [$text];
    }
    
    $words = explode(' ', $text);
    $lines = [];
    $currentLine = '';
    
    foreach ($words as $word) {
      if (strlen($currentLine . ' ' . $word) <= $width) {
        $currentLine .= ($currentLine ? ' ' : '') . $word;
      } else {
        if ($currentLine) {
          $lines[] = $currentLine;
        }
        $currentLine = $word;
      }
    }
    
    if ($currentLine) {
      $lines[] = $currentLine;
    }
    
    return $lines;
  }
}
