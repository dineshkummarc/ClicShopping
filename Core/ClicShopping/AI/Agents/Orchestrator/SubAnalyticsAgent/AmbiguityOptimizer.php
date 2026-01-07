<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubAnalyticsAgent;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Domain\Patterns\AmbiguityPreFilter;
use ClicShopping\OM\Cache as OMCache;

/**
 * AmbiguityOptimizer Class
 *
 * Optimizes ambiguity detection and SQL generation performance by:
 * 1. Using English pattern matching before expensive LLM calls
 * 2. Reducing number of interpretations from 3 to 2 (or 1 if clear)
 * 3. Using Memcached/Redis cache if available, otherwise traditional cache
 * 4. Implementing confidence threshold to skip unnecessary interpretations
 *
 * Performance Impact:
 * - Pattern matching: ~0.001s (vs ~1-2s for LLM detection)
 * - 2 interpretations: ~6s (vs ~9s for 3 interpretations)
 * - Cache hit: ~0.5s (vs ~9-14s for full generation)
 * - Total potential gain: ~8-13s per query
 */
class AmbiguityOptimizer
{
  private SecurityLogger $logger;
  private bool $debug;
  private bool $useCache;
  private string $cacheType;

  public function __construct(bool $debug = false)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
    
    // Determine cache type based on configuration
    // MariaDB uses Memcached/Redis if enabled, otherwise traditional cache
    $this->useCache = defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True';
    
