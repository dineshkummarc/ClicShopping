<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

/**
 * TaskPlanner Refactorisé
 * 
 * Orchestrateur principal qui délègue la planification aux SubTaskPlanners spécialisés
 * Responsabilité : Sélectionner le bon SubTaskPlanner et coordonner l'exécution
 */

namespace ClicShopping\AI\Agents\Planning;

use AllowDynamicProperties;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Infrastructure\Monitoring\MetricsCollector;
use ClicShopping\AI\Agents\Planning\SubTaskPlanning\SubTaskPlannerCompetitorAnalysis;
use ClicShopping\AI\Agents\Planning\SubTaskPlanning\SubTaskPlannerPatternAnalysis;
use ClicShopping\AI\Agents\Planning\SubTaskPlanning\SubTaskPlannerPriceAnalytics;
use ClicShopping\AI\Agents\Planning\SubTaskPlanning\SubTaskPlannerSemanticSearch;
use ClicShopping\AI\Agents\Planning\SubTaskPlanning\SubTaskPlannerWebSearch;
use ClicShopping\AI\Agents\Planning\SubTaskPlanning\SubTaskPlannerStandard;
use ClicShopping\AI\Agents\PromptSystem\PromptSystem;
use ClicShopping\AI\Domain\Semantics\Semantics;
use ClicShopping\OM\Registry;

// Import des SubTaskPlanners

/**
 * TaskPlanner Refactorisé - Architecture modulaire avec SubTaskPlanners
 */
#[AllowDynamicProperties]
class TaskPlanner
{
    private SecurityLogger $securityLogger;
    private mixed $chat;
    private bool $debug;
    private int $languageId;
    private MetricsCollector $collector;

    // SubTaskPlanners spécialisés
    private array $subTaskPlanners = [];

    // Statistiques de planification
    private array $planningStats = [
        'total_plans_created' => 0,
        'total_steps_planned' => 0,
        'replans_triggered' => 0,
        'successful_executions' => 0,
        'failed_executions' => 0,
        'planner_usage' => [],
    ];

    /**
     * Constructor - Initialise les SubTaskPlanners
     */
    public function __construct(?int $languageId = null)
    {
        $this->securityLogger = new SecurityLogger();
        $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';
        $this->collector = new MetricsCollector();

        if (is_null($languageId)) {
            $this->languageId = Registry::get('Language')->getId();
        } else {
            $this->languageId = $languageId;
        }

        // Initialiser le chat pour la planification
        $this->initializeChat();

        // Initialiser les SubTaskPlanners spécialisés
        $this->initializeSubTaskPlanners();

        if ($this->debug) {
            $this->securityLogger->logSecurityEvent(
                "TaskPlannerRefactored initialized with " . count($this->subTaskPlanners) . " SubTaskPlanners",
                'info'
            );
        }
    }

    /**
     * Initialise tous les SubTaskPlanners spécialisés
     */
    private function initializeSubTaskPlanners(): void
    {
        // Ordre d'évaluation : du plus spécifique au plus général
        $this->subTaskPlanners = [
            'competitor_analysis' => new SubTaskPlannerCompetitorAnalysis($this->debug, $this->securityLogger),
            'pattern_analysis' => new SubTaskPlannerPatternAnalysis($this->debug, $this->securityLogger),
            'price_analytics' => new SubTaskPlannerPriceAnalytics($this->debug, $this->securityLogger),
            'semantic_search' => new SubTaskPlannerSemanticSearch($this->debug, $this->securityLogger),
            'web_search' => new SubTaskPlannerWebSearch($this->debug, $this->securityLogger),
            'standard' => new SubTaskPlannerStandard($this->debug, $this->securityLogger), // Fallback
        ];

        if ($this->debug) {
            $plannerNames = array_keys($this->subTaskPlanners);
            $this->securityLogger->logSecurityEvent(
                "SubTaskPlanners initialized: " . implode(', ', $plannerNames),
                'info'
            );
        }
    }

    /**
     * Initialise le chat pour le raisonnement de planification
     */
    private function initializeChat(): void
    {
        $model = defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL') ? CLICSHOPPING_APP_CHATGPT_CH_MODEL : 'gpt-4';

        // Utiliser getChat qui gère automatiquement tous les types de modèles
        $this->chat = Gpt::getChat('', null, null, $model);

        // Vérifier que le chat a été initialisé correctement
        if ($this->chat === false || $this->chat === null) {
            // Mode dégradé : utiliser des plans prédéfinis au lieu de l'IA
            $this->chat = null;
            error_log('TaskPlannerRefactored: Using fallback mode due to missing API key');
            return;
        }

        $systemPrompt = PromptSystem::getTaskPlannerSystemPrompt();
        $this->chat->setSystemMessage($systemPrompt);
    }

