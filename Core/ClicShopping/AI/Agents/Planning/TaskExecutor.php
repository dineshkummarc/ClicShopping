<?php
/**
 * TaskExecutor - Exécuteur avec Rollback Intelligent
 * 
 * Fonctionnalités avancées :
 * - Rollback automatique en cas d'échec critique
 * - Skip intelligent des étapes avec données manquantes
 * - Retry avec stratégies adaptatives
 * - Recovery gracieux avec fallback
 * - Gestion des transactions d'étapes
 */

namespace ClicShopping\AI\Agents\Planning;

use ClicShopping\AI\Security\SecurityLogger;

class TaskExecutor
{
    private bool $debug;
    private ?SecurityLogger $securityLogger;
    
    // Configuration du rollback
    private array $rollbackConfig = [
        'enable_rollback' => true,
        'enable_skip' => true,
        'enable_retry' => true,
        'max_retries' => 2,
        'rollback_on_critical_failure' => true,
        'skip_on_missing_data' => true,
        'recovery_strategies' => ['fallback', 'skip', 'retry']
    ];
    
    // État d'exécution pour rollback
    private array $executionState = [
        'completed_steps' => [],
        'rollback_points' => [],
        'failed_steps' => [],
        'skipped_steps' => []
    ];
    
    public function __construct(bool $debug = false, ?SecurityLogger $securityLogger = null)
    {
        $this->debug = $debug;
        $this->securityLogger = $securityLogger;
    }
    
    /**
     * 🎯 EXÉCUTION AVEC ROLLBACK INTELLIGENT
     * 
     * @param array $steps Liste des TaskStep
     * @param array $context Contexte d'exécution
     * @return array Outputs avec gestion rollback
     */
    public function executeStepsWithRollback(array $steps, array $context = []): array
    {
        $outputs = [];
        $this->resetExecutionState();
        
        if ($this->debug) {
            $this->logDebug("Starting enhanced execution of " . count($steps) . " steps with rollback support");
        }
        
        try {
            // Exécution séquentielle avec points de rollback
            foreach ($steps as $stepIndex => $step) {
                $stepId = $step->getId();
                $stepType = $step->getType();
                
                // Créer un point de rollback avant chaque étape critique
                if ($this->isCriticalStep($step)) {
                    $this->createRollbackPoint($stepId, $outputs);
                }
                
                if ($this->debug) {
                    $this->logDebug("Executing step {$stepId} ({$stepType}) - Index: $stepIndex");
                }
                
                // Vérifier les dépendances avec gestion intelligente
                $dependencyCheck = $this->checkDependenciesEnhanced($step, $outputs);
                if (!$dependencyCheck['satisfied']) {
                    $result = $this->handleDependencyFailure($step, $dependencyCheck, $outputs, $context);
                    $outputs[$stepId] = $result;
                    
                    if ($result['status'] === 'critical_failure') {
                        return $this->handleCriticalFailure($stepId, $outputs, $steps, $stepIndex);
                    }
                    continue;
                }
                
                // Exécuter l'étape avec retry et recovery
                $result = $this->executeStepWithRecovery($step, $context, $outputs);
                $outputs[$stepId] = $result;
                
                // Gérer le résultat
                if ($result['status'] === 'success' || $result['status'] === 'ok') {
                    $this->executionState['completed_steps'][] = $stepId;
                } elseif ($result['status'] === 'failed') {
                    $this->executionState['failed_steps'][] = $stepId;
                    
                    // Décider de la stratégie de recovery
                    $recoveryResult = $this->applyRecoveryStrategy($step, $result, $outputs, $context, $steps, $stepIndex);
                    
                    if ($recoveryResult['action'] === 'rollback') {
                        return $this->performRollback($recoveryResult['rollback_to'], $outputs, $steps);
                    } elseif ($recoveryResult['action'] === 'abort') {
                        return $this->abortExecution($stepId, $outputs, $recoveryResult['reason']);
                    }
                    // Si 'continue' ou 'skip', on continue l'exécution
                }
            }
            
            // Exécution terminée avec succès
            $this->logDebug("Enhanced execution completed successfully");
            return $this->finalizeExecution($outputs);
            
        } catch (\Exception $e) {
            $this->logDebug("Exception during execution: " . $e->getMessage());
            return $this->handleExecutionException($e, $outputs, $steps);
        }
    }
    
