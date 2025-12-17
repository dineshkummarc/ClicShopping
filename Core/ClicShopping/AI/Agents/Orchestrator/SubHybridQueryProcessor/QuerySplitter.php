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

use ClicShopping\AI\Domain\Patterns\WebSearchPattern;
use ClicShopping\AI\Domain\Patterns\ComplexQueryPattern;
use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;
use ClicShopping\AI\Domain\Patterns\DependencyPattern;
use ClicShopping\AI\Domain\Semantics\Semantics;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * QuerySplitter - Splits complex queries into sub-queries
 *
 * Responsibilities:
 * - Detect report/analysis queries and split into analytics + semantic + web_search
 * - Detect comma-separated intents and split accordingly
 * - Detect "and then" patterns and split sequentially
 * - Detect multiple questions (multiple "?") and split accordingly
 * - Handle analytics + analytics combinations (e.g., "stock AND sales")
 * - Use LLM for intelligent splitting with fallback to simple splitting
 *
 * Requirements:
 * - REQ-3.1: Detect report/analysis queries and split into analytics + semantic + web_search
 * - REQ-3.2: Detect comma-separated intents and split accordingly
 * - REQ-3.3: Detect "and then" patterns and split sequentially
 * - REQ-3.4: Detect multiple questions (multiple "?") and split accordingly
 * - REQ-3.5: Handle analytics + analytics combinations
 * - REQ-3.6: Use LLM for intelligent splitting with fallback
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubHybridQueryProcessor
 * @since 2025-12-14
 */
class QuerySplitter extends BaseQueryProcessor
{
  /**
   * @var QueryClassifier Query classifier for sub-query classification
   */
  private QueryClassifier $classifier;

  /**
   * @var array Translation cache for performance optimization
   */
  private static array $translationCache = [];

  /**
   * @var int Translation cache TTL in seconds (1 hour)
   */
  private const TRANSLATION_CACHE_TTL = 3600;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   * @param QueryClassifier|null $classifier Query classifier instance (auto-created if null)
   */
  public function __construct(bool $debug = false, ?QueryClassifier $classifier = null)
  {
    parent::__construct($debug, 'QuerySplitter');
    $this->classifier = $classifier ?? new QueryClassifier($debug);
  }

  /**
   * Process query splitting
   *
   * @param mixed $input Query string to split or array with 'query' and 'intent'
   * @param array $context Additional context (may include 'intent' for hybrid detection)
   * @return array Array of sub-queries with types and priorities
   * @throws \Exception If input is invalid
   */
  public function process($input, array $context = []): array
  {
    if (!$this->validate($input)) {
      throw new \Exception("Invalid input for QuerySplitter: input must be a non-empty string or array with 'query' key");
    }

    // Extract query and intent from input
    if (is_array($input)) {
      $query = $input['query'] ?? '';
      $intent = $input['intent'] ?? [];
    } else {
      $query = $input;
      $intent = $context['intent'] ?? [];
    }

    // Check if query is hybrid
    if ($this->detectMultipleIntents($query, $intent)) {
      return $this->splitHybridQuery($query, $intent);
    }

    // Check if query is complex
    return $this->splitComplexQuery($query);
  }

  /**
   * Validate input is a non-empty string or array with 'query' key
   *
   * @param mixed $input Input to validate
   * @return bool True if valid, false otherwise
   */
  public function validate($input): bool
  {
    if (is_string($input)) {
      return !empty(trim($input));
    }
    
    if (is_array($input)) {
      return isset($input['query']) && !empty(trim($input['query']));
    }
    
    return false;
  }

