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
 * ClearQueryPattern Class
 *
 * Defines patterns for clearly non-ambiguous analytics queries.
 * These patterns indicate specific, unambiguous user intents that don't require
 * multiple interpretations or LLM-based disambiguation.
 *
 * IMPORTANT: All patterns are in ENGLISH because queries are translated
 * to English before classification (see Semantics::translateToEnglish)
 *
 * Performance Impact:
 * - Pattern matching: ~0.001s (very fast)
 * - LLM ambiguity detection: ~1-2s (slow)
 * - Gain: ~1-2s per query
 */
class ClearQueryPattern
{
  /**
   * Patterns for aggregation queries (SUM/TOTAL)
   * These indicate the user wants a total/sum value
   * INCLUDES FRENCH PATTERNS for performance (avoids translation timeout)
   */
  private const AGGREGATION_PATTERNS = [
    // English patterns
    '/\btotal\s+(sales|revenue|amount|value|turnover|quantity|sold)/i',
    '/\bsum\s+of\s+/i',
    '/\bgross\s+(sales|revenue)/i',
    '/\bturnover\s+(for|of|this|in)/i',
    '/\bsales\s+amount/i',
    '/\brevenue\s+(for|of|this|in)/i',
    '/\banalysis\s+of\s+.*\s+evolution/i', // "analysis of revenue evolution"
    '/\bevolution\s+of\s+(sales|revenue|turnover)/i',
    // French patterns
    '/\btotal(e)?\s+(des?\s+)?(ventes?|chiffre|montant|valeur|quantité|vendu(e)?s?)/i',
    '/\bsomme\s+(des?\s+)?/i',
    '/\bquantité\s+total(e)?\s+vendu(e)?/i',
    '/\bavec\s+(leur|sa|son)\s+quantité\s+total(e)?\s+vendu(e)?/i', // "avec leur quantité totale vendue"
  ];

  /**
   * Patterns for count queries
   * These indicate the user wants a count/number
   */
  private const COUNT_PATTERNS = [
    '/\bnumber\s+of\s+(orders|products|customers|transactions|sales)/i',
    '/\bcount\s+(of\s+)?(orders|products|customers|transactions)/i',
    '/\bhow\s+many\s+(orders|products|customers|transactions)/i',
    '/\btotal\s+(orders|products|customers|transactions)\b/i',
  ];

  /**
   * Patterns for list queries
   * These indicate the user wants a list of items
   * INCLUDES FRENCH PATTERNS for performance (avoids translation timeout)
   */
  private const LIST_PATTERNS = [
    // English patterns
    '/\blist\s+(of\s+)?(all\s+)?(products|categories|customers|orders)/i',
    '/\bshow\s+(me\s+)?(all\s+)?(products|categories|customers|orders)/i',
    '/\bdisplay\s+(all\s+)?(products|categories|customers|orders)/i',
    '/\bget\s+(all\s+)?(products|categories|customers|orders)/i',
    // French patterns
    '/\bliste\s+(des?\s+)?(tous\s+les\s+)?(produits|catégories|clients|commandes)/i',
    '/\bafficher\s+(les\s+)?(tous\s+les\s+)?(produits|catégories|clients|commandes)/i',
    '/\bmontrer\s+(les\s+)?(tous\s+les\s+)?(produits|catégories|clients|commandes)/i',
  ];

  /**
   * Patterns for specific time periods (clear intent)
   * These indicate a specific, unambiguous time range
   */
  private const TIME_PERIOD_PATTERNS = [
    '/\b(today|yesterday|this\s+week|this\s+month|this\s+year)\b/i',
    '/\blast\s+\d+\s+(days|weeks|months|years)/i',
    '/\bin\s+\d{4}\b/i', // "in 2024"
    '/\bfor\s+\d{4}\b/i', // "for 2024"
    '/\bduring\s+(january|february|march|april|may|june|july|august|september|october|november|december)/i',
    '/\bin\s+(q1|q2|q3|q4)\b/i', // "in Q1"
  ];

  /**
   * Patterns for comparison queries (clear intent)
   * These indicate the user wants to compare values
   */
  private const COMPARISON_PATTERNS = [
    '/\bcompare\s+/i',
    '/\bcomparison\s+(of|between)/i',
    '/\bversus\b/i',
    '/\bvs\.?\b/i',
    '/\bdifference\s+between/i',
  ];

