<?php
/**
 * SubTaskPlannerStandard - Default fallback planner for generic analytics queries
 * 
 * @copyright 2008 - https://www.clicshopping.org
 */

namespace ClicShopping\AI\Agents\Planning\SubTaskPlanning;

use AllowDynamicProperties;
use ClicShopping\AI\Agents\Planning\TaskStep;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\DomainsAI\DomainRegistry;

#[AllowDynamicProperties]
class SubTaskPlannerStandard
{
    private bool $debug;
    private ?SecurityLogger $securityLogger;
    
    public function __construct(bool $debug = false, ?SecurityLogger $securityLogger = null)
    {
        $this->debug = $debug;
        $this->securityLogger = $securityLogger;
    }
    
    public function canHandle(string $query): bool
    {
        return true; // Fallback planner, accepts all
    }
    
    public function createPlan(array $intent, string $query): array
    {
        $steps = [];

        $step1 = new TaskStep(
            'step_1',
            'analytics_query',
            $query,
            [
                'sub_query' => $query,
                'intent' => $intent,
                'query_type' => 'standard_analytics',
                'data_source' => 'internal_database',
                'tables' => $this->getTablesFromDomain(),
                'processing_mode' => 'direct_sql',
                'depends_on' => [],
                'can_run_parallel' => false,
                'is_final' => true,
            ]
        );
        $steps[] = $step1;

        return $steps;
    }
    
    public function getMetadata(): array
    {
        return [
            'name' => 'Standard Analytics Planner',
            'description' => 'Default planner for generic analytics queries',
            'steps_count' => 1,
            'step_types' => ['analytics_query'],
            'data_sources' => ['internal_database'],
            'processing_mode' => 'direct_sql',
            'supports_fallback' => true,
            'requires_external_data' => false,
            'is_fallback_planner' => true
        ];
    }
    
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
    
    private function logDebug(string $message): void
    {
        if ($this->securityLogger) {
            $this->securityLogger->logSecurityEvent($message, 'info');
        }
        
        if ($this->debug) {
            error_log("[SubTaskPlannerStandard] $message");
        }
    }
}