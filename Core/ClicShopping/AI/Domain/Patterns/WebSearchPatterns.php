<?php
/**
 * WebSearchPatterns.php
 * 
 * Pattern definitions for web search detection.
 * Contains ONLY pattern arrays - no logic.
 * 
 * @package ClicShopping\AI\Domain\Patterns
 * @since 2026-01-03
 */

namespace ClicShopping\AI\Domain\Patterns;

class WebSearchPatterns
{
  /**
   * Trends/news keywords (English-only)
   * 
   * Queries about trends, news, latest info require external web search
   */
  public static array $trendsNewsKeywords = [
    'trend',
    'trends',
    'trending',
    'latest',
    'recent',
    'news',
    'what\'s new',
    'whats new',
    ' new ', // Space-padded to avoid matching "renew", "newest" in other contexts
    'current',
    'nowadays',
    'today',
    'this week',
    'this month'
  ];
  
  /**
   * Entity keywords that indicate database queries (not web search)
   */
  public static array $entityKeywords = [
    'product', 'products', 'item', 'items', 'article', 'articles',
    'order', 'orders', 'sale', 'sales', 'purchase', 'purchases',
    'customer', 'customers', 'client', 'clients', 'user', 'users',
    'supplier', 'suppliers', 'vendor', 'vendors', 'manufacturer', 'manufacturers',
    'invoice', 'invoices', 'payment', 'payments', 'transaction', 'transactions'
  ];
  
  /**
   * Competitor keywords (English-only)
   */
  public static array $competitorKeywords = [
    'competitor',
    'competitors',
    'competition',
    'rival',
    'rivals'
  ];
  
  /**
   * Price keywords
   */
  public static array $priceKeywords = [
    'price', 
    'cost', 
    'pricing', 
    'prices', 
    'costs'
  ];
  
  /**
   * Comparison keywords
   */
  public static array $comparisonKeywords = [
    'compare',
    'comparison',
    'vs',
    'versus',
    'best price',
    'cheaper',
    'cheapest',
    'find',
    'search'
  ];
  
  /**
   * Internal keywords (to exclude from external site detection)
   */
  public static array $internalKeywords = [
    'database', 
    'site', 
    'website', 
    'store', 
    'shop', 
    'our', 
    'my', 
    'the', 
    'it'
  ];
  
  /**
   * Cheaper/availability patterns (regex)
   */
  public static array $cheaperPatterns = [
    '/\bis\s+it\s+(cheaper|available|sold)/i',
    '/\bcan\s+i\s+(find|buy|get)/i',
    '/\bwhere\s+(?:can|to)\s+(find|buy|get)/i'
  ];
}
