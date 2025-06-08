<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\Cache\Classes\ClicShoppingAdmin\CacheAdmin;
/**
 * Class Cache
 *
 * Handles caching functionality for prompts in the ChatGPT application
 * This class manages a cache system to store and retrieve prompts efficiently
 *
 */
class Cache
{
  private array $promptCache = [];
  private bool $enablePromptCache = false;
  private bool $debug = false;
  private bool $cache = false;
  private bool $useMemcached = false;
  private ?\Memcached $memcached = null;
  private SecurityLogger $securityLogger;
  private const MEMCACHED_PREFIX = 'rag_cache_';

  /**
   * Cache constructor.
   *
   * Initializes the prompt cache system for the ChatGPT application.
   * Sets up debug and cache flags based on configuration constants.
   * Optionally enables prompt cache and initializes Memcached if configured.
   *
   * @param bool $enablePromptCache Whether to enable the prompt cache on construction (default: true)
   */
  public function __construct($enablePromptCache = true)
  {
    $this->promptCache = [];
    $this->securityLogger = new SecurityLogger();

    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';
    $this->cache = defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True';

    // First set the enable state
    $this->enablePromptCache = $this->cache === true ? true : false;

    if ($this->cache === true) {
      // Then call setPromptCacheEnabled with the parameter
      if ($enablePromptCache) {
        $this->setPromptCacheEnabled(true);
      }

      if (defined('USE_MEMCACHED') && USE_MEMCACHED == 'True') {
        $this->useMemcached = true;
        $this->initMemcached();
      }
    }
  }

