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
    $prompt = "Determine whether the following question is of type 'analytics' or 'semantic'. Respond with only one word: 'analytics' or 'semantic'.\nQ: {$text}\nAnswer:";
    $type = Gpt::getGptResponse($prompt, 20);

    $result = in_array(strtolower(trim($type)), ['analytics', 'semantic']) ? strtolower(trim($type)) : 'semantic';

    return $result;
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
        '/\b(product|item)\s*reference\s*:?\s*([A-Z0-9-]+)\b/i',
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

    if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER === 'True') {
      self::logSecurityEvent("Total score: {$score}", 'info');
    }

    if (is_null($threshold)) {
      $threshold = 3;
    }

    if ($score >= $threshold) {
      return 'analytics';
    }

    if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER === 'True') {
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
  private static function hasCriticalMatch(string $text): bool
  {
    $patterns = self::analyticsPatterns();

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

    foreach ($criticalPatterns as $type) {
      if (!isset($patterns[$type])) {
        continue;
      }

      foreach ($patterns[$type] as $pattern) {
        if (preg_match($pattern, $text)) {
          return true;
        }
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

    $weights = [
      'performance' => 2,
      'price' => 2,
      'comparison' => 2,
      'calculation' => 2,
      'filters' => 1,
      'sorting' => 1,
      'entity' => 0.5,
      'category' => 0.5,
      'customer' => 0.5,
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