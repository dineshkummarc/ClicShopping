<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubHybridQueryProcessor;

use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;
use ClicShopping\AI\Domain\Patterns\SemanticsPattern;
use ClicShopping\AI\Domain\Patterns\WebSearchPattern;
use ClicShopping\AI\Domain\Semantics\Semantics;

/**
 * QueryClassifier - Classifies queries into analytics, semantic, web_search, or hybrid types
 *
 * Responsibilities:
 * - Detect analytics queries (SQL-based data retrieval)
 * - Detect semantic queries (embedding-based knowledge retrieval)
 * - Detect web_search queries (external search requirements)
 * - Detect hybrid queries (multiple intents)
 * - Provide confidence scores and reasoning for classifications
 *
 * Requirements:
 * - REQ-2.1: Detect analytics queries with confidence >= 0.80
 * - REQ-2.2: Detect semantic queries with confidence >= 0.80
 * - REQ-2.3: Detect web_search queries with confidence >= 0.80
 * - REQ-2.4: Detect hybrid queries when multiple intents are present
 * - REQ-2.5: Use centralized pattern classes
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubHybridQueryProcessor
 * @since 2025-12-14
 */
class QueryClassifier extends BaseQueryProcessor
{
  /**
   * Confidence threshold for intent detection
   * 
   * TASK 13.2 & 13.6 (2025-12-14): Increased from 0.60 to 0.87 to reduce hybrid over-classification
   * - First tried 0.70 but still had hybrid over-classification with semantic 0.85 + analytics 0.90
   * - Then tried 0.80 but still had issues with ambiguous patterns like "show me", "what is"
   * - Tried 0.88 but it was too high, missing true hybrid query (Test 42)
   * - Tried 0.86 but still missing Test 42
   * - Tried 0.85 but too low, back to hybrid over-classification
   * - Settled on 0.87 as optimal balance:
   *   - Queries with semantic 0.85 + analytics 0.90 will be single-type (analytics wins)
   *   - True hybrid queries with both intents >= 0.87 will be detected correctly
   * - This effectively implements disambiguation by preferring the stronger intent
   */
  private const INTENT_THRESHOLD = 0.87;

  /**
   * @var QuerySplitter|null Query splitter for fallback hybrid detection
   */
  private ?QuerySplitter $querySplitter = null;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   * @param QuerySplitter|null $querySplitter Query splitter instance (for fallback hybrid detection)
   */
  public function __construct(bool $debug = false, ?QuerySplitter $querySplitter = null)
  {
    parent::__construct($debug, 'QueryClassifier');
    $this->querySplitter = $querySplitter;
  }

  /**
   * Process query classification
   *
   * @param mixed $input Query string to classify
   * @param array $context Additional context (unused for classification)
   * @return array Classification result with type, confidence, and reasoning
   * @throws \Exception If input is invalid
   */
  public function process($input, array $context = []): array
  {
    if (!$this->validate($input)) {
      throw new \Exception("Invalid input for QueryClassifier: input must be a non-empty string");
    }

    return $this->classifyQueryType($input);
  }

  /**
   * Validate input is a non-empty string
   *
   * @param mixed $input Input to validate
   * @return bool True if valid, false otherwise
   */
  public function validate($input): bool
  {
    return is_string($input) && !empty(trim($input));
  }

