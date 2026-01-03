<?php
/**
 * SuperlativePatterns.php
 * 
 * Pattern definitions for superlative query detection.
 * Contains ONLY pattern arrays - no logic.
 * 
 * @package ClicShopping\AI\Domain\Patterns
 * @since 2026-01-03
 */

namespace ClicShopping\AI\Domain\Patterns;

class SuperlativePatterns
{
  /**
   * Superlative keywords (English-only)
   * 
   * These terms indicate MIN/MAX/BEST/WORST queries:
   * - most expensive, cheapest, highest, lowest, best-selling, worst-selling
   * - most recent, latest, newest, oldest (temporal superlatives)
   */
  public static array $superlativeKeywords = [
    // Price superlatives
    'most expensive', 'least expensive', 'cheapest', 'priciest',
    'highest price', 'lowest price', 'highest priced', 'lowest priced',
    'most costly', 'least costly',
    
    // Sales superlatives
    'best-selling', 'best selling', 'worst-selling', 'worst selling',
    'most sold', 'least sold', 'most popular', 'least popular',
    'top-selling', 'top selling', 'bottom-selling', 'bottom selling',
    
    // Temporal superlatives (for orders, dates, etc.)
    'most recent', 'least recent', 'latest', 'earliest',
    'newest', 'oldest', 'most current', 'most up-to-date',
    'first', 'last',
    
    // General superlatives
    'most', 'least', 'best', 'worst', 'highest', 'lowest',
    'maximum', 'minimum', 'top', 'bottom',
    
    // Comparative forms that imply superlative
    'more expensive than', 'less expensive than', 'cheaper than', 'pricier than',
  ];
  
  /**
   * Entity keywords that indicate database queries
   */
  public static array $entityKeywords = [
    'product', 'products', 'item', 'items', 'article', 'articles',
    'order', 'orders', 'sale', 'sales', 'purchase', 'purchases',
    'customer', 'customers', 'client', 'clients', 'user', 'users',
    'supplier', 'suppliers', 'vendor', 'vendors', 'manufacturer', 'manufacturers',
  ];
}
