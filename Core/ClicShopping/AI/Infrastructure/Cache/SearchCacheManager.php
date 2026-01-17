<?php
/**
 * SearchCacheManager - Intelligent Cache Manager for Web Search Results
 * 
 * Manages intelligent caching (Learning RAG) for web search results.
 * Stores high-quality results in vector store with fast RagCache layer.
 * 
 * Features:
 * - Two-tier caching: RagCache (fast memory) + Vector Store (persistent)
 * - Quality-based storage (only cache high-quality results)
 * - Self-healing through learning from past searches
 * - Automatic cache invalidation and cleanup
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\AI\Infrastructure\Cache;

use AllowDynamicProperties;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\AI\Infrastructure\Storage\MariaDBVectorStore;
use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;

/**
 * SearchCacheManager Class
 * 
 * Manages intelligent caching for web search results with two-tier architecture:
 * 1. RagCache (Redis/Memcached): Fast memory-based cache (<1ms access)
 * 2. Vector Store (MariaDB): Persistent semantic search cache (10-50ms access)
 * 
 * Cache Flow:
 * - Read: RagCache → Vector Store → Web Search API
 * - Write: Web Search API → RagCache + Vector Store
 * 
 * Performance:
 * - RagCache hit: <1ms (90-98% faster than vector store)
 * - Vector Store hit: 10-50ms (still faster than API call)
 * - API call: 500-2000ms (fallback when no cache)
 */
#[AllowDynamicProperties]
class SearchCacheManager
{
  private const CACHE_KEY_PREFIX = 'web_search:';
  private const DEFAULT_CACHE_TTL = 3600; // 1 hour (fallback if MEMCACHED_CACHE_LIFETIME not defined)
  
  private MariaDBVectorStore $vectorStore;
  private ?RagCache $ragCache = null;
  private $embeddingGenerator;
  private SecurityLogger $logger;
  private bool $debug;
  private bool $enabled;
  private string $tableName;
  private int $cacheTTL;

  // Configuration
  private int $maxChunkSize = 800;
  private int $chunkOverlap = 50;
  private float $qualityThreshold = 0.7;

