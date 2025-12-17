<?php
/**
 * TaskPlanValidator - Validateur renforcé avec auto-correction
 * 
 * Stratégie Mixte (Option 3) :
 * - Tente correction automatique des incohérences
 * - Si impossible → refuse avec diagnostic détaillé
 * - Fallback déterministe interne-only si doute
 * - Rollback intelligent avec skip gracieux
 */

namespace ClicShopping\AI\Agents\Planning;

use ClicShopping\AI\Security\SecurityLogger;

class TaskPlanValidator
{
    private bool $debug;
    private ?SecurityLogger $securityLogger;
    
    // Politiques de validation
    private array $validationPolicies = [
        'min_steps' => 1,
        'max_steps' => 10,
        'allow_orphan_steps' => false,
        'require_final_step' => true,
        'allow_external_data' => true,
        'auto_correct_dependencies' => true,
        'fallback_to_internal_only' => true
    ];
    
    public function __construct(bool $debug = false, ?SecurityLogger $securityLogger = null)
    {
        $this->debug = $debug;
        $this->securityLogger = $securityLogger;
    }
    
    /**
     * 🎯 VALIDATION PRINCIPALE - Option 3 Mixte
     * 
     * @param array $steps Liste des TaskStep
     * @param array $dependencies Graphe de dépendances
     * @param array $context Contexte d'exécution
     * @return array [bool $valid, array $correctedSteps, array $diagnostics]
     */
    public function validateAndCorrectPlan(array $steps, array $dependencies, array $context = []): array
    {
        $diagnostics = [];
        $correctedSteps = $steps;
        
        if ($this->debug) {
            $this->logDebug("Starting plan validation with " . count($steps) . " steps");
        }
        
        // 1. Validations critiques (refus immédiat si échec)
        $criticalValidation = $this->validateCriticalConstraints($steps, $diagnostics);
        if (!$criticalValidation['valid']) {
            return [
                'valid' => false,
                'corrected_steps' => [],
                'diagnostics' => $criticalValidation['diagnostics'],
                'correction_attempted' => false,
                'reason' => 'critical_constraints_failed'
            ];
        }
        
        // 2. Validations avec auto-correction
        $correctionResults = $this->attemptAutoCorrections($correctedSteps, $dependencies, $context, $diagnostics);
        $correctedSteps = $correctionResults['steps'];
        $diagnostics = array_merge($diagnostics, $correctionResults['diagnostics']);
        
        // 3. Validation finale après corrections
        $finalValidation = $this->validateFinalPlan($correctedSteps, $dependencies, $diagnostics);
        
        return [
            'valid' => $finalValidation['valid'],
            'corrected_steps' => $correctedSteps,
            'diagnostics' => $diagnostics,
            'correction_attempted' => $correctionResults['corrections_applied'],
            'corrections_applied' => $correctionResults['correction_details'],
            'reason' => $finalValidation['reason'] ?? 'validated'
        ];
    }
    
    /**
     * 🚨 Validations critiques (refus immédiat)
     */
    private function validateCriticalConstraints(array $steps, array &$diagnostics): array
    {
        // Contrainte 1 : Nombre de steps
        if (count($steps) < $this->validationPolicies['min_steps']) {
            $diagnostics[] = [
                'type' => 'critical_error',
                'code' => 'INSUFFICIENT_STEPS',
                'message' => 'Plan must have at least ' . $this->validationPolicies['min_steps'] . ' step(s)',
                'current_count' => count($steps)
            ];
            return ['valid' => false, 'diagnostics' => $diagnostics];
        }
        
        if (count($steps) > $this->validationPolicies['max_steps']) {
            $diagnostics[] = [
                'type' => 'critical_error',
                'code' => 'TOO_MANY_STEPS',
                'message' => 'Plan exceeds maximum of ' . $this->validationPolicies['max_steps'] . ' steps',
                'current_count' => count($steps)
            ];
            return ['valid' => false, 'diagnostics' => $diagnostics];
        }
        
        // Contrainte 2 : Steps vides ou malformés
        foreach ($steps as $i => $step) {
            if (!$step || !method_exists($step, 'getId') || !method_exists($step, 'getType')) {
                $diagnostics[] = [
                    'type' => 'critical_error',
                    'code' => 'MALFORMED_STEP',
                    'message' => "Step at index $i is malformed or missing required methods",
                    'step_index' => $i
                ];
                return ['valid' => false, 'diagnostics' => $diagnostics];
            }
            
            if (empty($step->getId()) || empty($step->getType())) {
                $diagnostics[] = [
                    'type' => 'critical_error',
                    'code' => 'EMPTY_STEP_DATA',
                    'message' => "Step at index $i has empty ID or type",
                    'step_id' => $step->getId(),
                    'step_type' => $step->getType()
                ];
                return ['valid' => false, 'diagnostics' => $diagnostics];
            }
        }
        
        return ['valid' => true, 'diagnostics' => $diagnostics];
    }
    
