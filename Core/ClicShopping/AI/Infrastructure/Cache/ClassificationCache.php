<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Infrastructure\Cache;

use AllowDynamicProperties;
use ClicShopping\OM\CLICSHOPPING;

/**
 * Manages caching of query classifications to minimize redundant calls to the LLM API.
 * 
 * This cache stores classification results (analytics, semantic, hybrid, web_search)
 * to avoid repeated LLM calls for the same or similar queries.
 * 
 * Cache Structure:
 * - Directory: Work/Cache/Rag/Classification/
 * - Format: JSON files with md5 hash filenames
 * - TTL: 30 days (configurable)
 * 
 * @package ClicShopping\AI\Infrastructure\Cache
 * @since 2025-12-26
 */
#[AllowDynamicProperties]
class ClassificationCache
{
  /**
   * @var string Directory where cache files are stored.
   */
  private string $cacheDir;

  /**
   * @var int Lifetime of cache files in seconds (default: 30 days).
   */
  private int $lifetime;

  /**
   * @var bool Whether caching is enabled (from configuration).
   */
  private bool $cacheEnabled;

  /**
   * @var bool Enable debug logging.
   */
  private bool $debug;

  /**
   * ClassificationCache constructor.
   *
   * @param int $lifetime Cache lifetime in seconds (default: 30 days).
   * @param bool $debug Enable debug logging.
   */
  public function __construct(int $lifetime = 2592000, bool $debug = false)
  {
    $this->cacheEnabled = defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER') && 
                          CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True';
    $this->cacheDir = CLICSHOPPING::BASE_DIR . 'Work/Cache/Rag/Classification/';
    $this->lifetime = $lifetime;
    $this->debug = $debug || (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && 
                              CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True');

    // Check cache configuration
    $this->checkClassificationCache();

    // Create cache directory if it doesn't exist
    if (!is_dir($this->cacheDir)) {
      if (!mkdir($concurrentDirectory = $this->cacheDir, 0755, true) && !is_dir($concurrentDirectory)) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
      }
      
      if ($this->debug) {
        error_log("[ClassificationCache] Created cache directory: {$this->cacheDir}");
      }
    }
  }

  /**
   * Check cache configuration and clear cache if disabled.
   *
   * @return bool True if cache is enabled, false otherwise.
   */
  public function checkClassificationCache(): bool
  {
    if ($this->cacheEnabled === false) {
      if ($this->debug) {
        error_log("[ClassificationCache] Cache disabled, clearing cache");
      }
      $this->clearCache();
      return false;
    }
    
    if ($this->debug) {
      error_log("[ClassificationCache] Cache enabled");
    }
    return true;
  }

  /**
   * Retrieves a cached classification for a given query.
   *
   * @param string $query Original query.
   * @param string|null $translatedQuery Translated query (English).
   * @return array|null The classification result, or null if not found or expired.
   */
  public function getCachedClassification(string $query, ?string $translatedQuery = null): ?array
  {
    if (!$this->cacheEnabled) {
      return null;
    }

    $file = $this->getCacheFile($query, $translatedQuery);
    
    if (file_exists($file)) {
      $data = json_decode(file_get_contents($file), true);

      // Check if the cache is expired
      $age = time() - ($data['timestamp'] ?? 0);
      if ($age < $this->lifetime) {
        if ($this->debug) {
          error_log(sprintf(
            "[ClassificationCache] Cache HIT for query: \"%s\" (age: %ds, file: %s)",
            substr($query, 0, 50),
            $age,
            basename($file)
          ));
        }
        
        // Return classification result
        return [
          'type' => $data['type'] ?? 'semantic',
          'confidence' => $data['confidence'] ?? 0.5,
          'reasoning' => $data['reasoning'] ?? [],
          'detection_method' => $data['detection_method'] ?? 'llm_cached',
          'sub_types' => $data['sub_types'] ?? [],
          'cached' => true,
          'cache_age' => $age
        ];
      }

      // If expired, delete the file to trigger a new classification
      @unlink($file);
      
      if ($this->debug) {
        error_log(sprintf(
          "[ClassificationCache] Cache EXPIRED for query: \"%s\" (age: %ds > TTL: %ds)",
          substr($query, 0, 50),
          $age,
          $this->lifetime
        ));
      }
    } else {
      if ($this->debug) {
        error_log(sprintf(
          "[ClassificationCache] Cache MISS for query: \"%s\" (file: %s)",
          substr($query, 0, 50),
          basename($file)
        ));
      }
    }

    return null;
  }