  /**
   * Initializes the Memcached connection for caching.
   *
   * Checks if the Memcached extension is available, creates a Memcached instance,
   * adds a default server if none are configured, and tests the connection.
   * Logs events for debugging and disables Memcached usage on failure.
   *
   * @return void
   */
  private function initMemcached(): void
  {
    if (class_exists('Memcached')) {
      try {
        // Using CacheAdmin's Memcached instance to ensure consistency across the application
        $this->memcached = CacheAdmin::getMemcached();

        // Test connection
        $stats = $this->memcached->getStats();
        if (!is_array($stats) || count($stats) === 0) {
          throw new \Exception('Could not connect to Memcached server');
        }

        if ($this->debug) {
          $this->securityLogger->logSecurityEvent("Memcached initialized", 'info');
        }
      } catch (\Exception $e) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent("Memcached initialization failed: " . $e->getMessage(), 'error');
        }
        $this->useMemcached = false;
        $this->memcached = null;
      }
    } else {
      $this->useMemcached = false;
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Memcached extension not available", 'warning');
      }
    }
  }

  /**
   * Gets statistics about the prompt cache.
   *
   * Returns information about the cache status, number of entries, and source.
   * If Memcached is used, returns Memcached stats; otherwise, returns file cache stats.
   *
   * @return array An array containing cache statistics with keys:
   *               - 'enabled': boolean indicating if cache is enabled
   *               - 'entries': integer count of cached items
   *               - 'source' (optional): 'memcached' if using Memcached
   *               - 'size_bytes' (optional): size of file cache in bytes
   *               - 'cache_file' (optional): file path of the cache file
   */
  public function getPromptCacheStats(): array
  {
    if ($this->useMemcached && $this->memcached) {
      $stats = $this->memcached->getStats();
      $entries = array_sum(array_column($stats, 'curr_items'));
      return [
        'enabled' => true,
        'entries' => $entries,
        'source' => 'memcached'
      ];
    } else {
      return [
        'enabled' => $this->enablePromptCache,
        'entries' => count($this->promptCache),
        'size_bytes' => strlen(json_encode($this->promptCache)),
        'cache_file' => $this->getPromptCacheFilePath()
      ];
    }
  }

  /**
   * Sets whether the prompt cache is enabled or disabled.
   *
   * Loads the prompt cache from file if enabling and the cache is empty.
   * Logs the change if debugging is enabled.
   *
   * @param bool $enable True to enable the cache, false to disable
   * @return void
   */
  public function setPromptCacheEnabled(bool $enable): void
  {
    $this->enablePromptCache = $enable;

    if ($enable && empty($this->promptCache)) {
      $this->loadPromptCache();
    }

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent("Prompt cache " . ($enable ? "enabled" : "disabled"), 'info');
    }
  }

  /**
   * Loads the prompt cache data from the cache file into memory.
   *
   * If the cache file exists, attempts to load and decode the JSON data.
   * Prunes expired entries based on their TTL.
   * Initializes an empty cache if loading fails or file does not exist.
   * Does nothing if caching is disabled.
   *
   * @return void
   */
  private function loadPromptCache(): void
  {
    if (!$this->enablePromptCache) {
      return;
    }

    $cacheFile = $this->getPromptCacheFilePath();

    if (file_exists($cacheFile)) {
      $json = @file_get_contents($cacheFile);
      $this->promptCache = json_decode($json, true) ?: [];
      // prune expired
      $now = time();

      foreach ($this->promptCache as $k => $entry) {
        if ($now - $entry['last_used'] > $entry['ttl']) {
          unset($this->promptCache[$k]);
        }
      }

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Prompt cache loaded with " . count($this->promptCache) . " live entries", 'info');
      }
    } else {
      $this->promptCache = [];
    }
  }

  /**
   * Ensures that a directory exists, creating it if necessary.
   *
   * @param string $dir The directory path to check/create
   * @throws \RuntimeException if the directory cannot be created
   * @return void
   */
  private static function ensureDirectoryExists(string $dir): void
  {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
      throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
    }
  }

  /**
   * Returns the file path for the log file.
   *
   * The log file is stored in the Work/Cache/Rag directory.
   *
   * @return string The file path for the log file
   */
  public static function getLogFilePath(): string
  {
    $logDir = CLICSHOPPING::BASE_DIR . 'Work/Cache/Rag';
    self::ensureDirectoryExists($logDir);
    return $logDir . '/rag_security.log';
  }

  /**
   * Saves the current prompt cache data to the cache file.
   *
   * Creates the cache directory if it doesn't exist.
   * Encodes the cache data as JSON before saving.
   * Uses a temporary file and renames it for atomicity.
   * Logs the save event if debugging is enabled.
   * Does nothing if caching is disabled.
   *
   * @return void
   */
  public function savePromptCache(): void
  {
    if (!$this->enablePromptCache) {
      return;
    }

    $cacheFile = $this->getPromptCacheFilePath();

    try {
      $cacheDir = dirname($cacheFile);
      self::ensureDirectoryExists($cacheDir);

      $tmpFile = $cacheFile . '.tmp';
      file_put_contents($tmpFile, json_encode($this->promptCache));
      rename($tmpFile, $cacheFile);

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Prompt cache saved with " . count($this->promptCache) . " entries", 'info');
      }
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Error saving prompt cache: " . $e->getMessage(), 'error');
      }
    }
  }

  /**
   * Returns the file path for the prompt cache.
   *
   * The cache file is stored in the Work/Cache/Rag directory.
   *
   * @return string The file path for the prompt cache
   */
  private function getPromptCacheFilePath(): string
  {
    return CLICSHOPPING::BASE_DIR . 'Work/Cache/Rag/rag_cache.cache';
  }

  /**
   * Generates a unique cache key for a given prompt.
   *
   * Normalizes the prompt by trimming whitespace, converting to lowercase,
   * removing punctuation, and stripping accents before hashing.
   *
   * @param string $prompt The prompt text to generate a key for
   * @return string MD5 hash of the normalized prompt
   */
  public function generateCacheKey(string $prompt): string {
    // strip tags, collapse whitespace, remove punctuation, lowercase, strip accents
    $clean = strip_tags($prompt);
    $clean = mb_strtolower($clean, 'UTF-8');
    $clean = preg_replace('/[^\p{L}\p{N}\s]/u', '', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = mb_strtolower($clean, 'UTF-8');

    // remove accents
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
    $clean = $converted !== false ? $converted : $clean;

    return md5(trim($clean));
  }

  /**
   * Checks if a given prompt is already cached.
   *
   * Checks both Memcached and file cache depending on configuration.
   * Does nothing if caching is disabled.
   *
   * @param string $prompt The prompt text to check
   * @return bool True if the prompt is cached, false otherwise
   */
  public function isPromptInCache(string $prompt): bool
  {
    $cacheKey = $this->generateCacheKey($prompt);

    if ($this->useMemcached && $this->memcached) {
      return $this->memcached->get(self::MEMCACHED_PREFIX . $cacheKey) !== false;
    }

    if (!$this->enablePromptCache) {
      return false;
    }

    return isset($this->promptCache[$cacheKey]);
  }

  /**
   * Caches a response for a given prompt.
   *
   * Stores the prompt, response, and timestamps in the cache.
   * Limits the cache size to 1000 entries.
   * Stores in both Memcached (if enabled) and file cache as backup.
   * Does nothing if caching is disabled.
   *
   * @param string $prompt The prompt to cache
   * @param string $response The response to cache
   * @param int $ttl Time-to-live for the cache entry in seconds (default: 3600)
   * @return void
   */
  public function cacheResponse(string $prompt, string $response, int $ttl = 3600): void
  {
    if (!$this->enablePromptCache) {
      return;
    }

    $cacheKey = $this->generateCacheKey($prompt);
    $data = [
      'prompt' => $prompt,
      'response' => $response,
      'created' => time(),
      'last_used' => time(),
      'ttl' => $ttl
    ];

    if ($this->useMemcached && $this->memcached) {
      if (!$this->memcached->set(self::MEMCACHED_PREFIX . $cacheKey, $data, $ttl)) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Memcached set failed: " . $this->memcached->getResultMessage(),
            'warning'
          );
        }
      }
    }

    // Always maintain file cache as backup
    $this->promptCache[$cacheKey] = $data;

    if (count($this->promptCache) > 1000) {
      uasort($this->promptCache, fn($a, $b) => $b['last_used'] - $a['last_used']);
      $this->promptCache = array_slice($this->promptCache, 0, 1000, true);
    }

    $this->savePromptCache();
  }

  /**
   * Retrieves a cached response for the given prompt if it exists.
   *
   * Updates the last_used timestamp if a cache entry is found.
   * Checks both Memcached and file cache depending on configuration.
   *
   * @param string $prompt The prompt to get the response for
   * @return string|null The cached response if found, null otherwise
   */
  public function getCachedResponse(string $prompt): ?string
  {
    if (!$this->enablePromptCache) {
      return null;
    }

    $cacheKey = $this->generateCacheKey($prompt);

    if ($this->useMemcached && $this->memcached) {
      $data = $this->memcached->get(self::MEMCACHED_PREFIX . $cacheKey);
      if ($data !== false) {
        $data['last_used'] = time();
        $this->memcached->set(self::MEMCACHED_PREFIX . $cacheKey, $data, $data['ttl']);
        return $data['response'];
      }
    }

    if (isset($this->promptCache[$cacheKey])) {
      $data = $this->promptCache[$cacheKey];
      if (time() - $data['created'] <= $data['ttl']) {
        $this->promptCache[$cacheKey]['last_used'] = time();
        return $data['response'];
      }
      unset($this->promptCache[$cacheKey]);
    }

    return null;
  }
}