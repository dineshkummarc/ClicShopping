<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Query;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;
use ClicShopping\AI\Domain\Patterns\HybridPattern;
use ClicShopping\AI\Domain\Patterns\HybridKeywords;
use ClicShopping\AI\Domain\Patterns\SemanticsPattern;
use ClicShopping\AI\Domain\Patterns\WebSearchPattern;
use ClicShopping\AI\Domain\Semantics\SubSemantics\ClassificationEngine;

/**
 * QueryClassifier Class
 *
 * Centralized query classification logic to ensure consistency across all components.
 * This class is used by:
 * - IntentAnalyzer
 * - HybridQueryProcessor
 * - AnalyticsAgent
 * - Semantics
 *
 * All classification logic should be maintained here to avoid duplication and inconsistencies.
 */
class QueryClassifier
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
   * Classify a query into one of: analytics, semantic, web_search, hybrid
   * 
   * 🔧 TASK 4.3 (2025-12-11): Enhanced fallback logic
   * 
   * FALLBACK STRATEGY:
   * - Default type: 'semantic' (safer than 'analytics')
   * - If no patterns match with high confidence, use LLM fallback
   * - If LLM fails, default to 'semantic'
   * 
   * RATIONALE:
   * - Semantic queries are safer to misclassify (RAG search is more forgiving)
   * - Analytics queries require precise SQL generation (higher risk if wrong)
   * - Web search queries are rare and have very specific patterns
   *
   * @param string $query Query to classify (can be in any language)
   * @param string|null $translatedQuery Optional pre-translated query (English)
   * @return array Classification result with type, confidence, and reasoning
   */
  public function classify(string $query, ?string $translatedQuery = null): array
  {
    $queryLower = strtolower($query);
    $translatedLower = $translatedQuery ? strtolower($translatedQuery) : $queryLower;
    
    $confidence = 0.5; // Base confidence
    $type = 'semantic'; // 🔧 TASK 4.3: Default to semantic (safer fallback)
    $reasoning = [];

    // ============================================
    // STEP 0: DETECT HYBRID QUERIES FIRST
    // ============================================
    $hybridResult = $this->detectHybridQuery($query, $translatedQuery);
    if ($hybridResult['is_hybrid']) {
      return [
        'type' => 'hybrid',
        'confidence' => $hybridResult['confidence'],
        'reasoning' => $hybridResult['reasoning'],
      ];
    }

    // ============================================
    // STEP 1: ANALYTICS PATTERNS (HIGHEST PRIORITY)
    // ============================================
    $analyticsResult = $this->detectAnalyticsQuery($query, $translatedQuery);
    if ($analyticsResult['is_analytics']) {
      return [
        'type' => 'analytics',
        'confidence' => $analyticsResult['confidence'],
        'reasoning' => $analyticsResult['reasoning'],
      ];
    }

    // ============================================
    // STEP 2: WEB SEARCH PATTERNS
    // ============================================
    $webSearchResult = $this->detectWebSearchQuery($query, $translatedQuery);
    if ($webSearchResult['is_web_search']) {
      return [
        'type' => 'web_search',
        'confidence' => $webSearchResult['confidence'],
        'reasoning' => $webSearchResult['reasoning'],
      ];
    }

    // ============================================
    // STEP 3: SEMANTIC PATTERNS
    // ============================================
    $semanticResult = $this->detectSemanticQuery($query, $translatedQuery);
    
    // ============================================
    // STEP 4: LLM FALLBACK (if confidence is low)
    // ============================================
    // If no pattern matched with high confidence, use LLM to analyze
    if ($semanticResult['confidence'] <= 0.5) {
      if ($this->debug) {
        $this->logger->logSecurityEvent("No pattern matched with high confidence, using LLM fallback", 'info');
      }
      
      $llmResult = $this->classifyWithLLM($query, $translatedQuery);
      
      // If LLM has higher confidence, use its classification
      if ($llmResult['confidence'] > $semanticResult['confidence']) {
        return $llmResult;
      }
    }
    
    return [
      'type' => 'semantic',
      'confidence' => $semanticResult['confidence'],
      'reasoning' => $semanticResult['reasoning'],
    ];
  }

  /**
   * Detect hybrid queries (multiple intents)
   *
   * TASK 2.15: Added analytics + analytics detection with French connectors
   * TASK 4.5.6 FIX: Enhanced to detect queries with BOTH analytics AND semantic keywords
   * 
   * ALGORITHM:
   * 1. Check explicit hybrid patterns (with connectors like "and", "et")
   * 2. Check for presence of BOTH analytics AND semantic keywords
   * 3. Check for presence of BOTH analytics AND web_search keywords
   * 4. Check for "our/my X vs competitors" pattern (internal + external)
   *
   * @param string $query Original query
   * @param string|null $translatedQuery Translated query
   * @return array Result with is_hybrid, confidence, reasoning
   */
  private function detectHybridQuery(string $query, ?string $translatedQuery): array
  {
    $queryToCheck = $translatedQuery ?? $query;
    $queryLower = strtolower($queryToCheck);
    
    // STEP 1: Check explicit hybrid patterns (with connectors)
    $patterns = HybridPattern::detectHybridQuery();

    foreach ($patterns as $pattern => $score) {
      if (preg_match($pattern, $query) || ($translatedQuery && preg_match($pattern, $translatedQuery))) {
        return [
          'is_hybrid' => true,
          'confidence' => $score,
          'reasoning' => ["Matched hybrid pattern with connector: {$pattern}"],
        ];
      }
    }
    
    // STEP 2: Check for BOTH analytics AND semantic keywords (without connector)
    // This catches queries like "sales with summary", "list products and their warranty"
    // REFACTORED (2025-12-11): Use centralized HybridKeywords class (English only)
    $hasAnalytics = false;
    $hasSemantic = false;
    $hasWebSearch = false;
    
    // Get keywords from centralized class (ALL ENGLISH ONLY)
    $analyticsKeywords = HybridKeywords::getAnalyticsKeywords();
    $semanticKeywords = HybridKeywords::getSemanticKeywords();
    $webSearchKeywords = HybridKeywords::getWebSearchKeywords();
    
    // Check for analytics keywords
    foreach ($analyticsKeywords as $keyword) {
      if (stripos($queryLower, $keyword) !== false) {
        $hasAnalytics = true;
        break;
      }
    }
    
    // Check for semantic keywords
    foreach ($semanticKeywords as $keyword) {
      if (stripos($queryLower, $keyword) !== false) {
        $hasSemantic = true;
        break;
      }
    }
    
    // Check for web search keywords
    foreach ($webSearchKeywords as $keyword) {
      if (stripos($queryLower, $keyword) !== false) {
        $hasWebSearch = true;
        break;
      }
    }
    
    // STEP 3: Determine if hybrid based on keyword combinations
    // BUT: Only classify as hybrid if there's a clear intent to combine multiple data sources
    // Don't classify as hybrid if one intent is clearly dominant
    // REFACTORED (2025-12-11): Use centralized HybridKeywords class
    
    // Check for strong web search indicators (these should NOT be hybrid)
    $strongWebSearchIndicators = HybridKeywords::getStrongWebSearchIndicators();
    
    $hasStrongWebSearch = false;
    foreach ($strongWebSearchIndicators as $pattern) {
      if (preg_match($pattern, $queryLower)) {
        $hasStrongWebSearch = true;
        break;
      }
    }
    
    // If web search is dominant, don't classify as hybrid
    // (e.g., "compare price with competitors" is web_search, not hybrid)
    if ($hasStrongWebSearch && !$hasSemantic) {
      // Let web_search detection handle this
      return [
        'is_hybrid' => false,
        'confidence' => 0,
        'reasoning' => []
      ];
    }
    
    // Analytics + Semantic = Hybrid (e.g., "sales with summary", "stock and policy")
    // BUT: Check if "and" is part of a common phrase (e.g., "terms and conditions")
    // REFACTORED (2025-12-11): Use centralized HybridKeywords class
    if ($hasAnalytics && $hasSemantic) {
      // Check for common phrases where "and" is NOT a connector
      $commonPhrases = HybridKeywords::getCommonPhrasePatterns();
      
      $hasCommonPhrase = false;
      foreach ($commonPhrases as $phrase) {
        if (preg_match($phrase, $queryLower)) {
          $hasCommonPhrase = true;
          break;
        }
      }
      
      // If it's just a common phrase, not hybrid
      // Check if query has actual analytics keywords (not just semantic)
      $hasActualAnalytics = false;
      foreach (['stock', 'inventory', 'sales', 'revenue', 'data'] as $keyword) {
        if (stripos($queryLower, $keyword) !== false) {
          $hasActualAnalytics = true;
          break;
        }
      }
      
      if ($hasCommonPhrase && !$hasActualAnalytics) {
        // Pure semantic query about policies/terms
        return [
          'is_hybrid' => false,
          'confidence' => 0,
          'reasoning' => []
        ];
      }
      
      return [
        'is_hybrid' => true,
        'confidence' => 0.85,
        'reasoning' => ["Query contains BOTH analytics and semantic keywords"],
      ];
    }
    
    // Analytics + Web Search = Hybrid ONLY if there's a clear connector or internal reference
    // (e.g., "our stock vs competitors", "show revenue and compare with market")
    // REFACTORED (2025-12-11): Use centralized HybridKeywords class
    if ($hasAnalytics && $hasWebSearch) {
      // Check for connectors or internal reference
      $connectorPatterns = HybridKeywords::getConnectorPatterns();
      $internalRefPatterns = HybridKeywords::getInternalReferencePatterns();
      
      $hasConnector = false;
      foreach ($connectorPatterns as $pattern) {
        if (preg_match($pattern, $queryLower)) {
          $hasConnector = true;
          break;
        }
      }
      
      $hasInternalRef = false;
      foreach ($internalRefPatterns as $pattern) {
        if (preg_match($pattern, $queryLower)) {
          $hasInternalRef = true;
          break;
        }
      }
      
      if ($hasConnector || $hasInternalRef) {
        return [
          'is_hybrid' => true,
          'confidence' => 0.85,
          'reasoning' => ["Query combines internal analytics with external web search"],
        ];
      }
      
      // Otherwise, let web_search detection handle it
      return [
        'is_hybrid' => false,
        'confidence' => 0,
        'reasoning' => []
      ];
    }
    
    // Semantic + Web Search = Hybrid (e.g., "explain our policy vs competitors")
    // REFACTORED (2025-12-11): Use centralized HybridKeywords class
    if ($hasSemantic && $hasWebSearch) {
      // Check for internal reference (our, my, the)
      $internalRefPatterns = HybridKeywords::getInternalReferencePatterns();
      
      $hasInternalRef = false;
      foreach ($internalRefPatterns as $pattern) {
        if (preg_match($pattern, $queryLower)) {
          $hasInternalRef = true;
          break;
        }
      }
      
      if ($hasInternalRef) {
        return [
          'is_hybrid' => true,
          'confidence' => 0.85,
          'reasoning' => ["Query compares internal information with external competitors"],
        ];
      }
      
      // Otherwise, let web_search detection handle it
      return [
        'is_hybrid' => false,
        'confidence' => 0,
        'reasoning' => []
      ];
    }
    
    // STEP 4: Check for "our/my X vs competitors" pattern (internal + external)
    // REFACTORED (2025-12-11): Use centralized pattern
    if (preg_match('/\b(our|my|the)\b.*\b(vs|versus|compared to|against)\b.*\b(competitor|competitors|competition)\b/i', $queryLower)) {
      return [
        'is_hybrid' => true,
        'confidence' => 0.85,
        'reasoning' => ["Query compares internal data with external competitors"],
      ];
    }

    $array = [
      'is_hybrid' => false,
      'confidence' => 0,
      'reasoning' => []
    ];

    return $array;
  }

  /**
   * Detect analytics queries (database/SQL queries)
   * 
   * TASK 4.5.7 FIX: Exclude queries with web_search keywords (competitors, market, online)
   * to prevent misclassification of external queries as analytics
   * 
   * REFACTORED (2025-12-11): Use centralized HybridKeywords class for exclusions
   *
   * @param string $query Original query
   * @param string|null $translatedQuery Translated query
   * @return array Result with is_analytics, confidence, reasoning
   */
  public function detectAnalyticsQuery(string $query, ?string $translatedQuery): array
  {
    $queryToCheck = $translatedQuery ?? $query;
    $queryLower = strtolower($queryToCheck);
    
    // TASK 4.5.7 FIX: Check for web_search keywords first
    // If query has "competitors", "market", "online" etc., it's likely web_search, not analytics
    // REFACTORED (2025-12-11): Use centralized HybridKeywords class
    $webSearchExclusions = HybridKeywords::getWebSearchExclusionPatterns();
    
    foreach ($webSearchExclusions as $exclusion) {
      if (preg_match($exclusion, $queryLower)) {
        // This is likely a web_search query, not analytics
        return ['is_analytics' => false, 'confidence' => 0, 'reasoning' => []];
      }
    }
    
    $patterns = AnalyticsPattern::detectAnalyticsQuery();

    foreach ($patterns as $pattern => $score) {
      if (preg_match($pattern, $query) || ($translatedQuery && preg_match($pattern, $translatedQuery))) {
        return [
          'is_analytics' => true,
          'confidence' => $score,
          'reasoning' => ["Matched analytics pattern: {$pattern}"],
        ];
      }
    }

    return ['is_analytics' => false, 'confidence' => 0, 'reasoning' => []];
  }

  /**
   * Detect web search queries (external search)
   *
   * @param string $query Original query
   * @param string|null $translatedQuery Translated query
   * @return array Result with is_web_search, confidence, reasoning
   */
  private function detectWebSearchQuery(string $query, ?string $translatedQuery): array
  {
    $patterns = WebSearchPattern::getWebSearchPatterns();

    foreach ($patterns as $pattern => $score) {
      if (preg_match($pattern, $query) || ($translatedQuery && preg_match($pattern, $translatedQuery))) {
        return [
          'is_web_search' => true,
          'confidence' => $score,
          'reasoning' => ["Matched web search pattern: {$pattern}"],
        ];
      }
    }

    return ['is_web_search' => false, 'confidence' => 0, 'reasoning' => []];
  }

  /**
   * Detect semantic queries (knowledge base/RAG queries)
   *
   * @param string $query Original query
   * @param string|null $translatedQuery Translated query
   * @return array Result with confidence and reasoning
   */
  private function detectSemanticQuery(string $query, ?string $translatedQuery): array
  {
    $patterns = SemanticsPattern::detectSemanticQuery();

    $confidence = 0.5; // Default semantic confidence
    $reasoning = ['Default classification: semantic'];

    foreach ($patterns as $pattern => $score) {
      if (preg_match($pattern, $query) || ($translatedQuery && preg_match($pattern, $translatedQuery))) {
        $confidence = $score;
        $reasoning = ["Matched semantic pattern: {$pattern}"];
        break;
      }
    }

    return [
      'confidence' => $confidence,
      'reasoning' => $reasoning,
    ];
  }

  /**
   * Quick check if query is analytics (for backward compatibility)
   *
   * @param string $query Query to check
   * @return bool True if analytics
   */
  public function isAnalytics(string $query): bool
  {
    $result = $this->classify($query);
    return $result['type'] === 'analytics';
  }

  /**
   * Quick check if query is semantic (for backward compatibility)
   *
   * @param string $query Query to check
   * @return bool True if semantic
   */
  public function isSemantic(string $query): bool
  {
    $result = $this->classify($query);
    return $result['type'] === 'semantic';
  }

  /**
   * Quick check if query is web search (for backward compatibility)
   *
   * @param string $query Query to check
   * @return bool True if web search
   */
  public function isWebSearch(string $query): bool
  {
    $result = $this->classify($query);
    return $result['type'] === 'web_search';
  }

  /**
   * Quick check if query is hybrid (for backward compatibility)
   *
   * @param string $query Query to check
   * @return bool True if hybrid
   */
  public function isHybrid(string $query): bool
  {
    $result = $this->classify($query);
    return $result['type'] === 'hybrid';
  }

  /**
   * Classify query using LLM fallback when patterns fail
   * 
   * 🔧 TASK 4.5.5 (2025-12-11): Updated to handle new array return format from checkSemantics
   * 
   * This method uses ClassificationEngine::checkSemantics() which calls the LLM
   * to determine if a query is 'analytics', 'semantic', 'hybrid', or 'web_search'.
   * 
   * @param string $query Original query
   * @param string|null $translatedQuery Translated query (English)
   * @return array Classification result with type, confidence, reasoning
   */
  private function classifyWithLLM(string $query, ?string $translatedQuery): array
  {
    // Use translated query if available, otherwise use original
    $queryToClassify = $translatedQuery ?? $query;
    
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Using LLM fallback for classification: {$queryToClassify}",
        'info'
      );
    }
    
    try {
      // Use ClassificationEngine::checkSemantics() which returns array with type, confidence, reasoning
      $classificationResult = ClassificationEngine::checkSemantics($queryToClassify);
      
      // Extract type
      $type = $classificationResult['type'] ?? 'semantic';
      
      // Validate response (now supports 4 categories)
      $validTypes = ['analytics', 'semantic', 'hybrid', 'web_search'];
      if (!in_array($type, $validTypes)) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "LLM returned invalid type '{$type}', defaulting to semantic",
            'warning'
          );
        }
        $type = 'semantic';
        $classificationResult['confidence'] = 0.5;
      }
      
      // Log classification with confidence and reasoning
      if ($this->debug) {
        $this->logger->logStructured(
          'info',
          'QueryClassifier',
          'llm_classification',
          [
            'query' => $queryToClassify,
            'type' => $type,
            'confidence' => $classificationResult['confidence'] ?? 0.7,
            'reasoning' => $classificationResult['reasoning'] ?? 'N/A',
            'sub_types' => $classificationResult['sub_types'] ?? []
          ]
        );
      }
      
      return [
        'type' => $type,
        'confidence' => $classificationResult['confidence'] ?? 0.7,
        'reasoning' => [$classificationResult['reasoning'] ?? 'LLM classification fallback'],
        'sub_types' => $classificationResult['sub_types'] ?? []
      ];
      
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "LLM fallback failed: {$e->getMessage()}",
          'error'
        );
      }
      
      // Ultimate fallback: semantic
      return [
        'type' => 'semantic',
        'confidence' => 0.5,
        'reasoning' => ['LLM fallback failed, defaulting to semantic'],
      ];
    }
  }
}