    /**
     * 🔧 Tentatives d'auto-correction
     */
    private function attemptAutoCorrections(array $steps, array $dependencies, array $context, array &$diagnostics): array
    {
        $correctedSteps = $steps;
        $correctionsApplied = false;
        $correctionDetails = [];
        
        // Correction 1 : Dépendances circulaires
        $circularResult = $this->fixCircularDependencies($correctedSteps, $dependencies, $diagnostics);
        if ($circularResult['corrected']) {
            $correctedSteps = $circularResult['steps'];
            $correctionsApplied = true;
            $correctionDetails[] = 'circular_dependencies_fixed';
        }
        
        // Correction 2 : Steps orphelins
        $orphanResult = $this->fixOrphanSteps($correctedSteps, $dependencies, $diagnostics);
        if ($orphanResult['corrected']) {
            $correctedSteps = $orphanResult['steps'];
            $correctionsApplied = true;
            $correctionDetails[] = 'orphan_steps_fixed';
        }
        
        // Correction 3 : Step final manquant
        $finalStepResult = $this->ensureFinalStep($correctedSteps, $diagnostics);
        if ($finalStepResult['corrected']) {
            $correctedSteps = $finalStepResult['steps'];
            $correctionsApplied = true;
            $correctionDetails[] = 'final_step_ensured';
        }
        
        // Correction 4 : Fallback interne-only si données externes douteuses
        $fallbackResult = $this->applyInternalFallbackIfNeeded($correctedSteps, $context, $diagnostics);
        if ($fallbackResult['corrected']) {
            $correctedSteps = $fallbackResult['steps'];
            $correctionsApplied = true;
            $correctionDetails[] = 'internal_fallback_applied';
        }
        
        return [
            'steps' => $correctedSteps,
            'corrections_applied' => $correctionsApplied,
            'correction_details' => $correctionDetails,
            'diagnostics' => $diagnostics
        ];
    }
    
    /**
     * 🔄 Correction des dépendances circulaires
     */
    private function fixCircularDependencies(array $steps, array $dependencies, array &$diagnostics): array
    {
        // Détecter les cycles avec algorithme DFS
        $visited = [];
        $recursionStack = [];
        $cycles = [];
        
        foreach ($steps as $step) {
            $stepId = $step->getId();
            if (!isset($visited[$stepId])) {
                $this->detectCycles($stepId, $dependencies, $visited, $recursionStack, $cycles);
            }
        }
        
        if (empty($cycles)) {
            return ['corrected' => false, 'steps' => $steps];
        }
        
        // Correction : Supprimer les dépendances qui créent des cycles
        $correctedSteps = [];
        foreach ($steps as $step) {
            $stepId = $step->getId();
            $metadata = $step->getMetadata();
            $dependsOn = $metadata['depends_on'] ?? [];
            
            // Filtrer les dépendances circulaires
            $filteredDependsOn = array_filter($dependsOn, function($depId) use ($cycles, $stepId) {
                foreach ($cycles as $cycle) {
                    if (in_array($stepId, $cycle) && in_array($depId, $cycle)) {
                        // Cette dépendance crée un cycle
                        return false;
                    }
                }
                return true;
            });
            
            if (count($filteredDependsOn) !== count($dependsOn)) {
                // Créer un nouveau step avec dépendances corrigées
                $metadata['depends_on'] = array_values($filteredDependsOn);
                $correctedStep = new TaskStep(
                    $step->getId(),
                    $step->getType(),
                    $step->getDescription(),
                    $metadata
                );
                $correctedSteps[] = $correctedStep;
                
                $diagnostics[] = [
                    'type' => 'auto_correction',
                    'code' => 'CIRCULAR_DEPENDENCY_FIXED',
                    'message' => "Removed circular dependencies for step: $stepId",
                    'step_id' => $stepId,
                    'removed_dependencies' => array_diff($dependsOn, $filteredDependsOn)
                ];
            } else {
                $correctedSteps[] = $step;
            }
        }
        
        return ['corrected' => true, 'steps' => $correctedSteps];
    }
    
    /**
     * Détection de cycles avec DFS
     */
    private function detectCycles(string $stepId, array $dependencies, array &$visited, array &$recursionStack, array &$cycles): void
    {
        $visited[$stepId] = true;
        $recursionStack[$stepId] = true;
        
        $dependsOn = $dependencies[$stepId]['depends_on'] ?? [];
        foreach ($dependsOn as $depId) {
            if (!isset($visited[$depId])) {
                $this->detectCycles($depId, $dependencies, $visited, $recursionStack, $cycles);
            } elseif (isset($recursionStack[$depId]) && $recursionStack[$depId]) {
                // Cycle détecté
                $cycles[] = [$stepId, $depId];
            }
        }
        
        $recursionStack[$stepId] = false;
    }
    
