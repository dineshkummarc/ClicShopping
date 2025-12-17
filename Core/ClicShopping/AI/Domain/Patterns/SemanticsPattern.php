<?php
namespace ClicShopping\AI\Domain\Patterns;

class SemanticsPattern
{
  // Constants for keyword factorization (ALL IN ENGLISH ONLY)
  // CRITICAL FIX: Added missing semantic keywords
  private const QUERY_VERBS = 'tell|explain|describe|summarize|summarise|sum up|show|what|which|how|why|compare';
  private const HELP_TERMS = 'help|support|assistance|problem|issue';
  private const POLICY_TERMS = 'policy|policies|terms|conditions|rules|guideline|procedure|refund|warranty|guarantee|return|shipping|delivery|exchange';
  private const LOCATION_TERMS = 'location|address|city|street|district|region|country|state|province|postal|zip';

  /**
   * Defines and returns regex patterns for semantic queries.
   * 
   * ALL PATTERNS IN ENGLISH ONLY - queries are translated before pattern matching
   *
   * @return array<string, array<string>>
   */
    public static function getSemanticPatterns(): array
  {
    $Q = self::QUERY_VERBS;
    $H = self::HELP_TERMS;
    $P = self::POLICY_TERMS;
    $L = self::LOCATION_TERMS;

    return [
      'conversational' => [
        '/\b(?:' . $Q . ')\s+(?:me|us)\s+about\b/i',
        '/\b(?:what)\s+(?:do\s+you|can\s+you)\s+(?:' . $Q . ')\s+(?:me|us)\s+about\b/i',
        '/\bhow\s+(?:does|do)\s+[\w\s]+\s+work\b/i'
      ],

      'location' => [
        '/\bwhere\s+(?:is|are|can\s+(?:i|we)\s+find)\s+[\w\s]+\??$/i',
        '/\b(?:' . $L . ')\s+of\s+[\w\s]+\??$/i',
        '/\bhow\s+(?:to|do\s+i)\s+(?:get|go)\s+to\s+[\w\s]+\??$/i'
      ],

      'product_info' => [
        '/\b(?:what|which)\s+(?:is|are)\s+(?:the)?\s*(?:features?|attributes|options|specifications?|details?)\s+of\b/i',
        '/\b(?:tell|describe|explain)\s+(?:me|us)\s+about\s+(?:the)?\s*(?:product|item)\b/i'
      ],

      'support' => [
        '/\b(?:' . $H . ')\b/i',
        '/\bhow\s+(?:can|do)\s+(?:i|we)\s+(?:contact|reach)\b/i'
      ],

      'explanation' => [
        '/\bhow\s+(?:to|do\s+(?:i|we))\b/i',
        '/\bwhat\s+(?:is|are)\b/i',
        '/\bwhy\s+(?:is|are|do|does)\b/i'
      ],

      'policy' => [
        '/\b(?:' . $P . ')\b/i',
        '/\b(?:return|refund|warranty|guarantee)\s+(?:policy|procedure)\b/i'
      ],
      
      // CRITICAL FIX: Add summary patterns (ENGLISH ONLY)
      'summary' => [
        '/\b(?:summary|summarize|summarise|sum\s+up|overview|brief|synopsis)\b/i',
        '/\b(?:give|show|tell)\s+(?:me|us)\s+(?:a|an)?\s*(?:summary|overview|brief)\b/i',
        '/\bmake\s+(?:a|an)\s+(?:summary|overview)\b/i'
      ],
      
      // CRITICAL FIX: Add important points patterns (ENGLISH ONLY)
      'important_points' => [
        '/\b(?:important|key|main|essential)\s+points\b/i',
        '/\bhighlights\b/i',
        '/\bmost\s+important\b/i'
      ],
      
      // CRITICAL FIX: Add comparison patterns (ENGLISH ONLY)
      'comparison' => [
        '/\bcompare\b(?!.*\b(?:price|competitor)\b)/i',  // Exclude price comparisons (analytics)
        '/\bcomparison\b/i',
        '/\bconvergent\b/i',
        '/\bconvergence\b/i',
        '/\bsimilarities\b/i',
        '/\bdifferences\s+between\b/i',
        '/\bbetween\b.*\band\b/i'
      ]
    ];
  }