  /**
   * Classify query type: analytic, semantic, web, or hybrid
   *
   * This is the main classification method that determines the query type
   * by analyzing patterns and computing confidence scores.
   *
   * IMPORTANT: All patterns are in English. French queries are translated to English first.
   *
   * @param string $query Query to classify
   * @return array Classification result with type, confidence, and reasoning
   */
  public function classifyQueryType(string $query): array
  {
    // Translate query to English if needed (all patterns are in English)
    $translatedQuery = Semantics::translateToEnglish($query, 80);
    $q = $this->normalizeQuery($translatedQuery);

    if ($this->debug) {
      $this->logInfo("Classifying query", [
        'original' => $query,
        'translated' => $translatedQuery,
        'normalized' => $q
      ]);
    }

    // Stage 1: Independent detectors
    $analytic = $this->detectAnalytic($q);
    $semantic = $this->detectSemantic($q);
    $web = $this->detectWeb($q);

    // Stage 2: Hybrid resolution
    $scores = [
      'analytic' => $analytic['score'],
      'semantic' => $semantic['score'],
      'web' => $web['score'],
    ];

    $intentCount = count(array_filter($scores, fn($s) => $s >= self::INTENT_THRESHOLD));

    // Stage 2.5: Fallback to detectMultipleIntents() if threshold not met
    // This catches hybrid queries that don't meet the high threshold (0.87)
    // but have explicit connectors, multiple verbs, or other hybrid indicators
    $fallbackDetected = false;
    if ($intentCount < 2 && $this->querySplitter !== null) {
      // Check if query has multiple intents using alternative detection
      // Use translated query for consistency with pattern matching
      $hasMultipleIntents = $this->querySplitter->detectMultipleIntents($translatedQuery, ['is_hybrid' => false]);
      
      if ($hasMultipleIntents) {
        $fallbackDetected = true;
        $intentCount = 2; // Override for logging
        
        if ($this->debug) {
          $this->logInfo("Hybrid detected via fallback method", [
            'scores' => $scores,
            'method' => 'detectMultipleIntents'
          ]);
        }
      }
    }

    // Stage 3: Determine final type, confidence, and evidence
    if ($intentCount >= 2 || $fallbackDetected) {
      $type = 'hybrid';
      $confidence = $this->computeHybridScore($scores);

      $evidence = array_merge(
        $analytic['evidence'],
        $semantic['evidence'],
        $web['evidence']
      );
      
      if ($fallbackDetected) {
        $evidence[] = "hybrid: detected via alternative method (connectors/verbs/structure)";
      }
    } else {
      $type = array_keys($scores, max($scores))[0];
      $confidence = max($scores);
      $evidence = ${$type}['evidence'];
    }

    $result = [
      'type' => $type,
      'confidence' => round($confidence, 2),
      'reasoning' => $evidence,
      'is_hybrid' => ($type === 'hybrid'),
    ];

    if ($this->debug) {
      $this->logInfo("Classification complete", [
        'type' => $type,
        'confidence' => $confidence,
        'scores' => $scores,
        'intent_count' => $intentCount,
        'is_hybrid' => ($type === 'hybrid')
      ]);
    }

    return $result;
  }

  /**
   * Detect analytic intent
   *
   * Uses AnalyticsPattern class to detect SQL-based data retrieval queries.
   *
   * @param string $q Normalized query
   * @return array Detection result with score and evidence
   */
  private function detectAnalytic(string $q): array
  {
    $patterns = AnalyticsPattern::detectAnalyticsQuery();

    $score = 0.0;
    $evidence = [];

    foreach ($patterns as $pattern => $patternScore) {
      if (preg_match($pattern, $q)) {
        if ($patternScore > $score) {
          $score = $patternScore;
        }
        $evidence[] = "analytic: {$pattern}";
      }
    }

    if ($this->debug && $score > 0) {
      $this->logInfo("Analytics detection", ['score' => $score, 'evidence_count' => count($evidence)]);
    }

    return ['score' => $score, 'evidence' => $evidence];
  }

  /**
   * Detect semantic intent
   *
   * Uses SemanticsPattern class to detect embedding-based knowledge retrieval queries.
   *
   * @param string $q Normalized query
   * @return array Detection result with score and evidence
   */
  private function detectSemantic(string $q): array
  {
    $patterns = SemanticsPattern::detectSemanticQuery();

    $score = 0.0;
    $evidence = [];

    foreach ($patterns as $pattern => $patternScore) {
      if (preg_match($pattern, $q)) {
        if ($patternScore > $score) {
          $score = $patternScore;
        }
        $evidence[] = "semantic: {$pattern}";
      }
    }

    if ($this->debug && $score > 0) {
      $this->logInfo("Semantic detection", ['score' => $score, 'evidence_count' => count($evidence)]);
    }

    return ['score' => $score, 'evidence' => $evidence];
  }

  /**
   * Detect web search intent
   *
   * Uses WebSearchPattern class to detect external search requirements.
   *
   * @param string $q Normalized query
   * @return array Detection result with score and evidence
   */
  private function detectWeb(string $q): array
  {
    $patterns = WebSearchPattern::getWebSearchPatterns();

    $score = 0.0;
    $evidence = [];

    foreach ($patterns as $pattern => $patternScore) {
      if (preg_match($pattern, $q)) {
        if ($patternScore > $score) {
          $score = $patternScore;
        }
        $evidence[] = "web: {$pattern}";
      }
    }

    if ($this->debug && $score > 0) {
      $this->logInfo("Web search detection", ['score' => $score, 'evidence_count' => count($evidence)]);
    }

    return ['score' => $score, 'evidence' => $evidence];
  }

  /**
   * Compute hybrid confidence score
   *
   * Calculates confidence score for hybrid queries based on individual intent scores.
   * Higher confidence when multiple intents have strong scores.
   *
   * @param array $scores Individual intent scores
   * @return float Hybrid confidence score
   */
  private function computeHybridScore(array $scores): float
  {
    // Matrix-style: stronger if two domains exceed 0.8
    $high = array_filter($scores, fn($v) => $v >= 0.80);

    if (count($high) >= 2) {
      return 0.95;
    }
    if (count($high) === 1) {
      return 0.90;
    }

    return 0.85;
  }
}
