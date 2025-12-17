<?php

namespace ClicShopping\AI\Domain\Patterns;

use ClicShopping\AI\Helper\DatabaseSchemaIntrospector;

class AnalyticsPattern
{
  // Verbes et objets récurrents pour factorisation
  private const SHOW_VERBS = 'show|give|display|get';
  private const PRODUCTS_ITEMS = 'products?|items?|articles?';
  private const PRICE_TERMS = 'price|cost|amount|value';
  private const REFERENCE_TERMS = 'sku|ean|upc|isbn|gtin|barcode|reference|ref|model';
  private const TIME_TERMS = 'day|week|month|quarter|year';

  /**
   * Retourne les patterns analytics catégorisés
   *
   * @return array<string, array<string>>
   */
  public static function getAnalyticsPatterns(): array
  {
    return [
      'entity' => [
        // Liste et interrogation
        '/\b(which|what|all|list of)\s+(' . self::PRODUCTS_ITEMS . '|orders?|customers?|sales|returns?|stock|inventory)\b/i',
        '/\b(brands?|manufacturers?)\b/i',
        '/\b(?:' . self::SHOW_VERBS . ')\s+(?:me|us)?\s*(?:all\s+)?(?:the\s+)?(characteristics?|features?|details?|specifications?)\s+(?:of|for|about)\s+(?:product|item|reference)\b/i',
        '/\b(?:list|show|give|get|display)\s+(?:all\s+)?' . self::PRODUCTS_ITEMS . '\b/i'
      ],
      'time' => [
        '/\b(in|during|over|for|the|last|past|recent|next|previous|current)\s+\d*\s*' . self::TIME_TERMS . 's?\b/i',
        '/\b(today|yesterday|this week|this month|this year|current|now|year[-\s]?to[-\s]?date|ytd)\b/i',
        '/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{4}\b/i',
        '/\bQ[1-4]\s+\d{4}\b/i',
        '/\b(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})\b/i'
      ],
      'stock' => [
        '/\b(stock|inventory|available|availability|alert|level|threshold|reorder|shortage|out of stock)\b/i',
        '/\b(products?)\s+(out of stock|unavailable|to order|to restock)\b/i',
      ],
      'reference' => [
        '/\b(' . self::REFERENCE_TERMS . ')[-\s]?([\w-]+)\b/i',
        '/\b(?:find|search|get|show)\s+(?:by|with)\s+(' . self::REFERENCE_TERMS . ')[-\s]?([\w-]+)\b/i',
        '/\b(?:products?|items?)\s+(?:with|having|that have|which have)\s+(?:a|an|both)?\s*(' . self::REFERENCE_TERMS . ')\b/i',
      ],
      'price' => [
        '/\b(?:what is|what\'s|give me|show me|tell me|find|get)\s+(?:the\s+)?(' . self::PRICE_TERMS . ')\s+(?:of|for)\s+(?:the\s+)?' . self::PRODUCTS_ITEMS . '[\w\s\-]*\b/i',
        '/\b(' . self::PRICE_TERMS . ')\s+(?:of|for)\s+(?:the\s+)?' . self::PRODUCTS_ITEMS . '[\w\s\-]*\b/i',
        '/\b(?:list|show|give|get)\s+(?:all\s+)?' . self::PRODUCTS_ITEMS . '\s+(?:with|and)\s+(?:their\s+)?(' . self::PRICE_TERMS . 's?)\b/i',
        '/\b(' . self::PRICE_TERMS . ')s?\s+(?:by|per)\s+' . self::PRODUCTS_ITEMS . '\b/i'
      ],
      'quantity' => [
        '/\b(quantity|number|amount|count|total|volume)\s*(>|<|>=|<=|=|==|equal to|greater than|less than|at least|at most|between)\s*\d+\b/i',
        '/\b(between)\s*(\d+)\s*and\s*(\d+)\b/i',
      ],
      'performance' => [
        '/\b(revenue|sales|profit|margin|turnover|earnings|income)\b/i',
        '/\b(best|top|worst|most popular|least popular|best selling|worst selling)\s+' . self::PRODUCTS_ITEMS . '\b/i',
      ],
      'comparison' => [
        '/\b(compare|comparison|difference|progression|evolution|growth|variation)\b/i',
        '/\b(previous|last|past|current|next)\s+' . self::TIME_TERMS . '\b/i',
      ],
      'category' => [
        '/\b(category|categories|family|families|group|groups|type|types)\s+of\s+' . self::PRODUCTS_ITEMS . '\b/i',
        '/\b(brand|brands|manufacturer|manufacturers)\b/i'
      ],
      'customer' => [
        '/\b(customers?|buyers?|consumers?|users?)\b/i',
        '/\b(segmentation|segment|segments)\s+of\s+customers?\b/i',
      ],
      'calculation' => [
        '/\b(calculate|calculation|sum|total|average|median|min|max|count)\b/i',
        '/\b(add|subtract|multiply|divide)\b/i',
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
  }

  /**
   * Helper pour transformer une constante regex simple (ex: 'word1?|word2') en tableau de mots
   */
  private static function getWordsFromConstant(string $constant): array
  {
    $words = array_map(function($word) {
      // Supprime le '?' pour obtenir le mot de base (ex: 'products?' devient 'products')
      return trim(str_replace('?', '', $word));
    }, explode('|', $constant));

    return array_unique(array_filter($words));
  }

  /**
   * Obtient les mots-clés simples d'une entité du domaine pour le filtrage de mémoire.
   * Cette fonction a été centralisée depuis ContextManager et enrichie.
   * 
   * IMPROVED VERSION: Combines static keywords with dynamic field discovery
   *
   * @param string $domain Nom du domaine (ex: 'products', 'orders')
   * @param bool $useDynamic Whether to include dynamic fields from database (default: true)
   * @return array Mots-clés simples
   */
  public static function getDomainKeywords(string $domain, bool $useDynamic = true): array
  {
    // 1. Get static keywords (semantic and linguistic terms)
    $staticKeywords = self::getStaticDomainKeywords($domain);
    
    // 2. Get dynamic keywords from database schema (if enabled)
    if ($useDynamic) {
      try {
        $fieldsByTable = \ClicShopping\AI\Helper\DatabaseSchemaIntrospector::getFieldsByTable();
        $dynamicKeywords = $fieldsByTable[$domain] ?? [];
        
        // Combine static and dynamic keywords
        return \array_unique(\array_merge($staticKeywords, $dynamicKeywords));
      } catch (\Exception $e) {
        // Fallback to static keywords if dynamic discovery fails
        return $staticKeywords;
      }
    }
    
    return $staticKeywords;
  }
  
  /**
   * Get static domain keywords (semantic and linguistic terms)
   * 
   * These are NOT database fields but semantic concepts and linguistic variations.
   * 
   * @param string $domain Domain name
   * @return array Static keywords
   */
  private static function getStaticDomainKeywords(string $domain): array
  {
    // 1. Récupération des mots à partir des constantes de la classe
    $productsWords = self::getWordsFromConstant(self::PRODUCTS_ITEMS);
    $priceWords = self::getWordsFromConstant(self::PRICE_TERMS);
    $referenceWords = self::getWordsFromConstant(self::REFERENCE_TERMS);

    // 2. Construction de la structure de mots-clés par domaine
    $keywords = [
      'products' => \array_unique(\array_merge(
        $productsWords, // products, item, article
        $priceWords,    // price, cost, amount, value
        $referenceWords, // sku, ean, reference, model
        [
          // Mots-clés supplémentaires non inclus dans les constantes de regex
          'stock', 'inventory', 'quantity', 'availability', 'option', 'feature', 'attribute',
          'variant', 'details', 'specification', 'specs'
        ]
      )),
      'categories' => [
        'category', 'categories', 'section', 'sections', 'department',
        'departments', 'family', 'families', 'range', 'type', 'subcategory'
      ],
      'orders' => [
        'order', 'orders', 'sale', 'sales', 'purchase', 'purchases',
        'transaction', 'transactions', 'invoice', 'shipment', 'delivery', 'order_number'
      ],
      'customers' => [
        'customer', 'customers', 'client', 'clients', 'user', 'users',
        'member', 'members', 'account', 'profile', 'account_id', 'loyalty'
      ],
      'suppliers' => [
        'supplier', 'suppliers', 'vendor', 'vendors', 'provider', 'providers',
        'supply', 'supplies'
      ],
      'manufacturers' => [
        'manufacturer', 'manufacturers', 'brand', 'brands', 'maker', 'makers'
      ],
      'reviews' => [
        'review', 'reviews', 'comment', 'comments', 'rating', 'ratings',
        'feedback', 'testimonial', 'opinion', 'evaluation', 'score'
      ],
      'reviews_sentiment' => [
        'sentiment', 'sentiments', 'positive', 'negative', 'neutral',
        'score', 'opinion', 'feedback', 'evaluation'
      ],
    ];

    return $keywords[$domain] ?? [];
  }


  /**
   * Vérifie si le texte contient des mots-clés analytiques
   * @param string|null $text
   * @return bool Retourne true si le texte contient des mots-clés analytiques
   */
  public static function hasAnalyticsKeywords(?string $text): bool
  {
    $keywords = [
      'compare','price','sales','revenue','total','average','count','stats','data','report','analysis',
      'sku','ean','upc','isbn','gtin','barcode','reference','model','stock','inventory','quantity',
      'products','items','orders','customers','categories','brands','manufacturers','suppliers',
      'cart','checkout','invoice','payment','refund','margin','profit','turnover',
      'attribute','variant','option','collection','family','feature',
      'sum','min','max','median','percent','ratio','growth','trend'
    ];
    return preg_match('/\b(' . implode('|', $keywords) . ')\b/i', $text) === 1;
  }

  /**
   * Retourne une liste de mots-clés simples liés à l'analytics
   * @return array Liste des mots-clés analytiques simples
   */
  public static function getSimpleAnalyticsKeywords(): array
  {
    return [
      'count', 'total', 'sum', 'average', 'avg', 'min', 'max', 'how many',
      'products', 'orders', 'customers', 'sales', 'inventory', 'stock',
      'active', 'inactive', 'available', 'in stock', 'status', 'list', 'show',
      'pending', 'completed', 'instance,'
    ];
  }


  /**
   * Retourne une liste de champs structurés liés à l'analytics
   * 
   * IMPROVED VERSION: Uses dynamic field discovery from database schema
   * 
   * @param bool $useCache Whether to use cached results (default: true)
   * @return array Liste des champs analytiques structurés
   */
  public static function getStructuredFields(bool $useCache = true): array
  {
    // Use dynamic field discovery
    return DatabaseSchemaIntrospector::getAllDatabaseFields($useCache);
  }


  /**
   * Vérifie si le texte contient des patterns analytiques spécifiques
   * @param string $text
   * @return bool Retourne true si le texte correspond à un pattern analytique
   */
  public static function hasAnalyticalContext(string $text): bool
  {
    $patterns = [
      // Variation / chiffres
      '/\b(increase|decrease|variation)\b/i',
      '/\b(from \d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|between.*and)\b/i',
      '/\b(more|less)\s+than\b/i',
      '/\b(percentage|ratio|rate)\b/i',

      // Références
      '/\b(?:reference|ref|no|number|id)\s*[:# ]?\s*\d+/i',

      // Caractéristiques produit
      '/\b(?:characteristics?|features?|details?|specifications?)\s+(?:of|for|about)\s+(?:product|item)\b/i',

      // Prix / coût (multi-mots)
      '/\b(?:list|show|give|get|display)\s+(?:all\s+)?' . self::PRODUCTS_ITEMS . '\s+(?:with|and)\s+(?:their\s+)?(?:prices?|costs?|amounts?|values?)\b/i',
      '/\b(?:price|cost|amount|value)s?\s+(?:by|per)\s+' . self::PRODUCTS_ITEMS . '\b/i',
      '/\b(?:price|cost|amount|value)\s+(?:of|for)\s+(?:the\s+)?' . self::PRODUCTS_ITEMS . '[\w\s\-]*\b/i',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $text)) {
        return true;
      }
    }
    return false;
  }

/**
   * Defines and returns regex patterns to detect analytics queries.
   * 
   * IMPORTANT: All patterns MUST be in English only.
   * French queries are translated to English BEFORE pattern matching.
   * 
   * Process: Query → Translation to English → Pattern Matching → LLM Fallback (if needed)
   * 
   * TASK 3.2 FIX (2025-12-11): Made patterns MORE SPECIFIC to avoid false positives
   * - Require numeric/temporal context for aggregation queries
   * - Added stock keywords: stock, quantity, inventory
   * - Added sales keywords: revenue, sales, turnover
   * - Added list keywords: show, list, all products
   * - Improved specificity to prevent semantic query misclassification
   *
   * @return array<string, float> Associative array where:
   *                             - key: regex pattern (string)
   *                             - value: confidence score (float)
   */
  public static function detectAnalyticsQuery(): array
  {
    return [
      // ============================================
      // AGGREGATION QUERIES (require numeric/temporal context)
      // ============================================
      // Count/total/sum/average with numeric context or entity
      // 🔧 TASK 4.4 FIX: Added "all" as optional word between aggregation verb and entity
      '/\b(count|total|sum|average|mean)\s+(?:of\s+)?(?:the\s+)?(?:all\s+)?(' . self::PRODUCTS_ITEMS . '|orders?|customers?|sales|categories?)\b/i' => 0.90,
      '/\b(how\s+many|how\s+much)\s+(' . self::PRODUCTS_ITEMS . '|orders?|customers?|sales|categories?)\b/i' => 0.95,
      
      // 🔧 2025-12-14: Added calculate pattern for aggregation queries without time context
      '/\b(calculate|compute|determine|find)\s+(?:the\s+)?(?:total|sum|average|mean|count)\s+(?:of\s+)?(?:the\s+)?(revenue|sales|profit|margin|turnover|earnings|income|' . self::PRODUCTS_ITEMS . '|orders?|customers?)\b/i' => 0.90,
      
      // ============================================
      // STOCK QUERIES (TASK 3.2: Added stock keywords)
      // ============================================
      '/\b(stock|inventory|quantity|available|availability)\s+(?:of|for|level)?\s*(' . self::PRODUCTS_ITEMS . '|[\w\s-]+)\b/i' => 0.95,
      '/\b(what|show|give|get|display)\s+(?:is|are|me)?\s*(?:the\s+)?(stock|inventory|quantity|quantities)\b/i' => 0.95,
      '/\b(' . self::PRODUCTS_ITEMS . ')\s+(?:in\s+)?(stock|inventory|available)\b/i' => 0.90,
      '/\b(out\s+of\s+stock|low\s+stock|stock\s+alert|reorder)\b/i' => 0.95,
      '/\b(quantities?)\s+(?:in|of)?\s*(stock|inventory)\b/i' => 0.95,
      
      // ============================================
      // SALES/REVENUE QUERIES (TASK 3.2: Added sales keywords)
      // ============================================
      '/\b(sales|revenue|turnover|profit|margin|earnings|income)\s+(?:by|per|for|in)\s*(month|quarter|year|day|week|period|this|last|next)\b/i' => 0.95,
      '/\b(annual|monthly|quarterly|weekly|daily)\s+(sales|revenue|turnover|profit)\b/i' => 0.95,
      '/\b(best|top|worst|most|least)\s+(?:selling|sold|profitable)?\s*(' . self::PRODUCTS_ITEMS . '|sales)\b/i' => 0.90,
      '/\b(sales)\s+(?:for|in|during)\s+(this|last|next|current)\s+(quarter|month|year|week)\b/i' => 0.95,
      
      // ============================================
      // PRICE QUERIES (exclude competitive comparisons)
      // ============================================
      '/\b(price|cost|pricing)\s+(?:of|for)\s+(' . self::PRODUCTS_ITEMS . '|[\w\s-]+)\b(?!.*\b(competitor|amazon|compare|best|online)\b)/i' => 0.85,
      '/\b(what|show|give|get|display)\s+(?:is|me)?\s*(?:the\s+)?(price|cost)\b(?!.*\b(competitor|amazon|compare|best|online)\b)/i' => 0.85,
      
      // 🔧 2025-12-14 TASK 13: Added pattern for internal/our pricing (no negative lookahead)
      // This allows hybrid queries like "compare with competitors and show internal pricing"
      '/\b(show|display|give|get|list)\s+(?:me|us)?\s*(?:the\s+)?(?:internal|our|my)\s+(price|prices|pricing|cost|costs)\b/i' => 0.90,
      
      // ============================================
      // LIST QUERIES (TASK 3.2: Added list keywords)
      // ============================================
      // List/show with entity type (products, categories, orders, customers, suppliers)
      // 🔧 TASK 4.3: Added suppliers? to the list pattern
      '/\b(list|show|display|give|get)\s+(?:me|us)?\s*(?:all)?\s*(?:the)?\s*(' . self::PRODUCTS_ITEMS . '|categories?|orders?|customers?|brands?|manufacturers?|suppliers?)\b/i' => 0.90,
      '/\b(show|list)\s+(?:all)?\s*(' . self::PRODUCTS_ITEMS . ')\s+(?:in|from|of)\s+(category|brand|manufacturer|supplier)\b/i' => 0.90,
      
      // ============================================
      // STATUS/FILTER QUERIES
      // ============================================
      '/\b(status)\s+(off|on|active|inactive|enabled|disabled)\b/i' => 0.90,
      '/\b(active|inactive|enabled|disabled)\s+(' . self::PRODUCTS_ITEMS . '|categories?|orders?|customers?)\b/i' => 0.90,
      '/\b(' . self::PRODUCTS_ITEMS . '|categories?)\s+(with|having)\s+(status)\b/i' => 0.90,
      
      // ============================================
      // NUMERIC CONTEXT QUERIES (TASK 3.2: Added numeric detection)
      // ============================================
      // Queries with explicit numbers (top 10, last 5, etc.)
      '/\b(top|first|last|best|worst)\s+\d+\s+(' . self::PRODUCTS_ITEMS . '|orders?|customers?|sales)\b/i' => 0.95,
      '/\b(' . self::PRODUCTS_ITEMS . '|orders?|customers?)\s+(?:with|having)\s+\w+\s*(>|<|>=|<=|=|equal|greater|less)\s*\d+\b/i' => 0.95,
      
      // ============================================
      // TEMPORAL CONTEXT QUERIES (TASK 3.2: Added temporal detection)
      // ============================================
      '/\b(in|during|over|for|the|last|past|recent|next|previous|current)\s+\d*\s*' . self::TIME_TERMS . 's?\b/i' => 0.90,
      '/\b(today|yesterday|this\s+week|this\s+month|this\s+year|current|now)\b/i' => 0.85,
      '/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{4}\b/i' => 0.90,
      '/\bQ[1-4]\s+\d{4}\b/i' => 0.90,
      
      // ============================================
      // REPORT GENERATION
      // ============================================
      '/\b(create|generate|make)\s+(?:a|an)?\s*(?:analysis\s+)?(report|analysis)\b/i' => 0.95,
      
      // ============================================
      // REFERENCE/SKU QUERIES
      // ============================================
      '/\b(' . self::REFERENCE_TERMS . ')[-\s:]?\s*([\w-]+)\b/i' => 0.85,
      '/\b(find|search|get|show)\s+(?:by|with)\s+(' . self::REFERENCE_TERMS . ')\b/i' => 0.85,
    ];
  }
  