  /**
   * Detect if query contains multiple intents (hybrid query)
   *
   * @param string $query Query to analyze
   * @param array $intent Intent analysis (optional)
   * @return bool True if hybrid query detected
   */
  public function detectMultipleIntents(string $query, array $intent = []): bool
  {
    try {
      if ($intent['is_hybrid'] ?? false) return true;

      // Report/analysis patterns (CENTRALIZED)
      $reportPatterns = ComplexQueryPattern::getReportPatterns();
      
      foreach ($reportPatterns as $pattern) {
        if (preg_match($pattern, $query)) {
          if ($this->debug) $this->logInfo("Hybrid: report pattern", ['query' => $query]);
          return true;
        }
      }

      // Multiple verbs (CENTRALIZED)
      $verbs = ComplexQueryPattern::getActionVerbs();
      $verbCount = count(array_filter($verbs, fn($v) => preg_match('/\b' . preg_quote($v, '/') . '\b/i', $query)));
      
      if ($verbCount >= 2) {
        if ($this->debug) $this->logInfo("Hybrid: multiple verbs", ['count' => $verbCount]);
        return true;
      }

      // Explicit connectors (CENTRALIZED)
      $connectors = ComplexQueryPattern::getConnectors()['strong'];
      foreach ($connectors as $connector) {
        if (stripos($query, $connector) !== false) {
          if ($this->debug) $this->logInfo("Hybrid: connector", ['connector' => $connector]);
          return true;
        }
      }

      // Multiple questions or semicolons
      if (substr_count($query, '?') >= 2 || substr_count($query, ';') >= 1) {
        if ($this->debug) $this->logInfo("Hybrid: multiple questions/semicolons");
        return true;
      }

      // Period-delimited sentences with dependencies (CENTRALIZED)
      if (DependencyPattern::hasPeriodDelimitedDependencies($query)) {
        if ($this->debug) $this->logInfo("Hybrid: period-delimited with dependency");
        return true;
      }

      // Comma-separated with different types
      if (strpos($query, ',') !== false) {
        $parts = array_filter(array_map('trim', explode(',', $query)), fn($p) => strlen($p) >= 3);
        if (count($parts) >= 2) {
          $types = array_map(fn($p) => $this->classifier->classifyQueryType($p)['type'], $parts);
          if (count(array_unique($types)) > 1) {
            if ($this->debug) $this->logInfo("Hybrid: comma-separated different types");
            return true;
          }
        }
      }

      // Analytics + analytics (CENTRALIZED)
      $analyticsKeywords = AnalyticsPattern::getAnalyticsKeywords();
      $analyticsMatches = count(array_filter($analyticsKeywords, fn($k) => preg_match('/\b' . preg_quote($k, '/') . '\b/i', $query)));
      
      if ($analyticsMatches >= 2) {
        // Check for explicit connectors
        foreach ($connectors as $connector) {
          if (preg_match('/\b' . preg_quote($connector, '/') . '\b/i', $query)) {
            if ($this->debug) $this->logInfo("Hybrid: analytics + analytics", ['matches' => $analyticsMatches]);
            return true;
          }
        }
        
        // Check for simple "and" connector with analytics keywords
        if (preg_match('/\band\b/i', $query)) {
          // Split by "and" and check if both parts contain analytics keywords
          $parts = preg_split('/\band\b/i', $query, -1, PREG_SPLIT_NO_EMPTY);
          $parts = array_filter(array_map('trim', $parts), fn($p) => strlen($p) >= 3);
          
          if (count($parts) >= 2) {
            // Check if each part contains at least one analytics keyword
            $analyticsPartsCount = 0;
            foreach ($parts as $part) {
              $hasAnalyticsKeyword = false;
              foreach ($analyticsKeywords as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $part)) {
                  $hasAnalyticsKeyword = true;
                  break;
                }
              }
              if ($hasAnalyticsKeyword) {
                $analyticsPartsCount++;
              }
            }
            
            // If at least 2 parts have analytics keywords, it's a hybrid query
            if ($analyticsPartsCount >= 2) {
              if ($this->debug) $this->logInfo("Hybrid: analytics + analytics (simple 'and')", ['matches' => $analyticsMatches, 'parts' => $analyticsPartsCount]);
              return true;
            }
          }
        }
      }

