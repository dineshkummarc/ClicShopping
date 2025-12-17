<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer;

use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;

/**
 * AnalyticsIntentAnalyzer
 *
 * Specialized analyzer for analytics/data queries.
 * Detects queries that require SQL generation and database queries.
 *
 * Examples:
 * - "How many products do we have?"
 * - "Sales today"
 * - "Annual revenue by month"
 * - "Stock of iPhone 17 Pro"
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer
 * @since 2025-12-14
 */
class AnalyticsIntentAnalyzer extends BaseIntentAnalyzer
{
  protected string $type = 'analytics';

  /**
   * {@inheritdoc}
   */
  public function analyze(string $query, string $originalQuery): array
  {
    $normalizedQuery = $this->normalizeQuery($query);

    // Get analytics patterns from centralized pattern class
    $patterns = AnalyticsPattern::detectAnalyticsQuery();

    // Count pattern matches
    $matchData = $this->countPatternMatches($normalizedQuery, $patterns);

    // Determine if this is an analytics query
    $matches = $matchData['count'] > 0;

    // Calculate confidence
    $confidence = $this->calculateConfidence($query, $matchData);

    // Extract metadata
    $metadata = $this->extractMetadata($query);

    // Build reasoning
    $reasoning = [
      'patterns_matched' => $matchData['count'],
      'total_patterns' => $matchData['total_patterns'],
      'matched_patterns' => array_slice($matchData['patterns'], 0, 3), // Top 3
    ];

    // Log detection
    $this->logDetection($query, $matches, $confidence, $reasoning);

    return [
      'matches' => $matches,
      'confidence' => $confidence,
      'type' => $this->type,
      'metadata' => $metadata,
      'reasoning' => $reasoning,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateConfidence(string $query, array $detectionData): float
  {
    $matchCount = $detectionData['count'] ?? 0;

    if ($matchCount === 0) {
      return 0.0;
    }

    // Analytics confidence algorithm:
    // - 1 match: 0.8 (good confidence)
    // - 2 matches: 0.85 (high confidence)
    // - 3+ matches: 0.9 (very high confidence)
    if ($matchCount === 1) {
      return 0.8;
    } elseif ($matchCount === 2) {
      return 0.85;
    }

    return 0.9;
  }

  /**
   * {@inheritdoc}
   */
  public function extractMetadata(string $query): array
  {
    $metadata = [];

    // Extract time range
    $metadata['time_range'] = $this->extractTimeRange($query);

    // Extract aggregation type
    $metadata['aggregation'] = $this->extractAggregation($query);

    // Extract entity type
    $metadata['entity_type'] = $this->extractEntityType($query);

    // Extract filters
    $metadata['filters'] = $this->extractFilters($query);

    return $metadata;
  }

  /**
   * Extract time range from query
   *
   * @param string $query Query to analyze
   * @return array|null Time range or null
   */
  private function extractTimeRange(string $query): ?array
  {
    $normalized = $this->normalizeQuery($query);

    // Get centralized time range patterns
    $timePatterns = AnalyticsPattern::getTimeRangePatterns();

    foreach ($timePatterns as $range => $pattern) {
      if (preg_match($pattern, $normalized)) {
        return ['type' => $range];
      }
    }

    return null;
  }

  /**
   * Extract aggregation type from query
   *
   * @param string $query Query to analyze
   * @return string|null Aggregation type or null
   */
  private function extractAggregation(string $query): ?string
  {
    $normalized = $this->normalizeQuery($query);

    // Get centralized aggregation patterns
    $aggregations = AnalyticsPattern::getAggregationPatterns();

    foreach ($aggregations as $type => $pattern) {
      if (preg_match($pattern, $normalized)) {
        return $type;
      }
    }

    return null;
  }

  /**
   * Extract entity type from query
   *
   * @param string $query Query to analyze
   * @return string|null Entity type or null
   */
  private function extractEntityType(string $query): ?string
  {
    $normalized = $this->normalizeQuery($query);

    // Get centralized entity type patterns
    $entities = AnalyticsPattern::getEntityTypePatterns();

    foreach ($entities as $type => $pattern) {
      if (preg_match($pattern, $normalized)) {
        return $type;
      }
    }

    return null;
  }

  /**
   * Extract filters from query
   *
   * @param string $query Query to analyze
   * @return array Filters found
   */
  private function extractFilters(string $query): array
  {
    $filters = [];
    $normalized = $this->normalizeQuery($query);

    // Get centralized status filter patterns
    $statusPatterns = AnalyticsPattern::getStatusFilterPatterns();
    
    foreach ($statusPatterns as $status => $pattern) {
      if (preg_match($pattern, $normalized)) {
        $filters['status'] = $status;
        break;
      }
    }

    // Get centralized price filter pattern
    $pricePattern = AnalyticsPattern::getPriceFilterPattern();
    
    if (preg_match($pricePattern, $normalized, $matches)) {
      $filters['price'] = [
        'operator' => $matches[2],
        'value' => (float)$matches[3],
      ];
    }

    return $filters;
  }
}
