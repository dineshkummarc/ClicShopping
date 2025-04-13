<?php

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

class Semantics {

  /**
   * Translate a given text to English using the OpenAI API.
   * @param string $text
   * @param int|null $token
   * @return string
   */
  public static function translateToEnglish(string $text, int|null $token = 80): string
  {
    $question = "Translate the following query to English: {$text}";
    $query = Gpt::getGptResponse($question, $token);

    return $query;
  }

  /**
   * Classify the query as 'analytics', 'semantic' or fallback to 'semantic' if unclear.
   * @param string $text
   * @return string
   */
  public static function checkSemantics(string $text): string
  {
    $prompt = "La question est de type: analytics or semantic? Rèpond uniquement analytics ou semantic\nQ: {$text}\nAnswer:";
    $type = Gpt::getGptResponse($prompt, 20);
    $result = in_array(strtolower(trim($type)), ['analytics', 'semantic']) ? strtolower(trim($type)) : 'semantic';

    return $result;
  }

  /**
   * Try to classify based on patterns first, fallback to semantic check.
   * @param string $text
   * @return string
   */
  public static function classifyQuery(string $text): string
  {
    $translated = self::translateToEnglish($text);
    $analyticsPatterns = self::analyticsPatterns();

    foreach ($analyticsPatterns as $category => $patterns) {
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $translated)) {
          error_log("Match trouvé dans la catégorie $category avec le pattern : $pattern");
          return 'analytics';
        }
      }
    }

    // Aucun match clair → GPT fallback
    return self::checkSemantics($translated);
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
  public static function analyticsPatterns() : array
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
      ],
      'stock' => [
        '/\b(stock|inventory|available|availability|alert|level|levels|threshold|thresholds|reorder|shortage|out of stock|alert|discountinued)\b/i',
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
}