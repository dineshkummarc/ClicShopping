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
use ClicShopping\AI\Domain\Semantics\SubSemantics\ClassificationEngine;
use ClicShopping\AI\Infrastructure\Cache\ClassificationCache;
use ClicShopping\OM\Registry;

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
 * 
 * TASK 2.10.2 (2025-12-26): Added ClassificationCache integration for performance optimization
 */
class QueryClassifier
{
  private SecurityLogger $logger;
  private bool $debug;
  private ?ClassificationCache $classificationCache = null;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
    
    // TASK 2.10.2: Initialize ClassificationCache
    if (!Registry::exists('ClassificationCache')) {
      Registry::set('ClassificationCache', new ClassificationCache(2592000, $this->debug));
    }
    $this->classificationCache = Registry::get('ClassificationCache');
    
    if ($this->debug) {
      $this->logger->logSecurityEvent('ClassificationCache initialized', 'info');
      $this->logger->logSecurityEvent('Pure LLM mode active - all classification via LLM', 'info');
    }
  }

  /**
   * Classify a query into one of: analytics, semantic, web_search, hybrid
   * 
   * 🔧 TASK 4.3 (2025-12-11): Enhanced fallback logic
   * 🔧 TASK 2026-01-02: Added optional HybridPreFilter for hybrid query detection
   * 
   * CLASSIFICATION FLOW:
   * 1. If USE_HYBRID_PRE_FILTER='True' and translatedQuery provided:
   *    - Try HybridPreFilter (pattern-based, English-only)
   *    - If hybrid detected: Return hybrid classification (90% confidence)
   *    - If no match: Fall through to LLM classification
   * 2. Use LLM classification for all other cases
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
   * - HybridPreFilter solves LLM weakness (ignoring second intent in hybrid queries)
   *
   * @param string $query Query to classify (can be in any language)
   * @param string|null $translatedQuery Optional pre-translated query (English)
   * @return array Classification result with type, confidence, and reasoning
   */
  public function classify(string $query, ?string $translatedQuery = null): array
  {
    // Check if HybridPreFilter is enabled
    $useHybridPreFilter = (defined('USE_HYBRID_PRE_FILTER') && USE_HYBRID_PRE_FILTER === 'True');
    
    // Try HybridPreFilter if enabled and translated query available
    if ($useHybridPreFilter && $translatedQuery !== null) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          'Trying HybridPreFilter for hybrid detection',
          'info'
        );
      }
      
      // Use HybridPreFilter (pattern-based, English-only)
      $hybridCheck = \ClicShopping\AI\Domain\Patterns\HybridPreFilter::preFilter($translatedQuery);
      
      if ($hybridCheck !== null) {
        // Pattern detected hybrid query
        if ($this->debug) {
          $this->logger->logStructured(
            'info',
            'QueryClassifier',
            'hybrid_pre_filter_match',
            [
              'query' => substr($translatedQuery, 0, 50),
              'type' => 'hybrid',
              'sub_types' => $hybridCheck['sub_types'] ?? [],
              'confidence' => $hybridCheck['confidence'] ?? 0.90,
              'detection_method' => 'pattern_pre_filter',
              'note' => 'HybridPreFilter detected hybrid query (no LLM call)'
            ]
          );
        }
        
        return $hybridCheck;
      }
      
      // Pattern didn't match - fall through to LLM
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          'HybridPreFilter no match - falling back to LLM',
          'info'
        );
      }
    }
    
    // Pure LLM mode or HybridPreFilter didn't match
    if ($this->debug) {
      $mode = $useHybridPreFilter ? 'LLM fallback (HybridPreFilter no match)' : 'Pure LLM mode';
      $this->logger->logSecurityEvent("$mode - using LLM for classification", 'info');
    }
    
    $result = $this->classifyWithLLM($query, $translatedQuery);
    $result['detection_method'] = 'llm';
    return $result;
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
   * 🔧 TASK 2.10.2 (2025-12-26): Added ClassificationCache integration for performance optimization
   * 
   * This method uses ClassificationEngine::checkSemantics() which calls the LLM
   * to determine if a query is 'analytics', 'semantic', 'hybrid', or 'web_search'.
   * 
   * CACHE FLOW:
   * 1. Check ClassificationCache for cached result
   * 2. If cache HIT: Return cached result (instant, ~1ms)
   * 3. If cache MISS: Call LLM via ClassificationEngine
   * 4. Store LLM result in cache for future queries
   * 5. Return classification result
   * 
   * @param string $query Original query
   * @param string|null $translatedQuery Translated query (English)
   * @return array Classification result with type, confidence, reasoning
   */
  private function classifyWithLLM(string $query, ?string $translatedQuery): array
  {
    // Use translated query if available, otherwise use original
    $queryToClassify = $translatedQuery ?? $query;
    
    // TASK 2.10.2: Check cache first
    if ($this->classificationCache !== null) {
      $cached = $this->classificationCache->getCachedClassification($query, $translatedQuery);
      
      if ($cached !== null) {
        if ($this->debug) {
          $this->logger->logStructured(
            'info',
            'QueryClassifier',
            'classification_cache_hit',
            [
              'query' => substr($queryToClassify, 0, 50),
              'type' => $cached['type'],
              'confidence' => $cached['confidence'],
              'cache_age' => $cached['cache_age'] ?? 'unknown',
              'note' => 'Returned from cache (no LLM call)'
            ]
          );
        }
        
        // Return cached result
        return $cached;
      }
      
      // Cache miss - log and proceed to LLM call
      if ($this->debug) {
        $this->logger->logStructured(
          'info',
          'QueryClassifier',
          'classification_cache_miss',
          [
            'query' => substr($queryToClassify, 0, 50),
            'note' => 'Cache miss - calling LLM'
          ]
        );
      }
    }
    
    // Original LLM classification logic
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
      
      // Prepare result
      $result = [
        'type' => $type,
        'confidence' => $classificationResult['confidence'] ?? 0.7,
        'reasoning' => [$classificationResult['reasoning'] ?? 'LLM classification fallback'],
        'sub_types' => $classificationResult['sub_types'] ?? []
      ];
      
      // TASK 2.10.2: Cache the result for future queries
      if ($this->classificationCache !== null) {
        $this->classificationCache->cacheClassification($query, $translatedQuery, $result);
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
            'sub_types' => $classificationResult['sub_types'] ?? [],
            'cached' => 'yes'
          ]
        );
      }
      
      return $result;
      
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
