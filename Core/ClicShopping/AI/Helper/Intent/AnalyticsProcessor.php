<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Helper\Intent;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;

/**
 * AnalyticsProcessor Class
 *
 * 🔧 PRIORITY 3 - PHASE 3.3: Analytics query processing logic
 *
 * Responsible for processing analytics queries:
 * - Detecting analytics patterns
 * - Calculating analytics confidence
 * - Extracting analytics metadata (aggregations, filters, time ranges)
 * - Validating analytics requirements
 *
 * Separated from IntentAnalyzer to follow Single Responsibility Principle
 * and improve maintainability.
 *
 * @package ClicShopping\AI\Helper\Intent
 */
class AnalyticsProcessor
{
  private SecurityLogger $logger;
  private bool $debug;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
  }

  /**
   * Calculate confidence for analytics query
   *
   * Uses analytics patterns to determine confidence score.
   * Higher confidence for queries with multiple analytics indicators.
   *
   * @param string $query Query to analyze
   * @return array Result with confidence and matched patterns
   */
  public function calculateConfidence(string $query): array
  {
    if ($this->debug) {
      error_log("\n--- ANALYTICS CONFIDENCE CALCULATION ---");
      error_log("Query: '{$query}'");
    }

    $analyticsPatterns = AnalyticsPattern::detectAnalyticsQuery();
    $matchCount = 0;
    $matchedPatterns = [];

    foreach ($analyticsPatterns as $pattern => $score) {
      if (preg_match($pattern, $query)) {
        $matchCount++;
        $matchedPatterns[] = [
          'pattern' => substr($pattern, 0, 100),
          'score' => $score
        ];
        
        if ($this->debug) {
          error_log("✓ Analytics pattern matched (score: {$score}): " . substr($pattern, 0, 80) . "...");
        }
      }
    }

    // Calculate confidence based on matches
    // 1 match: 0.8 (strong single indicator)
    // 2 matches: 0.85 (very confident)
    // 3+ matches: 0.9 (extremely confident)
    $confidence = 0.5; // Default
    
    if ($matchCount >= 1) {
      $confidence = 0.8 + (min($matchCount - 1, 2) * 0.05);
    }

    // Adjust for query length
    $wordCount = str_word_count($query);
    if ($wordCount >= 10) {
      $confidence = min(0.95, $confidence + 0.05);
    }
    // Note: Don't penalize short analytics queries (they're often concise)

    if ($this->debug) {
      error_log("Analytics matches: {$matchCount} patterns");
      error_log("Word count: {$wordCount}");
      error_log("Calculated confidence: {$confidence}");
      error_log("--- END ANALYTICS CONFIDENCE ---\n");
    }

    return [
      'confidence' => round($confidence, 2),
      'match_count' => $matchCount,
      'matched_patterns' => $matchedPatterns,
      'word_count' => $wordCount,
    ];
  }

  /**
   * Extract analytics metadata from query
   *
   * Extracts analytics-specific metadata like:
   * - Aggregation type (count, sum, average, etc.)
   * - Time ranges
   * - Filters
   * - Sorting
   * - Limits
   *
   * @param string $query Query to analyze
   * @return array Analytics metadata
   */
  public function extractMetadata(string $query): array
  {
    $metadata = [
      'aggregation_type' => null,
      'time_range' => null,
      'filters' => [],
      'sorting' => null,
      'limit' => null,
      'grouping' => null,
    ];

    // Detect aggregation type
    if (preg_match('/\b(count|total|sum|nombre)\b/i', $query)) {
      $metadata['aggregation_type'] = 'count';
    } elseif (preg_match('/\b(average|avg|mean|moyenne)\b/i', $query)) {
      $metadata['aggregation_type'] = 'average';
    } elseif (preg_match('/\b(maximum|max|highest|plus élevé)\b/i', $query)) {
      $metadata['aggregation_type'] = 'max';
    } elseif (preg_match('/\b(minimum|min|lowest|plus bas)\b/i', $query)) {
      $metadata['aggregation_type'] = 'min';
    }

    // Extract time range
    if (preg_match('/\b(today|yesterday|this week|this month|this year|aujourd\'hui|hier|cette semaine|ce mois|cette année)\b/i', $query, $matches)) {
      $metadata['time_range'] = strtolower($matches[1]);
    } elseif (preg_match('/\b(last|past|previous|dernier|précédent)\s+(\d+)\s+(days?|weeks?|months?|years?|jours?|semaines?|mois|années?)\b/i', $query, $matches)) {
      $metadata['time_range'] = [
        'type' => 'relative',
        'value' => (int)$matches[2],
        'unit' => strtolower($matches[3]),
      ];
    }

    // Extract limit
    if (preg_match('/\b(top|first|last|premier|dernier)\s+(\d+)\b/i', $query, $matches)) {
      $metadata['limit'] = (int)$matches[2];
    }

    // Extract sorting
    if (preg_match('/\b(best|worst|highest|lowest|most|least|meilleur|pire|plus|moins)\b/i', $query, $matches)) {
      $metadata['sorting'] = strtolower($matches[1]);
    }

    // Detect grouping
    if (preg_match('/\b(by|per|par)\s+(product|category|brand|supplier|manufacturer|customer|produit|catégorie|marque|fournisseur|client)\b/i', $query, $matches)) {
      $metadata['grouping'] = strtolower($matches[2]);
    }

    // Extract filters
    if (preg_match('/\b(where|with|having|status|état)\b/i', $query)) {
      $metadata['filters'][] = 'conditional_filter';
    }

    return $metadata;
  }

  /**
   * Check if analytics query is simple (no filters/grouping)
   *
   * Simple analytics queries don't need entity context.
   * Examples: "count all products", "show me sales", "list orders"
   *
   * @param string $query Query to check
   * @param float $confidence Query confidence
   * @return bool True if simple analytics query
   */
  public function isSimpleAnalyticsQuery(string $query, float $confidence): bool
  {
    // Must have high confidence to be considered simple
    if ($confidence < 0.8) {
      return false;
    }

    // Check for simple analytics patterns
    $simplePatterns = [
      '/\b(count|total|sum|average|list|show)\s+(all|total)?\s*(products|orders|customers|categories)\b/i',
      '/\b(how many|combien)\s+(products|orders|customers|categories)\b/i',
    ];

    foreach ($simplePatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }

    // Check for filters/grouping (not simple if present)
    $complexPatterns = [
      '/\b(by|per|par|where|with|having)\b/i',
      '/\b(for|pour|of|de)\s+(product|category|brand|supplier)/i',
    ];

    foreach ($complexPatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return false;
      }
    }

    return false;
  }
}