  /**
   * Get fallback database fields (static list)
   * 
   * Used by DatabaseSchemaIntrospector when dynamic discovery fails.
   * Centralized here to avoid duplication.
   * 
   * @return array List of common database fields
   */
  public static function getFallbackDatabaseFields(): array
  {
    // Use array_unique to ensure no duplicates
    return \array_unique([
      // Technical identifiers
      'sku', 'ean', 'upc', 'isbn', 'gtin', 'barcode', 'code', 'reference', 'ref', 'model',
      'id', 'number', 'serial',
      
      // Measurable attributes
      'price', 'cost', 'amount', 'value', 'total', 'subtotal',
      'stock', 'inventory', 'quantity', 'qty', 'available', 'count',
      'weight', 'height', 'width', 'length', 'dimension', 'dimensions', 'size',
      
      // Timestamps
      'date', 'time', 'created', 'updated', 'modified', 'deleted',
      'timestamp', 'datetime',
      
      // Status fields
      'status', 'state', 'active', 'enabled', 'disabled', 'published',
      'visible', 'sold', 'shipped',  // Removed duplicate 'available'
      
      // Financial
      'tax', 'discount', 'margin', 'profit', 'revenue', 'sales',
      
      // Relationships
      'category', 'brand', 'manufacturer', 'supplier', 'vendor',
      
      // Contact info
      'email', 'phone', 'address', 'zip', 'postal', 'city', 'country',
      
      // Ratings
      'rating', 'score', 'rank', 'position', 'order'
    ]);
  }
  