  public static function hasSemanticKeywords(string $text): bool
  {
    $semanticKeywords = [
      // Question words (ENGLISH ONLY)
      'how to', 'why', 'what is', 'what are', 'which', 'who', 'where', 'when',
      
      // Explanation/description verbs (ENGLISH ONLY)
      'explain', 'describe', 'tell me', 'show me', 'give me',
      
      // Summary keywords (ENGLISH ONLY) - CRITICAL FIX
      'summary', 'summarize', 'summarise', 'sum up', 'overview', 'brief', 'synopsis',
      
      // Important points keywords (ENGLISH ONLY) - CRITICAL FIX
      'important points', 'key points', 'main points', 'highlights', 'essential',
      
      // Document comparison keywords (ENGLISH ONLY) - CRITICAL FIX
      'compare', 'comparison', 'convergent', 'convergence', 'similarities', 'differences',
      'between', 'versus', 'vs',
      
      // Policy/document keywords (ENGLISH ONLY)
      'policy', 'policies', 'procedure', 'procedures', 'rules', 'guidelines', 'terms',
      'conditions', 'agreement', 'contract', 'document',
      
      // Question keywords (ENGLISH ONLY) - CRITICAL FIX
      'refund', 'warranty', 'guarantee', 'return', 'shipping', 'delivery', 'exchange',
      
      // Help/support keywords (ENGLISH ONLY)
      'help', 'assistance', 'support', 'problem', 'issue',
      
      // Definition keywords (ENGLISH ONLY)
      'definition', 'meaning', 'what does', 'what means'
    ];

    $textLower = strtolower($text);

    foreach ($semanticKeywords as $keyword) {
      if (strpos($textLower, $keyword) !== false) {
        return true;
      }
    }

    return false;
  }

