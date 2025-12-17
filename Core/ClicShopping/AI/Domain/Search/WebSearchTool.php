<?php
/**
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

namespace ClicShopping\AI\Domain\Search;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Infrastructure\Cache\Cache;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Domain\Search\WebSearchLogger;
use ClicShopping\OM\Registry;

/**
 * WebSearchTool Class
 *
 * Wrapper intelligent pour l'API SerpApi avec :
 * - Gestion du cache (réduction coûts)
 * - Rate limiting (protection API)
 * - Formatage des résultats
 * - Intégration avec SearchCacheManager (Learning RAG)
 * - Support multi-moteurs (Google, Bing, DuckDuckGo)
 */
class WebSearchTool
{
  private string $apiKey;
  private SecurityLogger $logger;
  private Cache $cache;
  private SearchResultProcessor $processor;
  private SearchCacheManager $cacheManager;
  private WebSearchLogger $searchLogger;
  private bool $debug;
  private mixed $db;
  private mixed $language;

  // Configuration
  private int $maxResults = 20;
  private int $rateLimitPerHour = 100;
  private int $cacheExpiration = 86400; // 24h
  private string $defaultEngine = 'google';
  private int $requestTimeout = 30; // secondes

  // Statistiques
  private array $stats = [
    'total_requests' => 0,
    'cache_hits' => 0,
    'api_calls' => 0,
    'errors' => 0,
  ];