    /**
     * 🎯 MÉTHODE PRINCIPALE : Crée un plan d'exécution pour une requête
     * 
     * Architecture refactorisée :
     * 1. Sélectionne le SubTaskPlanner approprié
     * 2. Délègue la création du plan
     * 3. Analyse les dépendances et optimise
     */
    public function createPlan(array $intent, string $query, array $context = []): ExecutionPlan
    {
        $startTime = microtime(true);
        $this->collector->startTimer('plan_creation');

        // 🔧 TASK 4.3.4.3: Add logging to trace query through planning
        error_log("\n" . str_repeat("+", 100));
        error_log("TASK 4.3.4.3: TaskPlanner.createPlan() CALLED");
        error_log("+" . str_repeat("+", 99));
        error_log("Query received: '{$query}'");
        error_log("Query length: " . strlen($query));
        error_log("Intent type: " . ($intent['type'] ?? 'unknown'));
        error_log("Intent has translated_query: " . (isset($intent['translated_query']) ? 'YES' : 'NO'));
        if (isset($intent['translated_query'])) {
            error_log("Translated query: " . $intent['translated_query']);
        }

        try {
            if ($this->debug) {
                $this->securityLogger->logSecurityEvent(
                    "Creating plan for intent type: " . ($intent['type'] ?? 'unknown') .
                    ", query: " . substr($query, 0, 100),
                    'info'
                );
            }

            // 1. Sélectionner le SubTaskPlanner approprié
            $selectedPlanner = $this->selectSubTaskPlanner($intent, $query);

            if ($this->debug) {
                $plannerName = $this->getSubTaskPlannerName($selectedPlanner);
                $this->securityLogger->logSecurityEvent(
                    "Selected SubTaskPlanner: $plannerName",
                    'info'
                );
            }

            // 2. Déléguer la création du plan au SubTaskPlanner
            $steps = $selectedPlanner->createPlan($intent, $query);

            // 🔧 TASK 4.3.4.3: Log the steps created
            error_log("\nSteps created by SubTaskPlanner:");
            foreach ($steps as $step) {
                error_log("  Step: " . $step->getId());
                error_log("    Type: " . $step->getType());
                error_log("    Description: " . $step->getDescription());
                $subQuery = $step->getMeta('sub_query', null);
                error_log("    sub_query metadata: " . ($subQuery ?? 'NULL'));
            }

            // 3. Analyser les dépendances
            $dependencies = $this->analyzeDependencies($steps);

            // 4. Optimiser l'ordre d'exécution
            $optimizedSteps = $this->optimizeExecutionOrder($steps, $dependencies);

            // 5. Analyser la complexité (pour compatibilité)
            $complexity = $this->analyzeComplexity($intent, $query);

            // 6. Créer le plan d'exécution
            $plan = new ExecutionPlan($query, $intent, $optimizedSteps, $dependencies, $complexity);

            // Statistiques
            $this->updatePlanningStats($selectedPlanner, count($optimizedSteps));

            $this->collector->stopTimer('plan_creation');
            $plan->setPlanningTime(microtime(true) - $startTime);

            if ($this->debug) {
                $this->securityLogger->logSecurityEvent(
                    "Plan created with " . count($optimizedSteps) . " steps in " .
                    round($plan->getPlanningTime(), 3) . "s",
                    'info'
                );
            }

            return $plan;

        } catch (\Exception $e) {
            $this->securityLogger->logSecurityEvent(
                "Error creating plan: " . $e->getMessage(),
                'error'
            );

            // Fallback : plan simple avec le planificateur standard
            return $this->createFallbackPlan($intent, $query);
        }
    }

