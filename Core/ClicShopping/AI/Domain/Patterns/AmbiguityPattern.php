<?php
/**
 * AmbiguityPattern.php
 * 
 * Provides patterns and indicators for detecting ambiguous queries
 * Used by AmbiguousQueryDetector to identify queries that need clarification
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 * 
 * @package ClicShopping\AI\Domain\Patterns
 * @author ClicShopping Team
 * @date 2025-12-06
 */

namespace ClicShopping\AI\Domain\Patterns;

/**
 * Class AmbiguityPattern
 * 
 * Provides indicators and keywords that suggest query ambiguity
 * Does NOT contain hardcoded patterns - used as hints for LLM-based detection
 */
class AmbiguityPattern
{
  /**
   * Get ambiguity indicators for LLM guidance
   * These are hints, not strict patterns
   * 
   * @return array Ambiguity indicators by category
   */
  public static function getAmbiguityIndicators(): array
  {
    return [
      'quantification_ambiguity' => [
        'description' => 'Query could mean COUNT (distinct items) or SUM (total quantity)',
        'keywords' => ['how many', 'combien', 'number of', 'nombre de'],
        'context_needed' => ['products', 'items', 'stock', 'inventory'],
        'example_ambiguous' => 'How many products in stock?',
        'example_clear_count' => 'How many distinct products in stock?',
        'example_clear_sum' => 'What is the total quantity in stock?'
      ],
      
      'scope_ambiguity' => [
        'description' => 'Query scope unclear (all, recent, active, etc.)',
        'keywords' => ['show', 'list', 'display', 'montre', 'affiche'],
        'context_needed' => ['orders', 'customers', 'products'],
        'example_ambiguous' => 'Show me orders',
        'example_clear_all' => 'Show me all orders',
        'example_clear_recent' => 'Show me orders from last 30 days'
      ],
      
      'metric_ambiguity' => [
        'description' => 'Metric type unclear (average, min, max, sum, etc.)',
        'keywords' => ['price', 'cost', 'value', 'prix', 'coût'],
        'context_needed' => ['products', 'orders', 'sales'],
        'example_ambiguous' => 'What is the price?',
        'example_clear_avg' => 'What is the average price?',
        'example_clear_list' => 'List all prices'
      ],
      
      'time_ambiguity' => [
        'description' => 'Time period unclear',
        'keywords' => ['sales', 'orders', 'revenue', 'ventes', 'commandes'],
        'context_needed' => ['no time period specified'],
        'example_ambiguous' => 'Show me sales',
        'example_clear_period' => 'Show me sales for last quarter'
      ]
    ];
  }
  
  /**
   * Get keywords that indicate explicit intent (not ambiguous)
   * 
   * @return array Keywords that clarify intent
   */
  public static function getClarityKeywords(): array
  {
    return [
      'count_explicit' => ['count', 'distinct', 'unique', 'different', 'types of'],
      'sum_explicit' => ['sum', 'total', 'aggregate', 'combined', 'all together'],
      'average_explicit' => ['average', 'mean', 'avg', 'typical'],
      'min_explicit' => ['minimum', 'min', 'lowest', 'cheapest', 'smallest'],
      'max_explicit' => ['maximum', 'max', 'highest', 'most expensive', 'largest'],
      'all_explicit' => ['all', 'every', 'complete', 'entire', 'full'],
      'recent_explicit' => ['recent', 'latest', 'last', 'past', 'previous'],
      'active_explicit' => ['active', 'current', 'ongoing', 'pending', 'in progress']
    ];
  }
  
  /**
   * Get common ambiguous query patterns for reference
   * These are examples, not exhaustive patterns
   * 
   * @return array Example patterns
   */
  public static function getCommonAmbiguousPatterns(): array
  {
    return [
      'stock_quantity' => [
        'en' => ['how many products', 'products in stock', 'available products'],
        'fr' => ['combien de produits', 'produits en stock', 'produits disponibles']
      ],
      'order_list' => [
        'en' => ['show orders', 'list orders', 'customer orders'],
        'fr' => ['montre commandes', 'liste commandes', 'commandes client']
      ],
      'price_query' => [
        'en' => ['product price', 'price of', 'how much'],
        'fr' => ['prix produit', 'prix de', 'combien coûte']
      ]
    ];
  }

