<?php
/**
 * SubTaskPlannerStandard
 * 
 * Planificateur standard pour les requêtes analytics génériques
 * Responsabilité : Créer des plans simples pour requêtes ne correspondant à aucun type spécialisé
 */

namespace ClicShopping\AI\Agents\Planning\SubTaskPlanning;

use AllowDynamicProperties;
use ClicShopping\AI\Agents\Planning\TaskStep;
use ClicShopping\AI\Security\SecurityLogger;

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
    
    /**
     * Détecte si une requête peut être gérée par le planificateur standard
     * Note: Ce planificateur accepte toutes les requêtes (fallback)
     */
    public function canHandle(string $query): bool
    {
        return true; // Planificateur de fallback, accepte tout
    }
    
    /**
     * Crée le plan standard (1 étape analytics simple)
     */
    public function createPlan(array $intent, string $query): array
    {
        if ($this->debug) {
            $this->logDebug("Creating standard analytics plan for query: " . substr($query, 0, 100));
        }
        
        $steps = [];

        // Étape unique: Requête analytics standard
        $step1 = new TaskStep(
            'step_1',
            'analytics_query',
            $query,
            [
                'sub_query' => $query,  // 🔧 TASK 4.3.4.3: Add sub_query metadata for AnalyticsExecutor
                'intent' => $intent,
                'query_type' => 'standard_analytics',
                'data_source' => 'internal_database',
                'tables' => ['products', 'categories', 'customers', 'orders','suppliers', 'manufacturers'],
                'processing_mode' => 'direct_sql',
                'depends_on' => [],
                'can_run_parallel' => false,
                'is_final' => true,
            ]
        );
        $steps[] = $step1;

        if ($this->debug) {
            $this->logDebug("Created standard analytics plan with " . count($steps) . " step");
        }

        return $steps;
    }
    
    /**
     * Obtient les métadonnées du planificateur
     */
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