<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Planning;

use AllowDynamicProperties;
use ClicShopping\AI\Agents\Orchestrator\AnalyticsAgent;
use ClicShopping\AI\Rag\MultiDBRAGManager;
use ClicShopping\AI\Infrastructure\Metrics\CalculatorTool;
use ClicShopping\AI\Domain\Search\WebSearchTool;

/**
 * ExecutionPlan Class
 *
 * Représente un plan d'exécution complet avec :
 * - Liste ordonnée d'étapes
 * - Graphe de dépendances
 * - Métadonnées de complexité
 * - État d'exécution
 */
#[AllowDynamicProperties]
class ExecutionPlan
{
  private string $query;
  private array $intent;
  private array $steps;
  private array $dependencies;
  private array $complexity;
  private float $planningTime = 0.0;
  private bool $isReplan = false;
  private ?ExecutionPlan $originalPlan = null;
  private ?array $replanContext = null;

  // État d'exécution
  private string $status = 'pending'; // pending, in_progress, completed, failed
  private array $stepResults = [];
  private ?string $finalResult = null;
  private float $executionTime = 0.0;

  private ?AnalyticsAgent $analyticsAgent = null;
  private ?MultiDBRAGManager $ragManager = null;
  private bool $debug;
  private string $userId;
  private int $languageId;

  private ?CalculatorTool $calculatorTool = null;
  private ?WebSearchTool $webSearchTool = null; // 🆕 Outil de recherche web


  /**
   * Constructor
   *
   * @param string $query Requête originale
   * @param array $intent Intention analysée
   * @param array $steps Liste de TaskStep
   * @param array $dependencies Graphe de dépendances
   * @param array $complexity Analyse de complexité
   */
  public function __construct(string $query, array $intent, array $steps, array $dependencies, array $complexity)
  {
    $this->query = $query;
    $this->intent = $intent;
    $this->steps = $steps;
    $this->dependencies = $dependencies;
    $this->complexity = $complexity;
  }

  /**
   * Marque ce plan comme une replanification
   */
  public function markAsReplan(ExecutionPlan $originalPlan, array $context): void
  {
    $this->isReplan = true;
    $this->originalPlan = $originalPlan;
    $this->replanContext = $context;
  }

  /**
   * Obtient la liste des étapes
   */
  public function getSteps(): array
  {
    return $this->steps;
  }

  /**
   * Obtient une étape par son ID
   */
  public function getStepById(string $id): ?TaskStep
  {
    foreach ($this->steps as $step) {
      if ($step->getId() === $id) {
        return $step;
      }
    }
    return null;
  }

  /**
   * Obtient les étapes qui peuvent s'exécuter maintenant
   * (dont toutes les dépendances sont complétées)
   */
  public function getReadySteps(): array
  {
    $ready = [];

    foreach ($this->steps as $step) {
      if ($step->getStatus() !== 'pending') {
        continue;
      }

      $stepId = $step->getId();
      $dependsOn = $this->dependencies[$stepId]['depends_on'] ?? [];

      // Vérifier si toutes les dépendances sont complétées
      $allDependenciesComplete = true;
      foreach ($dependsOn as $depId) {
        $depStep = $this->getStepById($depId);
        if (!$depStep || $depStep->getStatus() !== 'completed') {
          $allDependenciesComplete = false;
          break;
        }
      }

      if ($allDependenciesComplete) {
        $ready[] = $step;
      }
    }

    return $ready;
  }

  /**
   * Obtient les étapes qui peuvent s'exécuter en parallèle
   */
  public function getParallelSteps(): array
  {
    $ready = $this->getReadySteps();
    $parallel = [];

    foreach ($ready as $step) {
      if ($step->getMetadata()['can_run_parallel'] ?? false) {
        $parallel[] = $step;
      }
    }

    return $parallel;
  }

  /**
   * Stocke le résultat d'une étape
   */
  public function setStepResult(string $stepId, $result): void
  {
    $this->stepResults[$stepId] = $result;

    $step = $this->getStepById($stepId);
    if ($step) {
      $step->setResult($result);
    }
  }