    /**
     * 🔍 Vérification des dépendances améliorée
     */
    private function checkDependenciesEnhanced($step, array $outputs): array
    {
        $stepId = $step->getId();
        $deps = $step->getMetadata()['depends_on'] ?? [];
        
        $result = [
            'satisfied' => true,
            'missing_deps' => [],
            'failed_deps' => [],
            'recoverable' => true
        ];
        
        foreach ($deps as $depId) {
            if (!array_key_exists($depId, $outputs)) {
                $result['satisfied'] = false;
                $result['missing_deps'][] = $depId;
            } elseif (isset($outputs[$depId]['status']) && 
                     in_array($outputs[$depId]['status'], ['failed', 'critical_failure'])) {
                $result['satisfied'] = false;
                $result['failed_deps'][] = $depId;
                
                // Si une dépendance critique a échoué, pas récupérable
                if ($outputs[$depId]['status'] === 'critical_failure') {
                    $result['recoverable'] = false;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 🚨 Gestion des échecs de dépendances
     */
    private function handleDependencyFailure($step, array $dependencyCheck, array $outputs, array $context): array
    {
        $stepId = $step->getId();
        
        if (!$dependencyCheck['recoverable']) {
            return [
                'status' => 'critical_failure',
                'reason' => 'critical_dependency_failed',
                'failed_dependencies' => $dependencyCheck['failed_deps'],
                'recovery_possible' => false
            ];
        }
        
        // Tentative de skip si configuré
        if ($this->rollbackConfig['skip_on_missing_data'] && !empty($dependencyCheck['missing_deps'])) {
            $this->executionState['skipped_steps'][] = $stepId;
            
            return [
                'status' => 'skipped',
                'reason' => 'missing_dependencies',
                'missing_dependencies' => $dependencyCheck['missing_deps'],
                'recovery_action' => 'skip_graceful'
            ];
        }
        
        return [
            'status' => 'failed',
            'reason' => 'dependencies_not_satisfied',
            'missing_dependencies' => $dependencyCheck['missing_deps'],
            'failed_dependencies' => $dependencyCheck['failed_deps']
        ];
    }
    
    /**
     * 🔄 Exécution d'étape avec recovery
     */
    private function executeStepWithRecovery($step, array $context, array $outputs): array
    {
        $stepId = $step->getId();
        $maxRetries = $this->rollbackConfig['max_retries'];
        $attempt = 0;
        
        while ($attempt <= $maxRetries) {
            try {
                if ($attempt > 0) {
                    $this->logDebug("Retry attempt $attempt for step $stepId");
                }
                
                // Exécuter l'étape (utilise la logique du TaskExecutor original)
                $result = $this->executeStepCore($step, $context, $outputs);
                
                // Si succès, retourner immédiatement
                if (in_array($result['status'] ?? 'unknown', ['success', 'ok', 'completed'])) {
                    if ($attempt > 0) {
                        $result['retry_successful'] = true;
                        $result['attempts'] = $attempt + 1;
                    }
                    return $result;
                }
                
                // Si échec mais récupérable, tenter retry
                if ($this->isRecoverableFailure($result) && $attempt < $maxRetries) {
                    $attempt++;
                    $this->logDebug("Step $stepId failed but recoverable, retrying...");
                    
                    // Attendre un peu avant retry (backoff)
                    if ($attempt > 1) {
                        usleep(100000 * $attempt); // 100ms, 200ms, etc.
                    }
                    continue;
                }
                
                // Échec définitif
                $result['retry_attempts'] = $attempt + 1;
                $result['max_retries_reached'] = ($attempt >= $maxRetries);
                return $result;
                
            } catch (\Exception $e) {
                $this->logDebug("Exception in step $stepId attempt $attempt: " . $e->getMessage());
                
                if ($attempt >= $maxRetries) {
                    return [
                        'status' => 'failed',
                        'reason' => 'exception_after_retries',
                        'exception' => $e->getMessage(),
                        'retry_attempts' => $attempt + 1
                    ];
                }
                
                $attempt++;
            }
        }
        
        return [
            'status' => 'failed',
            'reason' => 'max_retries_exceeded',
            'retry_attempts' => $attempt
        ];
    }
    
    /**
     * 🎯 Exécution core d'une étape (logique du TaskExecutor original)
     */
    private function executeStepCore($step, array $context, array $outputs): array
    {
        $stepType = $step->getType();
        
        switch ($stepType) {
            case 'collect_our_product_data':
                return $this->executeCollectOurProductData($step, $context);
                
            case 'collect_competitor_market_data':
                return $this->executeCollectCompetitorData($step, $context);
                
            case 'competitive_analysis_synthesis':
                return $this->executeCompetitiveAnalysis($step, $context, $outputs);
                
            case 'analytics_query':
                return $this->executeAnalyticsQuery($step, $context);
                
            case 'semantic_search':
                return $this->executeSemanticSearch($step, $context);
                
            default:
                return [
                    'status' => 'executed',
                    'type' => $stepType,
                    'meta' => $step->getMetadata()
                ];
        }
    }
    
    /**
     * 🔄 Stratégies de recovery
     */
    private function applyRecoveryStrategy($step, array $result, array $outputs, array $context, array $allSteps, int $stepIndex): array
    {
        $stepId = $step->getId();
        $stepType = $step->getType();
        
        // Analyser la gravité de l'échec
        $failureSeverity = $this->assessFailureSeverity($step, $result);
        
        if ($failureSeverity === 'critical') {
            // Échec critique → Rollback
            $rollbackPoint = $this->findBestRollbackPoint($stepId);
            return [
                'action' => 'rollback',
                'rollback_to' => $rollbackPoint,
                'reason' => 'critical_failure_detected'
            ];
        }
        
        if ($failureSeverity === 'recoverable') {
            // Échec récupérable → Skip ou Continue
            if ($this->canSkipStep($step, $allSteps, $stepIndex)) {
                $this->executionState['skipped_steps'][] = $stepId;
                return [
                    'action' => 'skip',
                    'reason' => 'recoverable_failure_skipped'
                ];
            } else {
                return [
                    'action' => 'continue',
                    'reason' => 'non_critical_failure_continue'
                ];
            }
        }
        
        // Échec modéré → Continue avec warning
        return [
            'action' => 'continue',
            'reason' => 'moderate_failure_continue_with_warning'
        ];
    }
    
    /**
     * 📊 Évaluation de la gravité d'un échec
     */
    private function assessFailureSeverity($step, array $result): string
    {
        $stepType = $step->getType();
        $status = $result['status'] ?? 'unknown';
        
        // Échecs critiques
        if ($status === 'critical_failure') {
            return 'critical';
        }
        
        // Étapes critiques qui ne peuvent pas échouer
        $criticalStepTypes = ['collect_our_product_data'];
        if (in_array($stepType, $criticalStepTypes) && $status === 'failed') {
            return 'critical';
        }
        
        // Échecs récupérables
        $recoverableStepTypes = ['collect_competitor_market_data'];
        if (in_array($stepType, $recoverableStepTypes)) {
            return 'recoverable';
        }
        
        return 'moderate';
    }
    
    /**
     * 🔙 Rollback vers un point de sauvegarde
     */
    private function performRollback(string $rollbackPoint, array $outputs, array $steps): array
    {
        $this->logDebug("Performing rollback to point: $rollbackPoint");
        
        // Nettoyer les outputs après le point de rollback
        $cleanedOutputs = [];
        $rollbackPointFound = false;
        
        foreach ($outputs as $stepId => $output) {
            if ($stepId === $rollbackPoint) {
                $rollbackPointFound = true;
            }
            
            if (!$rollbackPointFound) {
                $cleanedOutputs[$stepId] = $output;
            } else {
                // Marquer comme rollback
                $cleanedOutputs[$stepId] = [
                    'status' => 'rolled_back',
                    'original_result' => $output,
                    'rollback_point' => $rollbackPoint
                ];
            }
        }
        
        // Ajouter métadonnées de rollback
        $cleanedOutputs['_rollback_info'] = [
            'rollback_performed' => true,
            'rollback_point' => $rollbackPoint,
            'rollback_timestamp' => date('Y-m-d H:i:s'),
            'steps_rolled_back' => array_keys(array_diff_key($outputs, $cleanedOutputs))
        ];
        
        return $cleanedOutputs;
    }
    
    /**
     * 🛑 Gestion des échecs critiques
     */
    private function handleCriticalFailure(string $failedStepId, array $outputs, array $steps, int $stepIndex): array
    {
        $this->logDebug("Critical failure detected at step: $failedStepId");
        
        if ($this->rollbackConfig['rollback_on_critical_failure']) {
            $rollbackPoint = $this->findBestRollbackPoint($failedStepId);
            return $this->performRollback($rollbackPoint, $outputs, $steps);
        }
        
        return $this->abortExecution($failedStepId, $outputs, 'critical_failure_no_rollback');
    }
    
    /**
     * ⛔ Abandon d'exécution
     */
    private function abortExecution(string $failedStepId, array $outputs, string $reason): array
    {
        $this->logDebug("Aborting execution due to: $reason at step: $failedStepId");
        
        $outputs['_execution_aborted'] = [
            'aborted' => true,
            'failed_step' => $failedStepId,
            'reason' => $reason,
            'abort_timestamp' => date('Y-m-d H:i:s'),
            'completed_steps' => $this->executionState['completed_steps'],
            'failed_steps' => $this->executionState['failed_steps'],
            'skipped_steps' => $this->executionState['skipped_steps']
        ];
        
        return $outputs;
    }
    
    /**
     * 🎯 Méthodes utilitaires
     */
    private function isCriticalStep($step): bool
    {
        $criticalTypes = ['collect_our_product_data', 'competitive_analysis_synthesis'];
        return in_array($step->getType(), $criticalTypes);
    }
    
    private function isRecoverableFailure(array $result): bool
    {
        $recoverableStatuses = ['timeout', 'network_error', 'temporary_failure'];
        return in_array($result['reason'] ?? '', $recoverableStatuses);
    }
    
    private function canSkipStep($step, array $allSteps, int $stepIndex): bool
    {
        // Ne peut pas skip le dernier step
        if ($stepIndex >= count($allSteps) - 1) {
            return false;
        }
        
        // Ne peut pas skip les steps critiques
        if ($this->isCriticalStep($step)) {
            return false;
        }
        
        return true;
    }
    
    private function createRollbackPoint(string $stepId, array $outputs): void
    {
        $this->executionState['rollback_points'][] = [
            'step_id' => $stepId,
            'timestamp' => microtime(true),
            'outputs_snapshot' => $outputs
        ];
    }
    
    private function findBestRollbackPoint(string $failedStepId): string
    {
        // Trouver le dernier point de rollback avant l'échec
        $rollbackPoints = array_reverse($this->executionState['rollback_points']);
        
        foreach ($rollbackPoints as $point) {
            if ($point['step_id'] !== $failedStepId) {
                return $point['step_id'];
            }
        }
        
        // Si pas de point de rollback, retourner au début
        return 'start';
    }
    
    private function resetExecutionState(): void
    {
        $this->executionState = [
            'completed_steps' => [],
            'rollback_points' => [],
            'failed_steps' => [],
            'skipped_steps' => []
        ];
    }
    
    private function finalizeExecution(array $outputs): array
    {
        $outputs['_execution_summary'] = [
            'status' => 'completed',
            'completed_steps' => $this->executionState['completed_steps'],
            'failed_steps' => $this->executionState['failed_steps'],
            'skipped_steps' => $this->executionState['skipped_steps'],
            'rollback_points_created' => count($this->executionState['rollback_points'])
        ];
        
        return $outputs;
    }
    
    private function handleExecutionException(\Exception $e, array $outputs, array $steps): array
    {
        return [
            '_execution_exception' => [
                'exception' => true,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'outputs_before_exception' => $outputs
            ]
        ];
    }
    
    /**
     * Configuration du rollback
     */
    public function configureRollback(array $config): void
    {
        $this->rollbackConfig = array_merge($this->rollbackConfig, $config);
    }
    
    public function getRollbackConfig(): array
    {
        return $this->rollbackConfig;
    }
    
    // Méthodes d'exécution spécialisées (reprises du TaskExecutor original)
    private function executeCollectOurProductData($step, array $context): array
    {
        $ourProducts = $context['our_products'] ?? [];
        
        if (empty($ourProducts)) {
            return [
                'status' => 'failed',
                'reason' => 'no_internal_products',
                'recovery_possible' => false
            ];
        }
        
        return [
            'status' => 'success',
            'data' => $ourProducts,
            'source' => 'internal_database',
            'count' => count($ourProducts)
        ];
    }
    
    private function executeCollectCompetitorData($step, array $context): array
    {
        // Utilise TaskValidator pour déterminer la stratégie
        [$strategy, $data] = TaskValidator::determineFallbackStrategy($context);
        
        return [
            'status' => empty($data) ? 'failed' : 'success',
            'data' => $data,
            'source' => $strategy === 'external_valid' ? 'external_sources' : 'internal_cache',
            'strategy' => $strategy,
            'recovery_possible' => true
        ];
    }
    
    private function executeCompetitiveAnalysis($step, array $context, array $outputs): array
    {
        $ourData = $outputs['step_1']['data'] ?? [];
        $competitorData = $outputs['step_2']['data'] ?? [];
        
        if (empty($ourData)) {
            return [
                'status' => 'failed',
                'reason' => 'missing_our_data',
                'recovery_possible' => false
            ];
        }
        
        // Peut continuer même sans données concurrents (analyse limitée)
        return [
            'status' => 'success',
            'analysis_type' => empty($competitorData) ? 'internal_only' : 'competitive',
            'our_products_count' => count($ourData),
            'competitor_products_count' => count($competitorData)
        ];
    }
    
    private function executeAnalyticsQuery($step, array $context): array
    {
        return [
            'status' => 'success',
            'type' => 'analytics_query',
            'query' => $step->getDescription()
        ];
    }
    
    private function executeSemanticSearch($step, array $context): array
    {
        return [
            'status' => 'success',
            'type' => 'semantic_search',
            'query' => $step->getDescription()
        ];
    }
    
    private function logDebug(string $message): void
    {
        if ($this->securityLogger) {
            $this->securityLogger->logSecurityEvent($message, 'info');
        }
        
        if ($this->debug) {
            error_log("[TaskExecutor] $message");
        }
    }
}