    /**
     * 🔗 Correction des steps orphelins
     */
    private function fixOrphanSteps(array $steps, array $dependencies, array &$diagnostics): array
    {
        if ($this->validationPolicies['allow_orphan_steps']) {
            return ['corrected' => false, 'steps' => $steps];
        }
        
        $correctedSteps = [];
        $hasCorrections = false;
        
        foreach ($steps as $i => $step) {
            $stepId = $step->getId();
            $dependsOn = $dependencies[$stepId]['depends_on'] ?? [];
            $requiredBy = $dependencies[$stepId]['required_by'] ?? [];
            
            // Un step est orphelin s'il n'a pas de dépendances ET n'est requis par personne
            // SAUF s'il est le premier ou le dernier step
            $isOrphan = empty($dependsOn) && empty($requiredBy) && $i > 0 && $i < (count($steps) - 1);
            
            if ($isOrphan) {
                // Correction : Lier au step précédent
                $previousStep = $steps[$i - 1];
                $metadata = $step->getMetadata();
                $metadata['depends_on'] = [$previousStep->getId()];
                
                $correctedStep = new TaskStep(
                    $step->getId(),
                    $step->getType(),
                    $step->getDescription(),
                    $metadata
                );
                
                $correctedSteps[] = $correctedStep;
                $hasCorrections = true;
                
                $diagnostics[] = [
                    'type' => 'auto_correction',
                    'code' => 'ORPHAN_STEP_LINKED',
                    'message' => "Linked orphan step to previous step",
                    'step_id' => $stepId,
                    'linked_to' => $previousStep->getId()
                ];
            } else {
                $correctedSteps[] = $step;
            }
        }
        
        return ['corrected' => $hasCorrections, 'steps' => $correctedSteps];
    }
    
    /**
     * ✅ Assurer qu'il y a un step final
     */
    private function ensureFinalStep(array $steps, array &$diagnostics): array
    {
        if (!$this->validationPolicies['require_final_step']) {
            return ['corrected' => false, 'steps' => $steps];
        }
        
        // Vérifier si le dernier step est marqué comme final
        $lastStep = end($steps);
        $metadata = $lastStep->getMetadata();
        
        if (!($metadata['is_final'] ?? false)) {
            // Correction : Marquer le dernier step comme final
            $metadata['is_final'] = true;
            
            $correctedLastStep = new TaskStep(
                $lastStep->getId(),
                $lastStep->getType(),
                $lastStep->getDescription(),
                $metadata
            );
            
            $correctedSteps = $steps;
            $correctedSteps[count($correctedSteps) - 1] = $correctedLastStep;
            
            $diagnostics[] = [
                'type' => 'auto_correction',
                'code' => 'FINAL_STEP_MARKED',
                'message' => "Marked last step as final",
                'step_id' => $lastStep->getId()
            ];
            
            return ['corrected' => true, 'steps' => $correctedSteps];
        }
        
        return ['corrected' => false, 'steps' => $steps];
    }
    
    /**
     * 🛡️ Fallback interne-only si données externes douteuses
     */
    private function applyInternalFallbackIfNeeded(array $steps, array $context, array &$diagnostics): array
    {
        if (!$this->validationPolicies['fallback_to_internal_only']) {
            return ['corrected' => false, 'steps' => $steps];
        }
        
        // Vérifier si les données externes sont fiables
        $externalDataReliable = $this->assessExternalDataReliability($context);
        
        if ($externalDataReliable) {
            return ['corrected' => false, 'steps' => $steps];
        }
        
        // Correction : Modifier les steps externes pour utiliser uniquement les données internes
        $correctedSteps = [];
        $hasCorrections = false;
        
        foreach ($steps as $step) {
            $stepType = $step->getType();
            $metadata = $step->getMetadata();
            
            if ($stepType === 'collect_competitor_market_data') {
                // Forcer l'utilisation du cache interne uniquement
                $metadata['force_internal_only'] = true;
                $metadata['external_access_blocked'] = 'unreliable_external_data';
                
                $correctedStep = new TaskStep(
                    $step->getId(),
                    $step->getType(),
                    $step->getDescription(),
                    $metadata
                );
                
                $correctedSteps[] = $correctedStep;
                $hasCorrections = true;
                
                $diagnostics[] = [
                    'type' => 'auto_correction',
                    'code' => 'INTERNAL_FALLBACK_APPLIED',
                    'message' => "Forced internal-only mode due to unreliable external data",
                    'step_id' => $step->getId(),
                    'reason' => 'external_data_unreliable'
                ];
            } else {
                $correctedSteps[] = $step;
            }
        }
        
        return ['corrected' => $hasCorrections, 'steps' => $correctedSteps];
    }
    
