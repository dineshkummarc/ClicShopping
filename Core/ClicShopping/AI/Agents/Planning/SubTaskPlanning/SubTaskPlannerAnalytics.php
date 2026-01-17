<?php
/**
 * SubTaskPlannerAnalytics
 * 
 * Planificateur pour TOUTES les requêtes analytics de base
 * Responsabilité : Gérer COUNT, SUM, AVG, MIN, MAX, ORDER BY, GROUP BY
 * 
 * Ce planificateur est le "catch-all" pour les requêtes analytics qui ne correspondent
 * pas aux planificateurs spécialisés (competitor_analysis, pattern_analysis, price_analytics)
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\AI\Agents\Planning\SubTaskPlanning;

use AllowDynamicProperties;
use ClicShopping\AI\Agents\Planning\TaskStep;
use ClicShopping\AI\Security\SecurityLogger;

#[AllowDynamicProperties]
class SubTaskPlannerAnalytics
{
    private bool $debug;
    private ?SecurityLogger $securityLogger;
    
    // Keywords that indicate basic analytics queries
    private array $analyticsKeywords = [
        // Quantitative (COUNT)
        'how many', 'number of', 'count', 'total number',
        'combien', 'nombre de', 'nombre total',
        
        // Aggregation (SUM, AVG)
        'total', 'sum', 'average', 'mean',
        'total', 'somme', 'moyenne',
        
        // Sorting (ORDER BY)
        'cheapest', 'most expensive', 'highest', 'lowest', 'best', 'worst',
        'moins cher', 'plus cher', 'le plus', 'le moins', 'meilleur', 'pire',
        
        // Comparison (MIN, MAX)
        'minimum', 'maximum', 'min', 'max',
        'minimum', 'maximum',
        
        // Grouping (GROUP BY)
        'by category', 'by month', 'by year', 'per',
        'par catégorie', 'par mois', 'par année', 'par',
    ];
    
    public function __construct(bool $debug = false, ?SecurityLogger $securityLogger = null)
    {
        $this->debug = $debug;
        $this->securityLogger = $securityLogger;
    }
    
    /**
     * Détecte si une requête peut être gérée par le planificateur analytics de base
     * 
     * Ce planificateur gère TOUTES les requêtes analytics qui contiennent des mots-clés
     * d'agrégation, de comptage, de tri, etc.
     * 
     * Note: Ce planificateur est appelé APRÈS les planificateurs spécialisés
     * (competitor_analysis, pattern_analysis, price_analytics) donc il agit comme
     * un "catch-all" pour les requêtes analytics de base.
     */
    public function canHandle(string $query): bool
    {
        $queryLower = strtolower($query);
        
        // Check if query contains any analytics keywords
        foreach ($this->analyticsKeywords as $keyword) {
            if (str_contains($queryLower, $keyword)) {
                if ($this->debug) {
                    $this->logDebug("Analytics keyword detected: '$keyword' in query: " . substr($query, 0, 100));
                }
                return true;
            }
        }
        
        // If no keywords found, still return true because this is called only for analytics intent
        // This ensures ALL analytics queries go to SQL, not embeddings
        if ($this->debug) {
            $this->logDebug("No specific keywords, but accepting as basic analytics query: " . substr($query, 0, 100));
        }
        
        return true; // Accept all analytics queries as fallback
    }
    
    /**
     * Crée le plan analytics de base (1 étape SQL)
     * 
     * Ce plan génère une requête SQL pour répondre à la question analytics
     */
    public function createPlan(array $intent, string $query): array
    {
        if ($this->debug) {
            $this->logDebug("Creating basic analytics plan for query: " . substr($query, 0, 100));
        }
        
        $steps = [];

        // Detect query type for better SQL generation
        $queryType = $this->detectQueryType($query);
        
        // Étape unique: Requête analytics SQL
        $step1 = new TaskStep(
            'step_1',
            'analytics_query',
            $query,
            [
                'sub_query' => $query,  // Required for AnalyticsExecutor
                'intent' => $intent,
                'query_type' => $queryType,
                'data_source' => 'internal_database',
                'tables' => $this->detectTables($query),
                'processing_mode' => 'sql_generation',
                'operation_type' => $this->detectOperationType($query),
                'depends_on' => [],
                'can_run_parallel' => false,
                'is_final' => true,
                'planner' => 'analytics_basic'
            ]
        );
        $steps[] = $step1;

        if ($this->debug) {
            $this->logDebug("Created basic analytics plan: type=$queryType, operation=" . $step1->getMeta('operation_type'));
        }

        return $steps;
    }
    
    /**
     * Détecte le type de requête analytics
     */
    private function detectQueryType(string $query): string
    {
        $queryLower = strtolower($query);
        
        // Count queries
        if (preg_match('/\b(how many|number of|count|combien|nombre)\b/i', $queryLower)) {
            return 'count';
        }
        
        // Aggregation queries
        if (preg_match('/\b(total|sum|average|mean|somme|moyenne)\b/i', $queryLower)) {
            return 'aggregation';
        }
        
        // Sorting queries
        if (preg_match('/\b(cheapest|most expensive|highest|lowest|best|worst|moins cher|plus cher|le plus|le moins)\b/i', $queryLower)) {
            return 'sorting';
        }
        
        // Comparison queries
        if (preg_match('/\b(minimum|maximum|min|max)\b/i', $queryLower)) {
            return 'comparison';
        }
        
        // Grouping queries
        if (preg_match('/\b(by category|by month|by year|per|par catégorie|par mois|par année)\b/i', $queryLower)) {
            return 'grouping';
        }
        
        return 'basic_analytics';
    }
    
    /**
     * Détecte les tables nécessaires pour la requête
     */
    private function detectTables(string $query): array
    {
        $queryLower = strtolower($query);
        $tables = [];
        
        // Products
        if (preg_match('/\b(product|produit|article|item)\b/i', $queryLower)) {
            $tables[] = 'products';
            $tables[] = 'products_description';
        }
        
        // Categories
        if (preg_match('/\b(category|catégorie|categorie)\b/i', $queryLower)) {
            $tables[] = 'categories';
            $tables[] = 'categories_description';
        }
        
        // Customers
        if (preg_match('/\b(customer|client|user|utilisateur)\b/i', $queryLower)) {
            $tables[] = 'customers';
        }
        
        // Orders
        if (preg_match('/\b(order|commande|sale|vente|revenue|chiffre)\b/i', $queryLower)) {
            $tables[] = 'orders';
            $tables[] = 'orders_products';
        }
        
        // Suppliers
        if (preg_match('/\b(supplier|fournisseur)\b/i', $queryLower)) {
            $tables[] = 'suppliers';
        }
        
        // Manufacturers
        if (preg_match('/\b(manufacturer|fabricant|brand|marque)\b/i', $queryLower)) {
            $tables[] = 'manufacturers';
        }
        
        // Default: products if no specific table detected
        if (empty($tables)) {
            $tables = ['products', 'products_description'];
        }
        
        return array_unique($tables);
    }
    
    /**
     * Détecte le type d'opération SQL
     */
    private function detectOperationType(string $query): string
    {
        $queryLower = strtolower($query);
        
        if (preg_match('/\b(how many|number of|count|combien|nombre)\b/i', $queryLower)) {
            return 'COUNT';
        }
        
        if (preg_match('/\b(total|sum|somme)\b/i', $queryLower)) {
            return 'SUM';
        }
        
        if (preg_match('/\b(average|mean|moyenne)\b/i', $queryLower)) {
            return 'AVG';
        }
        
        if (preg_match('/\b(minimum|min)\b/i', $queryLower)) {
            return 'MIN';
        }
        
        if (preg_match('/\b(maximum|max)\b/i', $queryLower)) {
            return 'MAX';
        }
        
        if (preg_match('/\b(cheapest|lowest|moins cher)\b/i', $queryLower)) {
            return 'ORDER_BY_ASC';
        }
        
        if (preg_match('/\b(most expensive|highest|plus cher)\b/i', $queryLower)) {
            return 'ORDER_BY_DESC';
        }
        
        if (preg_match('/\b(by category|by month|by year|per|par)\b/i', $queryLower)) {
            return 'GROUP_BY';
        }
        
        return 'SELECT';
    }
    
    /**
     * Obtient les métadonnées du planificateur
     */
    public function getMetadata(): array
    {
        return [
            'name' => 'Basic Analytics Planner',
            'description' => 'Handles all basic analytics queries (COUNT, SUM, AVG, MIN, MAX, ORDER BY, GROUP BY)',
            'steps_count' => 1,
            'step_types' => ['analytics_query'],
            'data_sources' => ['internal_database'],
            'processing_mode' => 'sql_generation',
            'supports_operations' => ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'ORDER_BY', 'GROUP_BY'],
            'requires_external_data' => false,
            'is_fallback_planner' => false,
            'is_catch_all' => true,
            'priority' => 'medium', // After specialized planners, before standard
            'keywords' => $this->analyticsKeywords
        ];
    }
    
    private function logDebug(string $message): void
    {
        if ($this->securityLogger) {
            $this->securityLogger->logSecurityEvent($message, 'info');
        }
        
        if ($this->debug) {
            error_log("[SubTaskPlannerAnalytics] $message");
        }
    }
}