  /**
   * Stores a classification result in the cache.
   *
   * @param string $query Original query.
   * @param string|null $translatedQuery Translated query (English).
   * @param array $classification Classification result.
   */
  public function cacheClassification(string $query, ?string $translatedQuery, array $classification): void
  {
    if (!$this->cacheEnabled) {
      return;
    }

    $file = $this->getCacheFile($query, $translatedQuery);
    $data = [
      'query' => $query,
      'translated_query' => $translatedQuery,
      'type' => $classification['type'] ?? 'semantic',
      'confidence' => $classification['confidence'] ?? 0.5,
      'reasoning' => $classification['reasoning'] ?? [],
      'detection_method' => $classification['detection_method'] ?? 'llm',
      'sub_types' => $classification['sub_types'] ?? [],
      'timestamp' => time()
    ];

    $success = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    
    if ($this->debug) {
      if ($success !== false) {
        error_log(sprintf(
          "[ClassificationCache] Cached classification for query: \"%s\" (type: %s, confidence: %.2f, file: %s)",
          substr($query, 0, 50),
          $data['type'],
          $data['confidence'],
          basename($file)
        ));
      } else {
        error_log(sprintf(
          "[ClassificationCache] ERROR: Failed to cache classification for query: \"%s\"",
          substr($query, 0, 50)
        ));
      }
    }
  }

  /**
   * Returns the full path to the cache file for the given query.
   *
   * @param string $query Original query.
   * @param string|null $translatedQuery Translated query (English).
   * @return string The full path to the cache file.
   */
  private function getCacheFile(string $query, ?string $translatedQuery = null): string
  {
    // Create a unique filename based on both original and translated query
    // This ensures cache hits for the same query regardless of translation variations
    $cacheKey = $query . ($translatedQuery ?? '');
    $hash = md5($cacheKey);
    return $this->cacheDir . $hash . '.json';
  }

  /**
   * Clears the entire classification cache.
   *
   * @return bool True on success, false on failure.
   */
  public function clearCache(): bool
  {
    if (!is_dir($this->cacheDir)) {
      return true; // Nothing to clear
    }

    $files = glob($this->cacheDir . '*.json');
    $success = true;
    $count = 0;

    foreach ($files as $file) {
      if (@unlink($file)) {
        $count++;
      } else {
        $success = false;
      }
    }

    if ($this->debug) {
      error_log(sprintf(
        "[ClassificationCache] Cleared %d cache files from %s",
        $count,
        $this->cacheDir
      ));
    }

    return $success;
  }

  /**
   * Get cache statistics.
   *
   * @return array Statistics about the cache.
   */
  public function getStatistics(): array
  {
    if (!is_dir($this->cacheDir)) {
      return [
        'enabled' => $this->cacheEnabled,
        'directory' => $this->cacheDir,
        'file_count' => 0,
        'total_size' => 0,
        'oldest_file' => null,
        'newest_file' => null
      ];
    }

    $files = glob($this->cacheDir . '*.json');
    $totalSize = 0;
    $oldestTime = PHP_INT_MAX;
    $newestTime = 0;

    foreach ($files as $file) {
      $totalSize += filesize($file);
      $mtime = filemtime($file);
      $oldestTime = min($oldestTime, $mtime);
      $newestTime = max($newestTime, $mtime);
    }

    return [
      'enabled' => $this->cacheEnabled,
      'directory' => $this->cacheDir,
      'file_count' => count($files),
      'total_size' => $totalSize,
      'total_size_mb' => round($totalSize / 1024 / 1024, 2),
      'oldest_file' => $oldestTime < PHP_INT_MAX ? date('Y-m-d H:i:s', $oldestTime) : null,
      'newest_file' => $newestTime > 0 ? date('Y-m-d H:i:s', $newestTime) : null,
      'lifetime' => $this->lifetime,
      'lifetime_days' => round($this->lifetime / 86400, 1)
    ];
  }
}