  public static function isEnhancedSemanticQuery(string $text): bool
  {
    // ALL PATTERNS IN ENGLISH ONLY
    $enhancedPatterns = [
      // Location queries (ENGLISH ONLY)
      '/\bwhere\s+(?:is|are)\b/i',
      '/\blocation\s+of\b/i',
      '/\bcapital\s+of\b/i',
      
      // Question patterns (ENGLISH ONLY)
      '/\bwhat\s+(?:is|are)\b/i',
      '/\bwho\s+(?:is|are)\b/i',
      '/\bwhen\s+(?:was|is|are)\b/i',
      '/\bwhy\b/i',
      '/\bhow\s+(?:to|do|does|can)\b/i',
      '/\bwhich\b/i',
      
      // Explanation verbs (ENGLISH ONLY)
      '/\bexplain\b/i',
      '/\bdescribe\b/i',
      '/\btell\s+me\b/i',
      '/\bshow\s+me\b/i',
      '/\bgive\s+me\b/i',
      
      // Summary keywords (ENGLISH ONLY) - CRITICAL FIX
      '/\bsummar(?:y|ize|ise)\b/i',
      '/\bsum\s+up\b/i',
      '/\boverview\b/i',
      '/\bbrief\b/i',
      '/\bsynopsis\b/i',
      
      // Important points (ENGLISH ONLY) - CRITICAL FIX
      '/\b(?:important|key|main|essential)\s+points\b/i',
      '/\bhighlights\b/i',
      
      // Comparison keywords (ENGLISH ONLY) - CRITICAL FIX
      '/\bcompare\b/i',
      '/\bcomparison\b/i',
      '/\bconvergent\b/i',
      '/\bconvergence\b/i',
      '/\bsimilarities\b/i',
      '/\bdifferences\b/i',
      '/\bbetween\b/i',
      '/\bversus\b/i',
      
      // Policy/document keywords (ENGLISH ONLY)
      '/\bpolic(?:y|ies)\b/i',
      '/\bprocedure(?:s)?\b/i',
      '/\bterms\b/i',
      '/\bconditions\b/i',
      '/\bagreement\b/i',
      '/\bcontract\b/i',
      '/\bdocument\b/i',
      
      // Question keywords (ENGLISH ONLY) - CRITICAL FIX
      '/\brefund\b/i',
      '/\bwarranty\b/i',
      '/\bguarantee\b/i',
      '/\breturn\b/i',
      '/\bshipping\b/i',
      '/\bdelivery\b/i',
      '/\bexchange\b/i',
      
      // Help/support (ENGLISH ONLY)
      '/\bhelp\b/i',
      '/\bsupport\b/i',
      '/\bassistance\b/i',
      '/\bproblem\b/i',
      '/\bissue\b/i',
      
      // Definition (ENGLISH ONLY)
      '/\bdefinition\b/i',
      '/\bmeaning\b/i',
      '/\bwhat\s+does\b/i',
      '/\bwhat\s+means\b/i',
    ];

    foreach ($enhancedPatterns as $pattern) {
      if (preg_match($pattern, $text)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Vérifie si le texte correspond à un motif de requête sémantique.
   *
   * @param string $text Le texte à vérifier.
   * @return bool Vrai si le texte correspond à un motif sémantique, faux sinon.
   */
  public static function isSemanticQuery(string $text): bool
  {
    $patterns = self::getSemanticPatterns();

    foreach ($patterns as $type => $typePatterns) {
      foreach ($typePatterns as $pattern) {
        if (preg_match($pattern, $text)) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Vérifie si le texte correspond à une requête géographique.
   *
   * @param string $text Le texte à vérifier.
   * @return bool Vrai si le texte correspond à une requête géographique, faux sinon.
   */
  public static function isGeographicQuery(string $text): bool
  {
    $basePatterns = [
      'location' => '/\blocation\s+of\s+[\w\s]+\??$/i',
      'where_is' => '/\bwhere\s+(?:is|can\s+(?:i|we)\s+find|can\s+(?:i|we)\s+locate)\s+[\w\s]+\??$/i',
      'directions' => '/\bdirections?\s+(?:to|towards?|for)\s+[\w\s]+\??$/i',
      'how_to_get' => '/\bhow\s+(?:to\s+(?:get|go)|do\s+(?:i|we)\s+(?:get|go))\s+to\s+[\w\s]+\??$/i',
      'find_near' => '/\b(?:find|locate|search|looking\s+for)\s+[\w\s]+\s+(?:near|around|close\s+to|nearby|in\s+the\s+area\s+of)\s+[\w\s]+\??$/i',
      'address' => '/\b(?:what\s+is\s+)?(?:the\s+)?(?:address|location|place|spot)\s+(?:of|for)\s+[\w\s]+\??$/i',
      'in_area' => '/\b(?:what|which|any)\s+[\w\s]+\s+(?:in|around|near)\s+(?:the\s+)?(?:area|region|zone|district|neighborhood)\s+(?:of\s+)?[\w\s]+\??$/i',
      'distance' => '/\b(?:what\s+is\s+)?(?:the\s+)?distance\s+(?:between|from|to)\s+[\w\s]+\??$/i',
      'travel_time' => '/\b(?:how\s+long|time)\s+(?:does\s+it\s+take|to\s+get|to\s+travel)\s+(?:from|to|between)\s+[\w\s]+\??$/i',
      'landmarks' => '/\b(?:what|which)\s+(?:landmarks?|monuments?|places\s+of\s+interest)\s+(?:are|is)\s+(?:in|near|around)\s+[\w\s]+\??$/i',
      'boundaries' => '/\b(?:where\s+(?:does|is)\s+)?(?:the\s+)?(?:border|boundary|limit)\s+of\s+[\w\s]+\??$/i',
      'delivery_area' => '/\b(?:do\s+you|does\s+it)\s+(?:deliver|ship|serve)\s+(?:to|in)\s+[\w\s]+\??$/i',
      'coverage' => '/\b(?:is|are)\s+[\w\s]+\s+(?:available|accessible)\s+in\s+[\w\s]+\??$/i',
      'store_location' => '/\b(?:where\s+(?:is|are)\s+)?(?:the\s+)?(?:nearest|closest)\s+(?:store|shop|branch|outlet|office)\s+(?:to|in|near)\s+[\w\s]+\??$/i',
      'opening_hours' => '/\b(?:what\s+(?:are|is)\s+)?(?:the\s+)?(?:opening|business|working)\s+hours?\s+(?:for|of)\s+[\w\s]+\s+(?:in|at)\s+[\w\s]+\??$/i'
    ];

    $customPatterns = [];
    $geographicContextPatterns = [
      '/\b(?:city|town|village|district|region|country|state|province)\b/i',
      '/\b(?:street|avenue|boulevard|road|highway|intersection)\b/i',
      '/\b(?:north|south|east|west|northern|southern|eastern|western)\b/i',
      '/\b(?:downtown|uptown|suburb|metropolitan|urban|rural)\b/i',
      '/\b(?:postal|zip)\s+code\b/i'
    ];

    $allPatterns = array_merge($basePatterns, $customPatterns);

    foreach ($allPatterns as $pattern) {
      if (preg_match($pattern, $text)) {
        return true;
      }
    }

    $contextMatches = 0;
    foreach ($geographicContextPatterns as $pattern) {
      if (preg_match($pattern, $text)) {
        $contextMatches++;
        if ($contextMatches >= 2) {
          return true;
        }
      }
    }

    return false;
  }

  public static function detectSemanticQuery()
  {
    // ALL PATTERNS IN ENGLISH ONLY - queries are translated before pattern matching
    return [
      // Policy/procedure patterns (ENGLISH ONLY)
      '/\b(policy|policies|procedure|procedures)\b/i'                     => 0.9,
      '/\b(terms?\s+(and\s+)?conditions?|terms?\s+of\s+service)\b/i'     => 0.9,
      '/\b(regulation|regulations|confidential|confidentiality)\b/i'     => 0.9,
      '/\b(faq|frequently\s+asked\s+questions?)\b/i'                     => 0.9,
      
      // Question keywords (ENGLISH ONLY) - CRITICAL FIX
      '/\b(return|refund|warranty|guarantee)\s+(policy|procedure)\b/i'   => 0.95,
      '/\b(return|refund|warranty|guarantee|shipping|delivery|exchange)\b/i' => 0.85,
      
      // Summary keywords (ENGLISH ONLY) - CRITICAL FIX
      '/\b(summary|summarize|summarise|sum\s+up|overview|brief|synopsis)\b/i' => 0.9,
      
      // Important points (ENGLISH ONLY) - CRITICAL FIX
      '/\b(?:important|key|main|essential)\s+points\b/i' => 0.9,
      '/\bhighlights\b/i' => 0.85,
      
      // Comparison keywords (ENGLISH ONLY) - CRITICAL FIX
      '/\b(compare|comparison|convergent|convergence|similarities|differences)\b/i' => 0.85,
      '/\bbetween\b.*\band\b/i' => 0.8,
      
      // Question patterns (ENGLISH ONLY)
      // TASK 13.3 (2025-12-14): Increased from 0.75 to 0.85 to meet >= 0.80 requirement
      '/\b(what\s+is|what\s+are|how\s+to|how\s+do|how\s+does|why|which)\b/i' => 0.85,
      '/\b(explain|describe|tell\s+me|show\s+me|give\s+me)\b/i' => 0.85,
      
      // Help/support patterns (ENGLISH ONLY) - TASK 13.3 (2025-12-14): Added missing patterns
      '/\b(help|support|assistance|problem|issue)\b/i' => 0.90,
      '/\b(how\s+(?:can|do)\s+(?:i|we)\s+(?:contact|reach))\b/i' => 0.90,
      
      // Information requests (ENGLISH ONLY)
      '/\b(tell\s+me\s+about|information\s+about|details\s+about)\b/i'    => 0.7,
      
      // Documentation (ENGLISH ONLY)
      '/\b(documentation|manual|guide|instructions)\b/i'                  => 0.8,
    ];
  }

  public static function semanticDomainPatterns(): array
  {
    return [
      'products' => [
        'core' => ['product','products','item','items','article','articles'],
        'technical' => ['sku','ean','model','gtin','upc','barcode','reference','ref'],
        'attributes' => ['stock','inventory','quantity','price','cost','weight','dimension'],
        'status' => ['active','inactive','available','unavailable','in-stock','out-of-stock'],
        'concepts' => ['catalog','merchandise','goods','commodity'],
      ],
      'categories' => [
        'core' => ['category','categories','section','sections'],
        'synonyms' => ['department','departments','division','divisions'],
        'concepts' => ['family','families','range','ranges','group','groups'],
        'hierarchy' => ['parent','child','subcategory','main-category'],
      ],
      'orders' => [
        'core' => ['order','orders','sale','sales'],
        'synonyms' => ['purchase','purchases','transaction','transactions'],
        'documents' => ['invoice','invoices','receipt','receipts','bill'],
        'status' => ['pending','confirmed','shipped','delivered','cancelled'],
        'concepts' => ['checkout','cart','basket','payment'],
      ],
      'customers' => [
        'core' => ['customer','customers','client','clients'],
        'synonyms' => ['user','users','member','members','buyer','buyers'],
        'concepts' => ['account','accounts','profile','profiles'],
        'attributes' => ['email','address','phone','contact'],
      ],
      'reviews' => [
        'core' => ['review','reviews','rating','ratings'],
        'synonyms' => ['comment','comments','feedback','feedbacks'],
        'concepts' => ['testimonial','testimonials','opinion','opinions'],
        'attributes' => ['star','stars','score','evaluation'],
      ],
      'suppliers' => [
        'core' => ['supplier','suppliers','vendor','vendors'],
        'synonyms' => ['provider','providers','wholesaler','wholesalers','distributor','distributors'],
        'concepts' => ['supply-chain','procurement','sourcing'],
        'attributes' => ['supplier-name','supplier-code','supplier-contact'],
      ],
      'manufacturers' => [
        'core' => ['manufacturer','manufacturers','brand','brands'],
        'synonyms' => ['maker','makers','producer','producers'],
        'concepts' => ['factory','factories','production','manufacturing'],
        'attributes' => ['brand-name','manufacturer-name','origin','made-by'],
      ],
      'return_orders' => [
        'core' => ['return','returns','return-order','return-orders'],
        'synonyms' => ['refund','refunds','rma','exchange','exchanges'],
        'concepts' => ['return-merchandise','product-return','order-return'],
        'status' => ['returned','refunded','exchanged','pending-return'],
        'attributes' => ['return-reason','return-date','return-status'],
      ],
      'reviews_sentiment' => [
        'core' => ['sentiment','sentiments','review-sentiment','opinion-analysis'],
        'synonyms' => ['feeling','feelings','emotion','emotions','mood'],
        'concepts' => ['positive','negative','neutral','satisfaction','dissatisfaction'],
        'attributes' => ['sentiment-score','polarity','tone','attitude'],
        'analysis' => ['sentiment-analysis','opinion-mining','text-analysis'],
      ],
    ];
  }

  public static function requiresConversationContext(): array
  {
    return ['/\\b(it|its|this|that|these|those|them|they|their)\\b/i'];
  }

  // NOTE: hasSemanticKeywords et isEnhancedSemanticQuery peuvent être fusionnées
  // en une seule fonction utilisant un tableau factorisé de motifs et un score de confiance pour prioriser le matching.


  /**
   * Defines and returns semantic keywords for confidence calculation.
   * 
   * ALL KEYWORDS IN ENGLISH ONLY - queries are translated before pattern matching
   *
   * @return array<string>
   */
  public static  function calculateConfidenceSemanticKeywords() :array
  {
    $patterns = [
      // Question words (ENGLISH ONLY)
      'what is', 'what\'s', 'what are', 'which', 'who', 'where is', 'where are', 'when', 
      'how to', 'how do I', 'how do', 'how does', 'how can', 'why',
      
      // Explanation verbs (ENGLISH ONLY)
      'explain', 'describe', 'tell me', 'show me', 'give me',
      
      // Summary keywords (ENGLISH ONLY) - CRITICAL FIX
      'summary', 'summarize', 'summarise', 'sum up', 'overview', 'brief', 'synopsis',
      
      // Important points (ENGLISH ONLY) - CRITICAL FIX
      'important points', 'key points', 'main points', 'highlights', 'essential points',
      
      // Comparison keywords (ENGLISH ONLY) - CRITICAL FIX
      'compare', 'comparison', 'convergent', 'convergence', 'similarities', 'differences',
      'between', 'versus', 'vs',
      
      // Policy/document keywords (ENGLISH ONLY)
      'policy', 'policies', 'procedure', 'procedures', 'terms', 'conditions',
      'agreement', 'contract', 'document', 'guidelines', 'rules',
      
      // Question keywords (ENGLISH ONLY) - CRITICAL FIX
      'refund', 'warranty', 'guarantee', 'return', 'shipping', 'delivery', 'exchange',
      
      // Help/support (ENGLISH ONLY)
      'help', 'assistance', 'support', 'problem', 'issue',
      
      // Definition (ENGLISH ONLY)
      'definition', 'meaning', 'what does', 'what means',
      
      // Information requests (ENGLISH ONLY)
      'display', 'find', 'lookup', 'search', 'get', 'details of', 'features of', 
      'specifications of', 'characteristics of', 'information about',
      
      // NOTE: Removed analytics keywords (time, metrics, etc.) - those belong in AnalyticsPattern
      // This function should only contain SEMANTIC keywords
    ];

    return $patterns;
  }

  /**
   * Get reference patterns for detection
   *
   * ENGLISH ONLY: All patterns are in English as per HybridQueryProcessor design:
   * "All detection and processing logic should operate in English for consistency
   * in a multilingual context."
   *
   * @return array Array of regex patterns (English only)
   */
  public static function getReferencePatterns(): array
  {
    return [
      // Demonstrative pronouns (ENGLISH ONLY)
      '/\b(it|this|that|them|these|those)\b/i',
      // Relative temporal references (ENGLISH ONLY)
      '/\b(previous|last|earlier|former)\b/i',
      // Comparative/Anaphora (ENGLISH ONLY)
      '/\b(same|similar|also|too|likewise)\b/i',
      // Explicit follow-up phrases (ENGLISH ONLY)
      '/\b(what about|how about|and)\s+(\w+)\b/i',
      // Possessive references (ENGLISH ONLY)
      '/\b(its|his|her|their)\b/i',
    ];
  }

  /**
   * Extract entities from message
   *
   * @return array
   */
  public static function extractEntitiesFromMessage()
  {
    $array = [
      'products' => ['product', 'products', 'item', 'items', 'article', 'articles', 'sku', 'model', 'ean', 'gtin', 'reference', 'ref'],
      'categories' => ['category', 'categories', 'section', 'sections', 'department', 'departments', 'family', 'families', 'range'],
      'orders' => ['order', 'orders', 'sale', 'sales', 'purchase', 'purchases', 'transaction', 'transactions', 'invoice', 'order number', 'order id'],
      'customers' => ['customer', 'customers', 'client', 'clients', 'user', 'users', 'member', 'members', 'account', 'profile'],
      'suppliers' => ['supplier', 'suppliers', 'vendor', 'vendors', 'provider', 'providers', 'wholesaler', 'distributor'],
      'manufacturers' => ['manufacturer', 'manufacturers', 'brand', 'brands', 'maker', 'producer', 'origin', 'made-by'],
      'reviews' => ['review', 'reviews', 'rating', 'ratings', 'comment', 'comments', 'feedback', 'testimonial', 'testimonials'],
      'reviews_sentiment' => ['sentiment', 'sentiments', 'review-sentiment', 'opinion-analysis', 'feeling', 'emotion', 'positive', 'negative', 'neutral'],
      'price' => ['price', 'cost', 'pricing', 'value', 'amount'],
      'stock' => ['stock', 'inventory', 'quantity', 'available', 'remaining'],
    ];

    return $array;
  }
  
  /**
   * Get question type patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from SemanticIntentAnalyzer for centralization
   * 
   * @return array<string, string> Mapping of question type => regex pattern
   */
  public static function getQuestionTypePatterns(): array
  {
    return [
      'what' => '/\b(what)\b/i',
      'how' => '/\b(how)\b/i',
      'why' => '/\b(why)\b/i',
      'when' => '/\b(when)\b/i',
      'where' => '/\b(where)\b/i',
      'who' => '/\b(who)\b/i',
    ];
  }
  
  /**
   * Get topic patterns for metadata extraction
   * 
   * ENGLISH ONLY: Queries are translated to English before pattern matching
   * 
   * REFACTORING 2025-12-14: Extracted from SemanticIntentAnalyzer for centralization
   * 
   * @return array<string, string> Mapping of topic => regex pattern
   */
  public static function getTopicPatterns(): array
  {
    return [
      'payment' => '/\b(payment|pay)\b/i',
      'delivery' => '/\b(delivery|shipping)\b/i',
      'return' => '/\b(return|refund)\b/i',
      'policy' => '/\b(policy|terms|conditions)\b/i',
      'product' => '/\b(product|item)\b/i',
    ];
  }
}
