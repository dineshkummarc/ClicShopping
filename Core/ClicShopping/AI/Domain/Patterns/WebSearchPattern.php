<?php

namespace ClicShopping\AI\Domain\Patterns;

class WebSearchPattern
{

  /**
   * Defines and returns regex patterns for competitor comparison queries.
   *
   * @return array<string> Array of regex patterns
   */
  public static function getCompetitorPatterns(): array
  {
    return [
      '/\b(compare|comparison|vs|versus|against)\b.*\b(price|cost|pricing|prices)\b/i',
      '/\b(price|prices|cost|costs)\b.*\b(compare|comparison|competitor|competition)\b/i',

      '/\b(competitor|competitors|competition|rival)\b.*\b(price|prices|pricing|cost)\b/i',
      '/\b(price|prices|pricing|cost)\b.*\b(competitor|competitors|competition)\b/i',

      '/\bcompetitor\s+pricing\b/i',
      '/\bcompetitor\s+prices\b/i',

      '/\b(best|cheapest|lowest)\b.*\b(competitor|competition)\b.*\b(price|prices|cost)\b/i',

      '/\b(competitive\s+(pricing|analysis))\b/i',
      '/\b(benchmark)\b.*\b(price|pricing|cost)\b/i',
    ];
  }


  /**
   * Checks if text is a competitor comparison query
   *
   * @param string $text Text to analyze
   * @return bool True if competitor comparison query detected
   */
  public static function isCompetitorComparisonQuery(string $text): bool
  {
    foreach (self::getCompetitorPatterns() as $pattern) {
      if (preg_match($pattern, $text)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Checks if text matches ANY general web search pattern.
   * This is crucial for filtering internal reports.
   *
   * @param string $text Text to analyze
   * @return bool True if any web search related pattern is detected
   */
  public static function isExternalQuery(string $text): bool
  {
    // Combine patterns from getWebSearchPatterns (excluding scores) and getCompetitorPatterns
    $generalPatterns = [];

    // Patterns with scores (we extract only the regex)
    foreach (self::getWebSearchPatterns() as $pattern => $score) {
      $generalPatterns[] = $pattern;
    }

    // Patterns without scores (pure regex list)
    $generalPatterns = array_merge($generalPatterns, self::getCompetitorPatterns());

    // Run the check
    foreach ($generalPatterns as $pattern) {
      // We use the pattern directly from the array, which is already a valid regex string
      if (preg_match($pattern, $text)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Defines and returns regex patterns for detecting web search related queries.
   * 
   * TASK 3.4 (2025-12-11): Enhanced patterns for web search detection
   * ALL PATTERNS IN ENGLISH ONLY - queries are translated before pattern matching
   *
   * @return array<string, float> Associative array where:
   *                             - key: regex pattern (string)
   *                             - value: confidence score (float)
   */
  public static function getWebSearchPatterns(): array
  {
    $array = [
      // Explicit web search
      '/\b(search)\b.{0,50}\b(web|internet|online|on\s+the\s+web)\b/i' => 0.95,

      // Competitor comparisons
      '/\b(compare|comparison)\b.*\b(with|to|against|competitor|competitors)\b/i' => 0.95,
      '/\b(amazon|ebay|alibaba|marketplace)\b/i' => 0.95,
      '/\b(competitor|competition|rival)\b.*\b(charging|pricing|price|cost)\b/i' => 0.95,

      // Market research
      '/\b(market\s+(trends?|analysis|research))\b/i' => 0.95,
      '/\b(trends)\b.{0,50}\b(market)\b/i' => 0.95,

      // Price comparison
      '/\b(price\s+comparison)\b/i' => 0.95,
      '/\b(price)\b.{1,50}\b(on\s+sites|competitor)\b/i' => 0.95,

      // External data
      '/\b(external|outside|public|information)\b\s*(?!.*\b(stock|inventory|sales|order|quantity)\b).{0,50}\b(data|source|analysis)\b/i' => 0.85,
      
      // TASK 3.4: Additional patterns for web search detection
      // Competitive keywords (ENGLISH ONLY)
      '/\b(competitor|competitors|competition|competitive)\b/i' => 0.90,
      
      // Market keywords with external context (ENGLISH ONLY)
      '/\b(best|top|leading)\b.*\b(competitor|competitors|competition|market)\b/i' => 0.95,
      '/\b(list|show|display)\b.*\b(competitor|competitors)\b/i' => 0.90,
      
      // Online/web keywords (ENGLISH ONLY)
      '/\b(online|web|internet|external)\b.*\b(search|find|look|price)\b/i' => 0.90,
      '/\b(search|find|look)\b.*\b(online|web|internet)\b/i' => 0.90,
      
      // Comparison keywords with price/product context (ENGLISH ONLY)
      '/\b(compare|comparison|versus|vs)\b.*\b(price|cost|product|competitor)\b/i' => 0.95,
      '/\b(compare)\b.*\b(with)\b.*\b(competitor|competitors)\b/i' => 0.95,
      
      // TASK 4.5.6 FIX: Add pattern for "best deals online" type queries
      '/\b(best|top|cheapest|lowest)\s+(deals?|prices?|offers?)\s+(online|web|internet)\b/i' => 0.95,
      '/\b(best|top|cheapest|lowest)\s+(online|web|internet)\s+(deals?|prices?|offers?)\b/i' => 0.95,
      
      // TASK 13.5 (2025-12-14): Add pattern for "cheapest prices on the internet" type queries
      '/\b(cheapest|lowest|best)\s+(price|prices|cost|costs|deal|deals)\s+(?:on\s+)?(?:the\s+)?(internet|web|online)\b/i' => 0.95,
    ];

    return $array;
  }
  
  /**
   * Get analytics sub-patterns for hybrid detection
   * 
   * TASK 3.3 (2025-12-11): Patterns for detecting multiple analytics sub-queries
   * Used to identify hybrid queries like "stock + revenue"
   * ALL PATTERNS IN ENGLISH ONLY
   * 
   * @return array<string, string> Associative array where:
   *                               - key: sub-type name (string)
   *                               - value: regex pattern (string)
   */
  public static function getAnalyticsSubPatterns(): array
  {
    return [
      'stock' => '/\b(stock|inventory|quantité|quantity)\b/i',
      'sales' => '/\b(sales|ventes|revenue|chiffre|CA)\b/i',
      'price' => '/\b(price|prix|cost|coût)\b/i',
      'performance' => '/\b(performance|best|top|meilleur)\b/i',
    ];
  }
  
  /**
   * Get product name extraction patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from WebSearchIntentAnalyzer for centralization
   * 
   * @return array<string> Array of regex patterns for extracting product names
   */
  public static function getProductNamePatterns(): array
  {
    return [
      '/price of\s+([^?]+?)(?:\s+(?:on|with|vs|versus)|\?|$)/i',
      '/compare\s+(?:price of\s+)?([^?]+?)(?:\s+(?:with|vs|versus)|\?|$)/i',
    ];
  }
  
  /**
   * Get competitor name patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from WebSearchIntentAnalyzer for centralization
   * 
   * @return array<string, string> Mapping of competitor name => regex pattern
   */
  public static function getCompetitorNamePatterns(): array
  {
    return [
      'amazon' => '/\b(amazon)\b/',
      'ebay' => '/\b(ebay)\b/',
      'aliexpress' => '/\b(aliexpress|ali express)\b/',
      'cdiscount' => '/\b(cdiscount)\b/',
      'fnac' => '/\b(fnac)\b/',
      'darty' => '/\b(darty)\b/',
    ];
  }
  
  /**
   * Get competitor general pattern for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from WebSearchIntentAnalyzer for centralization
   * 
   * @return string Regex pattern for general competitor mentions
   */
  public static function getCompetitorGeneralPattern(): string
  {
    return '/\b(competitor|competition)\b/';
  }
  
  /**
   * Get comparison type patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from WebSearchIntentAnalyzer for centralization
   * 
   * @return array<string, string> Mapping of comparison type => regex pattern
   */
  public static function getComparisonTypePatterns(): array
  {
    return [
      'compare' => '/\b(compare|comparison)\b/',
      'price' => '/\b(price)\b/',
      'availability' => '/\b(availability|stock)\b/',
    ];
  }
}