<?php
/**
 * AmbiguityPreFilter.php
 * 
 * ⚠️ EXCEPTION TO PURE LLM APPROACH ⚠️
 * 
 * This pattern-based pre-filter exists ONLY because LLM ambiguity detection
 * is inconsistent (85% success rate). It ensures 100% consistency for temporal
 * expressions and quantitative language.
 * 
 * WHY THIS EXISTS:
 * - LLM correctly identifies "this month" as NOT ambiguous: 85% of the time
 * - LLM correctly identifies "how many" as NOT ambiguous: 85% of the time
 * - Production requires 95%+ consistency
 * - Pattern-based pre-filter provides deterministic results
 * 
 * WHEN TO DELETE THIS FILE:
 * - When LLM ambiguity detection achieves 95%+ consistency
 * - When LLM temperature is reduced to 0.0 and tested
 * - When LLM model is upgraded (e.g., GPT-4o instead of GPT-4o-mini)
 * - Move to Old/ directory when no longer needed
 * 
 * PRINCIPLE:
 * DO NOT create new patterns unless LLM cannot do the job consistently (95%+)
 * 
 * ⚠️ IMPORTANT - POST-TRANSLATION PATTERN:
 * This pattern is called AFTER translation to English. All queries are
 * translated before reaching this pattern, so only English patterns are needed.
 * Multilingual patterns were removed in optimization (2025-12-23).
 * 
 * ARCHITECTURE:
 * User Query (any language) → Translation → English Query → AmbiguityPreFilter
 * 
 * @package ClicShopping\AI\Domain\Patterns\Hybrid
 * @date 2025-12-21
 * @updated 2025-12-23 (Simplified: removed multilingual patterns)
 * @author ClicShopping Team
 */

namespace ClicShopping\AI\Domain\Patterns\Hybrid;

class AmbiguityPreFilter
{
  /**
   * Check if query contains temporal expressions (NOT ambiguous)
   * 
   * ⚠️ IMPORTANT: This method is called AFTER translation to English.
   * All queries are translated before reaching this pattern, so only
   * English patterns are needed.
   * 
   * Temporal expressions are CLEAR time specifications:
   * - "this month", "this year", "today", "yesterday"
   * - "last month", "last year", "last week"
   * - "monthly", "yearly", "weekly", "daily"
   * 
   * @param string $query The query to check (already translated to English)
   * @return bool True if temporal expression found (NOT ambiguous)
   */
  public static function hasTemporalExpression(string $query): bool
  {
    $query = strtolower($query);
    
    // English temporal expressions (post-translation)
    $temporalPatterns = [
      // "this X" patterns
      '/\bthis\s+(month|year|week|quarter|day)\b/i',
      
      // "last X" patterns
      '/\blast\s+(month|year|week|quarter|day|\d+\s+days?)\b/i',
      
      // Single word temporal
      '/\b(today|yesterday|tomorrow|tonight)\b/i',
      
      // Frequency adverbs (monthly, yearly, etc.)
      '/\b(monthly|yearly|weekly|daily|quarterly|annually)\b/i',
    ];
    
    foreach ($temporalPatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Check if query contains quantitative language (NOT ambiguous)
   * 
   * ⚠️ IMPORTANT: This method is called AFTER translation to English.
   * All queries are translated before reaching this pattern, so only
   * English patterns are needed.
   * 
   * Quantitative language indicates clear aggregation intent:
   * - Counting: "how many", "number of", "count of"
   * - Summing: "total", "sum of", "total amount"
   * - Averaging: "average", "mean"
   * - Extremes: "minimum", "maximum", "highest", "lowest"
   * 
   * @param string $query The query to check (already translated to English)
   * @return bool True if quantitative language found (NOT ambiguous)
   */
  public static function hasQuantitativeLanguage(string $query): bool
  {
    $query = strtolower($query);
    
    // English quantitative patterns (post-translation)
    $quantitativePatterns = [
      // Counting
      '/\bhow\s+many\b/i',
      '/\bnumber\s+of\b/i',
      '/\bcount\s+of\b/i',
      '/\btotal\s+number\b/i',
      
      // Summing
      '/\btotal\b/i',
      '/\bsum\s+of\b/i',
      '/\btotal\s+amount\b/i',
      '/\bcombined\b/i',
      
      // Averaging
      '/\baverage\b/i',
      '/\bmean\b/i',
      '/\baverage\s+of\b/i',
      
      // Extremes
      '/\b(minimum|maximum|highest|lowest|least|most|smallest|largest)\b/i',
    ];
    
    foreach ($quantitativePatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Pre-filter query for ambiguity before LLM call
   * 
   * This method provides fast, deterministic ambiguity detection for
   * common cases where LLM is inconsistent.
   * 
   * Returns NULL if pre-filter cannot determine (use LLM)
   * Returns array if pre-filter can determine (skip LLM)
   * 
   * @param string $query The query to check
   * @return array|null Ambiguity result or null if LLM needed
   */
  public static function preFilter(string $query): ?array
  {
    // Check for temporal expressions
    if (self::hasTemporalExpression($query)) {
      return [
        'is_ambiguous' => false,
        'ambiguity_type' => null,
        'confidence' => 1.0,
        'interpretations' => [],
        'recommendation' => 'use_default',
        'default_interpretation' => null,
        'reasoning' => 'Clear temporal expression detected (pattern-based pre-filter)',
        'detection_method' => 'pattern_prefilter'
      ];
    }
    
    // Check for quantitative language
    if (self::hasQuantitativeLanguage($query)) {
      return [
        'is_ambiguous' => false,
        'ambiguity_type' => null,
        'confidence' => 1.0,
        'interpretations' => [],
        'recommendation' => 'use_default',
        'default_interpretation' => null,
        'reasoning' => 'Clear quantitative language detected (pattern-based pre-filter)',
        'detection_method' => 'pattern_prefilter'
      ];
    }
    
    // Cannot determine - use LLM
    return null;
  }
}
