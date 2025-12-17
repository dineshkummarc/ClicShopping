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

use ClicShopping\AI\Domain\Patterns\SemanticsPattern;

/**
 * SemanticIntentAnalyzer
 *
 * Specialized analyzer for semantic/knowledge-based queries.
 * Detects queries that require searching in embeddings or knowledge base.
 *
 * Examples:
 * - "What are the payment conditions?"
 * - "Explain the return policy"
 * - "How does delivery work?"
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer
 * @since 2025-12-14
 */
class SemanticIntentAnalyzer extends BaseIntentAnalyzer
{
  protected string $type = 'semantic';

  /**
   * {@inheritdoc}
   */
  public function analyze(string $query, string $originalQuery): array
  {
    $normalizedQuery = $this->normalizeQuery($query);

    // Get semantic patterns from centralized pattern class
    $patterns = SemanticsPattern::detectSemanticQuery();

    // Count pattern matches
    $matchData = $this->countPatternMatches($normalizedQuery, $patterns);

    // Determine if this is a semantic query
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

    // Semantic confidence algorithm:
    // - 1 match: 0.85 (high confidence for semantic)
    // - 2+ matches: 0.9 (very high confidence)
    if ($matchCount === 1) {
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

    // Extract question type
    $metadata['question_type'] = $this->detectQuestionType($query);

    // Extract topic if possible
    $metadata['topic'] = $this->extractTopic($query);

    return $metadata;
  }

  /**
   * Detect question type (what, how, why, etc.)
   *
   * @param string $query Query to analyze
   * @return string|null Question type or null
   */
  private function detectQuestionType(string $query): ?string
  {
    $normalized = $this->normalizeQuery($query);

    // Get centralized question type patterns
    $questionTypes = SemanticsPattern::getQuestionTypePatterns();

    foreach ($questionTypes as $type => $pattern) {
      if (preg_match($pattern, $normalized)) {
        return $type;
      }
    }

    return null;
  }

  /**
   * Extract topic from query
   *
   * @param string $query Query to analyze
   * @return string|null Topic or null
   */
  private function extractTopic(string $query): ?string
  {
    $normalized = $this->normalizeQuery($query);

    // Get centralized topic patterns
    $topics = SemanticsPattern::getTopicPatterns();

    foreach ($topics as $topic => $pattern) {
      if (preg_match($pattern, $normalized)) {
        return $topic;
      }
    }

    return null;
  }
}
