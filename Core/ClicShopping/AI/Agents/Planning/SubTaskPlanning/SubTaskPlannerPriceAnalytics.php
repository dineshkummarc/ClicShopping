<?php
/**
 * SubTaskPlannerPriceAnalytics
 * 
 * Planificateur spécialisé pour l'analyse de prix et tarification
 * Responsabilité : Créer des plans pour analyser évolutions, stratégies et tendances tarifaires
 */

namespace ClicShopping\AI\Agents\Planning\SubTaskPlanning;

use AllowDynamicProperties;
use ClicShopping\AI\Agents\Planning\TaskStep;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Domains\Semantic\Agent\SemanticAgent;

#[AllowDynamicProperties]
class SubTaskPlannerPriceAnalytics
{
    private bool $debug;
    private ?SecurityLogger $securityLogger;
    
    public function __construct(bool $debug = false, ?SecurityLogger $securityLogger = null)
    {
        $this->debug = $debug;
        $this->securityLogger = $securityLogger;
    }
    
    /**
     * Détecte si une requête concerne l'analyse de prix
     */
    public function canHandle(string $query): bool
    {
        // 🌍 Traduire en anglais pour analyse multilingue cohérente
        $translatedQuery = SemanticAgent::translateToEnglish($query, 80);
        
        if ($this->debug) {
            $this->logDebug("Price analytics detection - Original: " . substr($query, 0, 50) . 
                           " → Translated: " . substr($translatedQuery, 0, 50));
        }

      $patterns = [
        '/\b(price\s+.*\s+(analysis|analytics|trend))\b/i',
        '/\b(pricing\s+.*\s+(strategy|analysis|trends?))\b/i',
        '/\b(price\s+(evolution|development|changes?))\b/i',
        '/\b(cost\s+.*\s+(analysis|trends?))\b/i',
        '/\b(pricing\s+(analytics|intelligence))\b/i',
        '/\b(market\s+price\s+(trends?|analysis))\b/i',
        '/\b(price\s+(trends?|patterns?))\b/i',
        '/\b(tariff\s+(analysis|strategy))\b/i',
      ];

      // Utiliser la requête traduite en priorité
        $queryToAnalyze = !empty($translatedQuery) ? $translatedQuery : $query;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $queryToAnalyze)) {
                if ($this->debug) {
                    $this->logDebug("Price analytics detected with pattern: $pattern");
                }
                return true;
            }
        }

        return false;
    }
    
    /**
     * Crée le plan d'analyse de prix (3 étapes déterministes)
     */
    public function createPlan(array $intent, string $query): array
    {
        if ($this->debug) {
            $this->logDebug("Creating price analytics plan for query: " . substr($query, 0, 100));
        }
        
        $steps = [];

        // Étape 1: Collecte des données de prix
        $step1 = new TaskStep(
            'step_1',
            'price_data_collection',
            'Collect internal price data',
            [
                'intent' => $intent,
                'data_source' => 'internal_pricing',
                'tables' => ['products', 'specials', 'products_price_history', 'categories'],
                'price_types' => ['current_price', 'special_price', 'historical_price', 'cost_price'],
                'time_range' => 'last_12_months',
                'depends_on' => [],
                'can_run_parallel' => false,
                'is_final' => false,
            ]
        );
        $steps[] = $step1;

        // Étape 2: Analyse des tendances tarifaires
        $step2 = new TaskStep(
            'step_2',
            'price_analysis',
            'Analyze pricing patterns and trends',
            [
                'intent' => $intent,
                'analysis_type' => 'price_trends',
                'analysis_methods' => [
                    'trend_detection',
                    'seasonality_analysis', 
                    'price_elasticity',
                    'margin_analysis',
                    'competitive_positioning'
                ],
                'statistical_methods' => ['moving_average', 'regression_analysis', 'variance_analysis'],
                'depends_on' => ['step_1'],
                'can_run_parallel' => false,
                'is_final' => false,
            ]
        );
        $steps[] = $step2;

        // Étape 3: Synthèse et recommandations
        $step3 = new TaskStep(
            'step_3',
            'price_insights_synthesis',
            'Generate pricing insights and recommendations',
            [
                'intent' => $intent,
                'synthesis_type' => 'pricing_strategy',
                'output_components' => [
                    'price_trend_summary',
                    'optimization_opportunities',
                    'risk_assessment',
                    'strategic_recommendations'
                ],
                'recommendation_types' => ['price_adjustment', 'promotional_strategy', 'margin_optimization'],
                'depends_on' => ['step_1', 'step_2'],
                'can_run_parallel' => false,
                'is_final' => true,
            ]
        );
        $steps[] = $step3;

        if ($this->debug) {
            $this->logDebug("Created price analytics plan with " . count($steps) . " steps");
        }

        return $steps;
    }
    
    /**
     * Obtient les métadonnées du planificateur
     */
    public function getMetadata(): array
    {
        return [
            'name' => 'Price Analytics Planner',
            'description' => 'Specialized planner for pricing analysis, trends and strategy optimization',
            'steps_count' => 3,
            'step_types' => ['price_data_collection', 'price_analysis', 'price_insights_synthesis'],
            'data_sources' => ['internal_pricing'],
            'analysis_methods' => ['trend_detection', 'seasonality_analysis', 'price_elasticity', 'margin_analysis'],
            'supports_fallback' => false,
            'requires_external_data' => false
        ];
    }
    
    private function logDebug(string $message): void
    {
        if ($this->securityLogger) {
            $this->securityLogger->logSecurityEvent($message, 'info');
        }
        
        if ($this->debug) {
            error_log("[SubTaskPlannerPriceAnalytics] $message");
        }
    }
}
?>