  /**
   * Get non-database words (descriptive/explanatory terms)
   * 
   * These words are commonly used in queries but are NOT database fields.
   * Used to filter out semantic queries from analytics queries.
   * 
   * @return array List of non-database words
   */
  public static function getNonDatabaseWords(): array
  {
    return [
      'description', 'summary', 'information', 'details', 'info',
      'explanation', 'definition', 'meaning', 'purpose',
      'features', 'benefits', 'advantages', 'characteristics',
      'quality', 'performance', 'specifications', 'specs',
      'history', 'background', 'story', 'about',
      'why', 'how', 'what', 'when', 'where', 'who',
      'policy', 'terms', 'conditions', 'rules', 'regulations'
    ];
  }
  
  /**
   * Get field abbreviations mapping
   * 
   * Maps full field names to their common abbreviations.
   * Used for field name normalization.
   * 
   * @return array Mapping of full name => abbreviation
   */
  public static function getFieldAbbreviations(): array
  {
    return [
      'quantity' => 'qty',
      'reference' => 'ref',
      'description' => 'desc',
      'number' => 'no',
      'identifier' => 'id',
    ];
  }
  
  /**
   * Get analytics keywords
   * 
   * Keywords that indicate analytics queries.
   * Used for detecting analytics + analytics hybrid queries.
   * 
   * REFACTORING 2025-12-14: Extracted from QuerySplitter for centralization
   * 
   * @return array List of analytics keywords
   */
  public static function getAnalyticsKeywords(): array
  {
    return [
      // Metrics
      'stock', 'inventory', 'sales', 'sale', 'price', 'count',
      'total', 'sum', 'quantity', 'revenue', 'products',
      'profit', 'turnover', 'income', 'cost', 'amount', 'value',
      
      // Time periods
      'annual', 'monthly', 'quarterly', 'weekly', 'daily',
      
      // Entities
      'orders', 'order', 'customers', 'customer', 'categories', 'category',
      
      // French equivalents
      'chiffre', 'CA', 'ventes', 'vente', 'prix', 'quantité',
      'revenu', 'produits', 'commandes', 'commande', 'clients', 'client'
    ];
  }
  
