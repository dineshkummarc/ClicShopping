<?php
/**
 * CompoundQueryIndicatorsPattern.php
 * 
 * Centralized compound query indicators for detecting multi-part queries.
 * Contains ONLY English patterns - French translations are handled by LLM.
 * 
 * A compound query contains multiple distinct questions that should be
 * processed separately (e.g., "pending orders and total revenue").
 * 
 * @package ClicShopping\AI\Domain\Patterns\Common
 * @since 2026-01-11
 * 
 * NOTE: Pattern classes contain English keywords only.
 * All processing is done in English after translation.
  *
 * @deprecated Pattern-based logic superseded by Pure LLM Mode
 *             Scheduled for removal in Q3 2026
 *             Use UnifiedQueryAnalyzer for intent classification instead
 *             See Domain/Patterns/DEPRECATED.md for migration guide
 **/

namespace ClicShopping\AI\Domain\Patterns\Common;

use AllowDynamicProperties;

#[AllowDynamicProperties]
// DEPRECATED: Pattern-based logic superseded by Pure LLM Mode. Scheduled for removal in Q3 2026.

class CompoundQueryIndicatorsPattern
{
  /**
   * Compound query indicators with regex patterns
   * 
   * These patterns detect connectors that typically join multiple
   * independent questions in a compound query.
   * 
   * ENGLISH ONLY - queries are translated before pattern matching.
   * 
   * @var array<string, string>
   */
  public static array $indicators = [
    'and' => '/\band\b/i',
    'then' => '/\bthen\b/i',
    'also' => '/\balso\b/i',
    'as_well_as' => '/as well as/i',
    'plus' => '/\bplus\b/i',
    'in_addition' => '/in addition/i',
    'additionally' => '/\badditionally\b/i',
    'furthermore' => '/\bfurthermore\b/i',
    'along_with' => '/along with/i',
    'together_with' => '/together with/i',
  ];
  
  /**
   * Flat list of indicator keywords for simple matching
   * 
   * @var array<string>
   */
  public static array $keywords = [
    'and',
    'then',
    'also',
    'as well as',
    'plus',
    'in addition',
    'additionally',
    'furthermore',
    'along with',
    'together with',
  ];
  
  /**
   * Get all indicator patterns
   * 
   * @return array<string, string>
   */
  public static function getPatterns(): array
  {
    return self::$indicators;
  }
  
  /**
   * Get flat list of keywords
   * 
   * @return array<string>
   */
  public static function getKeywords(): array
  {
    return self::$keywords;
  }
  
  /**
   * Detect indicators in a query
   * 
   * @param string $query Query to analyze (should be in English)
   * @return array<string> List of detected indicator names
   */
  public static function detectIndicators(string $query): array
  {
    $detected = [];
    
    foreach (self::$indicators as $name => $pattern) {
      if (preg_match($pattern, $query)) {
        $detected[] = $name;
      }
    }
    
    return $detected;
  }
  
  /**
   * Check if query contains any compound indicators
   * 
   * @param string $query Query to check (should be in English)
   * @return bool True if any indicator is found
   */
  public static function hasIndicators(string $query): bool
  {
    foreach (self::$indicators as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Get the first detected indicator
   * 
   * @param string $query Query to analyze (should be in English)
   * @return string|null First detected indicator name or null
   */
  public static function getFirstIndicator(string $query): ?string
  {
    foreach (self::$indicators as $name => $pattern) {
      if (preg_match($pattern, $query)) {
        return $name;
      }
    }
    
    return null;
  }
  
  /**
   * Count indicators in a query
   * 
   * @param string $query Query to analyze (should be in English)
   * @return int Number of distinct indicators found
   */
  public static function countIndicators(string $query): int
  {
    return count(self::detectIndicators($query));
  }
  
  /**
   * Get metadata about this pattern
   * 
   * @return array Pattern metadata
   */
  public static function getMetadata(): array
  {
    return [
      'name' => 'Compound Query Indicators Pattern',
      'description' => 'Detects connectors that join multiple questions in compound queries',
      'language' => 'English only (queries translated before matching)',
      'indicator_count' => count(self::$indicators),
      'keywords' => self::$keywords,
    ];
  }
}
