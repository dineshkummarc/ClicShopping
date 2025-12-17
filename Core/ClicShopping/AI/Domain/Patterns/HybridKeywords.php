<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domain\Patterns;

/**
 * HybridKeywords Class
 * 
 * Centralized keyword lists for hybrid query detection.
 * 
 * CRITICAL: ALL KEYWORDS IN ENGLISH ONLY
 * - Queries are translated to English before pattern matching
 * - This ensures consistent processing regardless of input language
 * - Multilingual UI, English processing
 * 
 * @date 2025-12-11
 * @task 4.5.7 - Refactor keywords from QueryClassifier to Pattern classes
 */
class HybridKeywords
{
  /**
   * Get analytics keywords for hybrid detection
   * 
   * These keywords indicate quantitative data or database operations.
   * ALL KEYWORDS IN ENGLISH ONLY - queries are translated before matching.
   * 
   * @return array<string> Array of analytics keywords (English only)
   */
  public static function getAnalyticsKeywords(): array
  {
    return [
      // Quantitative data
      'stock', 'inventory', 'sales', 'sale', 'revenue', 'price', 'cost', 'quantity',
      'count', 'total', 'sum', 'average', 'data',
      
      // Time periods
      'annual', 'monthly', 'quarterly', 'quarters', 'yearly', 'weekly', 'daily',
      
      // Entities
      'products', 'product', 'orders', 'order', 'customers', 'customer',
      'categories', 'category', 'manufacturers', 'manufacturer', 'suppliers', 'supplier',
      
      // Metrics
      'turnover', 'profit', 'margin', 'growth', 'performance',
      'best', 'top', 'worst', 'lowest', 'highest',
    ];
  }
  
  /**
   * Get semantic keywords for hybrid detection
   * 
   * These keywords indicate explanations, policies, or document queries.
   * ALL KEYWORDS IN ENGLISH ONLY - queries are translated before matching.
   * 
   * NOTE: Excludes generic query verbs like "show", "list", "display", "what", "how"
   * These are query verbs, not semantic indicators.
   * 
   * @return array<string> Array of semantic keywords (English only)
   */
  public static function getSemanticKeywords(): array
  {
    return [
      // Summarization
      'summary', 'summarize', 'summarise', 'overview', 'brief',
      
      // Explanation
      'explain', 'describe', 'clarify', 'elaborate',
      
      // Policies and documents
      'policy', 'procedure', 'warranty', 'guarantee',
      'return policy', 'refund', 'shipping policy', 'delivery policy',
      'terms', 'conditions', 'terms and conditions',
      'privacy', 'confidential', 'privacy policy',
      
      // Document analysis
      'converging', 'convergent', 'important points', 'key points',
      'compare documents', 'differences', 'similarities',
    ];
  }
  
  /**
   * Get web search keywords for hybrid detection
   * 
   * These keywords indicate external data needs (competitors, market research).
   * ALL KEYWORDS IN ENGLISH ONLY - queries are translated before matching.
   * 
   * @return array<string> Array of web search keywords (English only)
   */
  public static function getWebSearchKeywords(): array
  {
    return [
      // Competitive
      'competitor', 'competitors', 'competition', 'competitive', 'rival', 'rivals',
      
      // Comparison
      'compare', 'comparison', 'vs', 'versus', 'against',
      
      // Market
      'market', 'marketplace', 'industry', 'sector',
      
      // Online/External
      'online', 'web', 'internet', 'external', 'outside',
      
      // Platforms
      'amazon', 'ebay', 'alibaba',
    ];
  }
  
  /**
   * Get strong web search indicator patterns
   * 
   * These patterns indicate queries that should be web_search, not hybrid.
   * ALL PATTERNS IN ENGLISH ONLY - queries are translated before matching.
   * 
   * @return array<string> Array of regex patterns (English only)
   */
  public static function getStrongWebSearchIndicators(): array
  {
    return [
      '/\b(competitor|competitors|competition)\b/i',
      '/\b(compare|comparison)\b.*\b(with|to|against)\b.*\b(competitor|competitors)\b/i',
      '/\b(find|search)\b.*\b(online|web|internet)\b/i',
      '/\b(best|top)\b.*\b(online|web|internet)\b/i',
    ];
  }
  
  /**
   * Get common phrase patterns where "and" is NOT a connector
   * 
   * These patterns identify phrases like "terms and conditions" where "and"
   * is part of the phrase, not a connector between two intents.
   * ALL PATTERNS IN ENGLISH ONLY - queries are translated before matching.
   * 
   * @return array<string> Array of regex patterns (English only)
   */
  public static function getCommonPhrasePatterns(): array
  {
    return [
      '/\bterms\s+and\s+conditions\b/i',
      '/\bprivacy\s+and\s+policy\b/i',
      '/\bprivacy\s+policy\b/i',
      '/\bterms\s+of\s+service\b/i',
      '/\bterms\s+of\s+sale\b/i',
    ];
  }
  
  /**
   * Get connector patterns for hybrid detection
   * 
   * These patterns identify connectors between multiple intents.
   * ALL PATTERNS IN ENGLISH ONLY - queries are translated before matching.
   * 
   * @return array<string> Array of regex patterns (English only)
   */
  public static function getConnectorPatterns(): array
  {
    return [
      '/\b(and|then|also)\b/i',
      '/\b(with|plus|along with)\b/i',
    ];
  }
  
  /**
   * Get internal reference patterns
   * 
   * These patterns identify references to internal data (our, my, the).
   * Used to distinguish hybrid queries from pure web_search queries.
   * ALL PATTERNS IN ENGLISH ONLY - queries are translated before matching.
   * 
   * @return array<string> Array of regex patterns (English only)
   */
  public static function getInternalReferencePatterns(): array
  {
    return [
      '/\b(our|my|the|internal)\b/i',
    ];
  }
  
  /**
   * Get web search exclusion patterns for analytics detection
   * 
   * These patterns identify queries that should NOT be classified as analytics
   * because they require external web search.
   * ALL PATTERNS IN ENGLISH ONLY - queries are translated before matching.
   * 
   * @return array<string> Array of regex patterns (English only)
   */
  public static function getWebSearchExclusionPatterns(): array
  {
    return [
      '/\b(competitor|competitors|competition)\b/i',
      '/\b(compare|comparison)\b.*\b(with|to|against)\b.*\b(competitor|competitors|market)\b/i',
      '/\b(online|web|internet)\b.*\b(search|find|price)\b/i',
      '/\b(market)\b.*\b(trends?|analysis|research)\b/i',
    ];
  }
}