  /**
   * Constructor
   *
   * @throws \RuntimeException Si la clé API n'est pas configurée
   */
  public function __construct()
  {
    // Try multiple sources for SERAPI key
    $this->apiKey = '';
    
    // 1. Try constant from config_clicshopping.php
    if (defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI') && !empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI)) {
      $this->apiKey = CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI;
    }
    // 2. Try environment variable
    elseif (!empty(getenv('SERP_API_KEY'))) {
      $this->apiKey = getenv('SERP_API_KEY');
    }
    // 3. Try Gpt class method if it exists
    elseif (method_exists('ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt', 'getSerpApiKey')) {
      $key = Gpt::getSerpApiKey();
      if (!empty($key)) {
        $this->apiKey = $key;
      }
    }

    $this->logger = new SecurityLogger();
    $this->cache = new Cache(true);
    $this->processor = new SearchResultProcessor();
    $this->cacheManager = new SearchCacheManager('rag_web_cache_embedding');
    $this->db = Registry::get('Db');
    
    // TASK 4.3.5: Handle Language registry not being available in AJAX context
    try {
      $this->language = Registry::get('Language');
    } catch (\Exception $e) {
      // Language not registered (AJAX context) - will use language_id from context
      $this->language = null;
    }
    
    $this->searchLogger = new WebSearchLogger();
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    if (empty($this->apiKey)) {
      throw new \RuntimeException(
        'SerpApi key not configured. Set CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI in configuration.'
      );
    }

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "WebSearchTool initialized with engine: {$this->defaultEngine} (key: " . substr($this->apiKey, 0, 10) . "...)",
        'info'
      );
    }
  }

  /**
   * 🎯 Point d'entrée principal : Recherche web intelligente
   *
   * Workflow :
   * 1. Vérifier cache court-terme (Cache.php)
   * 2. Vérifier cache RAG learning (SearchCacheManager)
   * 3. Si cache miss : Appel SerpApi
   * 4. Formater les résultats
   * 5. Cacher + Logger + Stocker dans RAG si haute qualité
   *
   * @param string $query Requête de recherche
   * @param array $options Options (max_results, engine, location, etc.)
   * @return array Résultats formatés avec métadonnées
   */
  public function search(string $query, array $options = []): array
  {
    $startTime = microtime(true);
    $this->stats['total_requests']++;

    try {
      // Validation de la requête
      if (empty(trim($query))) {
        throw new \InvalidArgumentException('Query cannot be empty');
      }

      if (strlen($query) > 500) {
        throw new \InvalidArgumentException('Query too long (max 500 characters)');
      }

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Web search request: {$query}",
          'info'
        );
      }

      // 🔹 ÉTAPE 1 : Vérifier cache court-terme (24h)
      $cacheKey = $this->generateCacheKey($query, $options);
      $cached = $this->cache->getCachedResponse($cacheKey);

      if ($cached !== null) {
        $this->stats['cache_hits']++;

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Cache hit (short-term): {$query}",
            'info'
          );
        }

        $result = json_decode($cached, true);
        $result['cached'] = true;
        $result['cache_source'] = 'short_term';
        $result['execution_time'] = microtime(true) - $startTime;

        return $result;
      }

      // 🔹 ÉTAPE 2 : Vérifier cache RAG learning
      $ragCached = $this->cacheManager->searchInCache($query, 3);

      if ($ragCached !== null && !empty($ragCached)) {
        // Convertir le format RAG en format standard
        $result = $this->convertRagCacheToStandardFormat($ragCached);
        
        // ⚠️ IMPORTANT: Only use cache if it contains actual items
        // Empty cache results should trigger a fresh API call
        if (!empty($result['items'])) {
          $this->stats['cache_hits']++;

          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Cache hit (RAG learning): {$query} with " . count($result['items']) . " items",
              'info'
            );
          }

          $result['cached'] = true;
          $result['cache_source'] = 'rag_learning';
          $result['execution_time'] = microtime(true) - $startTime;

          return $result;
        } else {
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Cache found but empty, will call API: {$query}",
              'info'
            );
          }
        }
      }

      // 🔹 ÉTAPE 3 : Cache miss → Appel API
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Cache miss, calling SerpApi for: {$query}",
          'info'
        );
      }

      // Vérifier rate limiting
      $this->checkRateLimit();

      // Appeler SerpApi
      $rawResults = $this->callSerpApi($query, $options);
      $this->stats['api_calls']++;

      // 🔹 ÉTAPE 4 : Traiter et formater les résultats
      $processed = $this->processor->process($rawResults, $query);

      // Enrichir avec métadonnées
      $processed['cached'] = false;
      $processed['cache_source'] = 'none';
      $processed['execution_time'] = microtime(true) - $startTime;
      $processed['api_cost'] = $this->estimateApiCost($rawResults);

      // 🔹 ÉTAPE 5 : Cacher les résultats
      $ttl = $options['cache_ttl'] ?? $this->cacheExpiration;
      $this->cache->cacheResponse($cacheKey, json_encode($processed), $ttl);

      // 🔹 ÉTAPE 6 : Stocker dans RAG learning si haute qualité
      $qualityScore = $this->processor->calculateQualityScore($processed);
      $processed['quality_score'] = $qualityScore;

      if ($qualityScore >= 0.7) {
        $this->cacheManager->storeInLearningRAG($query, $processed);
        $processed['stored_in_rag'] = true;

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "High quality result stored in RAG learning (score: {$qualityScore})",
            'info'
          );
        }
      } else {
        $processed['stored_in_rag'] = false;
      }

      // 🔹 ÉTAPE 7 : Logger la requête complète
      $this->searchLogger->saveWebSearchRequest(
        $query,
        $query, // translated_query (déjà en anglais normalement)
        ['intent' => 'EXTERNAL_WEB', 'confidence' => 1.0, 'method' => 'direct'],
        $processed,
        '', // response_summary sera rempli par le système après synthèse
        [
          'execution_time' => $processed['execution_time'],
          'api_cost' => $processed['api_cost'],
          'cached' => false,
          'cached_in_rag' => $processed['stored_in_rag'],
          'quality_score' => $qualityScore,
        ]
      );

      return $processed;

    } catch (\Exception $e) {
      $this->stats['errors']++;

      $this->logger->logSecurityEvent(
        "Web search error: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'error' => $e->getMessage(),
        'query' => $query,
        'execution_time' => microtime(true) - $startTime,
      ];
    }
  }

  /**
   * Appelle l'API SerpApi
   *
   * @param string $query Requête de recherche
   * @param array $options Options
   * @return array Résultats bruts de SerpApi
   * @throws \RuntimeException Si l'appel API échoue
   */
  private function callSerpApi(string $query, array $options): array
  {
    $engine = $options['engine'] ?? $this->defaultEngine;
    $maxResults = $options['max_results'] ?? $this->maxResults;

    // Construction des paramètres
    $params = [
      'q' => $query,
      'api_key' => $this->apiKey,
      'num' => $maxResults,
      'engine' => $engine,
    ];

    // Options additionnelles
    if (isset($options['language'])) {
      $params['hl'] = $options['language'];
    }

    if (isset($options['location'])) {
      $params['location'] = $options['location'];
    }

    if (isset($options['country'])) {
      $params['gl'] = $options['country'];
    }

    // Safe search (toujours activé pour e-commerce)
    $params['safe'] = 'active';

    // Construction URL
    $url = 'https://serpapi.com/search.json?' . http_build_query($params);



    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "SerpApi URL: " . preg_replace('/api_key=[^&]+/', 'api_key=***', $url),
        'info'
      );
    }

    // Appel cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ClicShoppingAI/1.0');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Gestion des erreurs HTTP
    if ($httpCode !== 200) {
      throw new \RuntimeException("SerpApi returned HTTP {$httpCode}. Error: {$curlError}");
    }

    if ($response === false) {
      throw new \RuntimeException("cURL error: {$curlError}");
    }

    // Parse JSON
    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \RuntimeException("Invalid JSON response from SerpApi: " . json_last_error_msg());
    }

    // Vérifier erreur API
    if (isset($decoded['error'])) {
      throw new \RuntimeException("SerpApi error: " . $decoded['error']);
    }

    return $decoded;
  }

  /**
   * Vérifie le rate limiting (100 requêtes/heure)
   *
   * @throws \RuntimeException Si la limite est atteinte
   */
  private function checkRateLimit(): void
  {
    $key = 'serpapi_rate_limit_' . date('YmdH');
    $count = (int)($this->cache->getCachedResponse($key) ?? 0);

    if ($count >= $this->rateLimitPerHour) {
      throw new \RuntimeException(
        "SerpApi rate limit exceeded ({$this->rateLimitPerHour} requests/hour). Try again later."
      );
    }

    $this->cache->cacheResponse($key, (string)($count + 1), 3600);

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Rate limit check: {$count}/{$this->rateLimitPerHour} requests this hour",
        'info'
      );
    }
  }

  /**
   * Génère une clé de cache unique
   *
   * @param string $query Requête
   * @param array $options Options
   * @return string Clé de cache
   */
  private function generateCacheKey(string $query, array $options): string
  {
    $normalized = strtolower(trim($query));
    $optionsHash = md5(json_encode([
      'engine' => $options['engine'] ?? $this->defaultEngine,
      'max_results' => $options['max_results'] ?? $this->maxResults,
      'language' => $options['language'] ?? 'en',
      'location' => $options['location'] ?? '',
    ]));

    return "websearch_" . md5($normalized) . "_{$optionsHash}";
  }

  /**
   * Convertit le format cache RAG en format standard
   *
   * @param array $ragCached Résultats du cache RAG
   * @return array Format standard
   */
  private function convertRagCacheToStandardFormat(array $ragCached): array
  {
    // Le cache RAG retourne du texte formaté
    // On doit le parser pour reconstruire un format standard

    $firstResult = $ragCached[0] ?? [];
    $content = $firstResult['content'] ?? '';

    return [
      'success' => true,
      'query' => $firstResult['original_query'] ?? '',
      'items' => $this->parseRagContentToItems($content),
      'total_results' => 0, // Inconnu dans le cache RAG
      'metadata' => [
        'search_engine' => 'rag_cache',
        'timestamp' => time(),
        'quality_score' => $firstResult['quality_score'] ?? 0,
        'usage_count' => $firstResult['usage_count'] ?? 0,
      ],
    ];
  }

  /**
   * Parse le contenu RAG pour extraire les items avec URLs
   *
   * @param string $content Contenu texte
   * @return array Items formatés
   */
  private function parseRagContentToItems(string $content): array
  {
    $items = [];

    // Format attendu :
    // 1. Title
    //    Snippet
    //    Source: https://example.com/page
    // OU
    // 1. Title
    //    Snippet
    //    Source: domain.com

    // Pattern amélioré pour capturer les URLs complètes
    $pattern = '/(\d+)\.\s+(.+?)\n\s+(.+?)\n\s+Source:\s+(https?:\/\/[^\s\n]+|[^\s\n]+?)(?:\n|$)/s';
    
    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $sourceOrLink = trim($match[4]);
        
        // Si c'est une URL complète, l'utiliser comme link
        // Sinon, c'est juste un domaine
        $link = '';
        $source = $sourceOrLink;
        
        if (preg_match('/^https?:\/\//', $sourceOrLink)) {
          $link = $sourceOrLink;
          // Extraire le domaine de l'URL pour source
          $parsed = parse_url($sourceOrLink);
          $source = $parsed['host'] ?? $sourceOrLink;
          $source = preg_replace('/^www\./', '', $source);
        }
        
        $items[] = [
          'position' => (int)$match[1],
          'title' => trim($match[2]),
          'snippet' => trim($match[3]),
          'source' => $source,
          'link' => $link,
          'relevance_score' => 0.8, // Score par défaut pour cache
        ];
      }
    }

    return $items;
  }

  /**
   * Estime le coût API d'une requête
   *
   * @param array $results Résultats bruts
   * @return float Coût estimé en USD
   */
  private function estimateApiCost(array $results): float
  {
    // SerpApi coût moyen : $0.002 par requête (varie selon le plan)
    return 0.002;
  }

  /**
   * Configure le moteur de recherche par défaut
   *
   * @param string $engine Moteur (google, bing, duckduckgo)
   */
  public function setDefaultEngine(string $engine): void
  {
    $allowed = ['google', 'bing', 'duckduckgo', 'yahoo'];

    if (!in_array($engine, $allowed)) {
      throw new \InvalidArgumentException("Engine must be one of: " . implode(', ', $allowed));
    }

    $this->defaultEngine = $engine;
  }

  /**
   * Configure le nombre max de résultats
   *
   * @param int $max Nombre max (1-20)
   */
  public function setMaxResults(int $max): void
  {
    $this->maxResults = max(1, min(20, $max));
  }

  /**
   * Configure la limite de requêtes par heure
   *
   * @param int $limit Limite
   */
  public function setRateLimitPerHour(int $limit): void
  {
    $this->rateLimitPerHour = max(10, $limit);
  }

  /**
   * Obtient les statistiques d'utilisation
   *
   * @return array Statistiques
   */
  public function getStats(): array
  {
    $cacheHitRate = $this->stats['total_requests'] > 0 ? ($this->stats['cache_hits'] / $this->stats['total_requests']) * 100 : 0;

    return array_merge($this->stats, [
      'cache_hit_rate' => round($cacheHitRate, 2) . '%',
      'api_call_rate' => ($this->stats['total_requests'] - $this->stats['cache_hits']),
      'error_rate' => $this->stats['total_requests'] > 0 ? round(($this->stats['errors'] / $this->stats['total_requests']) * 100, 2) . '%' : '0%',
    ]);
  }

  /**
   * Réinitialise les statistiques
   */
  public function resetStats(): void
  {
    $this->stats = [
      'total_requests' => 0,
      'cache_hits' => 0,
      'api_calls' => 0,
      'errors' => 0,
    ];
  }

  /**
   * Compare internal product price with web search results
   *
   * This method analyzes web search results to extract competitor prices
   * and compares them with the internal product price. It calculates
   * percentage differences and provides a recommendation.
   *
   * @param array $product Internal product data (product_id, name, price)
   * @param array $webResults Web search results from search() method
   * @return array Comparison data with analysis and recommendation
   */
  public function comparePrice(array $product, array $webResults): array
  {
    try {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Comparing price for product: {$product['name']} (Internal price: {$product['price']})",
          'info'
        );
      }

      $internalPrice = (float)$product['price'];
      $productName = $product['name'];
      $competitorPrices = [];

      // Extract prices from web search results
      if (isset($webResults['items']) && is_array($webResults['items'])) {
        foreach ($webResults['items'] as $item) {
          $extractedPrice = $this->extractPriceFromResult($item);
          
          if ($extractedPrice !== null) {
            $competitorPrices[] = [
              'source' => $item['source'] ?? 'Unknown',
              'url' => $item['link'] ?? '',
              'price' => $extractedPrice,
              'title' => $item['title'] ?? '',
              'snippet' => $item['snippet'] ?? '',
            ];
          }
        }
      }

      if (empty($competitorPrices)) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "No competitor prices found in web results",
            'info'
          );
        }

        return [
          'success' => true,
          'product_name' => $productName,
          'internal_price' => $internalPrice,
          'competitor_prices' => [],
          'comparison' => [
            'cheapest' => null,
            'most_expensive' => null,
            'average_competitor_price' => null,
            'price_differences' => [],
          ],
          'recommendation' => 'No competitor prices found for comparison.',
          'competitive_status' => 'unknown',
        ];
      }

      // Calculate price differences
      $priceDifferences = [];
      $competitorPriceValues = [];

      foreach ($competitorPrices as $competitor) {
        $competitorPrice = $competitor['price'];
        $competitorPriceValues[] = $competitorPrice;
        
        $difference = $internalPrice - $competitorPrice;
        $percentageDiff = $competitorPrice > 0 ? ($difference / $competitorPrice) * 100 : 0;

        $priceDifferences[] = [
          'source' => $competitor['source'],
          'url' => $competitor['url'],
          'competitor_price' => $competitorPrice,
          'difference' => round($difference, 2),
          'percentage_difference' => round($percentageDiff, 2),
          'status' => $difference < 0 ? 'cheaper' : ($difference > 0 ? 'more_expensive' : 'same'),
        ];
      }

      // Find cheapest and most expensive
      $cheapest = null;
      $mostExpensive = null;
      $minPrice = PHP_FLOAT_MAX;
      $maxPrice = 0;

      foreach ($priceDifferences as $diff) {
        if ($diff['competitor_price'] < $minPrice) {
          $minPrice = $diff['competitor_price'];
          $cheapest = $diff;
        }
        if ($diff['competitor_price'] > $maxPrice) {
          $maxPrice = $diff['competitor_price'];
          $mostExpensive = $diff;
        }
      }

      // Calculate average competitor price
      $avgCompetitorPrice = count($competitorPriceValues) > 0 
        ? array_sum($competitorPriceValues) / count($competitorPriceValues) 
        : 0;

      // Determine competitive status and recommendation
      $competitiveStatus = 'competitive';
      $recommendation = '';

      $avgDifference = $internalPrice - $avgCompetitorPrice;
      $avgPercentageDiff = $avgCompetitorPrice > 0 ? ($avgDifference / $avgCompetitorPrice) * 100 : 0;

      if ($avgPercentageDiff > 10) {
        // Our price is more than 10% higher than average (positive difference = we're more expensive)
        $competitiveStatus = 'not_competitive';
        $recommendation = sprintf(
          "Your price (%.2f) is %.1f%% higher than the average competitor price (%.2f). Consider reducing the price to remain competitive.",
          $internalPrice,
          abs($avgPercentageDiff),
          $avgCompetitorPrice
        );
      } elseif ($avgPercentageDiff < -10) {
        // Our price is more than 10% lower than average (negative difference = we're cheaper)
        $competitiveStatus = 'very_competitive';
        $recommendation = sprintf(
          "Your price (%.2f) is %.1f%% lower than the average competitor price (%.2f). You have a strong competitive advantage.",
          $internalPrice,
          abs($avgPercentageDiff),
          $avgCompetitorPrice
        );
      } else {
        // Our price is within 10% of average
        $competitiveStatus = 'competitive';
        $recommendation = sprintf(
          "Your price (%.2f) is competitive, within %.1f%% of the average competitor price (%.2f).",
          $internalPrice,
          abs($avgPercentageDiff),
          $avgCompetitorPrice
        );
      }

      // Add specific comparison to cheapest competitor
      if ($cheapest !== null) {
        $diffToCheapest = $internalPrice - $cheapest['competitor_price'];
        $percentDiffToCheapest = $cheapest['competitor_price'] > 0 
          ? ($diffToCheapest / $cheapest['competitor_price']) * 100 
          : 0;

        if ($percentDiffToCheapest > 5) {
          $recommendation .= sprintf(
            " The cheapest competitor (%s) offers this product at %.2f, which is %.1f%% lower than your price.",
            $cheapest['source'],
            $cheapest['competitor_price'],
            abs($percentDiffToCheapest)
          );
        }
      }

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Price comparison complete: Status={$competitiveStatus}, Avg competitor price={$avgCompetitorPrice}, Internal price={$internalPrice}",
          'info'
        );
      }

      return [
        'success' => true,
        'product_name' => $productName,
        'internal_price' => $internalPrice,
        'competitor_prices' => $competitorPrices,
        'comparison' => [
          'cheapest' => $cheapest,
          'most_expensive' => $mostExpensive,
          'average_competitor_price' => round($avgCompetitorPrice, 2),
          'price_differences' => $priceDifferences,
          'average_percentage_difference' => round($avgPercentageDiff, 2),
        ],
        'recommendation' => $recommendation,
        'competitive_status' => $competitiveStatus,
        'total_competitors_found' => count($competitorPrices),
      ];

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error comparing prices: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'error' => 'Unable to compare prices: ' . $e->getMessage(),
        'product_name' => $product['name'] ?? 'Unknown',
        'internal_price' => $product['price'] ?? 0,
      ];
    }
  }

  /**
   * Extract price from web search result
   *
   * Attempts to extract a price value from the title, snippet, or other fields
   * of a web search result. Supports multiple currency formats.
   *
   * @param array $result Web search result item
   * @return float|null Extracted price or null if not found
   */
  private function extractPriceFromResult(array $result): ?float
  {
    $text = '';
    
    // Combine title and snippet for price extraction
    if (isset($result['title'])) {
      $text .= ' ' . $result['title'];
    }
    if (isset($result['snippet'])) {
      $text .= ' ' . $result['snippet'];
    }

    if (empty($text)) {
      return null;
    }

    // Price patterns for different formats
    // Supports: $99.99, €99.99, 99.99€, £99.99, 99,99€, $1,049.99, etc.
    $patterns = [
      // US/UK format with thousand separators: $1,049.99, €1,234.56
      [
        'pattern' => '/[\$€£]\s*(\d{1,3}(?:,\d{3})+\.\d{2})/',
        'thousand_sep' => ',',
        'decimal_sep' => '.',
      ],
      // US/UK format without thousand separators: $99.99, €99.99
      [
        'pattern' => '/[\$€£]\s*(\d{1,6}\.\d{2})/',
        'thousand_sep' => '',
        'decimal_sep' => '.',
      ],
      // European format with comma as decimal: 99,99€, 79,99€
      [
        'pattern' => '/(\d{1,6},\d{2})\s*[€£\$]/',
        'thousand_sep' => '',
        'decimal_sep' => ',',
      ],
      // US/UK format suffix: 1,049.99€, 999.99$
      [
        'pattern' => '/(\d{1,3}(?:,\d{3})+\.\d{2})\s*[\$€£]/',
        'thousand_sep' => ',',
        'decimal_sep' => '.',
      ],
      // Simple format suffix: 99.99€, 99.99$
      [
        'pattern' => '/(\d{1,6}\.\d{2})\s*[\$€£]/',
        'thousand_sep' => '',
        'decimal_sep' => '.',
      ],
      // Price with label: price: $1,049.99
      [
        'pattern' => '/price[:\s]+[\$€£]?\s*(\d{1,3}(?:,\d{3})+\.\d{2})/i',
        'thousand_sep' => ',',
        'decimal_sep' => '.',
      ],
      // Price with label simple: price: 99.99
      [
        'pattern' => '/price[:\s]+[\$€£]?\s*(\d{1,6}\.\d{2})/i',
        'thousand_sep' => '',
        'decimal_sep' => '.',
      ],
      // Currency code suffix: 1,049.99 USD, 99.99 EUR
      [
        'pattern' => '/(\d{1,3}(?:,\d{3})+\.\d{2})\s*(?:USD|EUR|GBP)/i',
        'thousand_sep' => ',',
        'decimal_sep' => '.',
      ],
      [
        'pattern' => '/(\d{1,6}\.\d{2})\s*(?:USD|EUR|GBP)/i',
        'thousand_sep' => '',
        'decimal_sep' => '.',
      ],
    ];

    foreach ($patterns as $patternConfig) {
      if (preg_match($patternConfig['pattern'], $text, $matches)) {
        // Extract the numeric part
        $priceStr = $matches[1];
        
        // Remove thousand separators if present
        if (!empty($patternConfig['thousand_sep'])) {
          $priceStr = str_replace($patternConfig['thousand_sep'], '', $priceStr);
        }
        
        // Normalize decimal separator to dot
        if ($patternConfig['decimal_sep'] === ',') {
          $priceStr = str_replace(',', '.', $priceStr);
        }
        
        // Convert to float
        $price = (float)$priceStr;
        
        // Sanity check: price should be between 0.01 and 999999
        if ($price > 0 && $price < 1000000) {
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Extracted price: {$price} from text: " . substr($text, 0, 100),
              'info'
            );
          }
          return $price;
        }
      }
    }

    return null;
  }

  /**
   * Find product in database before web search
   * 
   * 🔧 TASK 4.3.7.2: Return entity_id and entity_type for memory tracking
   * TASK 4.3.5: Added language_id parameter to handle AJAX context
   * 
   * Uses IntentAnalyzer.detectEntityFromEmbeddings() to find product
   * with embedding search first, then falls back to SQL LIKE search
   *
   * @param string $query User query containing product reference
   * @param int|null $languageId Language ID (optional, defaults to 1 if Language not registered)
   * @return array|null Product data (product_id, name, price, entity_id, entity_type) or null if not found
   */
  public function findProductInDatabase(string $query, ?int $languageId = null): ?array
  {
    try {
      // TASK 4.3.5: Get language_id from parameter, Language registry, or default to 1
      if ($languageId === null) {
        $languageId = $this->language !== null ? $this->language->getId() : 1;
      }
      
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "TASK 4.3.7.2: Searching for product in database: {$query} (language_id: {$languageId})",
          'info'
        );
      }

      // Step 1: Try embedding search using IntentAnalyzer
      $intentAnalyzer = new \ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\IntentAnalyzer(null, $this->debug);
      $entityResult = $intentAnalyzer->detectEntityFromEmbeddings($query, 'product');

      if ($entityResult !== null && isset($entityResult['entity_id'])) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Product found via embeddings: ID={$entityResult['entity_id']}, Confidence={$entityResult['confidence']}",
            'info'
          );
        }

        // Get product details from database
        $product = $this->getProductById($entityResult['entity_id'], $languageId);
        
        if ($product !== null) {
          $product['detection_method'] = 'embedding';
          $product['confidence'] = $entityResult['confidence'];
          
          // 🔧 TASK 4.3.7.2: Add entity information for memory tracking
          $product['entity_id'] = $product['product_id'];
          $product['entity_type'] = 'product';
          
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "TASK 4.3.7.2: Product found with entity_id={$product['entity_id']}, entity_type={$product['entity_type']}",
              'info'
            );
          }
          
          return $product;
        }
      }

      // Step 2: Fallback to SQL LIKE search
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Embedding search failed, trying SQL LIKE search",
          'info'
        );
      }

      $product = $this->searchProductByName($query, $languageId);
      
      if ($product !== null) {
        $product['detection_method'] = 'sql_like';
        $product['confidence'] = 0.6;
        
        // 🔧 TASK 4.3.7.2: Add entity information for memory tracking
        $product['entity_id'] = $product['product_id'];
        $product['entity_type'] = 'product';
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "TASK 4.3.7.2: Product found via SQL LIKE: ID={$product['product_id']}, Name={$product['name']}, entity_type={$product['entity_type']}",
            'info'
          );
        }
        
        return $product;
      }

      // Step 3: No product found
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "No product found in database for query: {$query}",
          'info'
        );
      }

      return null;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error finding product in database: " . $e->getMessage(),
        'error'
      );
      return null;
    }
  }

  /**
   * Get product details by ID
   *
   * @param int $productId Product ID
   * @param int|null $languageId Language ID (optional, defaults to 1 if Language not registered)
   * @return array|null Product data or null
   */
  private function getProductById(int $productId, ?int $languageId = null): ?array
  {
    try {
      // TASK 4.3.5: Get language_id from parameter, Language registry, or default to 1
      if ($languageId === null) {
        $languageId = $this->language !== null ? $this->language->getId() : 1;
      }
      
      $Qproduct = $this->db->prepare('
        SELECT p.products_id as product_id,
               pd.products_name as name,
               p.products_price as price,
               p.products_model as model
        FROM :table_products p
        INNER JOIN :table_products_description pd ON p.products_id = pd.products_id
        WHERE p.products_id = :product_id
          AND pd.language_id = :language_id
        LIMIT 1
      ');

      $Qproduct->bindInt(':product_id', $productId);
      $Qproduct->bindInt(':language_id', $languageId);
      $Qproduct->execute();

      if ($Qproduct->fetch()) {
        return [
          'product_id' => $Qproduct->valueInt('product_id'),
          'name' => $Qproduct->value('name'),
          'price' => $Qproduct->valueDecimal('price'),
          'model' => $Qproduct->value('model'),
        ];
      }

      return null;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error getting product by ID: " . $e->getMessage(),
        'error'
      );
      return null;
    }
  }

  /**
   * Search product by name using SQL LIKE
   *
   * @param string $query Search query
   * @param int|null $languageId Language ID (optional, defaults to 1 if Language not registered)
   * @return array|null Product data or null
   */
  private function searchProductByName(string $query, ?int $languageId = null): ?array
  {
    try {
      // TASK 4.3.5: Get language_id from parameter, Language registry, or default to 1
      if ($languageId === null) {
        $languageId = $this->language !== null ? $this->language->getId() : 1;
      }
      
      // Extract potential product name from query
      // Remove common words like "stock", "price", "compare", etc.
      $cleanQuery = preg_replace('/\b(stock|price|compare|competitors?|show|give|display|of|the|a|an)\b/i', '', $query);
      $cleanQuery = trim($cleanQuery);

      if (empty($cleanQuery)) {
        return null;
      }

      $Qproduct = $this->db->prepare('
        SELECT p.products_id as product_id,
               pd.products_name as name,
               p.products_price as price,
               p.products_model as model
        FROM :table_products p
        INNER JOIN :table_products_description pd ON p.products_id = pd.products_id
        WHERE (pd.products_name LIKE :search_term
           OR p.products_model LIKE :search_term)
          AND pd.language_id = :language_id
          AND p.products_status = 1
        ORDER BY 
          CASE 
            WHEN pd.products_name = :exact_term THEN 1
            WHEN pd.products_name LIKE :starts_with THEN 2
            ELSE 3
          END
        LIMIT 1
      ');

      $searchTerm = '%' . $cleanQuery . '%';
      $startsWith = $cleanQuery . '%';

      $Qproduct->bindValue(':search_term', $searchTerm);
      $Qproduct->bindValue(':exact_term', $cleanQuery);
      $Qproduct->bindValue(':starts_with', $startsWith);
      $Qproduct->bindInt(':language_id', $languageId);
      $Qproduct->execute();

      if ($Qproduct->fetch()) {
        return [
          'product_id' => $Qproduct->valueInt('product_id'),
          'name' => $Qproduct->value('name'),
          'price' => $Qproduct->valueDecimal('price'),
          'model' => $Qproduct->value('model'),
        ];
      }

      return null;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error searching product by name: " . $e->getMessage(),
        'error'
      );
      return null;
    }
  }
}