    /**
     * 🎯 SÉLECTEUR DE SUBTASKPLANNER : Choisit le planificateur approprié
     */
    private function selectSubTaskPlanner(array $intent, string $query): object
    {
        $intentType = $intent['type'] ?? 'analytics';
        $confidence = $intent['confidence'] ?? 0.5;

        // TASK 5.2.1.1: Check for hybrid queries FIRST (highest priority)
        // Hybrid queries need special handling via HybridQueryProcessor
        if ($intentType === 'hybrid' || ($intent['is_hybrid'] ?? false)) {
            if ($this->debug) {
                $this->securityLogger->logSecurityEvent(
                    "HYBRID QUERY DETECTED - Routing to OrchestratorAgent.hybridQueryProcessor (NOT TaskPlanner)",
                    'warning'
                );
                $this->securityLogger->logSecurityEvent(
                    "BUG: TaskPlanner should NOT handle hybrid queries - they should be routed in OrchestratorAgent.handleFullOrchestration()",
                    'error'
                );
            }
            // Fallback to standard for now (this should never be reached after OrchestratorAgent fix)
            return $this->subTaskPlanners['standard'];
        }

        // Pour les requêtes sémantiques, utiliser directement le planificateur sémantique
        // Note: L'intent type peut être 'semantic' ou 'semantic_search'
        if ($intentType === 'semantic' || $intentType === 'semantic_search') {
            if ($this->debug) {
                $this->securityLogger->logSecurityEvent(
                    "Routing to semantic_search planner for intent type: {$intentType}",
                    'info'
                );
            }
            return $this->subTaskPlanners['semantic_search'];
        }

        // Pour les requêtes web_search, utiliser le planificateur web search
        // ✅ FIX (2025-01-02): Handle both 'web_search' and 'web' (QueryClassifier normalizes web_search → web)
        if ($intentType === 'web_search' || $intentType === 'web') {
            if ($this->debug) {
                $this->securityLogger->logSecurityEvent(
                    "Routing to web_search planner for intent type: {$intentType}",
                    'info'
                );
            }
            return $this->subTaskPlanners['web_search'];
        }

        // Pour les requêtes analytics, tester les planificateurs spécialisés
        if ($intentType === 'analytics') {
            // Tester dans l'ordre de spécificité
            $plannersToTest = [
                'competitor_analysis',
                'pattern_analysis',
                'price_analytics'
            ];

            foreach ($plannersToTest as $plannerKey) {
                $planner = $this->subTaskPlanners[$plannerKey];
                if ($planner->canHandle($query)) {
                    return $planner;
                }
            }
        }

        // Fallback : planificateur standard
        return $this->subTaskPlanners['standard'];
    }

    /**
     * Obtient le nom d'un SubTaskPlanner pour les logs
     */
    private function getSubTaskPlannerName(object $planner): string
    {
        foreach ($this->subTaskPlanners as $name => $instance) {
            if ($instance === $planner) {
                return $name;
            }
        }
        return 'unknown';
    }

    /**
     * Met à jour les statistiques de planification
     */
    private function updatePlanningStats(object $planner, int $stepsCount): void
    {
        $plannerName = $this->getSubTaskPlannerName($planner);

        $this->planningStats['total_plans_created']++;
        $this->planningStats['total_steps_planned'] += $stepsCount;

        if (!isset($this->planningStats['planner_usage'][$plannerName])) {
            $this->planningStats['planner_usage'][$plannerName] = 0;
        }
        $this->planningStats['planner_usage'][$plannerName]++;

        // Métriques pour monitoring
        $avgSteps = $this->planningStats['total_plans_created'] > 0 ?
            round($this->planningStats['total_steps_planned'] / $this->planningStats['total_plans_created'], 2) : 0;

        $this->collector->gauge('plans_created', $this->planningStats['total_plans_created']);
        $this->collector->gauge('avg_steps', $avgSteps);
    }

    /**
     * Analyse la complexité d'une requête (méthode conservée pour compatibilité)
     */
    private function analyzeComplexity(array $intent, string $query): array
    {
        $score = 0;
        $factors = [];

        // Traduire la requête en anglais pour analyse multilingue
        // not used : Delete ?
        $translatedQuery = Semantics::translateToEnglish($query, 80);

        // Facteur 1 : Requête hybride
        if ($intent['is_hybrid'] ?? false) {
            $score += 3;
            $factors[] = 'hybrid_query';
        }

        // Facteur 2 : Contexte conversationnel requis
        if ($intent['requires_context'] ?? false) {
            $score += 2;
            $factors[] = 'requires_context';
        }

        // Facteur 3 : Multiples entités
        $entityCount = count($intent['metadata']['entities'] ?? []);
        if ($entityCount > 1) {
            $score += $entityCount;
            $factors[] = "multiple_entities:{$entityCount}";
        }

        // Déterminer le niveau
        $level = 'simple';
        if ($score >= 8) {
            $level = 'very_complex';
        } elseif ($score >= 5) {
            $level = 'complex';
        } elseif ($score >= 3) {
            $level = 'medium';
        }

        return [
            'score' => $score,
            'level' => $level,
            'factors' => $factors,
        ];
    }

    /**
     * Analyse les dépendances entre étapes (conservé de l'original)
     */
    private function analyzeDependencies(array $steps): array
    {
        $dependencies = [];

        foreach ($steps as $step) {
            $stepId = $step->getId();
            $dependsOn = $step->getMetadata()['depends_on'] ?? [];

            $dependencies[$stepId] = [
                'depends_on' => $dependsOn,
                'required_by' => [],
            ];
        }

        // Construire le graphe inverse (required_by)
        foreach ($dependencies as $stepId => $data) {
            foreach ($data['depends_on'] as $dependencyId) {
                if (isset($dependencies[$dependencyId])) {
                    $dependencies[$dependencyId]['required_by'][] = $stepId;
                }
            }
        }

        return $dependencies;
    }

