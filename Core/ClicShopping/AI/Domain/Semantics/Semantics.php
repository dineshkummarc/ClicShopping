<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domain\Semantics;

use AllowDynamicProperties;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

use ClicShopping\AI\Rag\MultiDBRAGManager;
use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;
use ClicShopping\AI\Domain\Patterns\HybridPattern;
use ClicShopping\AI\Domain\Patterns\SemanticsPattern;
use ClicShopping\AI\Domain\Patterns\WebSearchPattern;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Interfaces\ConfigurableComponent;

// Import SubSemantic components
use ClicShopping\AI\Domain\Semantics\SubSemantics\ClassificationEngine;

use ClicShopping\AI\Domain\Semantics\SubSemantics\TranslationHandler;
use ClicShopping\AI\Infrastructure\Cache\TranslationCache;
use ClicShopping\AI\Domain\Semantics\SubSemantics\ThresholdManager;

/*
 * This class is responsible for semantic analysis and classification of queries.
 * It uses the OpenAI API to translate and classify queries, and also logs security events.
 * Implements ConfigurableComponent for dynamic configuration management.
 */
#[AllowDynamicProperties]
class Semantics implements ConfigurableComponent
{
  private static ?SecurityLogger $logger = null;
  
  // Configuration parameters with default values
  private static array $config = [
    'classification_threshold' => 3,
    'max_retries' => 3,
    'translation_cache_ttl' => 3600,
    'enable_fallback' => true,
    'enable_competitor_detection' => true
  ];

  public function __construct()
  {
    // 🔍 DEBUG: Vérifier que la classe modifiée est chargée
    error_log("=== SEMANTICS CLASS LOADED (MODIFIED VERSION) ===");
    error_log("File: " . __FILE__);
    error_log("Modified: " . date("Y-m-d H:i:s", filemtime(__FILE__)));
    
    self::initializeLogger();
    self::loadConfig();
  }

