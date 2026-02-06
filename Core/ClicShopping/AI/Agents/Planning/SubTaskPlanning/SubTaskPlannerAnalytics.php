<?php
/**
 * SubTaskPlannerAnalytics - Planner for basic analytics queries
 * Handles COUNT, SUM, AVG, MIN, MAX, ORDER BY, GROUP BY operations
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\AI\Agents\Planning\SubTaskPlanning;


use ClicShopping\AI\Agents\Planning\TaskStep;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\DomainsAI\DomainRegistry;


class SubTaskPlannerAnalytics
{
    private bool $debug;
    private ?SecurityLogger $securityLogger;
    
    private array $analyticsKeywords = [
        'how many', 'number of', 'count', 'total number',
        'combien', 'nombre de', 'nombre total',
        'total', 'sum', 'average', 'mean',
        'cheapest', 'most expensive', 'highest', 'lowest', 'best', 'worst',
        'minimum', 'maximum', 'min', 'max',
        'by category', 'by month', 'by year', 'per',
    ];
    
    public function __construct(bool $debug = false, ?SecurityLogger $securityLogger = null)
    {
        $this->debug = $debug;
        $this->securityLogger = $securityLogger;
    }
    
    /**
     * Check if query can be handled by this planner
     * 
     * @param string $query User query to analyze
     * @return bool True if planner can handle the query
     */
    public function canHandle(string $query): bool
    {
        $queryLower = strtolower($query);
        
        foreach ($this->analyticsKeywords as $keyword) {
            if (str_contains($queryLower, $keyword)) {
                if ($this->debug) {
                    $this->logDebug("Analytics keyword detected: '$keyword'");
                }
                return true;
            }
        }
        
        return true;
    }
    
    /**
     * Create analytics execution plan
     * 
     * @param array $intent Intent classification data
     * @param string $query User query
     * @return array Array of TaskStep objects
     */
    public function createPlan(array $intent, string $query): array
    {
        if ($this->debug) {
            $this->logDebug("Creating analytics plan");
        }
        
        $steps = [];
        $queryType = $this->detectQueryType($query);
        
        $step1 = new TaskStep(
            'step_1',
            'analytics_query',
            $query,
            [
                'sub_query' => $query,
                'intent' => $intent,
                'query_type' => $queryType,
                'data_source' => 'internal_database',
                'tables' => $this->getTablesFromDomain(),
                'processing_mode' => 'sql_generation',
                'operation_type' => $this->detectOperationType($query),
                'depends_on' => [],
                'can_run_parallel' => false,
                'is_final' => true,
                'planner' => 'analytics_basic'
            ]
        );
        $steps[] = $step1;

        return $steps;
    }
    
    /**
     * Detect analytics query type
     * 
     * @param string $query User query to analyze
     * @return string Query type (count, aggregation, sorting, comparison, grouping, basic_analytics)
     */
    private function detectQueryType(string $query): string
    {
        $queryLower = strtolower($query);
        
        if (preg_match('/\b(how many|number of|count|combien|nombre)\b/i', $queryLower)) {
            return 'count';
        }
        if (preg_match('/\b(total|sum|average|mean|somme|moyenne)\b/i', $queryLower)) {
            return 'aggregation';
        }
        if (preg_match('/\b(cheapest|most expensive|highest|lowest|best|worst|moins cher|plus cher|le plus|le moins)\b/i', $queryLower)) {
            return 'sorting';
        }
        if (preg_match('/\b(minimum|maximum|min|max)\b/i', $queryLower)) {
            return 'comparison';
        }
        if (preg_match('/\b(by category|by month|by year|per|par catégorie|par mois|par année)\b/i', $queryLower)) {
            return 'grouping';
        }
        
        return 'basic_analytics';
    }
    
    /**
     * Get tables from active domain configuration
     * 
     * @return array Array of table names from domain entity config
     */
    private function getTablesFromDomain(): array
    {
        $domainApp = DomainRegistry::getInstance()->getActiveApp();
        if ($domainApp && method_exists($domainApp, 'getEntityConfig')) {
            $entityConfig = $domainApp->getEntityConfig();
            $tables = [];
            foreach ($entityConfig as $entity) {
                if (isset($entity['table'])) {
                    $tables[] = $entity['table'];
                }
            }
            return array_unique($tables);
        }
        
        return [];
    }
    
    /**
     * Detect SQL operation type
     * 
     * @param string $query User query to analyze
     * @return string Operation type (COUNT, SUM, AVG, MIN, MAX, ORDER_BY_ASC, ORDER_BY_DESC, GROUP_BY, SELECT)
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
     * Get planner metadata
     * 
     * @return array Planner configuration and capabilities
     */
    public function getMetadata(): array
    {
        return [
            'name' => 'Basic Analytics Planner',
            'description' => 'Handles all basic analytics queries',
            'steps_count' => 1,
            'step_types' => ['analytics_query'],
            'data_sources' => ['internal_database'],
            'processing_mode' => 'sql_generation',
            'supports_operations' => ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'ORDER_BY', 'GROUP_BY'],
            'requires_external_data' => false,
            'is_catch_all' => true,
            'priority' => 'medium',
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
