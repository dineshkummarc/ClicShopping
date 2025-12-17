<?php

namespace ClicShopping\AI\Domain\Patterns;

use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;

class HybridPattern
{
  /**
   * Defines and returns regex patterns for price comparison queries.
   * 
   * Updated 2025-12-05: Added support for plural forms (prices, costs)
   * and improved pattern matching for various comparison query formats.
   *
   * @return array<string>
   */
  public static function priceComparisonPatterns(): array
  {
    $array = [
      // Compare/comparison + price/cost (singular or plural)
      '/\b(compare|comparison)\b.*\b(prices?|costs?|pricing)\b/i',
      
      // Price/cost + compare/comparison/competitor
      '/\b(prices?|costs?)\b.*\b(compare|comparison|competitor|vs|versus)\b/i',
      
      // How much + cost/price
      '/\b(how much)\b.*\b(costs?|prices?)\b/i',
      
      // Competitor/competition + price/cost
      '/\b(competitor|competition)\b.*\b(prices?|costs?|pricing)\b/i',
      
      // Competitive pricing queries
      '/\b(is.*competitive)\b/i',
      '/\b(competitively priced)\b/i',
      
      // Cheaper/expensive comparisons
      '/\b(cheaper|expensive|better deal)\b.*\b(than|alternative|elsewhere)\b/i',
      
      // Market/competitor analysis
      '/\b(market|competitor)\b.*\b(analysis|comparison)\b.*\b(prices?|costs?)\b/i',
      '/\b(prices?|costs?)\b.*\b(market|competitor)\b.*\b(analysis|comparison)\b/i',
      
      // Best price/deal queries
      '/\b(best|lowest|cheapest)\b.*\b(price|deal|offer)\b/i',
      
      // Price check queries
      '/\b(check|find|search)\b.*\b(prices?|costs?)\b.*\b(online|web|internet|competitors?)\b/i',
    ];

    return $array;
  }


  /**
   * Get implicit contextual query patterns
   *
   * ENGLISH ONLY: All patterns are in English as per HybridQueryProcessor design:
   * "All detection and processing logic should operate in English for consistency
   * in a multilingual context."
   *
   * @return array Array of regex patterns (English only)
   */
  public static function detectImplicitContextualQuery() :array
  {
    $array = [
      // Comparison queries without product name
      '/\b(compare|comparison)\b.*\b(competitor|competition|market|price|cost)\b/i',
      '/\b(compare|comparison)\b\s+(?:with|to|against|versus|vs)\b/i',

      // "Show more" type queries
      '/\b(show|display|give|tell)\b.*\b(more|additional|further|details|info|information)\b/i',
      '/\b(more|additional)\b.*\b(details|info|information|data)\b/i',

      // Price queries without product
      '/^(?:what|how much|tell me|show|give).*\b(price|cost|pricing)\b(?!.*\b(?:of|for)\s+\w+)/i',

      // Stock queries without product
      '/^(?:what|how much|show|give).*\b(stock|inventory|quantity|available)\b(?!.*\b(?:of|for)\s+\w+)/i',

      // Generic "what about" queries
      '/^(?:what|how)\s+about\b/i',
      '/^(?:and|also)\b/i',
    ];

    return $array;
  }


  /**
   * Checks if text contains critical analytics patterns
   *
   * @param string $text Text to analyze
   * @return bool True if critical match found
   */
  public static function hasCriticalMatch(string $text): bool
  {
    // Check for competitor comparison first
    if (WebSearchPattern::isCompetitorComparisonQuery($text)) {
      return true;
    }

    $patterns = AnalyticsPattern::getAnalyticsPatterns();

    $criticalPatterns = [
      'performance',
      'price',
      'quantity',
      'comparison',
      'calculation',
      'filters',
      'sorting',
      'time',
      'stock',
      'reference'
    ];

    foreach ($criticalPatterns as $type) {
      if (!isset($patterns[$type])) {
        continue;
      }

      foreach ($patterns[$type] as $pattern) {
        if (preg_match($pattern, $text)) {
          if (AnalyticsPattern::hasAnalyticalContext($text)) {
            return true;
          }
        }
      }
    }

    return false;
  }


