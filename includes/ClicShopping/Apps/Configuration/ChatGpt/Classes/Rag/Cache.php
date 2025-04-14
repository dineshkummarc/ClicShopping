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

  /**
   * Cache constructor.
   * Initializes the cache system and loads existing cached prompts
   *
   * @param bool $enablePromptCache Whether to enable prompt caching (default: true)
   */
  public function __construct($enablePromptCache = true)
  {
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER === 'True';

    // Active le cache si autorisé par la config
    if ($this->debug == 'True') {
      $this->enablePromptCache = true;
      $this->setPromptCacheEnabled($enablePromptCache);
    }
  }

  /**
   * Gets statistics about the prompt cache
   * Returns information about the cache status and number of entries
   *
   * @return array An array containing cache statistics with keys:
   *               - 'enabled': boolean indicating if cache is enabled
   *               - 'entries': integer count of cached items
   */
  public function getPromptCacheStats(): array
  {
    return [
      'enabled' => $this->enablePromptCache,
      'entries' => count($this->promptCache),
      'size_bytes' => strlen(json_encode($this->promptCache)),
      'cache_file' => $this->getPromptCacheFilePath()
    ];
  }

  /**
   * Sets whether the prompt cache is enabled or disabled
   *
   * @param bool $enable True to enable the cache, false to disable
   * @return void
   */
  private function setPromptCacheEnabled(bool $enable): void
  {
    $this->enablePromptCache = $enable;

    if ($enable && empty($this->promptCache)) {
      $this->loadPromptCache();
    }

    if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
      error_log("Prompt cache " . ($enable ? "enabled" : "disabled"));
    }
  }

  /**
   * Loads the prompt cache data from the cache file into memory
   * If the cache file exists, attempts to load and decode the JSON data
   * If loading fails, initializes an empty cache
   * Does nothing if caching is disabled
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
      try {
        $cacheData = file_get_contents($cacheFile);
        $this->promptCache = json_decode($cacheData, true) ?? [];

        if ($this->debug) {
          error_log("Prompt cache loaded with " . count($this->promptCache) . " entries");
        }
      } catch (\Exception $e) {
        if ($this->debug) {
          error_log("Error loading prompt cache: " . $e->getMessage());
        }
        $this->promptCache = [];
      }
    } else {
      $this->promptCache = [];
    }
  }

  /**
   * Saves the current prompt cache data to the cache file
   * Creates the cache directory if it doesn't exist
   * Encodes the cache data as JSON before saving
   * Does nothing if caching is disabled
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
      if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
      }

      file_put_contents($cacheFile, json_encode($this->promptCache));

      if ($this->debug) {
        error_log("Prompt cache saved with " . count($this->promptCache) . " entries");
      }
    } catch (\Exception $e) {
      if ($this->debug) {
        error_log("Error saving prompt cache: " . $e->getMessage());
      }
    }
  }

/**
   * Returns the file path for the prompt cache
   * The cache file is stored in the Work/Cache directory
   *
   * @return string The file path for the prompt cache
   */
  private function getPromptCacheFilePath(): string
  {
    return CLICSHOPPING::BASE_DIR . 'Work/Cache/rag_cache.cache';
  }

  /**
   * Generates a unique cache key for a given prompt
   * Normalizes the prompt by trimming whitespace, converting to lowercase,
   * and replacing multiple spaces with single spaces before hashing
   *
   * @param string $prompt The prompt text to generate a key for
   * @return string MD5 hash of the normalized prompt
   */
  public function generateCacheKey(string $prompt): string
  {
    // Normaliser le prompt (supprimer les espaces supplémentaires, mettre en minuscules)
    $normalizedPrompt = strtolower(trim(preg_replace('/\s+/', ' ', $prompt)));

    // Générer un hash pour le prompt normalisé
    return md5($normalizedPrompt);
  }

/**
 * Checks if a given prompt is already cached
 * Does nothing if caching is disabled
 *
 * @param string $prompt The prompt text to check
 * @return bool True if the prompt is cached, false otherwise
 */
  public function isPromptInCache(string $prompt): bool
  {
    if (!$this->enablePromptCache) {
      return false;
    }

    $cacheKey = $this->generateCacheKey($prompt);
    return isset($this->promptCache[$cacheKey]);
  }

  /**
   * Caches a response for a given prompt
   * Stores the prompt, response, and timestamp in the cache
   * Limits the cache size to 1000 entries
   * Does nothing if caching is disabled
   *
   * @param string $prompt The prompt to cache
   * @param string $response The response to cache
   * @param int $ttl
   * @return void
   */
  public function cacheResponse(string $prompt, string $response, int $ttl = 3600): void
  {
    if (!$this->enablePromptCache) {
      return;
    }

    $cacheKey = $this->generateCacheKey($prompt);

    $this->promptCache[$cacheKey] = [
      'prompt' => $prompt,
      'response' => $response,
      'created' => time(),
      'last_used' => time(),
      'ttl' => $ttl // TTL ajouté ici
    ];

    if (count($this->promptCache) > 1000) {
      uasort($this->promptCache, function ($a, $b) {
        return $b['last_used'] - $a['last_used'];
      });

      $this->promptCache = array_slice($this->promptCache, 0, 1000, true);
    }

    $this->savePromptCache();
  }
  
  /**
   * Retrieves a cached response for the given prompt if it exists
   * Updates the last_used timestamp if a cache entry is found
   *
   * @param string $prompt The prompt to get the response for
   * @return string|null The cached response if found, null otherwise
   */
  public function getCachedResponse(string $prompt): ?string {
    if (!$this->enablePromptCache) {
      return null;
    }

    $cacheKey = $this->generateCacheKey($prompt);

    if (isset($this->promptCache[$cacheKey])) {
      $this->promptCache[$cacheKey]['last_used'] = time();

      if ($this->debug == 'True') {
        error_log("Cache hit for prompt: " . substr($prompt, 0, 50) . "...");
      }

      return $this->promptCache[$cacheKey]['response'];
    }

    return null;
  }
}