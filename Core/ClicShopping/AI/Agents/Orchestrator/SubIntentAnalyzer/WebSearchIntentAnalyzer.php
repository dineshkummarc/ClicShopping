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

use ClicShopping\AI\Domain\Patterns\WebSearchPattern;

/**
 * WebSearchIntentAnalyzer
 *
 * Specialized analyzer for web search queries.
 * Detects queries that require external web search (price comparison, competitors, etc.)
 *
 * Examples:
 * - "Compare price of [product] with competitors"
 * - "What is the price of [product] on Amazon?"
 * - "Price [product] vs eBay"
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer
 * @since 2025-12-14
 */
class WebSearchIntentAnalyzer extends BaseIntentAnalyzer
{
  protected string $type = 'web_search';

  /**
   * {@inheritdoc}
   */
  public function analyze(string $query, string $originalQuery): array
  {
    $normalizedQuery = $this->normalizeQuery($query);

    // Get web search patterns from centralized pattern class
    $patterns = WebSearchPattern::getWebSearchPatterns();

    // Count pattern matches
    $matchData = $this->countPatternMatches($normalizedQuery, $patterns);

    // Determine if this is a web search query
    $matches = $matchData['count'] > 0;

    // Calculate confidence (fixed high confidence for web search)
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

    // Web search patterns are very specific, so high confidence
    return 0.85;
  }

  /**
   * {@inheritdoc}
   */
  public function extractMetadata(string $query): array
  {
    $metadata = [];

    // Extract product name
    $metadata['product_name'] = $this->extractProductName($query);

    // Extract competitors
    $metadata['competitors'] = $this->extractCompetitors($query);

    // Extract comparison type
    $metadata['comparison_type'] = $this->extractComparisonType($query);

    return $metadata;
  }

  /**
   * Extract product name from query
   *
   * @param string $query Query to analyze
   * @return string|null Product name or null
   */
  private function extractProductName(string $query): ?string
  {
    // Get centralized product name patterns
    $patterns = WebSearchPattern::getProductNamePatterns();

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $query, $matches)) {
        return trim($matches[1]);
      }
    }

    return null;
  }

  /**
   * Extract competitors from query
   *
   * @param string $query Query to analyze
   * @return array Competitors found
   */
  private function extractCompetitors(string $query): array
  {
    $competitors = [];
    $normalized = $this->normalizeQuery($query);

    // Get centralized competitor name patterns
    $competitorPatterns = WebSearchPattern::getCompetitorNamePatterns();

    foreach ($competitorPatterns as $competitor => $pattern) {
      if (preg_match($pattern, $normalized)) {
        $competitors[] = $competitor;
      }
    }

    // Get centralized competitor general pattern
    $generalPattern = WebSearchPattern::getCompetitorGeneralPattern();
    
    // If no specific competitor mentioned, assume "competitors" in general
    if (empty($competitors) && preg_match($generalPattern, $normalized)) {
      $competitors[] = 'general';
    }

    return $competitors;
  }

  /**
   * Extract comparison type from query
   *
   * @param string $query Query to analyze
   * @return string|null Comparison type or null
   */
  private function extractComparisonType(string $query): ?string
  {
    $normalized = $this->normalizeQuery($query);

    // Get centralized comparison type patterns
    $comparisonPatterns = WebSearchPattern::getComparisonTypePatterns();

    foreach ($comparisonPatterns as $type => $pattern) {
      if (preg_match($pattern, $normalized)) {
        return $type;
      }
    }

    return null;
  }
}