  /**
   * Loads configuration from JSON file
   * 
   * @return void
   */
  private static function loadConfig(): void
  {
    $configFile = __DIR__ . '/../config/chat_system_config.json';
    
    if (file_exists($configFile)) {
      $json = file_get_contents($configFile);
      $config = json_decode($json, true);
      
      if (isset($config['Semantics']) && is_array($config['Semantics'])) {
        // Merge loaded config with defaults
        self::$config = array_merge(self::$config, $config['Semantics']);
        
        if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
          error_log("Semantics configuration loaded from file");
        }
      }
    }
  }

  /**
   * Saves current configuration to JSON file
   * 
   * @return bool True if successful, false otherwise
   */
  private static function saveConfig(): bool
  {
    $configFile = __DIR__ . '/../config/chat_system_config.json';
    
    // Load existing config
    $fullConfig = [];
    if (file_exists($configFile)) {
      $json = file_get_contents($configFile);
      $fullConfig = json_decode($json, true) ?? [];
    }
    
    // Update Semantics section
    $fullConfig['Semantics'] = self::$config;
    
    // Save to file
    $json = json_encode($fullConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $result = file_put_contents($configFile, $json, LOCK_EX);
    
    if ($result !== false && defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log("Semantics configuration saved to file");
    }
    
    return $result !== false;
  }

  /**
   * Returns a singleton instance of the SecurityLogger
   * Initializes the logger with specified parameters if not already created
   *
   * @return void Instance of SecurityLogger
   */
  private static function initializeLogger(): void
  {
    if (self::$logger === null) {
      self::$logger = new SecurityLogger();
    }
  }

  /**
   * Logs security-related events
   * Delegates logging to the SecurityLogger instance
   *
   * @param string $text
   * @param string $alert
   * @return void
   */
  private static function logSecurityEvent(string $text, string $alert): void
  {
    self::initializeLogger(); // Ensure logger is initialized
    self::$logger->logSecurityEvent($text, $alert); // Call the logger's method
  }

  /**
   * Translate a given text to English using the OpenAI API.
   * 
   * 🔧 FIX 2025-12-10: Added timeout (5s) and fallback to original query
   * to prevent blocking when translation service fails
   * 
   * @param string $message
   * @param int|null $token
   * @return string Translated text or original message if translation fails
   */
  public static function translateToEnglish(string $message, int|null $token = 80): string
  {
    try {
      // Check if Language is registered in Registry
      if (!Registry::exists('Language')) {
        error_log("❌ CRITICAL: Language not registered in Registry");
        error_log("   This usually means the method is called outside normal application context");
        error_log("   Using hardcoded translation prompt as fallback");
        
        // Hardcoded fallback prompt
        $prompt = "Translate the following query to English. Follow these rules strictly:\n" .
                  "1. Preserve technical terms exactly as written.\n" .
                  "2. Do not alter acronyms or codes (e.g., EAN).\n" .
                  "3. If the content is in english, do not change anything.\n" .
                  "4. Provide only the raw translated text nothing else.\n";
      } else {
        $CLICSHOPPING_Language = Registry::get('Language');
        // Load SYSTEM prompt in English for better LLM evaluation (internal process)
        // Note: This evaluates the response quality, not user-facing
        $CLICSHOPPING_Language->loadDefinitions('rag_translation', 'en', null, 'ClicShoppingAdmin');

        $prompt = $CLICSHOPPING_Language->getDef('text_fix_translation');
      }

      // Call TranslationHandler
      // 🔧 TASK 3.2: Pass original message for fallback handling
      $startTime = microtime(true);
      $translated = TranslationHandler::translateToEnglish($prompt . ' ' . $message, self::$config['translation_cache_ttl'] ?? 3600, $message);
      $duration = microtime(true) - $startTime;
      
      error_log('================================');
      error_log('Translation result Semantics/Semantics:');
      error_log($translated);
      error_log("Duration: {$duration}s");
      error_log('================================');

      // 🔧 TASK 3.2: If translation is empty, use original message as fallback
      if (empty(trim($translated))) {
        error_log("⚠️ WARNING: Translation is empty after TranslationHandler, using original message");
        $translated = $message;
        
        self::logSecurityEvent(
          "Translation returned empty, using original message: {$message}",
          'warning'
        );
      }

      return $translated;
      
    } catch (\Exception $e) {
      // 🔧 TASK 3.2: Fallback to original message instead of throwing exception
      error_log("❌ TRANSLATION FAILED: " . $e->getMessage());
      error_log("   Original query: " . substr($message, 0, 100));
      error_log("🔄 FALLBACK: Using original message");
      
      self::logSecurityEvent(
        "Translation failed, using original message as fallback: " . $e->getMessage() . " | Original: {$message}",
        'warning'
      );
      
      // Return original message instead of throwing exception
      return $message;
    }
  }

  /**
   * Classifies query as 'analytics' or 'semantic' (delegates to ClassificationEngine)
   * 
   * 🔧 TASK 4.5.5 (2025-12-11): Updated to handle new array return format
   * 
   * @param string $text Text to classify
   * @return array ['type' => string, 'confidence' => float, 'reasoning' => string, 'sub_types' => array]
   */
  public static function checkSemantics(string $text): array
  {
    // Delegate to ClassificationEngine component
    return ClassificationEngine::checkSemantics($text);
  }

  /**
   * 🆕 Clean translation extraction
   * @param string $translatedQuery
   * @return string
   */
  private static function extractCleanTranslation(string $translatedQuery): string
  {
    // Delegate to TranslationHandler component
    return TranslationHandler::extractCleanTranslation($translatedQuery);
  }

  /**
   * Defines and returns regex patterns for parsing analytics-related queries.
   *
   * Patterns are organized into categories:
   * - entity: Basic query types for products, orders, customers, etc.
   * - time: Date and time period expressions
   * - stock: Inventory-related queries
   * - reference: Product identifiers (SKU, EAN, etc.)
   * - price: Price-related expressions and comparisons
   * - quantity: Quantity expressions and comparisons
   * - performance: Sales and business metrics
   * - comparison: Comparative analysis expressions
   * - category: Product categorization queries
   * - customer: Customer-related queries
   * - calculation: Mathematical operations
   * - filters: Query filtering expressions
   * - sorting: Result ordering expressions
   *
   * @return array<string, array<string>> Associative array where:
   *                                     - key: pattern category (string)
   *                                     - value: array of regex patterns (string[])
   *
   */
  public static function analyticsPatterns(): array
  {
    return AnalyticsPattern::getAnalyticsPatterns();
  }

  /**
   * Defines and returns regex patterns for parsing semantic-related queries.
   *
   * Patterns are organized into categories:
   * - geographic: Location and spatial queries
   * - product_info: Product information and details
   * - support: Customer support and help
   * - explanation: How-to and explanation requests
   * - preference: User preferences and recommendations
   * - availability: Service and product availability
   * - policy: Business policies and procedures
   * - contact: Contact and communication
   * - account: Account-related queries
   * - feedback: Reviews and opinions
   *
   * @return array<string, array<string>> Associative array where:
   *                                     - key: pattern category (string)
   *                                     - value: array of regex patterns (string[])
   */
  public static function semanticPatterns(): array
  {
    return SemanticsPattern::getSemanticPatterns();
  }

  /**
   * Classify the query as 'analytics' or 'semantic'.
   * This method first translates the text to English, then checks for critical patterns,
   * calculates a score based on matched patterns, and finally classifies the query.
   *
   * @param string $text The text to classify.
   * @param int threshold Adjust this threshold based on your needs
   * @param bool $alreadyTranslated If true, skip translation (text is already in English)
   * @return string The classification result: 'analytics' or 'semantic'.
   */
  public static function classifyQuery(string $text, int|null $threshold = null, bool $alreadyTranslated = false): string
  {
    // 🔍 DEBUG: Trace pour vérifier que cette méthode est appelée
    error_log("=== SEMANTICS::classifyQuery() CALLED ===");
    error_log("Input text: " . substr($text, 0, 100));
    error_log("Already translated: " . ($alreadyTranslated ? 'YES' : 'NO'));
    
    // Use configured threshold if not provided
    if ($threshold === null) {
      $threshold = self::$config['classification_threshold'];
    }
    
    // 🔧 FIX: Skip translation if already done
    if ($alreadyTranslated) {
      $translated = $text;
      error_log("Skipping translation, using input as-is");
    } else {
      $translated = self::translateToEnglish($text);
      error_log("Translated to: " . substr($translated, 0, 100));
    }
    
    // Clean the translation to remove GPT prefixes
    $cleanTranslated = self::extractCleanTranslation($translated);

    // DEBUG
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log("=== CLASSIFY QUERY ===");
      error_log("Original: " . substr($text, 0, 100));
      error_log("Translated (raw): " . substr($translated, 0, 100));
      error_log("Translated (clean): " . substr($cleanTranslated, 0, 100));
      
      // Structured logging for query translation
      self::initializeLogger();
      self::$logger->logStructured(
        'info',
        'Semantics',
        'translateQuery',
        [
          'original_query' => substr($text, 0, 200),
          'translated_query' => substr($cleanTranslated, 0, 200)
        ]
      );
    }
    
    // Use the cleaned translation for all subsequent processing
    $translated = $cleanTranslated;

    // 🆕 STEP 1: Check for web search patterns FIRST (highest priority)
    if (self::isWebSearchQuery($translated)) {
      if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        error_log("✓ WEB SEARCH PATTERN DETECTED → WEB_SEARCH");
        
        // Structured logging for web search detection
        self::initializeLogger();
        self::$logger->logStructured(
          'info',
          'Semantics',
          'webSearchDetected',
          [
            'query' => substr($translated, 0, 200),
            'result' => 'web_search'
          ]
        );
      }
      return 'web_search';
    }

    $hasCriticalMatch = self::hasCriticalMatch($translated);

    // Check geographic exceptions first
    if (SemanticsPattern::isGeographicQuery($text)) {
      return false;
    }

    if ($hasCriticalMatch === true) {
      if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        error_log("✓ CRITICAL MATCH FOUND → ANALYTICS");
        
        // Structured logging for critical match
        self::initializeLogger();
        self::$logger->logStructured(
          'info',
          'Semantics',
          'criticalMatch',
          [
            'query' => substr($translated, 0, 200),
            'result' => 'analytics'
          ]
        );
      }
      return 'analytics';
    }

    $score = self::calculateScore($translated);
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log("Pattern score: {$score}");
      self::logSecurityEvent("Total score: {$score}", 'info');
      
      // Structured logging for score calculation
      self::initializeLogger();
      self::$logger->logStructured(
        'info',
        'Semantics',
        'calculateScore',
        [
          'query' => substr($translated, 0, 200),
          'score' => $score,
          'threshold' => $threshold ?? 3
        ]
      );
    }

    if (is_null($threshold)) {
      $threshold = 3;
    }

    if ($score >= $threshold) {
      if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        error_log("✓ SCORE >= {$threshold} → ANALYTICS");
      }
      return 'analytics';
    }

    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      self::logSecurityEvent("No analytics pattern matched. Falling back to semantic analysis.", 'info');
    }

    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log("✗ Score {$score} < {$threshold}, using semantic fallback");
    }

    // 🔧 TASK 4.5.5 (2025-12-11): Handle new array return format from checkSemantics
    $classificationResult = self::checkSemantics($translated);
    
    // Extract type for backward compatibility
    $result = $classificationResult['type'] ?? 'semantic';

    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log("Final classification: " . $result . " (confidence: " . ($classificationResult['confidence'] ?? 0.5) . ")");
      self::logSecurityEvent("Final classification: " . $result . " (confidence: " . ($classificationResult['confidence'] ?? 0.5) . ")", 'info');
      
      // Structured logging for final classification
      self::initializeLogger();
      self::$logger->logStructured(
        'info',
        'Semantics',
        'finalClassification',
        [
          'query' => substr($text, 0, 200),
          'translated_query' => substr($translated, 0, 200),
          'score' => $score ?? 0,
          'had_critical_match' => $hasCriticalMatch ?? false,
          'used_fallback' => $score < ($threshold ?? 3),
          'result' => $result,
          'confidence' => $classificationResult['confidence'] ?? 0.5,
          'reasoning' => $classificationResult['reasoning'] ?? '',
          'sub_types' => $classificationResult['sub_types'] ?? []
        ]
      );
    }

    return $result;
  }

  /**
   * Detects competitor comparison queries (delegates to PatternAnalyzer)
   * 
   * @param string $text Text to analyze
   * @return bool True if competitor comparison detected
   */
  public static function isCompetitorComparisonQuery(string $text): bool
  {
    return WebSearchPattern::isCompetitorComparisonQuery($text);
  }

  /**
   * Checks if text contains critical analytics patterns (delegates to PatternAnalyzer)
   * 
   * @param string $text Text to analyze
   * @return bool True if critical pattern found
   */
  public static function hasCriticalMatch(string $text): bool
  {
    return HybridPattern::hasCriticalMatch($text);
  }

  /**
   * Calculate a score based on the number of matched patterns in the text.
   * The more patterns matched, the higher the score.
   *
   * @param string $text The text to analyze.
   * @return int The calculated score.
   */
  private static function calculateScore(string $text): int
  {
    // Delegate to ClassificationEngine component
    return ClassificationEngine::calculateScore($text);
  }



  /*************************
   * Implementation of ConfigurableComponent interface
   **************************/

  /**
   * Returns the list of configurable parameters
   * 
   * @return array Array of parameter definitions
   */
  public function getConfigurableParameters(): array
  {
    return [
      [
        'name' => 'classification_threshold',
        'type' => 'int',
        'default' => 3,
        'description' => 'Minimum score threshold for analytics classification',
        'min' => 1,
        'max' => 10
      ],
      [
        'name' => 'max_retries',
        'type' => 'int',
        'default' => 3,
        'description' => 'Maximum number of retry attempts for GPT validation',
        'min' => 1,
        'max' => 5
      ],
      [
        'name' => 'translation_cache_ttl',
        'type' => 'int',
        'default' => 3600,
        'description' => 'Translation cache time-to-live in seconds',
        'min' => 60,
        'max' => 86400
      ],
      [
        'name' => 'enable_fallback',
        'type' => 'bool',
        'default' => true,
        'description' => 'Enable fallback classification when GPT fails'
      ],
      [
        'name' => 'enable_competitor_detection',
        'type' => 'bool',
        'default' => true,
        'description' => 'Enable competitor comparison query detection'
      ]
    ];
  }

  /**
   * Sets a configuration parameter value
   * 
   * @param string $name Parameter name
   * @param mixed $value New value
   * @return bool True if successful, false otherwise
   */
  public function setParameter(string $name, mixed $value): bool
  {
    // Check if parameter exists
    if (!array_key_exists($name, self::$config)) {
      if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        error_log("Semantics::setParameter() - Unknown parameter: {$name}");
      }
      return false;
    }

    // Validate the value
    $validation = $this->validateParameter($name, $value);
    if (!$validation['valid']) {
      if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        error_log("Semantics::setParameter() - Validation failed for {$name}: " . implode(', ', $validation['errors']));
      }
      return false;
    }

    // Set the value
    self::$config[$name] = $value;

    // Save configuration to file
    self::saveConfig();

    // Log the change
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      self::initializeLogger();
      self::$logger->logStructured(
        'info',
        'Semantics',
        'setParameter',
        [
          'parameter' => $name,
          'value' => $value
        ]
      );
    }

    return true;
  }

  /**
   * Gets the current value of a configuration parameter
   * 
   * @param string $name Parameter name
   * @return mixed Current value or null if parameter doesn't exist
   */
  public function getParameter(string $name): mixed
  {
    return self::$config[$name] ?? null;
  }

  /**
   * Validates a parameter value
   * 
   * @param string $name Parameter name
   * @param mixed $value Value to validate
   * @return array Validation result with 'valid' and 'errors' keys
   */
  public function validateParameter(string $name, mixed $value): array
  {
    $errors = [];
    $warnings = [];

    // Check if parameter exists
    if (!array_key_exists($name, self::$config)) {
      return [
        'valid' => false,
        'errors' => ["Parameter '{$name}' does not exist"],
        'warnings' => []
      ];
    }

    // Get parameter definition
    $params = $this->getConfigurableParameters();
    $paramDef = null;
    foreach ($params as $param) {
      if ($param['name'] === $name) {
        $paramDef = $param;
        break;
      }
    }

    if (!$paramDef) {
      return [
        'valid' => false,
        'errors' => ["Parameter definition not found for '{$name}'"],
        'warnings' => []
      ];
    }

    // Type validation
    $expectedType = $paramDef['type'];
    $actualType = gettype($value);

    // Map PHP types to expected types
    $typeMap = [
      'integer' => 'int',
      'double' => 'float',
      'boolean' => 'bool',
      'string' => 'string'
    ];

    $actualType = $typeMap[$actualType] ?? $actualType;

    if ($actualType !== $expectedType) {
      $errors[] = "Expected type '{$expectedType}', got '{$actualType}'";
    }

    // Range validation for numeric types
    if (in_array($expectedType, ['int', 'float'])) {
      if (isset($paramDef['min']) && $value < $paramDef['min']) {
        $errors[] = "Value {$value} is below minimum {$paramDef['min']}";
      }
      if (isset($paramDef['max']) && $value > $paramDef['max']) {
        $errors[] = "Value {$value} is above maximum {$paramDef['max']}";
      }
    }

    // Enum validation
    if (isset($paramDef['allowed_values']) && !in_array($value, $paramDef['allowed_values'])) {
      $errors[] = "Value '{$value}' is not in allowed values: " . implode(', ', $paramDef['allowed_values']);
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors,
      'warnings' => $warnings
    ];
  }

  /**
   * Check if query is a web search query
   *
   * Detects patterns indicating external web search, competitor research,
   * or market information requests.
   *
   * @param string $query Query to check (should be in English)
   * @return bool True if web search query detected
   */
  private static function isWebSearchQuery(string $query): bool
  {
    $queryLower = strtolower($query);
    
    // Web search patterns (English only - query is already translated)
    $patterns = [
      // Explicit web search requests (highest priority)
      '/\b(web\s+search|search\s+(the\s+)?web|search\s+online)\b/i',
      '/\b(search\s+(for|on)\s+(the\s+)?(internet|web))\b/i',
      
      // Competitor patterns
      '/\b(compare|comparison)\b.*\b(with|to|against|competitors?)\b/i',
      '/\b(competitors?|competition|rival)\b/i',
      
      // Online search patterns
      '/\b(search|find|look\s+for)\b.*\b(online|web|internet)\b/i',
      '/\b(search)\b.*\b(prices?)\b.*\b(online)\b/i',
      
      // Market research patterns
      '/\b(market\s+(trends?|analysis|research))\b/i',
      '/\b(price\s+comparison)\b/i',
      
      // External data patterns
      '/\b(external|outside|public)\s+(data|information|source)\b/i',
      
      // Specific web search indicators
      '/\b(amazon|ebay|alibaba|marketplace)\b/i',
      '/\b(google|bing|search\s+engine)\b/i',
    ];
    
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $queryLower)) {
        if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
          error_log("Web search pattern matched: {$pattern}");
        }
        return true;
      }
    }
    
    return false;
  }

  /**
   * Search for documents in the embedding database using semantic similarity
   *
   * This method provides a simplified interface for semantic search using the MultiDBRAGManager.
   * It searches across all embedding tables and returns relevant documents with similarity scores.
   *
   * TASK 4.4.2: Ported from Domain/SemanticSearch/Semantics to consolidate functionality
   *
   * @param string $query Search query
   * @param int $limit Maximum number of results to return (default: 5)
   * @param float $minScore Minimum similarity score threshold 0.0-1.0 (default: 0.5)
   * @param int|null $languageId Language ID for filtering results (optional)
   * @param string|null $entityType Entity type for filtering results (optional)
   * @param int|null $entityId Entity ID for context (optional, for memory integration)
   * @param string|null $interactionId Interaction ID for context (optional, for memory integration)
   * @return array Search results with structure:
   *   [
   *     'success' => bool,
   *     'results' => [
   *       [
   *         'content' => string,
   *         'score' => float,
   *         'metadata' => array
   *       ],
   *       ...
   *     ],
   *     'error' => string|null
   *   ]
   */
  public static function search(
    string $query,
    int $limit = 5,
    float $minScore = 0.5,
    int|null $languageId = null,
    string|null $entityType = null,
    int|null $entityId = null,
    string|null $interactionId = null
  ): array
  {
    // Start timing for statistics
    $startTime = microtime(true);
    
    try {
      self::initializeLogger();

      if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        self::logSecurityEvent(
          "Semantics::search() called with query: {$query}, limit: {$limit}, minScore: {$minScore}",
          'info'
        );
      }

      // Validate inputs
      if (empty(trim($query))) {
        return [
          'success' => false,
          'results' => [],
          'error' => 'Query cannot be empty'
        ];
      }

      // Ensure limit is reasonable
      $limit = max(1, min($limit, 20));
      
      // Ensure minScore is in valid range
      $minScore = max(0.0, min($minScore, 1.0));

      // Get language ID from Registry if not provided
      if ($languageId === null && Registry::exists('Language')) {
        $languageId = Registry::get('Language')->getId();
      }

      // Create MultiDBRAGManager instance
      // Pass empty array for tableNames to use all embedding tables
      $ragManager = new MultiDBRAGManager(null, []);

      // Perform semantic search
      $searchResult = $ragManager->searchDocuments($query, $limit, $minScore, $languageId, $entityType);

      // Extract documents from search result
      $documents = $searchResult['documents'] ?? [];
      $auditMetadata = $searchResult['audit_metadata'] ?? [];

      // Format results
      $formattedResults = [];

      foreach ($documents as $doc) {
        $formattedResults[] = [
          'content' => $doc->content ?? '',
          'score' => $doc->metadata['score'] ?? 0.0,
          'metadata' => [
            'entity_type' => $doc->metadata['entity_type'] ?? null,
            'entity_id' => $doc->metadata['entity_id'] ?? null,
            'language_id' => $doc->metadata['language_id'] ?? null,
            'type' => $doc->metadata['type'] ?? null,
            'source' => $doc->sourceName ?? 'unknown',
            'priority_boost' => $doc->metadata['priority_boost'] ?? false,
          ]
        ];
      }

      if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        self::logSecurityEvent("Semantics::search() found " . count($formattedResults) . " results", 'info');
      }

      // Calculate response time
      $endTime = microtime(true);
      $responseTime = (int)round(($endTime - $startTime) * 1000);

      // Record statistics
      // TASK: Manual Test 1.1 - Statistiques enregistrées correctement
      self::recordSearchStatistics(
        $query,
        $responseTime,
        true,
        count($formattedResults),
        $languageId,
        $interactionId
      );

      return [
        'success' => true,
        'results' => $formattedResults,
        'audit_metadata' => $auditMetadata,
        'error' => null
      ];

    } catch (\Exception $e) {
      self::logSecurityEvent("Error in Semantics::search(): " . $e->getMessage(), 'error');

      // Calculate response time even on error
      $endTime = microtime(true);
      $responseTime = (int)round(($endTime - $startTime) * 1000);

      // Record statistics for failed search
      self::recordSearchStatistics(
        $query,
        $responseTime,
        false,
        0,
        $languageId,
        $interactionId
      );

      return [
        'success' => false,
        'results' => [],
        'error' => $e->getMessage()
      ];
    }
  }

  /**
   * Record search statistics to rag_statistics table
   * 
   * TASK: Manual Test 1.1 - Statistiques enregistrées correctement
   * 
   * @param string $query Search query
   * @param int $responseTime Response time in milliseconds
   * @param bool $success Whether the search was successful
   * @param int $resultsCount Number of results found
   * @param int|null $languageId Language ID
   * @param string|null $interactionId Interaction ID
   * @return void
   */
  private static function recordSearchStatistics(
    string $query,
    int $responseTime,
    bool $success,
    int $resultsCount,
    ?int $languageId,
    ?string $interactionId
  ): void
  {
    try {
      // Get database connection
      $db = Registry::get('Db');
      $prefix = CLICSHOPPING::getConfig('db_table_prefix');
      
      // Get user ID and session ID
      $userId = 1; // Default user ID
      $sessionId = session_id();
      
      // Get language ID if not provided
      if ($languageId === null && Registry::exists('Language')) {
        $languageId = Registry::get('Language')->getId();
      }
      
      // Build metadata
      $metadata = json_encode([
        'source' => 'documents',
        'query' => $query,
        'results_count' => $resultsCount,
        'min_score' => 0.25,
        'limit' => 5
      ]);
      
      // Insert statistics
      $sql = "INSERT INTO {$prefix}rag_statistics 
              (query_type, success, response_time, response_time_ms, metadata, 
               interaction_id, user_id, session_id, language_id, date_added, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
      
      $stmt = $db->prepare($sql);
      $stmt->execute([
        'semantic',
        $success ? 1 : 0,
        $responseTime,
        $responseTime,
        $metadata,
        $interactionId,
        $userId,
        $sessionId,
        $languageId
      ]);
      
      if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        self::logSecurityEvent(
          "Statistics recorded: query_type=semantic, success={$success}, response_time={$responseTime}ms, results={$resultsCount}",
          'info'
        );
      }
      
    } catch (\Exception $e) {
      // Log error but don't throw - statistics recording should not break the search
      error_log("Semantics::recordSearchStatistics() error: " . $e->getMessage());
    }
  }

  /**
   *  Create a taxonomy from the given text.
   *  The taxonomy is structured as [domain]: xxx, [type]: yyy, [subject]: zzz, etc.
   * @param string $text
   * @param int|null $min_character
   * @param string|null $getdef
   * @param string|null $language_code
   * @return string
   * @throws \Exception
   */
  public function createTaxonomy(string $text, ?string $language_code, ?int $min_character = 300): string
  {
    $result = '';
    $text = trim($text);

    $prompt = CLICSHOPPING::getDef('text_create_taxonomy', ['document_text' => $text]);

    if (strlen($prompt) > $min_character && str_word_count($text) > $min_character) {
      $result = Gpt::getGptResponse($prompt);
    }

    return is_string($result) ? trim($result) : '';
  }

}
