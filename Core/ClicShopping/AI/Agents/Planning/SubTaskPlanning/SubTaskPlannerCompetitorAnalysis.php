<?php
/**
 * SubTaskPlannerCompetitorAnalysis
 * 
 * Planificateur spécialisé pour les analyses concurrentielles
 * Responsabilité : Créer des plans déterministes pour comparer nos produits avec les concurrents
 */

namespace ClicShopping\AI\Agents\Planning\SubTaskPlanning;

use ClicShopping\AI\Agents\Planning\TaskStep;
use ClicShopping\AI\Security\SecurityLogger;

class SubTaskPlannerCompetitorAnalysis
{
    private bool $debug;
    private ?SecurityLogger $securityLogger;

    public function __construct(bool $debug = false, ?SecurityLogger $securityLogger = null)
    {
        $this->debug = $debug;
        $this->securityLogger = $securityLogger;
    }

    /**
     * Détecte si la requête concerne une analyse concurrentielle
     *
     * NOTE: Pure LLM mode - competitor analysis is not currently supported
     * This feature requires external data sources and pattern-based detection
     * which have been removed in the pure LLM implementation.
     *
     * @param string $query Requête utilisateur
     * @return bool Always returns false (feature disabled)
     */
    public function canHandle(string $query): bool
    {
      if ($this->debug) {
        $this->logDebug("Competitor analysis detection SKIPPED - Feature not supported in Pure LLM mode");
      }
      
      return false; // Feature disabled in pure LLM mode
    }

  /**
     * Crée le plan d'analyse concurrentielle (3 étapes déterministes)
     * 
     * @param array $intent Intention analysée
     * @param string $query Requête originale
     * @return array Liste des TaskStep
     */
    public function createPlan(array $intent, string $query): array
    {
        if ($this->debug) {
            $this->logDebug("Creating competitor analysis plan for query: " . substr($query, 0, 100));
        }

        // Extract product name from query
        $productName = $this->extractProductName($query);

        if ($this->debug) {
            $this->logDebug("Extracted product name: '{$productName}' from query");
        }

        $steps = [];

        // Étape 1: Collecter NOS données produits (toujours interne)
        $step1 = new TaskStep(
            'step_1',
            'collect_our_product_data',
            'Collect our product data from internal database',
            [
                'intent' => $intent,
                'sub_query' => 'Load our products and pricing from internal database',
                'product_name' => $productName, // 🆕 Nom du produit extrait
                'expected_output' => 'Our product dataset (price, features, availability)',
                'data_source' => 'internal_database',
                'tables' => ['products', 'categories', 'prices'],
                'scope' => 'our_products_only',
                'depends_on' => [],
                'can_run_parallel' => false,
                'is_final' => false,
            ]
        );
        $steps[] = $step1;

        // Étape 2: Collecter données CONCURRENTS (externes + fallback)
        $step2 = new TaskStep(
            'step_2',
            'collect_competitor_market_data',
            'Collect competitor data from external sources and cache',
            [
                'intent' => $intent,
                'sub_query' => 'Load competitor pricing and product data',
                'expected_output' => 'Competitor market dataset (external sources)',
                'data_source' => 'external_cache_and_web',
                'sources' => ['rag_web_cache_embedding', 'serpapi_if_needed'],
                'scope' => 'competitor_products_only',
                'depends_on' => ['step_1'],
                'can_run_parallel' => false,
                'is_final' => false,
            ]
        );
        $steps[] = $step2;

        // Étape 3: Comparaison et analyse concurrentielle
        $step3 = new TaskStep(
            'step_3',
            'competitive_analysis_synthesis',
            'Compare our products vs competitors and generate insights',
            [
                'intent' => $intent,
                'sub_query' => 'Analyze competitive positioning and pricing',
                'expected_output' => 'Competitive analysis with recommendations',
                'analysis_type' => 'our_vs_competitors_comparison',
                'comparison_dimensions' => ['price', 'features', 'positioning', 'value_proposition'],
                'depends_on' => ['step_1', 'step_2'],
                'can_run_parallel' => false,
                'is_final' => true,
            ]
        );
        $steps[] = $step3;

        if ($this->debug) {
            $this->logDebug("Created competitor analysis plan with " . count($steps) . " steps");
        }

        return $steps;
    }

    /**
     * Obtient les métadonnées du planificateur
     */
    public function getMetadata(): array
    {
        return [
            'name' => 'Competitor Analysis Planner',
            'description' => 'Specialized planner for competitive analysis and pricing comparison',
            'steps_count' => 3,
            'step_types' => ['collect_our_product_data', 'collect_competitor_market_data', 'competitive_analysis_synthesis'],
            'data_sources' => ['internal_database', 'external_cache_and_web'],
            'analysis_dimensions' => ['price', 'features', 'positioning', 'value_proposition'],
            'supports_fallback' => true,
            'requires_external_data' => true
        ];
    }

    /**
     * Extrait le nom du produit de la requête
     * 
     * @param string $query Requête (déjà traduite en anglais)
     * @return string Nom du produit extrait
     */
    private function extractProductName(string $query): string
    {
        // Patterns pour extraire le nom du produit (en anglais)
        $patterns = [
            // "compare the price of X with competitors"
            '/compare\s+the\s+price\s+of\s+(.+?)\s+with\s+(the\s+)?competitors?/iu',
            // "compare X with competitors"
            '/compare\s+(.+?)\s+with\s+(the\s+)?competitors?/iu',
            // "price of X with competitors"
            '/price\s+of\s+(.+?)\s+with\s+(the\s+)?competitors?/iu',
            // "X vs competitors"
            '/(.+?)\s+vs\s+competitors?/iu',
            // "X versus competitors"
            '/(.+?)\s+versus\s+competitors?/iu',
            // "X against competitors"
            '/(.+?)\s+against\s+competitors?/iu',
            // "X competitors" (dernier recours)
            '/(.+?)\s+competitors?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                $productName = trim($matches[1]);

                // Nettoyer le nom extrait
                $productName = preg_replace('/^(the|a|an)\s+/i', '', $productName);
                $productName = rtrim($productName, '?!.,;:');
                $productName = preg_replace('/\s+/', ' ', $productName);

                if (!empty($productName)) {
                    return $productName;
                }
            }
        }

        // Si aucun pattern ne correspond, retourner la requête nettoyée
        $cleaned = preg_replace('/\b(compare|comparison|price|cost|with|against|versus|vs|competitor|competitors|competition|the|a|an)\b/i', '', $query);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        return trim($cleaned);
    }

    /**
     * Log de debug
     */
    private function logDebug(string $message): void
    {
        if ($this->securityLogger) {
            $this->securityLogger->logSecurityEvent($message, 'info');
        }

        if ($this->debug) {
            error_log("[SubTaskPlannerCompetitorAnalysis] $message");
        }
    }
}