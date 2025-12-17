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
use ClicShopping\AI\Domain\Patterns\SemanticsPattern;

/**
 * SemanticProcessor Class
 *
 * 🔧 PRIORITY 3 - PHASE 3.3: Semantic query processing logic
 *
 * Responsible for processing semantic queries:
 * - Detecting semantic patterns
 * - Calculating semantic confidence
 * - Extracting semantic metadata
 * - Validating semantic requirements
 *
 * Separated from IntentAnalyzer to follow Single Responsibility Principle
 * and improve maintainability.
 *
 * @package ClicShopping\AI\Helper\Intent
 */
class SemanticProcessor
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
   * Calculate confidence for semantic query
   *
   * Uses semantic patterns to determine confidence score.
   * Higher confidence for queries with multiple semantic keywords.
   *
   * @param string $query Query to analyze
   * @return array Result with confidence and matched patterns
   */
  public function calculateConfidence(string $query): array
  {
    if ($this->debug) {
      error_log("\n--- SEMANTIC CONFIDENCE CALCULATION ---");
      error_log("Query: '{$query}'");
    }

    $semanticPatterns = SemanticsPattern::detectSemanticQuery();
    $matchCount = 0;
    $matchedPatterns = [];

    foreach ($semanticPatterns as $pattern => $score) {
      if (preg_match($pattern, $query)) {
        $matchCount++;
        $matchedPatterns[] = [
          'pattern' => substr($pattern, 0, 100),
          'score' => $score
        ];
        
        if ($this->debug) {
          error_log("✓ Semantic pattern matched (score: {$score}): " . substr($pattern, 0, 80) . "...");
        }
      }
    }

    // Calculate confidence based on matches
    // 1 match: 0.85 (semantic keywords are very specific)
    // 2+ matches: 0.9 (extremely confident)
    $confidence = 0.5; // Default
    
    if ($matchCount >= 1) {
      $confidence = 0.85 + (min($matchCount - 1, 1) * 0.05);
    }

    // Adjust for query length
    $wordCount = str_word_count($query);
    if ($wordCount >= 10) {
      $confidence = min(0.95, $confidence + 0.05);
    } elseif ($wordCount <= 2) {
      $confidence = max(0.5, $confidence - 0.2);
    }

    if ($this->debug) {
      error_log("Semantic matches: {$matchCount} patterns");
      error_log("Word count: {$wordCount}");
      error_log("Calculated confidence: {$confidence}");
      error_log("--- END SEMANTIC CONFIDENCE ---\n");
    }

    return [
      'confidence' => round($confidence, 2),
      'match_count' => $matchCount,
      'matched_patterns' => $matchedPatterns,
      'word_count' => $wordCount,
    ];
  }

  /**
   * Check if query requires conversation context
   *
   * Detects contextual references like "it", "this", "that", etc.
   *
   * @param string $query Query to check
   * @return bool True if requires context
   */
  public function requiresConversationContext(string $query): bool
  {
    $contextualPatterns = SemanticsPattern::requiresConversationContext();

    foreach ($contextualPatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Extract semantic metadata from query
   *
   * Extracts semantic-specific metadata like:
   * - Search keywords
   * - Contextual references
   * - Semantic filters
   *
   * @param string $query Query to analyze
   * @return array Semantic metadata
   */
  public function extractMetadata(string $query): array
  {
    $metadata = [
      'semantic_keywords' => [],
      'contextual_references' => [],
      'search_intent' => null,
    ];

    // Extract semantic keywords
    $semanticKeywords = SemanticsPattern::calculateConfidenceSemanticKeywords();
    foreach ($semanticKeywords as $keyword) {
      if (stripos($query, $keyword) !== false) {
        $metadata['semantic_keywords'][] = $keyword;
      }
    }

    // Detect contextual references
    if ($this->requiresConversationContext($query)) {
      $contextPatterns = [
        '/\b(it|this|that|these|those)\b/i' => 'pronoun_reference',
        '/\b(same|similar|like that)\b/i' => 'similarity_reference',
        '/\b(previous|last|earlier)\b/i' => 'temporal_reference',
      ];

      foreach ($contextPatterns as $pattern => $type) {
        if (preg_match($pattern, $query)) {
          $metadata['contextual_references'][] = $type;
        }
      }
    }

    // Detect search intent
    if (preg_match('/\b(find|search|look for|show me|tell me about)\b/i', $query)) {
      $metadata['search_intent'] = 'information_seeking';
    } elseif (preg_match('/\b(recommend|suggest|best|top)\b/i', $query)) {
      $metadata['search_intent'] = 'recommendation';
    } elseif (preg_match('/\b(compare|difference|versus|vs)\b/i', $query)) {
      $metadata['search_intent'] = 'comparison';
    }

    return $metadata;
  }
}
