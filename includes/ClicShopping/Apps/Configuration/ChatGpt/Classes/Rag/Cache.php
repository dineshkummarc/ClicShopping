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

class Cache {
  private array $promptCache = [];
  private bool $enablePromptCache = false;


  public function __construct($enablePromptCache = true)
  {
    $this->enablePromptCache = $enablePromptCache;
    $this->loadPromptCache();
  }

  /**
   * Saves the prompt cache to file
   */
  /*
  public function savePromptCache(): void
  {
    if (!$this->enablePromptCache) {
      return;
    }

    $cacheFile = $this->getPromptCacheFilePath();

    try {
      file_put_contents($cacheFile, json_encode($this->promptCache));
    } catch (\Exception $e) {
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Error saving prompt cache: " . $e->getMessage());
      }
    }
  }
*/
  /**
   * Gets statistics about the prompt cache
   *
   * @return array Cache statistics
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
   * Enables or disables the prompt cache
   *
   * @param bool $enable Whether to enable the cache
   */
  public function setPromptCacheEnabled(bool $enable): void
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
   * Clears the prompt cache
   */
  /*
  public function clearPromptCache(): void
  {
    $this->promptCache = [];
    $this->savePromptCache();

    if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
      error_log("Prompt cache cleared");
    }
  }
*/
  /**
   * Loads the prompt cache from file if it exists
   */
  private function loadPromptCache(): void {
    if (!$this->enablePromptCache) {
      return;
    }

    $cacheFile = $this->getPromptCacheFilePath();

    if (file_exists($cacheFile)) {
      try {
        $cacheData = file_get_contents($cacheFile);
        $this->promptCache = json_decode($cacheData, true) ?? [];

        if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Prompt cache loaded with " . count($this->promptCache) . " entries");
        }
      } catch (\Exception $e) {
        if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Error loading prompt cache: " . $e->getMessage());
        }
        $this->promptCache = [];
      }
    } else {
      $this->promptCache = [];
    }
  }

  /**
   * Saves the prompt cache to file
   */
  public function savePromptCache(): void {
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

      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Prompt cache saved with " . count($this->promptCache) . " entries");
      }
    } catch (\Exception $e) {
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Error saving prompt cache: " . $e->getMessage());
      }
    }
  }

  /**
   * Gets the path to the prompt cache file
   *
   * @return string Path to the cache file
   */
  private function getPromptCacheFilePath(): string {
    return CLICSHOPPING::BASE_DIR . 'Work/Cache/rag_cache.cache';
  }

  /**
   * Generates a cache key for a prompt
   *
   * @param string $prompt The prompt to generate a key for
   * @return string The cache key
   */
  public function generateCacheKey(string $prompt): string {
    // Normaliser le prompt (supprimer les espaces supplémentaires, mettre en minuscules)
    $normalizedPrompt = strtolower(trim(preg_replace('/\s+/', ' ', $prompt)));

    // Générer un hash pour le prompt normalisé
    return md5($normalizedPrompt);
  }

  /**
   * Checks if a prompt is in the cache
   *
   * @param string $prompt The prompt to check
   * @return bool True if the prompt is in the cache, false otherwise
   */
  public function isPromptInCache(string $prompt): bool {
    if (!$this->enablePromptCache) {
      return false;
    }

    $cacheKey = $this->generateCacheKey($prompt);
    return isset($this->promptCache[$cacheKey]);
  }

  /**
   * Gets a cached response for a prompt
   *
   * @param string $prompt The prompt to get the cached response for
   * @return string|null The cached response, or null if not found
   */
  public function getCachedResponse(string $prompt): ?string {
    if (!$this->enablePromptCache) {
      return null;
    }

    $cacheKey = $this->generateCacheKey($prompt);

    if (isset($this->promptCache[$cacheKey])) {
      // Mettre à jour le timestamp pour indiquer que cette entrée est toujours utilisée
      $this->promptCache[$cacheKey]['last_used'] = time();

      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Cache hit for prompt: " . substr($prompt, 0, 50) . "...");
      }

      return $this->promptCache[$cacheKey]['response'];
    }

    return null;
  }

  /**
   * Adds a response to the cache
   *
   * @param string $prompt The prompt
   * @param string $response The response to cache
   */
  public function cacheResponse(string $prompt, string $response): void {
    if (!$this->enablePromptCache) {
      return;
    }

    $cacheKey = $this->generateCacheKey($prompt);

    $this->promptCache[$cacheKey] = [
      'prompt' => $prompt,
      'response' => $response,
      'created' => time(),
      'last_used' => time()
    ];

    // Limiter la taille du cache (garder les 1000 entrées les plus récemment utilisées)
    if (count($this->promptCache) > 1000) {
      // Trier par dernier accès
      uasort($this->promptCache, function($a, $b) {
        return $b['last_used'] - $a['last_used'];
      });

      // Garder seulement les 1000 premières entrées
      $this->promptCache = array_slice($this->promptCache, 0, 1000, true);
    }

    // Sauvegarder le cache
    $this->savePromptCache();
  }
}