  /**
   * Defines and returns regex patterns specifically for analytics queries.
   *
   * TASK 3.3: Enhanced to detect analytics + semantic combinations
   *
   * @return array<string, float> Associative array where:
   *                              - key: regex pattern (string)
   *                              - value: confidence score (float)
   */
  public static function detectHybridQuery() : array
  {
    $patterns = [
      // Analytics + Web Search combinations
      '/\b(stock|inventory|sales|price|data)\b.{1,100}\b(and|then|et)\b.{1,100}\b(compare|amazon|competitor|market|web)\b/i' => 0.95,
      '/\b(compare|amazon|competitor)\b.{1,100}\b(and|then|et)\b.{1,100}\b(stock|inventory|sales|price|data)\b/i' => 0.95,

      // Analytics + Semantic combinations (ENHANCED for TASK 3.3)
      // Requires BOTH analytics keyword AND semantic keyword with connector
      '/\b(sales|stock|data|show|display|inventory|price|quantity|quantité|chiffre)\b.{1,100}\b(and|then|et|également)\b.{1,100}\b(policy|procedure|explain|summarize|return|refund|warranty|shipping|delivery|délais?|retour|expédition|politique|procédure)\b/i' => 0.95,
      '/\b(policy|procedure|return|refund|warranty|shipping|delivery|délais?|retour|expédition|politique|procédure)\b.{1,100}\b(and|then|et|également)\b.{1,100}\b(sales|stock|data|inventory|price|quantity|quantité|chiffre)\b/i' => 0.95,

      // Analytics + Analytics combinations (normalized connectors and extended keywords)
      // TASK 4.5.7 FIX: Added "turnover", "quarters", "best", "top" to catch more analytics+analytics hybrids
      '/\b(stock|inventory|sales?|price|quantity|annual|monthly|quarterly|quarters?|revenue|data|turnover|best|top|chiffre|CA)\b.{1,100}\b(and|also|then|et|également)\b.{1,100}\b(stock|inventory|sales?|price|quantity|annual|monthly|quarterly|quarters?|revenue|data|turnover|best|top|chiffre|CA)\b/i' => 0.95,

      // Sales (non-documentary) - REMOVED overly broad policy/procedure pattern
      // This was causing false positives for semantic queries like "What is the return policy?"

      // Report generation
      '/\b(create|generate|make|do\s+me|fais\s+moi)\s+(analysis\s+)?(report|analysis|rapport|analyse)\b/i' => 0.80,
      ];

    return $patterns;
  }

  /**
   * Defines and returns reset context markers.
   *
   * @return array<string>
   */
  public static function ResetContext()
  {
    $resetMarkers = [
      'new',
      'new question',
      'change of topic',
      'something else',
      'now',
      'let’s move on to',
      'let’s talk about',
      'start over',
      'reset context',
      'new topic',
      'forget previous',
      'ignore previous'
    ];

    return $resetMarkers;
  }


  /**
   * Defines and returns modification keywords.
   *
   * @return array<string>
   */
  public static function modificationKeywords(): array
  {
    $modificationKeywords = [
      // Addition keywords
      'add', 'adds', 'adding', 'include', 'includes', 'including',
      'with', 'also', 'and also', 'as well',
      // Modification keywords
      'modify', 'modifies', 'modifying', 'change', 'changes', 'changing',
      'update', 'updates', 'updating', 'alter', 'alters', 'altering',
      // Removal keywords
      'remove', 'removes', 'removing', 'delete', 'deletes', 'deleting',
      'drop', 'drops', 'dropping', 'exclude', 'excludes', 'excluding',
      // Replacement keywords
      'replace', 'replaces', 'replacing', 'substitute', 'substitutes'
    ];

    return $modificationKeywords;
  }
}