<?php
/**
 * SubTaskPlannerPatternAnalysis
 * 
 * Planificateur spécialisé pour l'analyse de patterns et tendances
 * Responsabilité : Créer des plans pour identifier motifs dominants, tendances, styles
 */

namespace ClicShopping\AI\Agents\Planning\SubTaskPlanning;

use ClicShopping\AI\Agents\Planning\TaskStep;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Domain\Semantics\Semantics;
use ClicShopping\AI\Domain\Patterns\PatternAnalysisPattern;

class SubTaskPlannerPatternAnalysis
{
    private bool $debug;
    private ?SecurityLogger $securityLogger;
    
    public function __construct(bool $debug = false, ?SecurityLogger $securityLogger = null)
    {
        $this->debug = $debug;
        $this->securityLogger = $securityLogger;
    }
    
    /**
     * Détecte si une requête concerne l'analyse de patterns
     * 
     * NOTE: Pure LLM Mode - This method uses pattern matching for FUTURE USE.
     * Current implementation should delegate to LLM-based detection.
     */
    public function canHandle(string $query): bool
    {
        // 🌍 Traduire en anglais pour analyse multilingue cohérente
        $translatedQuery = Semantics::translateToEnglish($query, 80);
        
        if ($this->debug) {
            $this->logDebug("Pattern analysis detection - Original: " . substr($query, 0, 50) . 
                           " → Translated: " . substr($translatedQuery, 0, 50));
        }

        // Utiliser la requête traduite en priorité
        $queryToAnalyze = !empty($translatedQuery) ? $translatedQuery : $query;

        // Use PatternAnalysisPattern class for detection
        $matches = PatternAnalysisPattern::matches($queryToAnalyze);
        
        if ($matches && $this->debug) {
            $this->logDebug("Pattern analysis detected using PatternAnalysisPattern class");
        }

        return $matches;
    }
    
    /**
     * Crée le plan d'analyse de patterns (4 étapes déterministes)
     */
    public function createPlan(array $intent, string $query): array
    {
        if ($this->debug) {
            $this->logDebug("Creating pattern analysis plan for query: " . substr($query, 0, 100));
        }
        
        $steps = [];

        // Étape 1: Charger le catalogue produits
        $step1 = new TaskStep(
            'step_1',
            'load_product_catalog_data',
            'Load our product catalog for pattern analysis',
            [
                'intent' => $intent,
                'data_source' => 'internal_database',
                'scope' => 'our_product_catalog',
                'tables' => ['products', 'categories', 'product_description', 'specials'],
                'depends_on' => [],
                'can_run_parallel' => false,
                'is_final' => false,
            ]
        );
        $steps[] = $step1;

        // Étape 2: Extraction des patterns
        $step2 = new TaskStep(
            'step_2',
            'pattern_extraction',
            'Extract patterns from internal data',
            [
                'intent' => $intent,
                'analysis_type' => 'pattern_detection',
                'extraction_methods' => ['keyword_frequency', 'category_clustering', 'price_patterns', 'seasonal_trends'],
                'depends_on' => ['step_1'],
                'can_run_parallel' => false,
                'is_final' => false,
            ]
        );
        $steps[] = $step2;

        // Étape 3: Classement par fréquence et pertinence
        $step3 = new TaskStep(
            'step_3',
            'pattern_frequency_ranking',
            'Rank patterns by frequency and relevance',
            [
                'intent' => $intent,
                'ranking_criteria' => ['frequency', 'relevance', 'market_impact', 'revenue_correlation'],
                'scoring_method' => 'weighted_composite',
                'depends_on' => ['step_2'],
                'can_run_parallel' => false,
                'is_final' => false,
            ]
        );
        $steps[] = $step3;

        // Étape 4: Synthèse des résultats
        $step4 = new TaskStep(
            'step_4',
            'pattern_synthesis',
            'Synthesize pattern analysis results',
            [
                'intent' => $intent,
                'synthesis_type' => 'pattern_insights',
                'output_format' => ['top_patterns', 'trend_analysis', 'recommendations'],
                'depends_on' => ['step_1', 'step_2', 'step_3'],
                'can_run_parallel' => false,
                'is_final' => true,
            ]
        );
        $steps[] = $step4;

        if ($this->debug) {
            $this->logDebug("Created pattern analysis plan with " . count($steps) . " steps");
        }

        return $steps;
    }
    
    /**
     * Obtient les métadonnées du planificateur
     */
    public function getMetadata(): array
    {
        return [
            'name' => 'Pattern Analysis Planner',
            'description' => 'Specialized planner for identifying patterns, trends and dominant motifs',
            'steps_count' => 4,
            'step_types' => ['load_product_catalog_data', 'pattern_extraction', 'pattern_frequency_ranking', 'pattern_synthesis'],
            'data_sources' => ['internal_database'],
            'analysis_methods' => ['keyword_frequency', 'category_clustering', 'price_patterns', 'seasonal_trends'],
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
            error_log("[SubTaskPlannerPatternAnalysis] $message");
        }
    }
}