    $this->cacheType = $this->detectCacheType();
    
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "AmbiguityOptimizer initialized with cache: {$this->cacheType}",
        'info'
      );
    }
  }

  /**
   * Detect which cache system is available
   * Priority: Memcached > Redis > Traditional
   *
   * @return string Cache type: 'memcached', 'redis', or 'traditional'
   */
  private function detectCacheType(): string
  {
    if (!$this->useCache) {
      return 'none';
    }

    // Check for Memcached
    if (class_exists('Memcached') && extension_loaded('memcached')) {
      try {
        $memcached = new \Memcached();
        $memcached->addServer('localhost', 11211);
        $stats = $memcached->getStats();
        if (!empty($stats)) {
          return 'memcached';
        }
      } catch (\Exception $e) {
        // Memcached not available
      }
    }

    // Check for Redis
    if (class_exists('Redis') && extension_loaded('redis')) {
      try {
        $redis = new \Redis();
        if ($redis->connect('localhost', 6379)) {
          $redis->close();
          return 'redis';
        }
      } catch (\Exception $e) {
        // Redis not available
      }
    }

    // Fall back to traditional cache
    return 'traditional';
  }

  /**
   * Check if query is clearly non-ambiguous using pattern-based pre-filter
   *
   * ⚠️ EXCEPTION TO PURE LLM APPROACH:
   * Uses AmbiguityPreFilter for temporal expressions and quantitative language
   * because LLM is inconsistent (85% success rate). This ensures 100% consistency.
   * 
   * This pre-filter is ALWAYS enabled (not configurable) because it's critical
   * for production consistency.
   *
   * @param string $translatedQuery Query in ENGLISH (already translated)
   * @return array ['is_clear' => bool, 'pattern_type' => string|null]
   */
  public function isClearlyNonAmbiguous(string $translatedQuery): array
  {
    // ⚠️ CRITICAL: Always use AmbiguityPreFilter (EXCEPTION to Pure LLM)
    // This ensures 100% consistency for temporal expressions + quantitative language
    // LLM is only 85% consistent, production requires 95%+
    
    $preFilterResult = AmbiguityPreFilter::preFilter($translatedQuery);
    
    if ($preFilterResult !== null) {
      // Pre-filter determined result - query is clearly NOT ambiguous
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "AmbiguityOptimizer: Pre-filter determined query is NOT ambiguous: {$preFilterResult['reasoning']}",
          'info',
          ['query' => $translatedQuery]
        );
      }
      
      return [
        'is_clear' => true,
        'pattern_type' => 'prefilter',
        'matched_text' => $translatedQuery,
        'reason' => $preFilterResult['reasoning']
      ];
    }
    
    // Pre-filter could not determine - use LLM
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "AmbiguityOptimizer: Pre-filter passed, using LLM for ambiguity detection",
        'info',
        ['query' => $translatedQuery]
      );
    }
    
    return [
      'is_clear' => false,
      'pattern_type' => null,
      'matched_text' => null,
      'reason' => 'prefilter_passed_use_llm'
    ];
  }

  /**
   * Determine optimal number of interpretations based on query complexity
   *
   * Reduces from 3 to 2 interpretations for most queries to improve performance.
   * Only generates 1 interpretation for clearly non-ambiguous queries.
   *
   * @param string $translatedQuery Query in ENGLISH
   * @param array $ambiguityAnalysis Ambiguity analysis from detector
   * @return int Number of interpretations to generate (1 or 2)
   */
  public function getOptimalInterpretationCount(string $translatedQuery, array $ambiguityAnalysis): int
  {
    // Check if clearly non-ambiguous using patterns
    $clearCheck = $this->isClearlyNonAmbiguous($translatedQuery);
    if ($clearCheck['is_clear']) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "AmbiguityOptimizer: Clear pattern detected, using 1 interpretation",
          'info'
        );
      }
      return 1;
    }

    // Check ambiguity type
    $ambiguityType = $ambiguityAnalysis['ambiguity_type'] ?? '';
    $ambiguityTypes = explode('|', $ambiguityType);

    // Default: 2 interpretations (good balance between coverage and performance)
    // We removed the 3-interpretation case to always optimize performance
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "AmbiguityOptimizer: Ambiguous query ({$ambiguityType}), generating 2 interpretations",
        'info'
      );
    }
    return 2;
  }

  /**
   * Select which interpretations to generate based on query analysis
   *
   * Prioritizes the most likely interpretations to avoid generating
   * unnecessary SQL queries. "recent" is deprioritized as it's rarely useful.
   *
   * @param array $ambiguityAnalysis Ambiguity analysis from detector
   * @param int $count Number of interpretations to generate
   * @return array Array of interpretation types to generate
   */
  public function selectInterpretations(array $ambiguityAnalysis, int $count): array
  {
    $availableInterpretations = $ambiguityAnalysis['interpretations'] ?? ['sum', 'count'];
    
    // 🔧 FIX: If interpretations array is empty, use defaults based on ambiguity type
    if (empty($availableInterpretations)) {
      $ambiguityType = $ambiguityAnalysis['ambiguity_type'] ?? '';
      
      // For quantification queries, default to count and sum
      if (strpos($ambiguityType, 'quantification') !== false) {
        $availableInterpretations = ['count', 'sum'];
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "AmbiguityOptimizer: Empty interpretations array, using defaults for quantification: count, sum",
            'info'
          );
        }
      } else {
        // For other ambiguity types, use general defaults
        $availableInterpretations = ['sum', 'count', 'list'];
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "AmbiguityOptimizer: Empty interpretations array, using general defaults: sum, count, list",
            'info'
          );
        }
      }
    }
    
    // Priority order: sum > count > list > recent
    // "recent" is rarely what users want, so it's last
    $priority = ['sum', 'count', 'list', 'recent'];
    
    $selected = [];
    foreach ($priority as $type) {
      if (in_array($type, $availableInterpretations)) {
        $selected[] = $type;
        if (count($selected) >= $count) {
          break;
        }
      }
    }
    
    // Fill remaining with any available interpretations
    foreach ($availableInterpretations as $type) {
      if (!in_array($type, $selected)) {
        $selected[] = $type;
        if (count($selected) >= $count) {
          break;
        }
      }
    }
    
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "AmbiguityOptimizer: Selected {$count} interpretations: " . implode(', ', $selected),
        'info'
      );
    }
    
    return $selected;
  }

 /**
   * Get cached ambiguity analysis
   *
   * Uses Memcached/Redis if available, otherwise traditional cache.
   *
   * @param string $query Query to check
   * @return array|null Cached analysis or null if not found
   */
  public function getCachedAmbiguityAnalysis(string $query): ?array
  {
    if (!$this->useCache) {
      return null;
    }

    $cacheKey = 'ambiguity_' . md5($query);

    try {
      switch ($this->cacheType) {
        case 'memcached':
          return $this->getFromMemcached($cacheKey);
        
        case 'redis':
          return $this->getFromRedis($cacheKey);
        
        case 'traditional':
          return $this->getFromTraditionalCache($cacheKey);
        
        default:
          return null;
      }
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "AmbiguityOptimizer: Cache read error: " . $e->getMessage(),
          'warning'
        );
      }
      return null;
    }
  }

  /**
   * Cache ambiguity analysis
   *
   * @param string $query Query
   * @param array $analysis Analysis to cache
   * @param int $ttl Time to live in seconds (default: 1 hour)
   */
  public function cacheAmbiguityAnalysis(string $query, array $analysis, int $ttl = 3600): void
  {
    if (!$this->useCache) {
      return;
    }

    $cacheKey = 'ambiguity_' . md5($query);

    try {
      switch ($this->cacheType) {
        case 'memcached':
          $this->setInMemcached($cacheKey, $analysis, $ttl);
          break;
        
        case 'redis':
          $this->setInRedis($cacheKey, $analysis, $ttl);
          break;
        
        case 'traditional':
          $this->setInTraditionalCache($cacheKey, $analysis, $ttl);
          break;
      }
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "AmbiguityOptimizer: Cache write error: " . $e->getMessage(),
          'warning'
        );
      }
    }
  }

  /**
   * Get from Memcached
   */
  private function getFromMemcached(string $key): ?array
  {
    $memcached = new \Memcached();
    $memcached->addServer('localhost', 11211);
    $value = $memcached->get($key);
    return $value !== false ? $value : null;
  }

  /**
   * Set in Memcached
   */
  private function setInMemcached(string $key, array $value, int $ttl): void
  {
    $memcached = new \Memcached();
    $memcached->addServer('localhost', 11211);
    $memcached->set($key, $value, $ttl);
  }

  /**
   * Get from Redis
   */
  private function getFromRedis(string $key): ?array
  {
    $redis = new \Redis();
    $redis->connect('localhost', 6379);
    $value = $redis->get($key);
    $redis->close();
    return $value !== false ? json_decode($value, true) : null;
  }

  /**
   * Set in Redis
   */
  private function setInRedis(string $key, array $value, int $ttl): void
  {
    $redis = new \Redis();
    $redis->connect('localhost', 6379);
    $redis->setex($key, $ttl, json_encode($value));
    $redis->close();
  }

  /**
   * Get from traditional cache (OM\Cache)
   */
  private function getFromTraditionalCache(string $key): ?array
  {
    try {
      $cache = new OMCache($key, 'Rag/Ambiguity');
      if ($cache->exists()) {
        return $cache->get();
      }
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Traditional cache get error: {$e->getMessage()}",
          'warning'
        );
      }
    }
    return null;
  }

  /**
   * Set in traditional cache (OM\Cache)
   */
  private function setInTraditionalCache(string $key, array $value, int $ttl): void
  {
    try {
      $cache = new OMCache($key, 'Rag/Ambiguity');
      $cache->save($value);
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Traditional cache set error: {$e->getMessage()}",
          'warning'
        );
      }
    }
  }

  /**
   * Get statistics about optimizer performance
   *
   * @return array Statistics
   */
  public function getStatistics(): array
  {
    return [
      'cache_enabled' => $this->useCache,
      'cache_type' => $this->cacheType,
      'default_interpretation_count' => 2,
      'confidence_threshold' => 0.85,
      'prefilter_enabled' => true, // AmbiguityPreFilter is always enabled (EXCEPTION to Pure LLM)
    ];
  }

  /**
   * Check if query should use confidence threshold optimization
   *
   * If the first interpretation has high confidence, we can skip
   * generating additional interpretations.
   *
   * @param float $firstInterpretationConfidence Confidence of first interpretation (0-1)
   * @return bool True if should skip additional interpretations
   */
  public function shouldUseConfidenceThreshold(float $firstInterpretationConfidence): bool
  {
    $threshold = 0.85; // High confidence threshold

    if ($firstInterpretationConfidence >= $threshold) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "AmbiguityOptimizer: First interpretation has high confidence ({$firstInterpretationConfidence}), skipping others",
          'info'
        );
      }
      return true;
    }

    return false;
  }
}