  /**
   * Obtient le résultat d'une étape
   */
  public function getStepResult(string $stepId)
  {
    return $this->stepResults[$stepId] ?? null;
  }

  /**
   * Obtient tous les résultats d'étapes
   */
  public function getAllStepResults(): array
  {
    return $this->stepResults;
  }

  /**
   * Marque le plan comme en cours
   */
  public function start(): void
  {
    $this->status = 'in_progress';
  }

  /**
   * Marque le plan comme complété
   */
  public function complete(string $finalResult): void
  {
    $this->status = 'completed';
    $this->finalResult = $finalResult;
  }

  /**
   * Marque le plan comme échoué
   */
  public function fail(string $error): void
  {
    $this->status = 'failed';
    $this->finalResult = $error;
  }

  /**
   * Vérifie si toutes les étapes sont complétées
   */
  public function isComplete(): bool
  {
    foreach ($this->steps as $step) {
      if ($step->getStatus() !== 'completed') {
        return false;
      }
    }
    return true;
  }

  /**
   * Vérifie si le plan a échoué
   */
  public function hasFailed(): bool
  {
    foreach ($this->steps as $step) {
      if ($step->getStatus() === 'failed') {
        return true;
      }
    }
    return $this->status === 'failed';
  }

  /**
   * Obtient le progrès d'exécution (0-100)
   */
  public function getProgress(): float
  {
    if (empty($this->steps)) {
      return 100.0;
    }

    $completed = 0;
    foreach ($this->steps as $step) {
      if ($step->getStatus() === 'completed') {
        $completed++;
      }
    }

    return ($completed / count($this->steps)) * 100;
  }

  /**
   * Obtient les résultats des étapes exécutées
   * 
   * @return array Tableau des résultats par étape
   */
  public function getResults(): array
  {
    return $this->stepResults;
  }

  /**
   * Génère un résumé du plan
   */
  public function getSummary(): array
  {
    return [
      'query' => $this->query,
      'total_steps' => count($this->steps),
      'complexity_level' => $this->complexity['level'],
      'complexity_score' => $this->complexity['score'],
      'status' => $this->status,
      'progress' => round($this->getProgress(), 2) . '%',
      'is_replan' => $this->isReplan,
      'planning_time' => round($this->planningTime, 3) . 's',
      'execution_time' => round($this->executionTime, 3) . 's',
    ];
  }

  /**
   * Génère une représentation détaillée du plan
   */
  public function getDetailedView(): array
  {
    $stepsView = [];

    foreach ($this->steps as $step) {
      $stepId = $step->getId();

      $stepsView[] = [
        'id' => $stepId,
        'type' => $step->getType(),
        'description' => $step->getDescription(),
        'status' => $step->getStatus(),
        'depends_on' => $this->dependencies[$stepId]['depends_on'] ?? [],
        'required_by' => $this->dependencies[$stepId]['required_by'] ?? [],
        'can_run_parallel' => $step->getMetadata()['can_run_parallel'] ?? false,
        'execution_time' => $step->getExecutionTime(),
        'has_result' => isset($this->stepResults[$stepId]),
      ];
    }

    return [
      'summary' => $this->getSummary(),
      'steps' => $stepsView,
      'dependencies' => $this->dependencies,
    ];
  }

  // Getters
  public function getQuery(): string {return $this->query;}
  public function getIntent(): array { return $this->intent; }
  public function getDependencies(): array { return $this->dependencies; }
  public function getComplexity(): array { return $this->complexity; }
  public function getStatus(): string { return $this->status; }
  public function getFinalResult(): ?string { return $this->finalResult; }
  public function isReplan(): bool { return $this->isReplan; }
  public function getOriginalPlan(): ?ExecutionPlan { return $this->originalPlan; }
  public function getReplanContext(): ?array { return $this->replanContext; }
  public function getPlanningTime(): float { return $this->planningTime; }
  public function getExecutionTime(): float { return $this->executionTime; }

  // Setters
  public function setPlanningTime(float $time): void { $this->planningTime = $time; }
  public function setExecutionTime(float $time): void { $this->executionTime = $time; }
}