    /**
     * Optimise l'ordre d'exécution des étapes (conservé de l'original)
     */
    private function optimizeExecutionOrder(array $steps, array $dependencies): array
    {
        // Tri topologique pour respecter les dépendances
        $sorted = [];
        $visited = [];
        $temp = [];

        foreach ($steps as $step) {
            if (!isset($visited[$step->getId()])) {
                $this->topologicalSort($step, $steps, $dependencies, $visited, $temp, $sorted);
            }
        }

        return array_reverse($sorted);
    }

    /**
     * Tri topologique récursif (conservé de l'original)
     */
    private function topologicalSort(
        TaskStep $step,
        array $allSteps,
        array $dependencies,
        array &$visited,
        array &$temp,
        array &$sorted
    ): void {
        $stepId = $step->getId();

        if (isset($temp[$stepId])) {
            // Cycle détecté - ignorer
            return;
        }

        if (isset($visited[$stepId])) {
            return;
        }

        $temp[$stepId] = true;

        // Visiter les dépendances d'abord
        $dependsOn = $dependencies[$stepId]['depends_on'] ?? [];
        foreach ($dependsOn as $depId) {
            $depStep = $this->findStepById($allSteps, $depId);
            if ($depStep) {
                $this->topologicalSort($depStep, $allSteps, $dependencies, $visited, $temp, $sorted);
            }
        }

        unset($temp[$stepId]);
        $visited[$stepId] = true;
        $sorted[] = $step;
    }

    /**
     * Trouve une étape par son ID (conservé de l'original)
     */
    private function findStepById(array $steps, string $id): ?TaskStep
    {
        foreach ($steps as $step) {
            if ($step->getId() === $id) {
                return $step;
            }
        }
        return null;
    }

    /**
     * Replanifie une exécution en cas d'échec
     * 
     * @param ExecutionPlan $failedPlan Plan qui a échoué
     * @param array $context Contexte de l'échec
     * @return ExecutionPlan Nouveau plan
     */
    public function replan(ExecutionPlan $failedPlan, array $context): ExecutionPlan
    {
        $this->planningStats['replans_triggered']++;
        
        if ($this->debug) {
            $this->securityLogger->logSecurityEvent(
                "Replanification déclenchée pour: " . $failedPlan->getQuery(),
                'info'
            );
        }
        
        // Créer un nouveau plan basé sur le contexte d'échec
        $query = $failedPlan->getQuery();
        $intent = $failedPlan->getIntent();
        
        // Créer un plan simplifié en cas d'échec
        // Signature: createPlan(array $intent, string $query, array $context = [])
        $newPlan = $this->createPlan($intent, $query, $context);
        $newPlan->markAsReplan($failedPlan, $context);
        
        return $newPlan;
    }

    /**
     * Crée un plan de fallback en cas d'erreur
     */
    private function createFallbackPlan(array $intent, string $query): ExecutionPlan
    {
        $step = new TaskStep(
            'fallback_step',
            'fallback',
            $query,
            [
                'intent' => $intent,
                'is_fallback' => true,
                'is_final' => true,
            ]
        );

        $plan = new ExecutionPlan(
            $query,
            $intent,
            [$step],
            [],
            ['level' => 'simple', 'score' => 0, 'factors' => ['fallback']]
        );

        return $plan;
    }

    /**
     * 📊 Obtient les statistiques de planification avec détail des SubTaskPlanners
     */
    public function getStats(): array
    {
        $totalExecutions = $this->planningStats['successful_executions'] +
            $this->planningStats['failed_executions'];

        $successRate = $totalExecutions > 0
            ? ($this->planningStats['successful_executions'] / $totalExecutions) * 100
            : 0;

        return array_merge($this->planningStats, [
            'success_rate' => round($successRate, 2) . '%',
            'avg_steps_per_plan' => $this->planningStats['total_plans_created'] > 0 ?
                round($this->planningStats['total_steps_planned'] / $this->planningStats['total_plans_created'], 2) : 0,
            'subtask_planners' => array_map(function ($planner) {
                return $planner->getMetadata();
            }, $this->subTaskPlanners)
        ]);
    }

    /**
     * Marque un plan comme réussi
     */
    public function markPlanSuccess(): void
    {
        $this->planningStats['successful_executions']++;
    }

    /**
     * Marque un plan comme échoué
     */
    public function markPlanFailure(): void
    {
        $this->planningStats['failed_executions']++;
    }
}