      return false;
    } catch (\Exception $e) {
      $this->logError("Error in detectMultipleIntents", $e);
      return false;
    }
  }

  /**
   * Split hybrid query into sub-queries using LLM
   *
   * @param string $query Original query
   * @param array $intent Intent analysis
   * @return array Array of sub-queries with their types
   */
  public function splitHybridQuery(string $query, array $intent): array
  {
    try {
      if ($this->debug) $this->logInfo("Splitting hybrid query", ['query' => $query]);

      $prompt = "Split this query into separate sub-queries. Each sub-query should be independent.\n\n";
      $prompt .= "Query: {$query}\n\n";
      $prompt .= "Return a JSON array of sub-queries with their type (analytics, semantic, or web_search).\n";
      $prompt .= "Example: [{\"query\": \"sales today\", \"type\": \"analytics\"}, {\"query\": \"what is our return policy\", \"type\": \"semantic\"}]\n\n";
      $prompt .= "JSON:";

      $validatedPrompt = $this->validatePrompt($prompt);
      if (empty($validatedPrompt)) {
        $this->logWarning("Prompt validation failed, using simple split");
        return $this->simpleSplit($query);
      }

      try {
        $response = Gpt::getGptResponse($validatedPrompt, 300);

        if (preg_match('/\[.*\]/s', $response, $matches)) {
          $subQueries = json_decode($matches[0], true);

          if (is_array($subQueries) && !empty($subQueries)) {
            // Filter out short parts (<=5 chars)
            $subQueries = array_values(array_filter($subQueries, fn($sq) => strlen(trim($sq['query'])) > 5));
            
            // Add classification, confidence, priority, and dependency detection
            foreach ($subQueries as $index => &$subQuery) {
              // Always get classification to ensure confidence scores
              $classification = $this->classifier->classifyQueryType($subQuery['query']);
              
              // Set type if missing or empty
              if (!isset($subQuery['type']) || empty($subQuery['type'])) {
                $subQuery['type'] = $classification['type'];
              }
              
              // Always set confidence score
              if (!isset($subQuery['confidence'])) {
                $subQuery['confidence'] = $classification['confidence'];
              }
              
              // Always set priority
              if (!isset($subQuery['priority'])) {
                $subQuery['priority'] = $index + 1;
              }
              
              // Detect dependencies: if this is not the first query, check for dependency indicators (CENTRALIZED)
              if ($index > 0 && !isset($subQuery['depends_on'])) {
                $prevQuery = $subQueries[$index - 1]['query'];
                $currentQuery = $subQuery['query'];
                
                // Translate to English for pattern matching (English-only patterns)
                // LLM may return queries in original language, so we translate them
                // Using cached translation for performance
                $prevQueryEnglish = $this->translateToEnglishCached($prevQuery);
                $currentQueryEnglish = $this->translateToEnglishCached($currentQuery);
                
                // Use centralized DependencyPattern for detection (English patterns)
                $dependency = DependencyPattern::detectDependency($prevQueryEnglish, $currentQueryEnglish);
                
                if ($dependency['has_dependency']) {
                  $subQuery['depends_on'] = $index; // Depends on previous sub-query (1-indexed)
                  $subQuery['dependency_type'] = $dependency['type'];
                  
                  if ($this->debug) {
                    $this->logInfo("Dependency detected", [
                      'query' => $currentQuery,
                      'query_en' => $currentQueryEnglish,
                      'type' => $dependency['type'],
                      'indicator' => $dependency['indicator']
                    ]);
                  }
                }
              }
            }

            if ($this->debug) $this->logInfo("Query split via LLM", ['count' => count($subQueries)]);
            return $subQueries;
          }
        }

        $this->logWarning("LLM response invalid JSON, using fallback");
        return $this->simpleSplit($query);

      } catch (\Exception $e) {
        $this->logWarning("LLM error, using fallback", ['error' => $e->getMessage()]);
        return $this->simpleSplit($query);
      }

    } catch (\Exception $e) {
      $this->logError("Critical error splitting query", $e, ['query' => $query]);
      return [['query' => $query, 'type' => 'semantic', 'confidence' => 0.3, 'error' => 'Query splitting failed']];
    }
  }

  /**
   * Split complex query into sub-queries
   *
   * @param string $query Original query
   * @return array Array of sub-queries with types and priorities
   */
  public function splitComplexQuery(string $query): array
  {
    try {
      if ($this->debug) $this->logInfo("Splitting complex query", ['query' => $query]);

      // Report/Analysis queries
      if (preg_match('/\b(create|generate|make|build)\s+(?:(?:a|an)\s+)?(?:(?:analysis|detailed|comprehensive)\s+)?(report|analysis|summary)\s+(?:for|of|on|about)\s+(.+)/i', $query, $matches)) {
        return $this->splitReportQuery(trim($matches[3]), $query);
      }

      // Try splitting by various delimiters
      $delimiters = [
        'comma' => ',',
        'and_then' => '/\s+and\s+then\s+/i',
        'period' => '/\.\s+/i',  // Period followed by space (sentence boundary)
        'and' => '/\band\b/i',
        'question' => '?',
        'semicolon' => ';'
      ];

      foreach ($delimiters as $type => $delimiter) {
        $result = $this->splitByDelimiter($query, $delimiter, $type);
        if (!empty($result)) return $result;
      }

      // Default: single query
      if ($this->debug) $this->logInfo("Query not split, treating as single");
      $classification = $this->classifier->classifyQueryType($query);
      return [['query' => $query, 'type' => $classification['type'], 'confidence' => $classification['confidence'], 'priority' => 1, 'original_part' => $query]];

    } catch (\Exception $e) {
      $this->logError("Error in splitComplexQuery", $e);
      return [['query' => $query, 'type' => 'semantic', 'confidence' => 0.3, 'priority' => 1, 'original_part' => $query, 'error' => 'Query splitting failed']];
    }
  }

  /**
   * Split report query into analytics + semantic + optional web_search
   */
  private function splitReportQuery(string $subject, string $originalQuery): array
  {
    $subQueries = [
      ['query' => "Get {$subject} stock and sales data", 'type' => 'analytics', 'priority' => 1, 'original_part' => $originalQuery],
      ['query' => "Get {$subject} product information and features", 'type' => 'semantic', 'priority' => 2, 'original_part' => $originalQuery]
    ];

    if (WebSearchPattern::isExternalQuery($subject)) {
      $subQueries[] = ['query' => "Search for {$subject} market analysis and competitor reviews", 'type' => 'web_search', 'priority' => 3, 'original_part' => $originalQuery];
    }

    if ($this->debug) $this->logInfo("Split report query", ['subject' => $subject, 'count' => count($subQueries)]);
    return $subQueries;
  }

  /**
   * Split query by delimiter
   */
  private function splitByDelimiter(string $query, $delimiter, string $type): array
  {
    $parts = is_string($delimiter) && !preg_match('/^\//', $delimiter)
      ? explode($delimiter, $query)
      : preg_split($delimiter, $query, -1, PREG_SPLIT_NO_EMPTY);

    $parts = array_filter(array_map('trim', $parts), fn($p) => strlen($p) >= 3);

    if (count($parts) < 2) return [];

    // Special handling for "and" - only split if analytics + analytics
    if ($type === 'and') {
      $analyticsCount = count(array_filter($parts, fn($p) => $this->classifier->classifyQueryType($p)['type'] === 'analytics'));
      if ($analyticsCount < 2) return [];
    }

    // Special handling for "period" - detect dependencies (CENTRALIZED)
    $hasDependency = false;
    if ($type === 'period' && count($parts) >= 2) {
      $dependency = DependencyPattern::detectDependency($parts[0], $parts[1]);
      $hasDependency = $dependency['has_dependency'];
    }

    $subQueries = [];
    foreach ($parts as $index => $part) {
      $classification = $this->classifier->classifyQueryType($part);
      $subQuery = [
        'query' => $type === 'question' ? $part . '?' : $part,
        'type' => $classification['type'],
        'confidence' => $classification['confidence'],
        'priority' => $index + 1,
        'original_part' => $part
      ];
      
      // Add dependency information if detected
      if ($hasDependency && $index > 0) {
        $subQuery['depends_on'] = $index; // Depends on previous sub-query (1-indexed)
        $subQuery['dependency_type'] = 'sequential'; // Sequential dependency
      }
      
      $subQueries[] = $subQuery;
    }

    if ($this->debug && !empty($subQueries)) {
      $depInfo = $hasDependency ? ' (with dependencies)' : '';
      $this->logInfo("Split by {$type}{$depInfo}", ['count' => count($subQueries)]);
    }
    return $subQueries;
  }

  /**
   * Detect if query contains multiple SQL queries (AND connector)
   *
   * Uses MultiQueryPattern class for pattern management (English-only)
   * 
   * @param string $query Query to analyze
   * @return array|false Array of sub-queries or false if single query
   */
  public function detectMultipleSqlQueries(string $query): array|false
  {
    $result = \ClicShopping\AI\Domain\Patterns\MultiQueryPattern::detectMultipleQueries($query);
    
    if ($result !== false && $this->debug) {
      $this->logInfo("Detected multi-query", ['sub_query_count' => count($result)]);
    }
    
    return $result;
  }

  /**
   * Simple fallback split on connectors
   *
   * @param string $query Query to split
   * @return array Array of sub-queries with types
   */
  public function simpleSplit(string $query): array
  {
    try {
      $parts = array_filter(array_map('trim', preg_split('/\b(and|then|also)\b/i', $query)), fn($p) => strlen($p) >= 3);
      
      $subQueries = [];
      foreach ($parts as $part) {
        $classification = $this->classifier->classifyQueryType($part);
        $subQueries[] = ['query' => $part, 'type' => $classification['type'], 'confidence' => $classification['confidence']];
      }

      if ($this->debug) $this->logInfo("Simple split", ['count' => count($subQueries)]);
      return $subQueries;

    } catch (\Exception $e) {
      $this->logError("Error in simple split", $e);
      return [['query' => $query, 'type' => 'semantic', 'confidence' => 0.3]];
    }
  }

  /**
   * Translate query to English with caching for performance
   *
   * This method caches translations to avoid repeated API calls for the same query.
   * Cache entries expire after 1 hour (TRANSLATION_CACHE_TTL).
   *
   * @param string $query Query to translate
   * @return string Translated query in English
   */
  private function translateToEnglishCached(string $query): string
  {
    // Generate cache key
    $cacheKey = md5($query);
    
    // Check if translation is cached and not expired
    if (isset(self::$translationCache[$cacheKey])) {
      $cached = self::$translationCache[$cacheKey];
      if (time() - $cached['timestamp'] < self::TRANSLATION_CACHE_TTL) {
        if ($this->debug) {
          $this->logInfo("Translation cache hit", ['query' => $query]);
        }
        return $cached['translation'];
      } else {
        // Cache expired, remove it
        unset(self::$translationCache[$cacheKey]);
      }
    }
    
    // Cache miss or expired, translate
    if ($this->debug) {
      $this->logInfo("Translation cache miss", ['query' => $query]);
    }
    
    $translation = Semantics::translateToEnglish($query);
    
    // Store in cache
    self::$translationCache[$cacheKey] = [
      'translation' => $translation,
      'timestamp' => time(),
    ];
    
    // Clean old cache entries (keep max 100 entries)
    if (count(self::$translationCache) > 100) {
      // Remove oldest entries
      uasort(self::$translationCache, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
      self::$translationCache = array_slice(self::$translationCache, -100, 100, true);
    }
    
    return $translation;
  }

  /**
   * Validate prompt before LLM call
   */
  private function validatePrompt(string $prompt): string
  {
    try {
      if (empty(trim($prompt))) return '';
      if (strlen($prompt) > 4096) $prompt = substr($prompt, 0, 4096);
      if (preg_match('/<script|<iframe/i', $prompt)) {
        $prompt = preg_replace('/<script.*?<\/script>|<iframe.*?<\/iframe>/is', '', $prompt);
      }
      return $prompt;
    } catch (\Exception $e) {
      $this->logError("Error validating prompt", $e);
      return $prompt;
    }
  }
}
