<?php

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/*
 * This class is responsible for semantic analysis and classification of queries.
 * It uses the OpenAI API to translate and classify queries, and also logs security events.
 */
class Semantics
{
  private static ?SecurityLogger $logger = null;
  public function __construct()
  {
    self::initializeLogger();
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
   * @param string $text
   * @param int|null $token
   * @return string
   */
  public static function translateToEnglish(string $text, int|null $token = 80): string
  {
    $language_id = Registry::get('Language')->getId();
    $language_name = Registry::get('Language')->getLanguagesName($language_id);

    if (strtolower($language_name) !== 'english') {
      $question = "Translate the following query to English: {$text}";
      $query = Gpt::getGptResponse($question, $token);
    } else {
      $query = trim($text);
    }

    return $query;
  }

  /**
   * Classify the query as 'analytics', 'semantic' or fallback to 'semantic' if unclear.
   * @param string $text
   * @return string
   */
  public static function checkSemantics(string $text): string
  {
    try {
      $prompt = "Determine whether the following question is of type 'analytics' or 'semantic'. " .
        "Respond with only one word: 'analytics' or 'semantic'.\nQ: {$text}\nAnswer:";

      $prompt_result = Gpt::getGptResponse($prompt, 20, 0);
      $type = trim(strtolower($prompt_result));

      if (in_array($type, ['analytics', 'semantic'])) {
        return $type;
      }

      // Fallback to local analysis if GPT response is invalid
      return self::isSemanticQuery($text) ? 'semantic' : 'analytics';
    } catch (\Exception $e) {
      // If GPT fails completely, fall back to local analysis
      return self::isSemanticQuery($text) ? 'semantic' : 'analytics';
    }
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
    $analyticsPatterns = [
      'entity' => [
        '/\b(which|what|all|list of)\s+(products?|orders?|specials?|customers?|sales|returns?|stock|inventory|items?)\b/i',
        // Ajout de patterns pour les caractéristiques de produits avec référence
        '/\b(?:show|get|give|display)\s+(?:me|us)?\s*(?:the)?\s*(?:characteristics?|features?|details?|specifications?)\s+(?:of|for|about)\s+(?:product|item|reference)\s*(?:number|no|ref|id)?[:# ]?\s*\d+\b/i',
        '/\b(?:what|which)\s+(?:are|is)\s+(?:the)?\s*(?:characteristics?|features?|details?|specifications?)\s+(?:of|for|about)\s+(?:product|item|reference)\s*(?:number|no|ref|id)?[:# ]?\s*\d+\b/i',
        '/\b(?:find|search|lookup|get)\s+(?:product|item)\s+(?:with|by)\s+(?:reference|ref|no|number|id)\s*[:# ]?\s*\d+\b/i'
      ],
      'time' => [
        '/\b(in|during|over|for|the|last|past|recent)\s+(\d*)\s+(days?|months?|weeks?|years?|quarters?|hours?)\b/i',
        '/\b(today|yesterday|this month|this week|this year|current|now)\b/i',
        '/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+(\d{4})\b/i',
        '/\bQ[1-4]\s+\d{4}\b/i', // Q1 2023, etc.
        '/\b(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})\b/i', // Dates in format 01/01/2023
        '/\b(last|latest|most recent)\s/i',
      ],
      'stock' => [
        '/\b(stock|inventory|available|availability|alert|level|levels|threshold|thresholds|reorder|shortage|out of stock|stock)\b/i',
        '/\b(available|remaining)\s+(quantity|quantities|stock)\b/i',
        '/\b(products?)\s+(out of stock|unavailable|to order|to restock)\b/i',
      ],
      'reference' => [
        '/\b(REF|SKU|EAN|UPC|ISBN|GTIN|barcode|bar code|reference)[-\s]?(\d+[-\w]*)\b/i',
        '/\b\d{8,13}\b/', // Standard barcodes (EAN-8, EAN-13, UPC, etc.)
        '/\bID\s*:\s*\d+\b/i',
        '/\b(product|item)\s*(?:reference|ref|no|number|id)\s*[:# ]?\s*(\d+[-\w]*)\b/i',
        // Ajout de patterns plus spécifiques pour les références
        '/\b(?:reference|ref|product|item)\s*[:# ]?\s*(\d+[-\w]*)\b/i',
        '/\b(?:find|search|get|show)\s+(?:by|with)\s+(?:reference|ref|no|number|id)\s*[:# ]?\s*(\d+[-\w]*)\b/i'
      ],
      'price' => [
        '/\b(price|cost|amount|value)\s*(>|<|>=|<=|=|==|equal to|greater than|less than|at least|at most|between)\s*(\d+[\.,]?\d*)\s*(\$|USD|EUR|£|GBP)?\b/i',
        '/\b(between)\s*(\d+[\.,]?\d*)\s*and\s*(\d+[\.,]?\d*)\s*(\$|USD|EUR|£|GBP)?\b/i', // Price between X and Y
        '/\b(lower|cheaper|less expensive|under)\s*than\s*(\d+[\.,]?\d*)\s*(\$|USD|EUR|£|GBP)?\b/i',
        '/\b(higher|more expensive|above)\s*than\s*(\d+[\.,]?\d*)\s*(\$|USD|EUR|£|GBP)?\b/i',
      ],
      'quantity' => [
        '/\b(quantity|number|amount|count|total|volume)\s*(>|<|>=|<=|=|==|equal to|greater than|less than|at least|at most|between)\s*(\d+)\b/i',
        '/\b(between)\s*(\d+)\s*and\s*(\d+)\s*(units|pieces|items|products)?\b/i', // Quantity between X and Y
        '/\b(more|less)\s*than\s*(\d+)\s*(units|pieces|items|products)?\b/i',
      ],
      'performance' => [
        '/\b(revenue|sales|profit|profits|margin|margins|turnover|earnings|income)\b/i',
        '/\b(best|top|worst|most popular|least popular|best selling|worst selling)\s+(products?|items?|categories?|customers?|sellers?)\b/i',
        '/\b(performance|results?|goals?|targets?|KPI)\b/i',
        '/\b(rate|ratio|percentage)\s+of\s+(conversion|return|satisfaction|growth|abandonment)\b/i',
      ],
      'comparison' => [
        '/\b(compare|comparison|difference|progression|evolution|growth|variation)\b/i',
        '/\b(compared to|versus|vs\.?|against)\b/i',
        '/\b(increase|decrease|growth|decline)\s+(?:of|by)\s+(\d+[\.,]?\d*)\s*(%|percent)?\b/i',
        '/\b(previous|last|past)\s+(year|month|week|day)\b/i',
      ],
      'category' => [
        '/\b(category|categories|family|families|group|groups|type|types)\s+of\s+(products?|items?)\b/i',
        '/\b(products?|items?)\s+in\s+(category|family|type)\b/i',
      ],
      'customer' => [
        '/\b(customers?|buyers?|consumers?|users?)\s+(loyal|regular|new|recurring|occasional|premium|VIP)\b/i',
        '/\b(segmentation|segment|segments)\s+of\s+customers?\b/i',
        '/\b(behavior|behaviors|habit|habits|preference|preferences)\s+of\s+(customers?|buyers?|consumers?|users?)\b/i',
      ],
      'calculation' => [
        '/\b(calculate|calculation|sum|total|average|median|minimum|maximum|min|max|avg|sum|count)\b/i',
        '/\b(add|subtract|multiply|divide)\b/i',
        '/\b(percentage|ratio|proportion|share)\s+of\b/i',
      ],
      'filters' => [
        '/\b(where|only|except|excluding)\b/i',
        '/\b(active|enabled|disabled|published)\b/i',
      ],
      'sorting' => [
        '/\b(order by|sort by|ranked by)\b/i',
        '/\b(ascending|descending|asc|desc)\b/i',
      ],
    ];

    return $analyticsPatterns;
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
    return [
      'conversational' => [
        '/\b(?:tell|explain|describe)\s+(?:me|us)\s+about\b/i',
        '/\bwhat\s+(?:do\s+you|can\s+you)\s+tell\s+(?:me|us)\s+about\b/i',
        '/\bhow\s+(?:does|do)\s+[\w\s]+\s+work\b/i'
      ],

      'location' => [
        '/\bwhere\s+(?:is|are|can\s+(?:i|we)\s+find)\s+[\w\s]+\??$/i',
        '/\b(?:location|address)\s+of\s+[\w\s]+\??$/i',
        '/\bhow\s+(?:to|do\s+i)\s+(?:get|go)\s+to\s+[\w\s]+\??$/i'
      ],

      'help_support' => [
        '/\bhow\s+(?:do|can)\s+(?:i|we)\s+(?:use|setup|configure)\s+[\w\s]+\??$/i',
        '/\b(?:help|assist(?:ance)?)\s+with\s+[\w\s]+\??$/i',
        '/\bhaving\s+(?:trouble|problems?|issues?)\s+with\s+[\w\s]+\??$/i'
      ],

      'general_info' => [
        '/\bwhy\s+(?:is|are|does|do)\s+[\w\s]+\??$/i',
        '/\bwhat\s+is\s+the\s+(?:purpose|benefit|advantage)\s+of\s+[\w\s]+\??$/i',
        '/\b(?:explain|clarify)\s+(?:how|why|what)\s+[\w\s]+\??$/i'
      ],

      'policy_info' => [
        '/\bwhat\s+(?:is|are)\s+(?:the|your)\s+(?:policy|terms)\s+(?:on|for|about)\s+[\w\s]+\??$/i',
        '/\bhow\s+(?:do|does)\s+(?:returns?|exchanges?|shipping)\s+work\??$/i'
      ]
    ];
  }

  private static function isSemanticQuery(string $text): bool
  {
    $patterns = self::semanticPatterns();

    foreach ($patterns as $type => $typePatterns) {
      foreach ($typePatterns as $pattern) {
        if (preg_match($pattern, $text)) {
          if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
            self::logSecurityEvent("Semantic pattern matched: Type: $type", 'info');
          }
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Classify the query as 'analytics' or 'semantic'.
   * This method first translates the text to English, then checks for critical patterns,
   * calculates a score based on matched patterns, and finally classifies the query.
   *
   * @param string $text The text to classify.
   * @param int threshold Adjust this threshold based on your needs
   * @return string The classification result: 'analytics' or 'semantic'.
   */
  public static function classifyQuery(string $text, int|null $threshold = 3): string
  {
    $translated = self::translateToEnglish($text);

    if (self::hasCriticalMatch($translated) === true) {
      return 'analytics';
    }

    $score = self::calculateScore($translated);

    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      self::logSecurityEvent("Total score: {$score}", 'info');
    }

    if (is_null($threshold)) {
      $threshold = 3;
    }

    if ($score >= $threshold) {
      return 'analytics';
    }

    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      self::logSecurityEvent("No analytics pattern matched. Falling back to semantic analysis.", 'info');
    }

    return self::checkSemantics($translated);
  }

  /**
   * Check if the text contains any critical patterns that indicate an analytics query.
   * This method uses regex patterns to identify specific keywords and phrases.
   *
   * @param string $text The text to analyze.
   * @return bool True if a critical pattern is found, false otherwise.
   */
  public static function hasCriticalMatch(string $text): bool
  {
    $patterns = self::analyticsPatterns();

    // Check geographic exceptions first
    if (self::isGeographicQuery($text)) {
      return false;
    }

    // Critical patterns that strongly indicate analytics queries
    $criticalPatterns = [
      'performance',
      'price',
      'quantity',
      'comparison',
      'calculation',
      'filters',
      'sorting',
      'time',
      'stock',
      'reference'
    ];

    // Check patterns with analytical context
    foreach ($criticalPatterns as $type) {
      if (!isset($patterns[$type])) {
        continue;
      }

      foreach ($patterns[$type] as $pattern) {
        if (preg_match($pattern, $text)) {
          $hasAnalyticalContext = self::hasAnalyticalContext($text);
          if ($hasAnalyticalContext) {
            if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
              self::logSecurityEvent("Critical pattern matched: Type: $type", 'info');
            }
            return true;
          }
        }
      }
    }

    return false;
  }

  /**
   * Check if the text matches any geographic patterns that should be treated as semantic queries
   * @param string $text
   * @return bool
   */
  private static function isGeographicQuery(string $text): bool
  {
    // Base geographic patterns in English
    $basePatterns = [
      // Basic location queries
      'location' => '/\blocation\s+of\s+[\w\s]+\??$/i',
      'where_is' => '/\bwhere\s+(?:is|can\s+(?:i|we)\s+find|can\s+(?:i|we)\s+locate)\s+[\w\s]+\??$/i',
      'directions' => '/\bdirections?\s+(?:to|towards?|for)\s+[\w\s]+\??$/i',
      'how_to_get' => '/\bhow\s+(?:to\s+(?:get|go)|do\s+(?:i|we)\s+(?:get|go))\s+to\s+[\w\s]+\??$/i',

      // Proximity and area queries
      'find_near' => '/\b(?:find|locate|search|looking\s+for)\s+[\w\s]+\s+(?:near|around|close\s+to|nearby|in\s+the\s+area\s+of)\s+[\w\s]+\??$/i',
      'address' => '/\b(?:what\s+is\s+)?(?:the\s+)?(?:address|location|place|spot)\s+(?:of|for)\s+[\w\s]+\??$/i',
      'in_area' => '/\b(?:what|which|any)\s+[\w\s]+\s+(?:in|around|near)\s+(?:the\s+)?(?:area|region|zone|district|neighborhood)\s+(?:of\s+)?[\w\s]+\??$/i',

      // Distance and travel queries
      'distance' => '/\b(?:what\s+is\s+)?(?:the\s+)?distance\s+(?:between|from|to)\s+[\w\s]+\??$/i',
      'travel_time' => '/\b(?:how\s+long|time)\s+(?:does\s+it\s+take|to\s+get|to\s+travel)\s+(?:from|to|between)\s+[\w\s]+\??$/i',

      // Geographic features
      'landmarks' => '/\b(?:what|which)\s+(?:landmarks?|monuments?|places\s+of\s+interest)\s+(?:are|is)\s+(?:in|near|around)\s+[\w\s]+\??$/i',
      'boundaries' => '/\b(?:where\s+(?:does|is)\s+)?(?:the\s+)?(?:border|boundary|limit)\s+of\s+[\w\s]+\??$/i',

      // Service area queries
      'delivery_area' => '/\b(?:do\s+you|does\s+it)\s+(?:deliver|ship|serve)\s+(?:to|in)\s+[\w\s]+\??$/i',
      'coverage' => '/\b(?:is|are)\s+[\w\s]+\s+(?:available|accessible)\s+in\s+[\w\s]+\??$/i',

      // Store/Branch location queries
      'store_location' => '/\b(?:where\s+(?:is|are)\s+)?(?:the\s+)?(?:nearest|closest)\s+(?:store|shop|branch|outlet|office)\s+(?:to|in|near)\s+[\w\s]+\??$/i',
      'opening_hours' => '/\b(?:what\s+(?:are|is)\s+)?(?:the\s+)?(?:opening|business|working)\s+hours?\s+(?:for|of)\s+[\w\s]+\s+(?:in|at)\s+[\w\s]+\??$/i'
    ];

    // Additional patterns can be added here without modifying the main logic
    $customPatterns = [];

    // Geographic context indicators that strengthen the geographic nature of the query
    $geographicContextPatterns = [
      '/\b(?:city|town|village|district|region|country|state|province)\b/i',
      '/\b(?:street|avenue|boulevard|road|highway|intersection)\b/i',
      '/\b(?:north|south|east|west|northern|southern|eastern|western)\b/i',
      '/\b(?:downtown|uptown|suburb|metropolitan|urban|rural)\b/i',
      '/\b(?:postal|zip)\s+code\b/i'
    ];

    // Merge base and custom patterns
    $allPatterns = array_merge($basePatterns, $customPatterns);

    // Check main patterns first
    foreach ($allPatterns as $type => $pattern) {
      if (preg_match($pattern, $text)) {
        if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
          self::logSecurityEvent("Geographic pattern matched: $type", 'info');
        }
        return true;
      }
    }

    // If no main pattern matched, check for multiple geographic context indicators
    $contextMatches = 0;
    foreach ($geographicContextPatterns as $pattern) {
      if (preg_match($pattern, $text)) {
        $contextMatches++;
        if ($contextMatches >= 2) { // Require at least 2 context matches to consider it geographic
          if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
            self::logSecurityEvent("Geographic context patterns matched", 'info');
          }
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Check if the text has an analytical context
   * @param string $text
   * @return bool
   */
  private static function hasAnalyticalContext(string $text): bool
  {
    $analyticalContextPatterns = [
      '/\b(compared to|versus|vs)\b/i',
      '/\b(increase|decrease|variation)\b/i',
      '/\b(from \d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|between.*and)\b/i',
      '/\b(more|less)\s+than\b/i',
      '/\b(percentage|ratio|rate)\b/i',
      // Ajout de patterns de contexte pour les références produits
      '/\b(?:reference|ref|no|number|id)\s*[:# ]?\s*\d+/i',
      '/\b(?:characteristics?|features?|details?|specifications?)\s+(?:of|for|about)\s+(?:product|item)\b/i',
      '/\b(?:product|item)\s+(?:information|data|details?)\s+(?:for|with)\s+(?:reference|ref)\b/i'
    ];

    foreach ($analyticalContextPatterns as $pattern) {
      if (preg_match($pattern, $text)) {
        return true;
      }
    }

    return false;
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
    $analyticsPatterns = self::analyticsPatterns();

    // More selective weights for analytics patterns
    $weights = [
      'performance' => 3,    // Strong analytics indicators
      'calculation' => 3,
      'comparison' => 2.5,
      'price' => 2,
      'quantity' => 2,
      'filters' => 1.5,
      'sorting' => 1.5,
      'time' => 1,
      'entity' => 0.5,      // Weak analytics indicators
      'category' => 0.5,
      'customer' => 0.5
    ];

    $score = 0;

    foreach ($analyticsPatterns as $category => $patterns) {
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
          self::logSecurityEvent("Pattern match detected: Category: $category | Pattern: $pattern", 'info');
          $score += $weights[$category] ?? 1;
        }
      }
    }

    return (int)$score;
  }

  /**
   * Create a taxonomy from the given text.
   * The taxonomy is structured as [domain]: xxx, [type]: yyy, [subject]: zzz, etc.
   *
   * @param string $text The text to analyze.
   * @return string The generated taxonomy.
   */
  public static function createTaxonomy(string $text): string
  {
    $prompt = CLICSHOPPING::getDef('text_create_taxonomy', ['document_text' => $text]);

    $result = Gpt::getGptResponse($prompt);

    return trim($result);
  }
}