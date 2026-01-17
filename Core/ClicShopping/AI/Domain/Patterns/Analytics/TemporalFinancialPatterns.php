<?php
/**
 * TemporalFinancialPatterns.php
 * 
 * Pattern definitions for temporal financial query detection.
 * Contains ONLY pattern arrays - no logic.
 * 
 * @package ClicShopping\AI\Domain\Patterns
 * @since 2026-01-03
  *
 * @deprecated Pattern-based logic superseded by Pure LLM Mode
 *             Scheduled for removal in Q3 2026
 *             Use UnifiedQueryAnalyzer for intent classification instead
 *             See Domain/Patterns/DEPRECATED.md for migration guide
 **/

namespace ClicShopping\AI\Domain\Patterns\Analytics;

// DEPRECATED: Pattern-based logic superseded by Pure LLM Mode. Scheduled for removal in Q3 2026.

class TemporalFinancialPatterns
{
  /**
   * Financial metric keywords (English-only)
   * 
   * These terms indicate financial/sales data queries:
   * - revenue, sales, turnover, profit, margin, income, earnings
   */
  public static array $financialMetrics = [
    // Core financial metrics
    'revenue', 'sales', 'turnover', 'profit', 'margin', 'income', 'earnings',
    
    // Variations
    'total sales', 'gross sales', 'net sales', 
    'total revenue', 'gross revenue', 'net revenue',
    'total income', 'gross income', 'net income',
  ];
  
  /**
   * Time period keywords (English-only)
   * 
   * These terms indicate temporal filtering:
   * - month, quarter, year, week, day, last, this, current
   */
  public static array $timePeriods = [
    // Time units
    'month', 'months', 'quarter', 'quarters', 'year', 'years', 
    'week', 'weeks', 'day', 'days',
    
    // Time modifiers
    'last', 'this', 'current', 'today', 'yesterday',
    
    // Common phrases
    'last month', 'this month', 'current month',
    'last quarter', 'this quarter', 'current quarter',
    'last year', 'this year', 'current year',
    'last week', 'this week', 'current week',
  ];
}