  /**
   * Patterns for ranking queries (clear intent)
   * These indicate the user wants ranked results
   */
  private const RANKING_PATTERNS = [
    '/\btop\s+\d+/i',
    '/\bbest\s+selling/i',
    '/\bmost\s+popular/i',
    '/\bworst\s+performing/i',
    '/\bhighest\s+(sales|revenue|value)/i',
    '/\blowest\s+(sales|revenue|value)/i',
    '/\bbest\s+\d+/i',
    '/\bworst\s+\d+/i',
  ];

  /**
   * Patterns for average/statistical queries
   * These indicate the user wants statistical calculations
   */
  private const STATISTICAL_PATTERNS = [
    '/\baverage\s+(price|value|amount|sales|revenue|order)/i',
    '/\bmean\s+(price|value|amount|order)/i',
    '/\bmedian\s+(price|value|amount|order)/i',
    '/\bstandard\s+deviation/i',
    '/\baverage\s+order\s+value/i',
  ];

  /**
   * Patterns for trend/analysis queries (complex but clear)
   * These indicate specific analytical intents
   */
  private const ANALYSIS_PATTERNS = [
    '/\btrend\s+analysis/i',
    '/\bseasonal\s+(analysis|coefficients|factors|patterns)/i',
    '/\bgrowth\s+rate/i',
    '/\byear\s+over\s+year/i',
    '/\bmonth\s+over\s+month/i',
    '/\bpercentage\s+change/i',
    '/\baccording\s+to\s+seasonal\s+coefficients/i',
    '/\bbased\s+on\s+seasonal\s+(factors|patterns)/i',
  ];

  /**
   * Check if query matches any clear pattern
   *
   * @param string $translatedQuery Query in ENGLISH (already translated)
   * @return array ['is_clear' => bool, 'pattern_type' => string|null, 'pattern' => string|null]
   */
  public static function matches(string $translatedQuery): array
  {
    $allPatterns = [
      'aggregation' => self::AGGREGATION_PATTERNS,
      'count' => self::COUNT_PATTERNS,
      'list' => self::LIST_PATTERNS,
      'time_period' => self::TIME_PERIOD_PATTERNS,
      'comparison' => self::COMPARISON_PATTERNS,
      'ranking' => self::RANKING_PATTERNS,
      'statistical' => self::STATISTICAL_PATTERNS,
      'analysis' => self::ANALYSIS_PATTERNS,
    ];

    foreach ($allPatterns as $type => $patterns) {
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $translatedQuery)) {
          return [
            'is_clear' => true,
            'pattern_type' => $type,
            'pattern' => $pattern,
            'matched_text' => self::getMatchedText($pattern, $translatedQuery),
          ];
        }
      }
    }

    return [
      'is_clear' => false,
      'pattern_type' => null,
      'pattern' => null,
      'matched_text' => null,
    ];
  }

  /**
   * Get the text that matched the pattern
   *
   * @param string $pattern Regex pattern
   * @param string $text Text to match against
   * @return string|null Matched text
   */
  private static function getMatchedText(string $pattern, string $text): ?string
  {
    if (preg_match($pattern, $text, $matches)) {
      return $matches[0] ?? null;
    }
    return null;
  }

  /**
   * Get all pattern categories
   *
   * @return array Array of pattern categories
   */
  public static function getCategories(): array
  {
    return [
      'aggregation',
      'count',
      'list',
      'time_period',
      'comparison',
      'ranking',
      'statistical',
      'analysis',
    ];
  }

  /**
   * Get statistics about patterns
   *
   * @return array Statistics
   */
  public static function getStatistics(): array
  {
    return [
      'aggregation_patterns' => count(self::AGGREGATION_PATTERNS),
      'count_patterns' => count(self::COUNT_PATTERNS),
      'list_patterns' => count(self::LIST_PATTERNS),
      'time_period_patterns' => count(self::TIME_PERIOD_PATTERNS),
      'comparison_patterns' => count(self::COMPARISON_PATTERNS),
      'ranking_patterns' => count(self::RANKING_PATTERNS),
      'statistical_patterns' => count(self::STATISTICAL_PATTERNS),
      'analysis_patterns' => count(self::ANALYSIS_PATTERNS),
      'total_patterns' => count(self::AGGREGATION_PATTERNS) +
                         count(self::COUNT_PATTERNS) +
                         count(self::LIST_PATTERNS) +
                         count(self::TIME_PERIOD_PATTERNS) +
                         count(self::COMPARISON_PATTERNS) +
                         count(self::RANKING_PATTERNS) +
                         count(self::STATISTICAL_PATTERNS) +
                         count(self::ANALYSIS_PATTERNS),
    ];
  }
}