  /**
   * Constructor
   *
   * @param string $tableName Table for cache (default: rag_web_cache_embedding)
   * @param bool $forceEnable Force enable cache regardless of configuration
   */
  public function __construct(string $tableName = 'rag_web_cache_embedding', bool $forceEnable = false)
  {
    $this->logger = new SecurityLogger();
    
    // Check if cache is enabled
    $this->enabled = $forceEnable || 
      (!defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER') || 
       CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True');
    
    $this->debug = \defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && 
                   CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';
    
    if (!$this->enabled) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "SearchCacheManager: Cache disabled (CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER = False)",
          'info'
        );
      }
      // Set minimal initialization when disabled
      $this->cacheTTL = self::DEFAULT_CACHE_TTL;
      $this->tableName = '';
      $this->vectorStore = null;
      $this->ragCache = null;
      $this->embeddingGenerator = null;
      return;
    }
    
    // Get cache TTL from configuration (same as RagCache)
    $this->cacheTTL = \defined('MEMCACHED_CACHE_LIFETIME') ? 
                      (int)MEMCACHED_CACHE_LIFETIME : 
                      self::DEFAULT_CACHE_TTL;
    
    if ($this->cacheTTL <= 0) {
      $this->cacheTTL = self::DEFAULT_CACHE_TTL;
    }
    
    $this->embeddingGenerator = NewVector::gptEmbeddingsModel();

    // Initialize the vector store
    $prefix = CLICSHOPPING::getConfig('db_table_prefix');
    $fullTableName = "{$prefix}{$tableName}";
    $this->tableName = $fullTableName;

    $this->vectorStore = new MariaDBVectorStore($this->embeddingGenerator, $fullTableName);

    // Initialize RagCache for fast memory-based caching
    try {
      $this->ragCache = new RagCache(true); // Force enable
      
      if ($this->debug) {
        $ragStats = $this->ragCache->getStats();
        $this->logger->logSecurityEvent(
          "SearchCacheManager: RagCache initialized (backend: {$ragStats['backend']})",
          'info'
        );
      }
    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "SearchCacheManager: RagCache initialization failed: " . $e->getMessage(),
        'warning'
      );
      $this->ragCache = null;
    }

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "SearchCacheManager initialized with table: {$fullTableName}",
        'info'
      );
    }
  }

  /**
   * Return the table name used for storage
   *
   * @return string Table name
   */
  public function getTableName(): string
  {
    return $this->tableName;
  }

  /**
   * Store web search results in Learning RAG
   * 
   * Stores results in both RagCache (fast) and Vector Store (persistent).
   * Only stores high-quality results based on quality threshold.
   *
   * @param string $query Original query
   * @param array $results Formatted results from WebSearchTool
   * @param array $metadata Additional metadata
   * @return bool True if successful
   */
  public function storeInLearningRAG(string $query, array $results, array $metadata = []): bool
  {
    // Check if cache is enabled
    if (!$this->enabled) {
      return false;
    }
    
    // Check if vector store is available
    if ($this->vectorStore === null) {
      return false;
    }
    
    try {
      // 1. Check quality (only store good results)
      $qualityScore = $this->calculateQualityScore($results);

      if ($qualityScore < $this->qualityThreshold) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Quality too low ({$qualityScore}), skipping storage",
            'info'
          );
        }
        return false;
      }

      // 2. Create formatted content
      $content = $this->formatContentForStorage($query, $results);

      // Check that content is not empty
      if (empty(trim($content))) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Empty content generated, cannot store",
            'warning'
          );
        }
        return false;
      }

      // 3. Store in RagCache (fast path for future hits)
      if ($this->ragCache !== null) {
        $cacheKey = $this->getCacheKey($query);
        $cacheData = [
          'content' => $content,
          'original_query' => $query,
          'quality_score' => $qualityScore,
          'results' => $results,
          'metadata' => $metadata,
          'cached_at' => date('Y-m-d H:i:s')
        ];
        
        $this->ragCache->set($cacheKey, $cacheData, $this->cacheTTL);
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Stored in RagCache: {$cacheKey} (TTL: {$this->cacheTTL}s)",
            'info'
          );
        }
      }

      // 4. Create document for vector store
      $document = new Document();
      $document->content = $content;
      $document->sourceType = 'web_search';
      $document->sourceName = $results['metadata']['search_engine'] ?? 'serpapi';

      // 5. Enriched metadata
      $document->metadata = [
        'type' => 'web_search_cache',
        'original_query' => $query,
        'search_engine' => $results['metadata']['search_engine'] ?? 'serpapi',
        'quality_score' => $qualityScore,
        'usage_count' => 0,
        'last_used' => null,
        'total_results' => $results['total_results'] ?? 0,
        'date_cached' => date('Y-m-d H:i:s'),
      ];

      // Add custom metadata
      if (!empty($metadata)) {
        $document->metadata = [...$document->metadata, ...$metadata];
      }

      // 6. Check size and split if necessary
      $estimatedTokens = $this->estimateTokenCount($content);

      if ($estimatedTokens > $this->maxChunkSize) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Content large ({$estimatedTokens} tokens), splitting into chunks",
            'info'
          );
        }

        // Split document
        $splitDocs = DocumentSplitter::splitDocument($document, $this->maxChunkSize, $this->chunkOverlap);

        // Store each chunk
        $storedCount = 0;

        foreach ($splitDocs as $chunk) {
          if (empty($chunk->content)) {
            continue; // Skip empty chunks
          }

          // Enrich chunk metadata
          $chunk->metadata['is_chunked'] = true;
          $chunk->metadata['chunk_parent_query'] = $query;

          $this->vectorStore->addDocument($chunk);
          $storedCount++;
        }

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Stored {$storedCount} chunks in learning RAG",
            'info'
          );
        }

      } else {
        // Document small enough, direct storage
        $this->vectorStore->addDocument($document);

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Stored single document in learning RAG",
            'info'
          );
        }
      }

      return true;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error storing in learning RAG: " . $e->getMessage(),
        'error'
      );
      return false;
    }
  }

  /**
   * Search in cache RAG
   * 
   * Two-tier search:
   * 1. Fast path: Check RagCache (Redis/Memcached) - <1ms
   * 2. Slow path: Check Vector Store (MariaDB) - 10-50ms
   * 3. Store vector results in RagCache for future hits
   *
   * @param string $query Search query
   * @param int $limit Maximum number of results
   * @return array|null Cache results or null if nothing found
   */
  public function searchInCache(string $query, int $limit = 3): ?array
  {
    // Check if cache is enabled
    if (!$this->enabled) {
      return null;
    }
    
    $startTime = microtime(true);
    
    // Fast path: Check RagCache first
    if ($this->ragCache !== null) {
      $cacheKey = $this->getCacheKey($query);
      $cached = $this->ragCache->get($cacheKey);
      
      if ($cached !== null) {
        $elapsed = (microtime(true) - $startTime) * 1000;
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "RagCache HIT: {$query} ({$elapsed}ms)",
            'info'
          );
        }
        
        // Return formatted result
        return [[
          'content' => $cached['content'],
          'original_query' => $cached['original_query'],
          'quality_score' => $cached['quality_score'],
          'similarity_score' => 1.0, // Exact match from cache
          'usage_count' => 0,
          'source' => 'ragcache'
        ]];
      }
      
      if ($this->debug) {
        $elapsed = (microtime(true) - $startTime) * 1000;
        $this->logger->logSecurityEvent(
          "RagCache MISS: {$query} ({$elapsed}ms)",
          'info'
        );
      }
    }
    
    // Slow path: Check vector store
    if ($this->vectorStore === null) {
      return null;
    }
    
    try {
      // Similarity search
      $results = $this->vectorStore->similaritySearch(
        $query,
        $limit,
        0.75, // High threshold for cache
        fn($metadata) => isset($metadata['type']) && $metadata['type'] === 'web_search_cache'
      );

      if (empty($results)) {
        return null;
      }

      // Increment usage_count and last_used
      foreach ($results as $doc) {
        $this->incrementUsageCount($doc->id);
      }

      $formatted = [];

      foreach ($results as $doc) {
        $formatted[] = [
          'content' => $doc->content,
          'original_query' => $doc->metadata['original_query'] ?? '',
          'quality_score' => $doc->metadata['quality_score'] ?? 0,
          'similarity_score' => $doc->metadata['score'] ?? 0,
          'usage_count' => $doc->metadata['usage_count'] ?? 0,
          'source' => 'vectorstore'
        ];
      }

      // Store first result in RagCache for future fast hits
      if ($this->ragCache !== null && !empty($formatted)) {
        $cacheKey = $this->getCacheKey($query);
        $cacheData = [
          'content' => $formatted[0]['content'],
          'original_query' => $formatted[0]['original_query'],
          'quality_score' => $formatted[0]['quality_score'],
          'results' => $formatted,
          'metadata' => [],
          'cached_at' => date('Y-m-d H:i:s')
        ];
        
        $this->ragCache->set($cacheKey, $cacheData, $this->cacheTTL);
      }

      $elapsed = (microtime(true) - $startTime) * 1000;
      
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Vector Store HIT: Found " . \count($formatted) . " results for query ({$elapsed}ms)",
          'info'
        );
      }

      return $formatted;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error searching cache: " . $e->getMessage(),
        'error'
      );
      return null;
    }
  }

  /**
   * Invalidate cache for a specific query
   * 
   * Removes cache entries from both RagCache and Vector Store.
   *
   * @param string $query Query to invalidate
   * @return bool True if successful
   */
  public function invalidateCache(string $query): bool
  {
    // Check if cache is enabled
    if (!$this->enabled) {
      return false;
    }
    
    $success = true;
    
    // Invalidate RagCache
    if ($this->ragCache !== null) {
      $cacheKey = $this->getCacheKey($query);
      $ragSuccess = $this->ragCache->delete($cacheKey);
      
      if ($this->debug) {
        $status = $ragSuccess ? 'SUCCESS' : 'FAILED';
        $this->logger->logSecurityEvent(
          "RagCache invalidation {$status}: {$cacheKey}",
          'info'
        );
      }
      
      $success = $success && $ragSuccess;
    }
    
    // Invalidate Vector Store entries
    try {
      $sql = "DELETE FROM {$this->tableName} 
              WHERE type = 'web_search_cache'
                AND original_query = :query";
      
      $deleted = DoctrineOrm::execute($sql, ['query' => $query]);
      
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Vector Store invalidation: Deleted {$deleted} entries for query",
          'info'
        );
      }
      
      $success = $success && ($deleted >= 0);
      
    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error invalidating vector store cache: " . $e->getMessage(),
        'error'
      );
      $success = false;
    }
    
    return $success;
  }

  /**
   * Format content for storage
   *
   * @param string $query Original query
   * @param array $results Search results
   * @return string Formatted content
   */
  private function formatContentForStorage(string $query, array $results): string
  {
    $parts = [];

    $parts[] = "Query: {$query}";
    $parts[] = "";

    if (isset($results['featured_snippet'])) {
      $parts[] = "Featured Answer:";
      $parts[] = $results['featured_snippet']['answer'];
      $parts[] = "Source: " . $results['featured_snippet']['source'];
      $parts[] = "";
    }

    $parts[] = "Top Results:";
    $items = \array_slice($results['items'] ?? [], 0, 5); // Max 5 results

    foreach ($items as $i => $item) {
      $parts[] = ($i + 1) . ". " . $item['title'];
      $parts[] = "   " . $item['snippet'];
      
      // Store full URL if available, otherwise just domain
      $parts[] = "   Source: " . ($item['link'] ?? $item['source']);
      $parts[] = "";
    }

    return implode("\n", $parts);
  }

  /**
   * Calculate quality score for results
   *
   * @param array $results Search results
   * @return float Quality score (0-1)
   */
  private function calculateQualityScore(array $results): float
  {
    $score = 0.5; // Base

    if (isset($results['featured_snippet']) && !empty($results['featured_snippet']['answer'])) {
      $score += 0.2;
    }

    $relevantCount = 0;
    foreach ($results['items'] ?? [] as $item) {
      if (($item['relevance_score'] ?? 0) > 0.7) {
        $relevantCount++;
      }
    }

    $score += min($relevantCount * 0.1, 0.3);

    if (($results['total_results'] ?? 0) > 100) {
      $score += 0.1;
    }

    return min($score, 1.0);
  }

  /**
   * Estimate token count in text
   *
   * Approximate rule: ~4 characters per token
   * 
   * @param string $text Text
   * @return int Estimated token count
   */
  private function estimateTokenCount(string $text): int
  {
    return (int)ceil(\strlen($text) / 4);
  }

  /**
   * Increment usage counter for a document
   *
   * @param int $documentId Document ID
   */
  private function incrementUsageCount(int $documentId): void
  {
    try {
      $sql = "UPDATE {$this->tableName}
              SET usage_count = COALESCE(usage_count, 0) + 1,
                  last_used = NOW()
              WHERE id = :id";

      DoctrineOrm::execute($sql, ['id' => $documentId]);

    } catch (\Exception $e) {
      // Log but don't block
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Failed to increment usage count: " . $e->getMessage(),
          'warning'
        );
      }
    }
  }

  /**
   * Clean old unused documents
   *
   * @param int $daysOld Age in days
   * @param int $minUsageCount Minimum usage to keep
   * @return int Number of documents deleted
   */
  public function cleanOldCache(int $daysOld = 90, int $minUsageCount = 2): int
  {
    // Check if cache is enabled
    if (!$this->enabled) {
      return 0;
    }
    
    try {
      $sql = "DELETE FROM {$this->tableName} 
              WHERE type = 'web_search_cache'
                AND date_modified < DATE_SUB(NOW(), INTERVAL :days DAY)
                AND COALESCE(usage_count, 0) < :min_usage";

      $deleted = DoctrineOrm::execute($sql, [
        'days' => $daysOld,
        'min_usage' => $minUsageCount
      ]);

      if ($this->debug && $deleted > 0) {
        $this->logger->logSecurityEvent(
          "Cleaned {$deleted} old cache entries (>{$daysOld} days, usage<{$minUsageCount})",
          'info'
        );
      }

      return $deleted;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error cleaning cache: " . $e->getMessage(),
        'error'
      );
      return 0;
    }
  }

  /**
   * Get cache statistics
   * 
   * Includes both RagCache and Vector Store statistics.
   *
   * @return array Statistics
   */
  public function getCacheStats(): array
  {
    $stats = [
      'enabled' => $this->enabled,
      'total_cached_entries' => 0,
      'average_quality_score' => 0,
      'total_reuses' => 0,
      'max_reuses' => 0,
      'unique_queries_cached' => 0,
      'ragcache_enabled' => false,
      'ragcache_backend' => 'none',
      'ragcache_hit_rate' => 0,
      'cache_ttl' => $this->cacheTTL,
    ];
    
    // If cache is disabled, return minimal stats
    if (!$this->enabled) {
      return $stats;
    }
    
    // Get RagCache statistics
    if ($this->ragCache !== null) {
      $ragStats = $this->ragCache->getStats();
      $stats['ragcache_enabled'] = $ragStats['enabled'];
      $stats['ragcache_backend'] = $ragStats['backend'];
      $stats['ragcache_hit_rate'] = $ragStats['hit_rate'];
      $stats['ragcache_hits'] = $ragStats['hits'];
      $stats['ragcache_misses'] = $ragStats['misses'];
    }
    
    // Get Vector Store statistics
    try {
      $sql = "SELECT 
                COUNT(*) as total_entries,
                AVG(quality_score) as avg_quality,
                SUM(usage_count) as total_usage,
                MAX(usage_count) as max_usage,
                COUNT(DISTINCT original_query) as unique_queries
              FROM {$this->tableName}
              WHERE type = 'web_search_cache'";

      $vectorStats = DoctrineOrm::selectOne($sql);

      if ($vectorStats) {
        $stats['total_cached_entries'] = (int)$vectorStats['total_entries'];
        $stats['average_quality_score'] = round((float)$vectorStats['avg_quality'], 2);
        $stats['total_reuses'] = (int)$vectorStats['total_usage'];
        $stats['max_reuses'] = (int)$vectorStats['max_usage'];
        $stats['unique_queries_cached'] = (int)$vectorStats['unique_queries'];
      }

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error getting cache stats: " . $e->getMessage(),
        'error'
      );
    }
    
    return $stats;
  }

  /**
   * Get cache key for query
   * 
   * @param string $query Query string
   * @return string Cache key
   */
  private function getCacheKey(string $query): string
  {
    return self::CACHE_KEY_PREFIX . md5($query);
  }

  /**
   * Set quality threshold
   *
   * @param float $threshold Threshold (0-1)
   */
  public function setQualityThreshold(float $threshold): void
  {
    $this->qualityThreshold = max(0.0, min(1.0, $threshold));
  }

  /**
   * Set max chunk size
   *
   * @param int $size Size in tokens
   */
  public function setMaxChunkSize(int $size): void
  {
    $this->maxChunkSize = max(100, $size);
  }

  /**
   * Get current quality threshold
   *
   * @return float Threshold (0-1)
   */
  public function getQualityThreshold(): float
  {
    return $this->qualityThreshold ?? 0.75;
  }

  /**
   * Get configured cache TTL
   *
   * @return int TTL in seconds
   */
  public function getCacheTTL(): int
  {
    return $this->cacheTTL;
  }

  /**
   * Check if cache is enabled
   *
   * @return bool True if cache is enabled
   */
  public function isEnabled(): bool
  {
    return $this->enabled;
  }
}