    /**
     * Évalue la fiabilité des données externes
     */
    private function assessExternalDataReliability(array $context): bool
    {
        $policy = $context['policy'] ?? [];
        
        // Critères de fiabilité
        $hasApiKey = !empty($policy['serpapi_key'] ?? '');
        $externalAllowed = $policy['allow_external'] ?? false;
        $hasValidCache = !empty($context['internal_competitor_cache'] ?? []);
        
        // Données externes fiables si API configurée ET autorisée
        return $hasApiKey && $externalAllowed;
    }
    
    /**
     * 🏁 Validation finale après corrections
     */
    private function validateFinalPlan(array $steps, array $dependencies, array &$diagnostics): array
    {
        // Vérifications finales
        $issues = [];
        
        // 1. Vérifier qu'il n'y a plus de cycles
        if ($this->hasCycles($steps, $dependencies)) {
            $issues[] = 'circular_dependencies_remain';
        }
        
        // 2. Vérifier la cohérence des types de steps
        if (!$this->validateStepTypeCoherence($steps)) {
            $issues[] = 'step_type_incoherence';
        }
        
        // 3. Vérifier que toutes les dépendances existent
        if (!$this->validateDependenciesExist($steps, $dependencies)) {
            $issues[] = 'missing_dependencies';
        }
        
        if (!empty($issues)) {
            $diagnostics[] = [
                'type' => 'validation_error',
                'code' => 'FINAL_VALIDATION_FAILED',
                'message' => 'Plan validation failed after corrections',
                'issues' => $issues
            ];
            
            return ['valid' => false, 'reason' => 'final_validation_failed'];
        }
        
        $diagnostics[] = [
            'type' => 'validation_success',
            'code' => 'PLAN_VALIDATED',
            'message' => 'Plan successfully validated and corrected',
            'steps_count' => count($steps)
        ];
        
        return ['valid' => true, 'reason' => 'validated'];
    }
    
    /**
     * Vérifie s'il reste des cycles
     */
    private function hasCycles(array $steps, array $dependencies): bool
    {
        $visited = [];
        $recursionStack = [];
        
        foreach ($steps as $step) {
            $stepId = $step->getId();
            if (!isset($visited[$stepId])) {
                if ($this->hasCycleDFS($stepId, $dependencies, $visited, $recursionStack)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    private function hasCycleDFS(string $stepId, array $dependencies, array &$visited, array &$recursionStack): bool
    {
        $visited[$stepId] = true;
        $recursionStack[$stepId] = true;
        
        $dependsOn = $dependencies[$stepId]['depends_on'] ?? [];
        foreach ($dependsOn as $depId) {
            if (!isset($visited[$depId])) {
                if ($this->hasCycleDFS($depId, $dependencies, $visited, $recursionStack)) {
                    return true;
                }
            } elseif (isset($recursionStack[$depId]) && $recursionStack[$depId]) {
                return true;
            }
        }
        
        $recursionStack[$stepId] = false;
        return false;
    }
    
    /**
     * Valide la cohérence des types de steps
     */
    private function validateStepTypeCoherence(array $steps): bool
    {
        $allowedTypes = [
            'collect_our_product_data',
            'collect_competitor_market_data',
            'competitive_analysis_synthesis',
            'load_product_catalog_data',
            'pattern_extraction',
            'pattern_frequency_ranking',
            'pattern_synthesis',
            'price_data_collection',
            'price_analysis',
            'price_insights_synthesis',
            'analytics_query',
            'semantic_search',
            'fallback'
        ];
        
        foreach ($steps as $step) {
            if (!in_array($step->getType(), $allowedTypes)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Valide que toutes les dépendances existent
     */
    private function validateDependenciesExist(array $steps, array $dependencies): bool
    {
        $stepIds = array_map(fn($step) => $step->getId(), $steps);
        
        foreach ($dependencies as $stepId => $deps) {
            foreach ($deps['depends_on'] as $depId) {
                if (!in_array($depId, $stepIds)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Met à jour les politiques de validation
     */
    public function updatePolicies(array $newPolicies): void
    {
        $this->validationPolicies = array_merge($this->validationPolicies, $newPolicies);
        
        if ($this->debug) {
            $this->logDebug("Validation policies updated: " . json_encode($newPolicies));
        }
    }
    
    /**
     * Obtient les politiques actuelles
     */
    public function getPolicies(): array
    {
        return $this->validationPolicies;
    }
    
    private function logDebug(string $message): void
    {
        if ($this->securityLogger) {
            $this->securityLogger->logSecurityEvent($message, 'info');
        }
        
        if ($this->debug) {
            error_log("[TaskPlanValidator] $message");
        }
    }
}