  /**
   * Get vague words that indicate unclear queries
   * 
   * NEW: Test 5.5 - Détection d'ambiguïté
   * These words alone or at the start of short queries indicate vagueness
   * 
   * @return array Vague words by language
   */
  public static function getVagueWords(): array
  {
    return [
      'french' => [
        'pronouns' => ['ça', 'ca', 'cela', 'celui', 'celle', 'ceux', 'celles'],
        'questions' => ['quoi', 'comment', 'pourquoi', 'où', 'qui', 'que'],
        'acknowledgments' => ['ok', 'oui', 'non', 'bon', 'bien', 'voilà'],
        'conjunctions' => ['et', 'ou', 'mais', 'donc', 'alors'],
        'fillers' => ['hein', 'euh', 'ben', 'bah']
      ],
      'english' => [
        'pronouns' => ['it', 'this', 'that', 'these', 'those'],
        'questions' => ['what', 'how', 'why', 'where', 'who'],
        'acknowledgments' => ['ok', 'yes', 'no', 'well', 'fine'],
        'conjunctions' => ['and', 'or', 'but', 'so', 'then'],
        'fillers' => ['uh', 'um', 'er', 'ah']
      ]
    ];
  }

  /**
   * Get minimum query length threshold
   * 
   * @return int Minimum characters for a clear query
   */
  public static function getMinimumQueryLength(): int
  {
    return 5;
  }

  /**
   * Get suggested questions for vague queries
   * 
   * @return array Suggested questions by language
   */
  public static function getSuggestedQuestions(): array
  {
    return [
      'french' => [
        'Quels sont les modes de livraison ?',
        'Quelles sont les conditions de paiement ?',
        'Montre-moi les ventes de cette année',
        'Liste des produits en stock',
        'Quel est le prix de [produit] ?',
        'Combien de commandes aujourd\'hui ?'
      ],
      'english' => [
        'What are the delivery methods?',
        'What are the payment conditions?',
        'Show me sales for this year',
        'List products in stock',
        'What is the price of [product]?',
        'How many orders today?'
      ]
    ];
  }

  /**
   * Get keywords for detecting missing parameters (ENGLISH ONLY)
   * 
   * Used by ClarificationHelper to detect when queries lack necessary context
   * All queries are translated to English before analysis
   * 
   * @return array Keywords by parameter type
   */
  public static function getMissingParameterKeywords(): array
  {
    return [
      'price_without_product' => [
        'keywords' => ['price', 'cost', 'pricing'],
        'missing' => 'product_id'
      ],
      'stock_without_product' => [
        'keywords' => ['stock', 'quantity', 'available', 'inventory'],
        'missing' => 'product_id'
      ],
      'sales_without_time' => [
        'keywords' => ['sales', 'orders', 'revenue'],
        'time_keywords' => ['today', 'yesterday', 'week', 'month', 'year', 'quarter'],
        'missing' => 'time_range'
      ]
    ];
  }

  /**
   * Get contextual pronouns (ENGLISH ONLY)
   * 
   * Pronouns that indicate a reference to previous context
   * All queries are translated to English before analysis
   * 
   * @return array List of contextual pronouns
   */
  public static function getContextualPronouns(): array
  {
    return [
      'it', 'its', 'this', 'that', 'these', 'those',
      'he', 'she', 'they', 'them', 'his', 'her', 'their'
    ];
  }

  /**
   * Get clarification questions by missing parameter type (ENGLISH ONLY)
   * 
   * @return array Questions by parameter type
   */
  public static function getClarificationQuestions(): array
  {
    return [
      'product_id' => 'Which product would you like information about?',
      'time_range' => 'For which time period would you like this data?',
      'default' => 'Could you please clarify your request?'
    ];
  }

  /**
   * Get clarification options by missing parameter type (ENGLISH ONLY)
   * 
   * @return array Options by parameter type
   */
  public static function getClarificationOptions(): array
  {
    return [
      'product_id' => [
        'Search product by name',
        'Enter product ID',
        'View recent products'
      ],
      'time_range' => [
        'Today',
        'This week',
        'This month',
        'This year'
      ]
    ];
  }
}