  /**
   * Get time range patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from AnalyticsIntentAnalyzer for centralization
   * 
   * @return array<string, string> Mapping of range type => regex pattern
   */
  public static function getTimeRangePatterns(): array
  {
    return [
      'today' => '/\b(today)\b/',
      'yesterday' => '/\b(yesterday)\b/',
      'this_week' => '/\b(this week)\b/',
      'this_month' => '/\b(this month)\b/',
      'this_year' => '/\b(this year|annual)\b/',
      'last_month' => '/\b(last month)\b/',
      'last_year' => '/\b(last year)\b/',
    ];
  }
  
  /**
   * Get aggregation type patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from AnalyticsIntentAnalyzer for centralization
   * 
   * @return array<string, string> Mapping of aggregation type => regex pattern
   */
  public static function getAggregationPatterns(): array
  {
    return [
      'count' => '/\b(how many|number of|count)\b/',
      'sum' => '/\b(total|sum)\b/',
      'average' => '/\b(average|mean|avg)\b/',
      'max' => '/\b(max|maximum|highest)\b/',
      'min' => '/\b(min|minimum|lowest)\b/',
    ];
  }
  
  /**
   * Get entity type patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from AnalyticsIntentAnalyzer for centralization
   * 
   * @return array<string, string> Mapping of entity type => regex pattern
   */
  public static function getEntityTypePatterns(): array
  {
    return [
      'product' => '/\b(product|item)\b/',
      'order' => '/\b(order|sale)\b/',
      'customer' => '/\b(customer|user)\b/',
      'category' => '/\b(category)\b/',
      'stock' => '/\b(stock|inventory)\b/',
    ];
  }
  
  /**
   * Get status filter patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from AnalyticsIntentAnalyzer for centralization
   * 
   * @return array<string, string> Mapping of status => regex pattern
   */
  public static function getStatusFilterPatterns(): array
  {
    return [
      'active' => '/\b(active)\b/',
      'inactive' => '/\b(inactive)\b/',
    ];
  }
  
  /**
   * Get price filter pattern for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from AnalyticsIntentAnalyzer for centralization
   * 
   * @return string Regex pattern for price filters
   */
  public static function getPriceFilterPattern(): string
  {
    return '/\b(price)\s*(>|<|=)\s*(\d+)/';
  }
}
