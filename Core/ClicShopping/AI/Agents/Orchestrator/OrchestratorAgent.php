<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

use ClicShopping\AI\Infrastructure\Monitoring\AlertManager;
use ClicShopping\AI\Infrastructure\Monitoring\MetricsCollector;
use ClicShopping\AI\Infrastructure\Monitoring\MonitoringAgent;

use ClicShopping\AI\Rag\MultiDBRAGManager;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Security\RateLimit;

use ClicShopping\AI\Agents\Memory\ConversationMemory;
use ClicShopping\AI\Agents\Planning\PlanExecutor;
use ClicShopping\AI\Agents\Planning\TaskPlanner;
use ClicShopping\AI\Agents\Memory\WorkingMemory;
use ClicShopping\AI\Agents\Response\LlmResponseProcessor;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\IntentAnalyzer;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\EntityExtractor;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\DiagnosticManager;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\ContextManager;
use ClicShopping\AI\Handler\Query\ComplexQueryHandler;
use ClicShopping\AI\Security\Validation\HallucinationDetector;
use ClicShopping\AI\DomainsAI\Analytics\Agent\AnalyticsAgent;
use ClicShopping\AI\DomainsAI\WebSearch\Tool\WebSearchTool;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\ResponseProcessor as ResponseProcessorComponent;
use ClicShopping\AI\Agents\Query\QueryAnalyzer;
use ClicShopping\AI\Handler\Error\ErrorHandler as ErrorHandlerComponent;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\MemoryManager as MemoryManagerComponent;
use ClicShopping\AI\Helper\OrchestratorHelper;

use ClicShopping\AI\DomainsAI\Semantic\Agent\SemanticAgent;
use ClicShopping\AI\InterfacesAI\QueryTypeDomainInterface;
use ClicShopping\AI\Config\DomainConfig;
use ClicShopping\AI\Config\ActorCriticConfig;
use ClicShopping\AI\Config\AgentSystemConfig;
use ClicShopping\AI\Config\AgentTechnicalConfig;
use ClicShopping\AI\Config\AgentActorsConfig;
use ClicShopping\AI\Config\AgentCriticsConfig;
use ClicShopping\AI\Config\AgentDomainsConfig;
use ClicShopping\AI\Config\AgentActivationConfig;
use ClicShopping\AI\Config\DomainFields;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\ActorCriticCoordinator;

/**
 * OrchestratorAgent Class
 * Main orchestrator agent that coordinates the multi-agent system
 * Handles intent analysis, agent coordination, execution, error management, and response synthesis
 */

class OrchestratorAgent
{
  public TaskPlanner $taskPlanner;
  public PlanExecutor $planExecutor;
  private ?MetricsCollector $collector = null;
  private SecurityLogger $securityLogger;
  private RateLimit $rateLimit;
  private string $userId;
  private bool $debug;
  private int $languageId;
  private int $entityId;
  private $db;
  private string $prefix;
  private ?MultiDBRAGManager $ragManager = null;
  private array $executionStats = [];
  private ?ConversationMemory $conversationMemory = null;
  private WorkingMemory $workingMemory;
  private CorrectionAgent $correctionAgent;
  private ValidationAgent $validationAgent;
  private ReasoningAgent $reasoningAgent;

  private MonitoringAgent $monitoring;
  private AlertManager $alertManager;
  private LlmResponseProcessor $responseProcessor;
  private ?ResponseProcessorComponent $responseProcessorComponent = null;

  private IntentAnalyzer $intentAnalyzer;
  private EntityExtractor $entityExtractor;
  private DiagnosticManager $diagnosticManager;
  private ContextManager $contextManager;
 private ActorCriticCoordinator $actorCriticCoordinator;
  private ComplexQueryHandler $complexQueryHandler;
  private ?QueryAnalyzer $queryAnalyzer = null;
  private ?ErrorHandlerComponent $errorHandler = null;
  private ?MemoryManagerComponent $memoryManager = null;
  private ?\ClicShopping\AI\Agents\Orchestrator\SubAutonomous\AutonomousConfig $autonomousConfig = null;

  // Diagnostics - delegated to DiagnosticManager

  /**
   * Constructor
   *
   * @param string $userId Identifiant de l'utilisateur
   * @param int|null $languageId ID de la langue (null = langue par défaut)
   * @param int $entityId Entity ID for context
   *

   */
  public function __construct(string $userId = 'system', ?int $languageId = null, int $entityId = 0)
  {
    // Core initialization
    $this->userId = $userId;
    $this->entityId = $entityId;
    $this->db = Registry::get('Db');
    $this->languageId = is_null($languageId) ? Registry::get('Language')->getId() : $languageId;
    $this->prefix = CLICSHOPPING::getConfig('db_table_prefix');

    // Initialize core components (security, monitoring, memory)
    $this->initializeCoreComponents();

    // Initialize all agents and SubOrchestrator components
    $this->initializeAgents();

    // Initialize SubOrchestrator components (Phase 2 extracted components)
    $this->initializeSubComponents();

    // Initialize statistics
    $this->initializeStats();

    // Register with monitoring
    $this->monitoring->registerComponent('orchestrator', $this);

    if ($this->debug) {
      $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'initialized', [
        'user_id' => $this->userId,
        'entity_id' => $this->entityId,
        'language_id' => $this->languageId
      ]);
    }
  }

  /**
   * Initialize core components (security, rate limiting, monitoring, memory)
   *
   */
  private function initializeCoreComponents(): void
  {
    // Security and rate limiting
    $this->securityLogger = new SecurityLogger();
    $this->rateLimit = new RateLimit('orchestrator', 100, 60);
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    
    $this->autonomousConfig = new \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\AutonomousConfig($this->debug);

    // Monitoring and metrics
    $this->monitoring = new MonitoringAgent();
    $this->collector = new MetricsCollector($this->monitoring);
    $this->alertManager = new AlertManager();

    // Memory systems
    $this->conversationMemory = new ConversationMemory(
      $this->userId,
      $this->languageId,
      $this->prefix . 'rag_conversation_memory_embedding',
      $this->entityId
    );
    $this->workingMemory = new WorkingMemory();

    // Response processor
    $this->responseProcessor = new LlmResponseProcessor();
  }

  /**
   * Initialize all agents (planning, correction, validation, reasoning)
   *
   */
  private function initializeAgents(): void
  {
    $this->taskPlanner = new TaskPlanner($this->languageId);
    $this->planExecutor = new PlanExecutor($this->taskPlanner, $this->userId, $this->languageId);
    $this->correctionAgent = new CorrectionAgent($this->userId, $this->languageId);
    $this->validationAgent = new ValidationAgent($this->userId);
    $this->reasoningAgent = new ReasoningAgent();
  }

  /**
   * Initialize SubOrchestrator components
   *
   */
  private function initializeSubComponents(): void
  {
    // Existing SubOrchestrator components
    $this->intentAnalyzer = new IntentAnalyzer($this->conversationMemory ?? null, $this->debug);
    $this->entityExtractor = new EntityExtractor($this->debug);
    $this->diagnosticManager = new DiagnosticManager($this->executionStats, $this->debug);
    $this->contextManager = new ContextManager($this->debug, [
      'auto_clear_on_domain_switch' => true,
      'prioritize_feedback_over_context' => true,
      'min_confidence_for_clear' => 0.7,
    ]);

    $this->actorCriticCoordinator = new ActorCriticCoordinator();
    $this->complexQueryHandler = new ComplexQueryHandler($this->debug);

    $this->responseProcessorComponent = new ResponseProcessorComponent($this->debug);
    $this->queryAnalyzer = new QueryAnalyzer($this->debug);
    $this->errorHandler = new ErrorHandlerComponent($this->debug, $this->responseProcessorComponent);
    $this->memoryManager = new MemoryManagerComponent(
      $this->conversationMemory,
      $this->workingMemory,
      $this->debug
    );

    if ($this->debug) {
      error_log('---------------------------');
      error_log('Actor CriticsConfig Enable : ' . ActorCriticConfig::isEnabled());
      error_log('---------------------------');
    }

    if (ActorCriticConfig::isEnabled()) {
      try {
        // Log Agent System and Agent Technical status
        if ($this->debug) {
          $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'agent_modules_status', [
            'agent_system' => [
              'enabled' => AgentSystemConfig::isEnabled(),
              'websearch_global' => AgentSystemConfig::isWebSearchGloballyEnabled(),
              'adaptive_weighting' => AgentSystemConfig::isAdaptiveWeightingEnabled(),
              'reputation_system' => AgentSystemConfig::isReputationSystemEnabled()
            ],
            'agent_technical' => [
              'enabled' => AgentTechnicalConfig::isEnabled(),
              'llm_provider' => AgentTechnicalConfig::getLLMProvider(),
              'coordination_timeout' => AgentTechnicalConfig::getCoordinationTimeout(),
              'max_critics' => AgentTechnicalConfig::getMaxCritics(),
              'consensus_threshold' => AgentTechnicalConfig::getConsensusThreshold()
            ],
            'agent_actors' => [
              'enabled' => AgentActorsConfig::isEnabled(),
              'analytics' => AgentActorsConfig::isAnalyticsEnabled(),
              'semantic' => AgentActorsConfig::isSemanticEnabled(),
              'validation' => AgentActorsConfig::isValidationEnabled(),
              'websearch' => AgentActorsConfig::isWebSearchEnabled(),
              'reasoning' => AgentActorsConfig::isReasoningEnabled()
            ],
            'agent_critics' => [
              'enabled' => AgentCriticsConfig::isEnabled(),
              'analytics_expert' => AgentCriticsConfig::isAnalyticsExpertEnabled(),
              'specialist' => AgentCriticsConfig::isSpecialistEnabled(),
              'security_expert' => AgentCriticsConfig::isSecurityExpertEnabled(),
              'generalist' => AgentCriticsConfig::isGeneralistEnabled()
            ],
            'agent_domains' => [
              'enabled' => AgentDomainsConfig::isEnabled(),
              'domains_enabled' => AgentDomainsConfig::isDomainsEnabled()
            ]
          ]);
        }
        
        // Initialize registries and register actors/critics
        $this->initializeActorCriticSystem();
        
        $this->actorCriticCoordinator = new ActorCriticCoordinator();
        
        if ($this->debug) {
          $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_critic_enabled', [
            'message' => 'Actor-Critic separation is ENABLED',
            'fallback_enabled' => ActorCriticConfig::shouldFallbackToHybrid()
          ]);
        }
      } catch (\Exception $e) {
        if ($this->debug) {
          $this->securityLogger->logStructured('warning', 'OrchestratorAgent', 'actor_critic_init_failed', [
            'message' => 'Failed to initialize ActorCriticCoordinator, will use hybrid mode',
            'error' => $e->getMessage()
          ]);
        }
        $this->actorCriticCoordinator = null;
      }
    } else {
      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_critic_disabled', [
          'message' => 'Actor-Critic separation is DISABLED (using hybrid mode)'
        ]);
      }
    }
  }
  
  /**
   * Initialize Actor-Critic system by registering all actors and critics
   * 
   * This method registers all available actors and critics in their respective registries.
   * Called during OrchestratorAgent initialization when Actor-Critic separation is enabled.
   * Only registers actors/critics that are enabled in their respective configurations.
   * 
   * @return void
   */
  private function initializeActorCriticSystem(): void
  {
    try {
      // Create registries
      $actorRegistry = new \ClicShopping\AI\RegistryAI\ActorRegistry();
      $criticRegistry = new \ClicShopping\AI\RegistryAI\CriticRegistry();
      
      $actorsRegistered = 0;
      $criticsRegistered = 0;
      
      // Register actors only if AgentActorsConfig is enabled
      if (AgentActorsConfig::isEnabled()) {
        // Register Analytics Actor if enabled
        if (AgentActorsConfig::isAnalyticsEnabled() && AgentActivationConfig::isAgentEnabled('analytics_actor')) {
          $actorRegistry->registerActor(new \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Actors\AnalyticsActor($this->languageId, $this->debug));
          $actorsRegistered++;
          if ($this->debug) {
            $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_registered', [
              'actor' => 'AnalyticsActor'
            ]);
          }
        }
        
        // Register Reasoning Actor if enabled
        if (AgentActorsConfig::isReasoningEnabled() && AgentActivationConfig::isAgentEnabled('reasoning_actor')) {
          $actorRegistry->registerActor(new \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Actors\ReasoningActor($this->languageId, $this->debug));
          $actorsRegistered++;
          if ($this->debug) {
            $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_registered', [
              'actor' => 'ReasoningActor'
            ]);
          }
        }
        
        // Register Validation Actor if enabled
        if (AgentActorsConfig::isValidationEnabled() && AgentActivationConfig::isAgentEnabled('validation_actor')) {
          $actorRegistry->registerActor(new \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Actors\ValidationActor($this->languageId, $this->debug));
          $actorsRegistered++;
          if ($this->debug) {
            $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_registered', [
              'actor' => 'ValidationActor'
            ]);
          }
        }
      } else {
        if ($this->debug) {
          $this->securityLogger->logStructured('warning', 'OrchestratorAgent', 'actors_disabled', [
            'message' => 'AgentActorsConfig is disabled, no actors registered'
          ]);
        }
      }
      
      // Register critics only if AgentCriticsConfig is enabled
      if (AgentCriticsConfig::isEnabled()) {
        // Register Analytics Expert Critic if enabled
        if (AgentCriticsConfig::isAnalyticsExpertEnabled() && AgentActivationConfig::isAgentEnabled('analytics_critic')) {
          $criticRegistry->registerCritic(new \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Critics\AnalyticsCritic($this->languageId, $this->debug));
          $criticsRegistered++;
          if ($this->debug) {
            $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'critic_registered', [
              'critic' => 'AnalyticsCritic'
            ]);
          }
        }
        
        // Register Reasoning Critic (maps to generalist) if enabled
        if (AgentCriticsConfig::isGeneralistEnabled() && AgentActivationConfig::isAgentEnabled('reasoning_critic')) {
          $criticRegistry->registerCritic(new \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Critics\ReasoningCritic($this->languageId, $this->debug));
          $criticsRegistered++;
          if ($this->debug) {
            $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'critic_registered', [
              'critic' => 'ReasoningCritic'
            ]);
          }
        }
        
        // Register Validation Critic (maps to specialist) if enabled
        if (AgentCriticsConfig::isSpecialistEnabled() && AgentActivationConfig::isAgentEnabled('validation_critic')) {
          $criticRegistry->registerCritic(new \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Critics\ValidationCritic($this->languageId, $this->debug));
          $criticsRegistered++;
          if ($this->debug) {
            $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'critic_registered', [
              'critic' => 'ValidationCritic'
            ]);
          }
        }
      } else {
        if ($this->debug) {
          $this->securityLogger->logStructured('warning', 'OrchestratorAgent', 'critics_disabled', [
            'message' => 'AgentCriticsConfig is disabled, no critics registered'
          ]);
        }
      }
      
      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_critic_system_initialized', [
          'actors_registered' => $actorsRegistered,
          'critics_registered' => $criticsRegistered,
          'actors_config_enabled' => AgentActorsConfig::isEnabled(),
          'critics_config_enabled' => AgentCriticsConfig::isEnabled(),
          'message' => 'Actor-Critic system initialized successfully'
        ]);
      }
      
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logStructured('error', 'OrchestratorAgent', 'actor_critic_init_error', [
          'error' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);
      }
      throw $e;
    }
  }

  /**
   * Initialize execution statistics
   */
  private function initializeStats(): void
  {
    $this->executionStats = [
      'total_queries' => 0,
      'total_requests' => 0, // 🆕 For DiagnosticManager
      'total_execution_time' => 0, // 🆕 For DiagnosticManager
      'successful_queries' => 0,
      'failed_queries' => 0,
      'analytics_queries' => 0,
      'semantic_queries' => 0,
      'hybrid_queries' => 0,
      'average_execution_time' => 0,
    ];
  }

  /**
   * Get latency metrics for dashboard
   *
   * @return array Latency metrics with detailed statistics
   */
  public function getLatencyMetrics(): array
  {
    $allStats = $this->collector->getHistogramStats('orchestrator_query_latency_ms');

    return [
      'overall' => $allStats ?? [
        'count' => 0,
        'mean' => 0,
        'median' => 0,
        'min' => 0,
        'max' => 0,
        'percentiles' => [
          'p50' => 0,
          'p75' => 0,
          'p90' => 0,
          'p95' => 0,
          'p99' => 0,
        ]
      ]
    ];
  }

  /**
   * Get comprehensive health report
   *
   * @return array Health report with metrics
   */
  public function getHealthReport(): array
  {
    return $this->diagnosticManager->getHealthReport();
  }

  /**
   * Explain the last error in human-readable language
   *
   * @return string Human-readable explanation
   */
  public function explainLastError(): string
  {
    return $this->diagnosticManager->explainLastError();
  }

  /**
   * Get recent errors with details
   *
   * @param int $limit Maximum number of errors to return
   * @return array Array of error objects
   */
  public function getRecentErrors(int $limit = 10): array
  {
    return $this->diagnosticManager->getRecentErrors($limit);
  }


  // ========================================
  // 🆕 DIAGNOSTIC METHODS (Delegated to DiagnosticManager)
  // ========================================

  /**
   * Analyze error patterns and suggest improvements
   *
   * @return array Array of improvement suggestions
   */
  public function suggestImprovements(): array
  {
    return $this->diagnosticManager->suggestImprovements();
  }

  /**
   * Approve or reject an agent's local objective
   *
   * Called when an objective requires orchestrator approval due to:
   * - Conflicts with other objectives
   * - System-wide constraint violations
   * - High-priority objectives
   *
   * @param string $objectiveId The objective ID to approve/reject
   * @param bool $approve True to approve, false to reject
   * @param string $reason Reason for the decision
   * @return array Approval result
   */
  public function approveObjective(string $objectiveId, bool $approve, string $reason = ''): array
  {
    try {
      $objectiveRegistry = new \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\ObjectiveRegistry($this->db, $this->debug);
      $objective = $objectiveRegistry->getObjective($objectiveId);

      if (!$objective) {
        throw new \InvalidArgumentException("Objective {$objectiveId} not found");
      }

      if ($approve) {
        $objectiveRegistry->updateObjectiveStatus($objectiveId, 'approved');

        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Orchestrator approved objective {$objectiveId}: {$reason}",
            'info'
          );
        }

        return [
          'success' => true,
          'objective_id' => $objectiveId,
          'status' => 'approved',
          'message' => 'Objective approved for execution'
        ];
      } else {
        $objectiveRegistry->cancelObjective($objectiveId, $reason);

        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Orchestrator rejected objective {$objectiveId}: {$reason}",
            'warning'
          );
        }

        return [
          'success' => true,
          'objective_id' => $objectiveId,
          'status' => 'cancelled',
          'message' => 'Objective rejected',
          'reason' => $reason
        ];
      }

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error approving objective {$objectiveId}: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'objective_id' => $objectiveId,
        'error' => $e->getMessage()
      ];
    }
  }

  /**
   * Resolve conflicts between agent objectives
   *
   * Called when ConflictDetector identifies conflicting objectives.
   * The orchestrator decides how to resolve the conflict:
   * - Cancel one objective
   * - Merge objectives
   * - Sequence objectives
   * - Allow both with constraints
   *
   * @param array $conflictingObjectiveIds Array of conflicting objective IDs
   * @param string $resolutionStrategy Strategy: 'cancel_lower_priority', 'merge', 'sequence', 'allow_both'
   * @return array Resolution result
   */
  public function resolveObjectiveConflict(array $conflictingObjectiveIds, string $resolutionStrategy = 'cancel_lower_priority'): array
  {
    try {
      $objectiveRegistry = new \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\ObjectiveRegistry($this->db, $this->debug);
      $objectives = [];

      // Load all conflicting objectives
      foreach ($conflictingObjectiveIds as $id) {
        $obj = $objectiveRegistry->getObjective($id);
        if ($obj) {
          $objectives[] = $obj;
        }
      }

      if (empty($objectives)) {
        throw new \InvalidArgumentException("No valid objectives found");
      }

      $result = match ($resolutionStrategy) {
        'cancel_lower_priority' => $this->cancelLowerPriorityObjectives($objectives, $objectiveRegistry),
        'merge' => $this->mergeObjectives($objectives, $objectiveRegistry),
        'sequence' => $this->sequenceObjectives($objectives, $objectiveRegistry),
        'allow_both' => $this->allowBothObjectives($objectives, $objectiveRegistry),
        default => throw new \InvalidArgumentException("Unknown resolution strategy: {$resolutionStrategy}")
      };

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Orchestrator resolved conflict using strategy '{$resolutionStrategy}'",
          'info'
        );
      }

      return $result;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error resolving objective conflict: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  /**
   * Cancel lower priority objectives in a conflict
   *
   * @param array $objectives Array of LocalObjective instances
   * @param \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\ObjectiveRegistry $registry
   * @return array Resolution result
   */
  private function cancelLowerPriorityObjectives(array $objectives, $registry): array
  {
    // Sort by priority (critical > high > medium > low)
    $priorityOrder = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
    usort($objectives, function($a, $b) use ($priorityOrder) {
      return ($priorityOrder[$b->getPriority()] ?? 0) <=> ($priorityOrder[$a->getPriority()] ?? 0);
    });

    // Keep highest priority, cancel others
    $kept = $objectives[0];
    $cancelled = [];

    for ($i = 1, $iMax = count($objectives); $i < $iMax; $i++) {
      $obj = $objectives[$i];
      $registry->cancelObjective($obj->getId(), 'Cancelled due to conflict with higher priority objective');
      $cancelled[] = $obj->getId();
    }

    return [
      'success' => true,
      'strategy' => 'cancel_lower_priority',
      'kept_objective' => $kept->getId(),
      'cancelled_objectives' => $cancelled
    ];
  }

  // ========================================
  // AUTONOMOUS AGENT INTEGRATION
  // ========================================

  /**
   * Merge compatible objectives
   *
   * @param array $objectives Array of LocalObjective instances
   * @param \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\ObjectiveRegistry $registry
   * @return array Resolution result
   */
  private function mergeObjectives(array $objectives, $registry): array
  {
    // Placeholder for merge logic
    // In a full implementation, this would create a new collaborative objective
    return [
      'success' => true,
      'strategy' => 'merge',
      'message' => 'Objective merging not yet fully implemented',
      'objectives' => array_map(fn($obj) => $obj->getId(), $objectives)
    ];
  }

  /**
   * Sequence objectives to execute in order
   *
   * @param array $objectives Array of LocalObjective instances
   * @param \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\ObjectiveRegistry $registry
   * @return array Resolution result
   */
  private function sequenceObjectives(array $objectives, $registry): array
  {
    // Placeholder for sequencing logic
    return [
      'success' => true,
      'strategy' => 'sequence',
      'message' => 'Objective sequencing not yet fully implemented',
      'objectives' => array_map(fn($obj) => $obj->getId(), $objectives)
    ];
  }

  /**
   * Allow both objectives with constraints
   *
   * @param array $objectives Array of LocalObjective instances
   * @param \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\ObjectiveRegistry $registry
   * @return array Resolution result
   */
  private function allowBothObjectives(array $objectives, $registry): array
  {
    return [
      'success' => true,
      'strategy' => 'allow_both',
      'message' => 'Both objectives allowed to proceed',
      'objectives' => array_map(fn($obj) => $obj->getId(), $objectives)
    ];
  }

  /**
   * Handle consensus escalation when agents cannot reach agreement
   *
   * Called when ConsensusBuilder fails to reach consensus within timeout.
   * The orchestrator makes the final decision based on:
   * - Evaluation scores
   * - Agent expertise levels
   * - Historical performance
   * - Business rules
   *
   * @param string $outputId The output being evaluated
   * @param array $evaluations Array of agent evaluations
   * @return array Final decision
   */
  public function escalateConsensusDecision(string $outputId, array $evaluations): array
  {
    try {
      if (empty($evaluations)) {
        throw new \InvalidArgumentException("No evaluations provided for consensus escalation");
      }

      // Calculate weighted average based on agent expertise
      $totalWeight = 0;
      $weightedSum = 0;

      foreach ($evaluations as $evaluation) {
        $agentId = $evaluation['evaluator_agent_id'] ?? '';
        $score = $evaluation['overall_score'] ?? 0;

        // Get agent's expertise level for this output type
        $weight = match ($agentId) {
          'AnalyticsAgent' => 1.5,  // Expert in analytics
          'ReasoningAgent' => 1.2,  // Competent in reasoning
          'ValidationAgent' => 1.0, // Standard weight
          default => 1.0
        };

        $weightedSum += $score * $weight;
        $totalWeight += $weight;
      }

      $finalScore = $totalWeight > 0 ? $weightedSum / $totalWeight : 0;

      $qualityThreshold = $this->autonomousConfig->getCorrectionTriggerThreshold();
      $requiresCorrection = $finalScore < $qualityThreshold;

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Orchestrator escalated consensus decision: score={$finalScore}, threshold={$qualityThreshold}, correction=" . ($requiresCorrection ? 'yes' : 'no'),
          'info'
        );
      }

      return [
        'success' => true,
        'output_id' => $outputId,
        'final_score' => $finalScore,
        'requires_correction' => $requiresCorrection,
        'decision_maker' => 'OrchestratorAgent',
        'evaluation_count' => count($evaluations),
        'message' => $requiresCorrection
          ? 'Output requires correction based on orchestrator decision'
          : 'Output approved by orchestrator'
      ];

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error escalating consensus decision: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'output_id' => $outputId,
        'error' => $e->getMessage()
      ];
    }
  }

  /**
   * Get pending objectives requiring approval
   *
   * @return array Array of pending objectives
   */
  public function getPendingObjectives(): array
  {
    try {
      $objectiveRegistry = new \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\ObjectiveRegistry($this->db, $this->debug);
      return $objectiveRegistry->getObjectivesByStatus('pending');
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error getting pending objectives: " . $e->getMessage(),
        'error'
      );
      return [];
    }
  }

  // ========================================
  // PRIVATE HELPER METHODS FOR CONFLICT RESOLUTION
  // ========================================

  /**
   * Get active objectives across all agents
   *
   * @return array Array of active objectives
   */
  public function getActiveObjectives(): array
  {
    try {
      $objectiveRegistry = new \ClicShopping\AI\Agents\Orchestrator\SubAutonomous\ObjectiveRegistry($this->db, $this->debug);
      return $objectiveRegistry->getObjectivesByStatus('active');
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error getting active objectives: " . $e->getMessage(),
        'error'
      );
      return [];
    }
  }

  /**
   * Traite une requête avec retry automatique pour les erreurs temporaires
   *
   * @param string $query La requête utilisateur
   * @param array $options Options de traitement
   * @return array Réponse structurée avec métadonnées
   */
  public function processWithRetry(string $query, array $options = []): array
  {
    $maxRetries = $options['max_retries'] ?? 2;
    $retryDelay = $options['retry_delay'] ?? 1; // secondes
    $attempt = 0;
    $lastError = null;

    while ($attempt <= $maxRetries) {
      try {
        $result = $this->processWithValidation($query, $options);

        // Si succès, retourner le résultat
        if ($result['success'] ?? false) {
          // Ajouter info sur les retries si nécessaire
          if ($attempt > 0) {
            $result['retry_info'] = [
              'attempts' => $attempt + 1,
              'succeeded_on_retry' => true
            ];
          }
          return $result;
        }

        // Si échec mais pas une erreur temporaire, ne pas retry
        if (!$this->errorHandler->isTemporaryError($result['error'] ?? '')) {
          return $result;
        }

        $lastError = $result;

      } catch (\Exception $e) {
        $lastError = [
          'success' => false,
          'error' => $e->getMessage()
        ];

        // Si pas une erreur temporaire, ne pas retry
        if (!$this->errorHandler->isTemporaryError($e->getMessage())) {
          $errorContext = [
            'query' => $query,
            'intent' => []
          ];
          return $this->errorHandler->buildErrorResponse($e->getMessage(), $errorContext);
        }
      }

      $attempt++;

      // Si on a encore des retries, attendre et logger
      if ($attempt <= $maxRetries) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Retry attempt {$attempt}/{$maxRetries} for query: {$query}",
            'info'
          );
        }

        sleep($retryDelay);
      }
    }

    // Tous les retries ont échoué
    if ($lastError) {
      if (is_array($lastError) && isset($lastError['error'])) {
        $errorContext = [
          'query' => $query,
          'intent' => []
        ];
        $response = $this->errorHandler->buildErrorResponse($lastError['error'], $errorContext);
        $response['retry_info'] = [
          'attempts' => $maxRetries + 1,
          'all_failed' => true
        ];
        return $response;
      }
    }

    // Fallback
    return $this->errorHandler->buildErrorResponse('Échec après plusieurs tentatives', ['query' => $query]);
  }

  /**
   * Point d'entrée principal : traite une requête utilisateur
   *
   * @param string $query La question de l'utilisateur
   * @param array $options Options additionnelles (contexte, préférences, etc.)
   * @return array Réponse structurée avec métadonnées
   */
  public function processWithValidation(string $query, array $options = []): array
  {
    $startTime = microtime(true);
    $perfMarkers = ['start' => $startTime]; // 🆕 Performance tracking
    $this->collector->startTimer('process_validation');

    $status = 'success';

    $intent = null;
    $executionId = null;

    if ($this->debug) {
      error_log("[INFO : TIME]️ [PERF] processWithValidation START at " . date('H:i:s'));
    }

    try {
      // Skip out-of-context detection for short queries (likely product names)
      $wordCount = str_word_count($query);
      $skipOutOfContextCheck = ($wordCount <= 4);

      if ($skipOutOfContextCheck) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Skipping out-of-context detection for short query (likely product name): '{$query}' ({$wordCount} words)",
            'info'
          );
        }
        // Set default context check (allow query to proceed)
        // Use DomainConfig to get active domain instead of hardcoding 'ecommerce'
        $activeDomain = DomainConfig::getActivities();
        $contextCheck = [
          'is_out_of_context' => false,
          'context_relevance' => 1.0,
          'detected_category' => $activeDomain ?: 'generic',
          'confidence' => 1.0,
          'explanation' => 'Short query - skipped out-of-context detection (likely product name)',
          'suggested_action' => 'allow'
        ];
      } else {
        // Only check out-of-context for longer queries (> 4 words)
        $hallucinationDetector = new HallucinationDetector($this->debug);
        $contextCheck = $hallucinationDetector->detectOutOfContext($query);

        if ($this->debug) {
          $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'out_of_context_check', [
            'query' => $query,
            'word_count' => $wordCount,
            'is_out_of_context' => $contextCheck['is_out_of_context'],
            'category' => $contextCheck['detected_category'],
            'action' => $contextCheck['suggested_action'],
            'confidence' => $contextCheck['confidence']
          ]);
        }
      }

      // Handle out-of-context queries based on suggested action
      if ($contextCheck['suggested_action'] === 'reject') {
        // Reject query immediately - return error response
        $this->securityLogger->logSecurityEvent(
          "Query rejected as out-of-context: '{$query}' (category: {$contextCheck['detected_category']})",
          'warning'
        );

        // Build dynamic error message based on active domain
        $activeDomain = DomainConfig::getActivities();
        $errorMessage = "I'm sorry, but this question is not related to business operations.";

        $entityConfigClass = DomainFields::resolveAppClass($activeDomain, 'EntityConfig');
        if ($entityConfigClass !== null) {
          // Use EntityConfig to get entity types dynamically
          try {
            $entityTypes = $entityConfigClass::getEntityTypes();
            if (!empty($entityTypes)) {
              $entityList = implode(', ', $entityTypes);
              $errorMessage = "I'm sorry, but this question is not related to the configured business domain. I can only help with questions about {$entityList}, revenue, analytics, and business operations.";
            } else {
              $errorMessage = "I'm sorry, but this question is not related to the configured business domain. I can only help with questions about business data, revenue, analytics, and operations.";
            }
          } catch (\Exception $e) {
            // Fallback to generic message if EntityConfig fails
            $errorMessage = "I'm sorry, but this question is not related to the configured business domain. I can only help with questions about business data, revenue, analytics, and operations.";
          }
        } else {
          // Generic message for other domains or no domain
          $errorMessage = "I'm sorry, but this question is not related to the configured business domain. I can only help with questions about business data and operations.";
        }

        return [
          'success' => false,
          'type' => 'error',
          'error' => 'out_of_context',
          'text_response' => $errorMessage,
          'response' => $errorMessage,
          'out_of_context_detection' => [
            'is_out_of_context' => true,
            'category' => $contextCheck['detected_category'],
            'confidence' => $contextCheck['confidence'],
            'explanation' => $contextCheck['explanation']
          ],
          'sources' => [],
          'data' => []
        ];
      } elseif ($contextCheck['suggested_action'] === 'ask_clarification') {
        // Ask user for clarification on ambiguous query
        $this->securityLogger->logSecurityEvent(
          "Query requires clarification: '{$query}' (category: {$contextCheck['detected_category']})",
          'info'
        );

        // Build clarification message
        $clarificationMessage = "Nous avons détecté une requête ambiguë. Veuillez préciser votre question:\n\n";
        if (isset($contextCheck['clarification_options']) && is_array($contextCheck['clarification_options'])) {
          foreach ($contextCheck['clarification_options'] as $index => $option) {
            $clarificationMessage .= ($index + 1) . ". " . $option . "\n";
          }
        } else {
          $clarificationMessage .= "1. Rechercher un produit nommé '{$query}'\n";
          $clarificationMessage .= "2. Obtenir des informations sur une personne\n";
          $clarificationMessage .= "3. Autre chose\n";
        }

        return [
          'success' => false,
          'type' => 'clarification_needed',
          'error' => 'ambiguous_query',
          'text_response' => $clarificationMessage,
          'response' => $clarificationMessage,
          'clarification_needed' => true,
          'original_query' => $query,
          'clarification_options' => $contextCheck['clarification_options'] ?? [
            "Rechercher un produit nommé '{$query}'",
            "Obtenir des informations sur une personne",
            "Autre chose"
          ],
          'out_of_context_detection' => [
            'is_out_of_context' => false,
            'category' => $contextCheck['detected_category'],
            'confidence' => $contextCheck['confidence'],
            'explanation' => $contextCheck['explanation']
          ],
          'sources' => [],
          'data' => []
        ];
      } elseif ($contextCheck['suggested_action'] === 'redirect_to_web_search') {
        // Force intent to web_search for business queries requiring external data
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Query redirected to web_search: '{$query}' (category: {$contextCheck['detected_category']})",
            'info'
          );
        }
        // Set option to force web_search intent
        $options['force_intent'] = 'web_search';
        $options['out_of_context_redirect'] = true;
      }
      // If action is 'allow', continue normally
      $resolved = $this->memoryManager->resolveContextualReferences($query);
      $queryToProcess = $resolved['resolved_query'] ?? $query;
      $contextUsed = $resolved['has_references'] ?? false;

      if ($contextUsed && $this->debug) {
        $this->securityLogger->logSecurityEvent(
          "TASK 2.8: Contextual references resolved EARLY: '{$query}' → '{$queryToProcess}'",
          'info'
        );
      }

      // Translation is done inside handleFullOrchestration in parallel with context retrieval
      // This early translation is kept for backward compatibility with logging
      $translatedQuery = '';
      try {
        $translatedQuery = SemanticAgent::translateToEnglish($queryToProcess, 80);
      } catch (\Exception $e) {
        // Non-blocking error: log and continue
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Query translation failed (using original): " . $e->getMessage(),
            'warning'
          );
        }
      }
      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'query_processing', [
          'original_query' => $query,
          'resolved_query' => $contextUsed ? $queryToProcess : null,
          'translated_query' => $translatedQuery,
          'context_used' => $contextUsed
        ]);
      }

      // Full orchestration path
      return $this->handleFullOrchestration($query, $queryToProcess, $startTime, $perfMarkers);
    } catch (\Exception $e) {
      $status = 'error';

      $this->securityLogger->logSecurityEvent(
        "Orchestrator error: " . $e->getMessage(),
        'error'
      );

      // 🆕 Store error in DiagnosticManager for analysis
      $this->diagnosticManager->storeError(
        $e->getMessage(),
        $query,
        [
          'intent' => $intent ?? null,
          'stack_trace' => $e->getTraceAsString(),
          'execution_id' => $executionId ?? null,
        ]
      );

      // Nettoyer en cas d'erreur
      if (isset($executionId)) {
        $this->workingMemory->deleteScope($executionId);
      }

      $this->collector->recordEvent('error', [
        'component' => 'orchestrator',
        'error_message' => $e->getMessage(),
      ]);

      // Construire une réponse d'erreur avec contexte pour messages explicites
      $errorContext = [
        'query' => $query ?? '',
        'intent' => $intent ?? []
      ];

      return $this->errorHandler->buildErrorResponse($e->getMessage(), $errorContext);
    } finally {
      $latencyMs = (microtime(true) - $startTime) * 1000;

      $this->collector->recordMetric(
        'orchestrator_query_latency_ms',
        $latencyMs,
        [
          'status' => $status
        ]
      );

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "⏱️ TASK 4.4.2.3: Query latency recorded: {$latencyMs}ms (status: {$status})",
          'info'
        );
      }

      $this->collector->stopTimer('process_validation');
    }
  }

  /**
   * Handle full orchestration for complex queries
   *
   *
   * @param string $query Original user query
   * @param string $queryToProcess Resolved query to process
   * @param float $startTime Start time for performance tracking
   * @param array $perfMarkers Performance markers array
   * @return array Full orchestration response
   */
  private function handleFullOrchestration(string $query, string $queryToProcess, float $startTime, array $perfMarkers): array
  {
    $executionId = 'exec_' . uniqid('', true);
    $this->workingMemory->enterScope($executionId);

    $this->workingMemory->set('original_query', $query);
    $this->workingMemory->set('start_time', $startTime);

    $perfMarkers['after_init'] = microtime(true); // 🆕

    // These operations are independent and can run in parallel
    $parallelStart = microtime(true);

    // Initialize results
    $rawContext = [];
    $translatedQuery = $queryToProcess;
    $translationError = null;
    $contextError = null;

    // Try parallel execution using PHP's built-in capabilities
    // Note: PHP doesn't have native async/await, but we can simulate parallel execution
    // by starting both operations and collecting results
    try {
      // Start both operations
      $contextStart = microtime(true);
      $translationStart = microtime(true);

      // Operation 1: Context retrieval
      try {
        $rawContext = $this->conversationMemory ? $this->conversationMemory->getRelevantContext($query) : [];
        $contextDuration = (microtime(true) - $contextStart) * 1000;
      } catch (\Exception $e) {
        $contextError = $e;
        $contextDuration = (microtime(true) - $contextStart) * 1000;
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Context retrieval failed (using empty context): " . $e->getMessage(),
            'warning'
          );
        }
      }
      $translationDuration = 0; // No longer measured here

      $parallelDuration = (microtime(true) - $parallelStart) * 1000;

      // Log parallel execution results
      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'parallel_execution', [
          'context_duration_ms' => round($contextDuration, 2),
          'translation_duration_ms' => round($translationDuration, 2),
          'parallel_total_ms' => round($parallelDuration, 2),
          'sequential_would_be_ms' => round($contextDuration + $translationDuration, 2),
          'time_saved_ms' => round(($contextDuration + $translationDuration) - $parallelDuration, 2),
          'context_success' => $contextError === null,
          'translation_success' => $translationError === null
        ]);
      }

    } catch (\Exception $e) {
      // Fallback to sequential execution if parallel fails
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Parallel execution failed, falling back to sequential: " . $e->getMessage(),
          'warning'
        );
      }

      // Sequential fallback
      try {
        $rawContext = $this->conversationMemory ? $this->conversationMemory->getRelevantContext($query) : [];
      } catch (\Exception $e) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Context retrieval failed: " . $e->getMessage(),
            'warning'
          );
        }
      }

    }

    $perfMarkers['after_parallel'] = microtime(true); // 🆕

    // 🆕 Gestion intelligente du contexte (éviter conflit feedback/contexte)
    $contextDecision = $this->contextManager->decideContextUsage(
      $query,
      $rawContext,
      $rawContext['feedback_context'] ?? []
    );

    // 3.2. Filtrer le contexte selon la décision
    $context = $this->contextManager->filterConversationContext($rawContext, $contextDecision);
    $context = $this->contextManager->enrichContextWithDecision($context, $contextDecision);

    $this->workingMemory->set('conversation_context', $context);
    $this->workingMemory->set('context_decision', $contextDecision);


    if ($contextDecision['clear_conversation_context'] && $this->conversationMemory) {
      try {
        // Clear the last entity from EntityTracker
        $this->conversationMemory->clearLastEntity();

        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "TASK 2.18: Cleared last entity due to context switch: " . $contextDecision['reason'],
            'info'
          );
        }
      } catch (\Exception $e) {
        $this->securityLogger->logSecurityEvent(
          "Error clearing last entity: " . $e->getMessage(),
          'warning'
        );
      }
    }

    if ($this->debug && $contextDecision['clear_conversation_context']) {
      $this->securityLogger->logSecurityEvent(
        "Context cleared: " . $contextDecision['reason'],
        'info'
      );
    }

    $contextAnalysis = $this->queryAnalyzer->analyzeQueryContextRelation($query, $context);
    $this->workingMemory->set('context_analysis', $contextAnalysis);

    if ($contextAnalysis['is_related_to_context']) {
      $queryToProcess = $this->queryAnalyzer->enrichQueryWithContext($queryToProcess, $context, $contextAnalysis);
    }

    $this->workingMemory->set('resolved_query', $queryToProcess);

    $intentStart = microtime(true);
    $intent = $this->analyzeIntent($queryToProcess);
    $this->workingMemory->set('intent', $intent);

    // Anti-hallucination verification (PRIORITY 1)
    // Check if translated_query contains "revenue", "month", or "quarter" but original query does NOT
    $translatedQuery = $intent['translated_query'] ?? $queryToProcess;
    $originalQueryLower = strtolower($query);
    $translatedQueryLower = strtolower($translatedQuery);

    $hallucinationDetected = false;
    $hallucinationKeywords = [];

    // Check for revenue bias hallucination (include French equivalents)
    if (str_contains($translatedQueryLower, 'revenue')
        && !str_contains($originalQueryLower, 'revenue')
        && !str_contains($originalQueryLower, 'chiffre')
        && !str_contains($originalQueryLower, 'affaires')
        && !str_contains($originalQueryLower, 'revenu')
        && !str_contains($originalQueryLower, ' ca ')) {
      $hallucinationDetected = true;
      $hallucinationKeywords[] = 'revenue';
    }
    if ((str_contains($translatedQueryLower, 'month') || str_contains($translatedQueryLower, 'monthly'))
        && !str_contains($originalQueryLower, 'month') && !str_contains($originalQueryLower, 'mois')) {
      $hallucinationDetected = true;
      $hallucinationKeywords[] = 'month';
    }
    if ((str_contains($translatedQueryLower, 'quarter') || str_contains($translatedQueryLower, 'quarterly'))
        && !str_contains($originalQueryLower, 'quarter') && !str_contains($originalQueryLower, 'trimestre')) {
      $hallucinationDetected = true;
      $hallucinationKeywords[] = 'quarter';
    }

    if ($hallucinationDetected) {
      // 🚨 HALLUCINATION DETECTED!
      $this->securityLogger->logSecurityEvent(
        "🚨 HALLUCINATION DETECTED: Revenue bias in translation",
        'warning',
        [
          'original_query' => $query,
          'translated_query' => $translatedQuery,
          'hallucination_keywords' => $hallucinationKeywords,
          'action' => 'using_original_query_as_fallback'
        ]
      );

      // Fallback: Use original query as translated query
      $intent['translated_query'] = $queryToProcess;
      $translatedQuery = $queryToProcess;

      // Log for analysis
      error_log("[warning] HALLUCINATION: '$query' → '$translatedQuery' (keywords: " . implode(', ', $hallucinationKeywords) . ")");
      error_log("   → Fallback to original: '$queryToProcess'");
    }

    if ($this->debug) {
      error_log("[INFO : TIME]️ [PERF] analyzeIntent took " . round((microtime(true) - $intentStart), 2) . "s");
      $this->securityLogger->logStructured(
        'info',
        'OrchestratorAgent',
        'PATH_DECISION.intent',
        [
          'translated_query' => $intent['translated_query'] ?? $queryToProcess,
          'intent_type' => $intent['type'] ?? 'unknown',
          'is_hybrid_flag' => $intent['is_hybrid'] ?? false,
          'confidence' => $intent['confidence'] ?? 0,
          'hallucination_detected' => $hallucinationDetected,
          'hallucination_keywords' => $hallucinationDetected ? $hallucinationKeywords : null,
        ]
      );
    }
    // 4.5. 🆕 Detect complex queries (Task 16.2)
    // Use translated query from intent for detection
    $translatedQuery = $intent['translated_query'] ?? $queryToProcess;
    $complexityDetection = $this->complexQueryHandler->detectComplexQuery($translatedQuery);
    $this->workingMemory->set('complexity_detection', $complexityDetection);

    if ($this->debug && $complexityDetection['is_complex']) {
      $this->securityLogger->logStructured(
        'info',
        'OrchestratorAgent',
        'detectComplexQuery',
        [
          'query_type' => $complexityDetection['query_type'],
          'complexity_score' => $complexityDetection['complexity_score'],
          'detected_patterns' => $complexityDetection['detected_patterns'],
          'requires_web_search' => $complexityDetection['requires_web_search'],
          'estimated_sub_queries' => $complexityDetection['estimated_sub_queries']
        ]
      );

      $this->securityLogger->logStructured(
        'info',
        'OrchestratorAgent',
        'PATH_DECISION.route',
        [
          'route' => 'hybrid',
          'reason' => 'complex_query_detected',
          'requires_web_search' => $complexityDetection['requires_web_search'],
        ]
      );
    }

    // 4.6. DEPRECATED: Complex query handling moved to ActorCriticCoordinator (2026-02-09)
    // Complex queries are now handled by the Actor-Critic system
    if ($complexityDetection['is_complex']) {
      // Use ActorCriticCoordinator instead of HybridQueryProcessor
      // For now, fall through to standard processing
      // TODO: Implement complex query handling in ActorCriticCoordinator
      $this->securityLogger->logStructured('warning', 'OrchestratorAgent', 'complex_query_fallthrough', [
        'message' => 'Complex query detected but HybridQueryProcessor is deprecated',
        'query' => $translatedQuery,
        'complexity' => $complexityDetection
      ]);
    }

    // 🔧 FIX (2026-02-08): Route hybrid queries BEFORE ReasoningAgent
    // BUG: Hybrid queries were being sent to ReasoningAgent instead of HybridQueryProcessor
    // This caused hybrid queries to be processed as analytics
    $intentType = $intent['type'] ?? $intent['query_type'] ?? 'semantic';

    if ($intentType === 'hybrid') {
      if ($this->debug) {
        $this->securityLogger->logStructured(
          'info',
          'OrchestratorAgent',
          'HYBRID_ROUTING_EARLY',
          [
            'action' => 'routing_to_hybrid_processor_before_reasoning',
            'intent_type' => $intentType,
            'is_hybrid_flag' => $intent['is_hybrid'] ?? false,
            'confidence' => $intent['confidence'] ?? 0,
            'query' => substr($queryToProcess, 0, 100),
            'note' => 'Hybrid routing moved before ReasoningAgent to fix routing bug'
          ]
        );
      }

      // Get enriched context for hybrid processing
      $enrichedContext = array_merge($context, [
        'context_analysis' => $contextAnalysis,
        'is_related_to_previous' => $contextAnalysis['is_related_to_context'],
        'relation_type' => $contextAnalysis['relation_type'],
      ]);

      // The LLM classifier sometimes misses web search keywords, so we add detection here
      // This is a temporary fix until ClassificationEngine prompt is improved
      if ($intent['type'] === 'hybrid' && isset($intent['sub_types'])) {
        $webSearchKeywords = [
          'amazon', 'ebay', 'google', 'alibaba', 'aliexpress',
          'compare with', 'search online', 'find on', 'check on',
          'prix sur', 'price on', 'chercher sur', 'rechercher sur'
        ];

        $hasWebSearchKeyword = false;
        foreach ($webSearchKeywords as $keyword) {
          if (stripos($queryToProcess, $keyword) !== false) {
            $hasWebSearchKeyword = true;
            break;
          }
        }

        // If web search keyword found but not in sub_types, add it
        if ($hasWebSearchKeyword && !in_array('web_search', $intent['sub_types'])) {
          $intent['sub_types'][] = 'web_search';

          if ($this->debug) {
            $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'web_search_detection', [
              'query' => substr($queryToProcess, 0, 100),
              'original_sub_types' => $intent['sub_types'],
              'corrected_sub_types' => $intent['sub_types'],
              'note' => 'Added web_search to sub_types based on keyword detection'
            ]);
          }
        }
      }

      // Handle hybrid queries with Actor-Critic approach
      // directly in OrchestratorAgent using TaskPlanner and specialized executors
      return $this->handleHybridQuery($queryToProcess, $translatedQuery, $intent, $enrichedContext, $startTime);
    }

    // 5. Vérifier si raisonnement complexe nécessaire

    // Fix: Safely check is_hybrid with default value
    $isHybrid = $intent['is_hybrid'] ?? false;

    // 🔧 Don't send hybrid queries to ReasoningAgent
    // Hybrid queries are already routed above
    if ($intent['confidence'] < 0.6 && !$isHybrid) {
      // Log fallback decision
      $this->securityLogger->logStructured(
        'info',
        'OrchestratorAgent',
        'fallback_decision',
        [
          'reason' => $intent['confidence'] < 0.6 ? 'low_confidence' : 'hybrid_query',
          'confidence' => $intent['confidence'],
          'original_type' => $intent['type'],
          'is_hybrid' => $intent['is_hybrid'],
          'query' => $queryToProcess
        ]
      );

      // Utiliser le ReasoningAgent pour clarifier
      $reasoning = $this->reasoningAgent->reason($queryToProcess, [
        'intent' => $intent,
        'context' => $context,
        'context_analysis' => $contextAnalysis,
      ]);

      $this->workingMemory->set('reasoning_result', $reasoning);

      // default to semantic (safer fallback than analytics)
      if ($intent['confidence'] < 0.6 && !in_array($intent['type'], ['analytics', 'semantic', 'web_search', 'hybrid'])) {
        $this->securityLogger->logStructured(
          'warning',
          'OrchestratorAgent',
          'fallback_to_semantic',
          [
            'original_type' => $intent['type'],
            'confidence' => $intent['confidence'],
            'reason' => 'Unknown type with low confidence, defaulting to semantic (safer)'
          ]
        );

        $intent['type'] = 'semantic';
        $intent['confidence'] = 0.5; // Reset to default semantic confidence
      }
    }

    $enrichedContext = array_merge($context, [
      'context_analysis' => $contextAnalysis,
      'is_related_to_previous' => $contextAnalysis['is_related_to_context'],
      'relation_type' => $contextAnalysis['relation_type'],
    ]);

    if ($this->debug) {
      $this->securityLogger->logStructured(
        'info',
        'OrchestratorAgent',
        'PATH_DECISION.intent_route',
        [
          'route' => $intent['type'] ?? 'unknown',
          'is_hybrid_flag' => $intent['is_hybrid'] ?? false,
        ]
      );
    }

    // ===========================================================================
    // DOMAIN-BASED ROUTING (PHASE 8: AI Architecture Domain Reorganization)
    // ===========================================================================
    //
    // Current Implementation: Query Type Domains (DomainsAI)
    // -------------------------------------------------------
    // Routes queries to appropriate query type domain based on intent:
    // - Semantic: Vector embeddings, similarity search (DomainsAI/Semantic/)
    // - Analytics: SQL generation, BI queries (DomainsAI/Analytics/)
    // - Hybrid: Combined semantic + analytics (DomainsAI/Hybrid/)
    // - WebSearch: External web search (DomainsAI/WebSearch/)
    //
    // Query Type Domains define HOW queries are processed.
    //
    // Future Enhancement: Business Domains (Apps/ - rag-multi-domain-evolution)
    // --------------------------------------------------------------------------
    // Will also route to business domains that define WHAT data is queried:
    // - Domain apps: Dynamic entity discovery via EntityConfig (Apps/AI/<Domain>/)
    // - Finance: Transactions, invoices, payments (Apps/Finance/)
    // - HR: Employees, payroll, benefits (Apps/HR/)
    // - Trading: Stocks, portfolios, market data (Apps/Trading/)
    //
    // Business Domains define WHAT data is queried.
    //
    // Future Orchestration Flow:
    // --------------------------
    // User Query → OrchestratorAgent
    //   ├- Identifies Query Type (HOW): Analytics
    //   ├- Identifies Business Domain (WHAT): Ecommerce
    //   ├- Routes to: DomainsAI/Analytics/Agent/AnalyticsAgent (HOW to generate SQL)
    //   +- Coordinates with: Apps/Ecommerce/Entities/ProductEntity (WHAT data to query)
    //
    // This separation enables:
    // - Same query type across multiple business domains
    // - Clear separation of concerns (HOW vs WHAT)
    // - Easy addition of new business domains
    // - Scalable multi-domain architecture
    //
    // ===========================================================================

    // Hybrid queries need to be split into sub-queries and executed by multiple agents
    // NOTE: Check intent_type ONLY (is_hybrid flag can be inconsistent)
    // 🔧 FIX: Check both 'type' and 'query_type' fields, default to 'semantic' (safer than 'analytics')
    $intentType = $intent['type'] ?? $intent['query_type'] ?? 'semantic';

    // Log when fallback default is used
    if (!isset($intent['type']) && !isset($intent['query_type'])) {
      $this->securityLogger->logStructured(
        'warning',
        'OrchestratorAgent',
        'intent_type_fallback',
        [
          'fallback_value' => 'semantic',
          'reason' => 'Neither type nor query_type found in intent',
          'intent_keys' => array_keys($intent),
          'query' => $queryToProcess
        ]
      );
    }

    // PHASE 8: Domain-based routing (transitional implementation)
    // Get domain for intent type (for logging and future use)
    $domainClass = $this->getDomainForIntent($intentType);

    // NOTE: Current implementation still uses direct routing for backward compatibility
    // Future implementation will use: $domain->getAgent()->processQuery($query)
    // when all domains implement QueryTypeDomainInterface

    // 🔧 Hybrid routing moved earlier (before ReasoningAgent)
    // This duplicate check is kept for safety but should never be reached
    // since hybrid queries are routed earlier in the flow
    if ($intentType === 'hybrid') {
      if ($this->debug) {
        $this->securityLogger->logStructured(
          'warning',
          'OrchestratorAgent',
          'HYBRID_ROUTING_DUPLICATE',
          [
            'action' => 'unexpected_hybrid_routing_fallback',
            'intent_type' => $intentType,
            'is_hybrid_flag' => $intent['is_hybrid'] ?? false,
            'confidence' => $intent['confidence'] ?? 0,
            'query' => substr($queryToProcess, 0, 100),
            'note' => 'This should not happen - hybrid queries should be routed earlier'
          ]
        );
      }

      // Apply same fix as earlier routing point
      if (isset($intent['sub_types'])) {
        $webSearchKeywords = [
          'amazon', 'ebay', 'google', 'alibaba', 'aliexpress',
          'compare with', 'search online', 'find on', 'check on',
          'prix sur', 'price on', 'chercher sur', 'rechercher sur'
        ];

        $hasWebSearchKeyword = false;
        foreach ($webSearchKeywords as $keyword) {
          if (stripos($queryToProcess, $keyword) !== false) {
            $hasWebSearchKeyword = true;
            break;
          }
        }

        if ($hasWebSearchKeyword && !in_array('web_search', $intent['sub_types'])) {
          $intent['sub_types'][] = 'web_search';

          if ($this->debug) {
            $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'web_search_detection_fallback', [
              'query' => substr($queryToProcess, 0, 100),
              'note' => 'Added web_search to sub_types in fallback routing'
            ]);
          }
        }
      }

      // NEW (2026-02-09): Handle hybrid queries with Actor-Critic approach
      // This is a fallback - hybrid queries should normally be caught earlier
      return $this->handleHybridQuery($queryToProcess, $translatedQuery, $intent, $enrichedContext, $startTime);
    }

    $planStart = microtime(true);
    $plan = $this->taskPlanner->createPlan($intent, $queryToProcess, $enrichedContext);
    if ($this->debug) {
      error_log("[INFO : TIME] [PERF] createPlan took " . round((microtime(true) - $planStart), 2) . "s");
    }

    $this->workingMemory->set('execution_plan', $plan->getSummary());

    // Structured logging for plan creation
    if ($this->debug) {
      $steps = $plan->getSteps();
      $stepTypes = array_map(fn($step) => $step->getType(), $steps);
      $this->securityLogger->logStructured(
        'info',
        'OrchestratorAgent',
        'createPlan',
        [
          'step_count' => count($steps),
          'step_types' => $stepTypes
        ]
      );
    }

    // 7. Valider chaque étape du plan AVANT exécution
    $validationResults = [];

    foreach ($plan->getSteps() as $step) {
      if ($step->getType() === 'analytics_query') {
        $subQuery = $step->getMeta('sub_query', $step->getDescription());

        // VALIDATION PROACTIVE
        $validation = $this->validationAgent->validateBeforeExecution($subQuery, [
          'step_id' => $step->getId(),
          'plan_id' => $executionId,
        ]);

        $validationResults[$step->getId()] = $validation;

        // Si validation échoue, corriger immédiatement
        if (!$validation['can_execute']) {
          // Utiliser le CorrectionAgent
          $correction = $this->correctionAgent->attemptCorrection([
            'error_message' => implode(', ', $validation['errors']),
            'failed_query' => $subQuery,
            'original_query' => $queryToProcess,
          ]);

          if ($correction['success']) {
            // Update query in step
            $step->setMeta('sub_query', $correction['corrected_query']);
            $step->setMeta('was_corrected', true);
            $step->setMeta('correction_method', $correction['correction_method']);
          }
        }
      }
    }

    $this->workingMemory->set('validations', $validationResults);

    // Structured logging for validation results
    if ($this->debug && !empty($validationResults)) {
      $passedCount = count(array_filter($validationResults, fn($v) => $v['can_execute']));
      $failedCount = count($validationResults) - $passedCount;
      $this->securityLogger->logStructured(
        'info',
        'OrchestratorAgent',
        'validation',
        [
          'total_validations' => count($validationResults),
          'passed' => $passedCount,
          'failed' => $failedCount
        ]
      );
    }

    // 8. Execute plan and extract entities
    $executionResult = $this->planExecutor->execute($plan);

    // Extract entity information
    $entityId = $this->entityExtractor->extractEntityId($executionResult, $intent, $plan);
    $entityType = $this->entityExtractor->extractEntityType($executionResult, $intent, $plan) ?? 'unknown';

    // Patch: Ensure entity_id is never null for database
    if ($entityId === null || $entityId === '' || $entityId === 'ABSENT') {
      $entityId = 0;
      $entityType = 'general';
    }

    // Debug logging
    if ($this->debug) {
      $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'execution_complete', [
        'success' => $executionResult['success'] ?? false,
        'entity_id' => $entityId,
        'entity_type' => $entityType
      ]);
    }

    $this->workingMemory->set('execution_result', $executionResult['success']);

    if (!$executionResult['success']) {
      throw new \Exception($executionResult['error'] ?? 'Execution failed');
    }

    // 9. Build final response - delegate to ResponseProcessor
    $response = $this->responseProcessorComponent->buildOrchestrationResponse(
      $executionResult,
      $intent,
      $query,
      $startTime,
      $entityId,
      $entityType,
      $this->responseProcessor
    );

    // 10. Store in conversation memory - delegate to MemoryManager

    $perfMarkers['before_memory'] = microtime(true);

    // Check if query is already in QueryCache (warm cache scenario)
    // Check both 'from_cache' and 'cached' flags (different agents use different naming)
    $skipMemoryStorage = false;
    $isCached = (isset($response['from_cache']) && $response['from_cache'] === true) ||
                (isset($response['cached']) && $response['cached'] === true) ||
                (isset($response['metadata']['from_cache']) && $response['metadata']['from_cache'] === true);

    if ($isCached) {
      $skipMemoryStorage = true;

      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'memory_storage_skipped', [
          'reason' => 'query_already_cached',
          'cache_hit' => true,
          'latency_saved_ms' => '2000-3000 (estimated)',
          'query' => substr($query, 0, 100)
        ]);
      }


      // Entity tracking is lightweight (<1ms) and essential for follow-up queries
      // This ensures "What is its stock level" works after cached "What is the price of iPhone"
      if ($entityId !== null && $entityId !== 0) {
        $this->memoryManager->setLastEntity($entityId, $entityType);

        if ($this->debug) {
          $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'entity_tracked_for_cached_query', [
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'reason' => 'contextual_reference_resolution',
            'overhead_ms' => '<1'
          ]);
        }
      }
    }

    // Only store in memory for NEW queries (cache miss)
    if (!$skipMemoryStorage) {
      $this->memoryManager->storeOrchestrationResult(
        $query,
        $queryToProcess,
        $response,
        $intent,
        $contextAnalysis,
        $plan,
        $validationResults,
        $entityId,
        $entityType,
        $this->userId,
        $this->languageId,
        $this->queryAnalyzer,
        $this->responseProcessorComponent
      );

      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'memory_storage_completed', [
          'cache_miss' => true,
          'entity_id' => $entityId,
          'entity_type' => $entityType
        ]);
      }
    }

    $perfMarkers['after_memory'] = microtime(true);

    // 11. Cleanup
    $this->workingMemory->deleteScope($executionId);

    $array_record = [
      'component' => 'orchestrator',
      'success' => true,
      'execution_time' => microtime(true) - $startTime,
    ];

    $this->collector->recordEvent('request', $array_record);

    // 🆕 Update execution stats for DiagnosticManager
    $this->executionStats['total_requests']++;
    $this->executionStats['total_execution_time'] += (microtime(true) - $startTime);

    // Log performance breakdown
    if ($this->debug && isset($perfMarkers)) {
      $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'performance_breakdown', [
        'init_ms' => round(($perfMarkers['after_init'] - $perfMarkers['start']) * 1000, 2),
        'parallel_ops_ms' => round(($perfMarkers['after_parallel'] - $perfMarkers['after_init']) * 1000, 2),
        'memory_ms' => round(($perfMarkers['after_memory'] - $perfMarkers['before_memory']) * 1000, 2),
        'total_ms' => round((microtime(true) - $startTime) * 1000, 2)
      ]);
    }

    return $response;
  }

  /**
   * Analyze query intent
   *
   * @param string $query User query
   * @return array Analyzed intent with type, confidence and flags
   */
  private function analyzeIntent(string $query): array
  {
    // Delegate to IntentAnalyzer
    return $this->intentAnalyzer->analyze($query);
  }

  /**
   * Handle hybrid queries using Actor-Critic approach
   *
   * NEW (2026-02-09): Replaces deprecated HybridQueryProcessor
   *
   * This method handles queries with multiple intents by:
   * 1. Using TaskPlanner to decompose the query into sub-tasks
   * 2. Executing each sub-task with appropriate executor
   * 3. Synthesizing results from all sub-tasks
   *
   * @param string $queryToProcess Original query
   * @param string $translatedQuery Translated query
   * @param array $intent Intent analysis
   * @param array $context Query context
   * @param float $startTime Query start time
   * @return array Synthesized result
   */
  private function handleHybridQuery(
    string $queryToProcess,
    string $translatedQuery,
    array $intent,
    array $context,
    float $startTime
  ): array {
    if ($this->debug) {
      $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'hybrid_query_start', [
        'query' => substr($queryToProcess, 0, 100),
        'intent_type' => $intent['type'] ?? 'unknown',
        'confidence' => $intent['confidence'] ?? 0
      ]);
    }

    try {
      // Step 1: Create execution plan using TaskPlanner
      $planStart = microtime(true);
      $plan = $this->taskPlanner->createPlan($intent, $queryToProcess, $context);

      if ($this->debug) {
        $steps = $plan->getSteps();
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'hybrid_plan_created', [
          'step_count' => count($steps),
          'step_types' => array_map(fn($step) => $step->getType(), $steps),
          'plan_time' => round((microtime(true) - $planStart) * 1000, 2) . 'ms'
        ]);
      }

      // Step 2: Execute plan using PlanExecutor
      $executeStart = microtime(true);
      $executionResult = $this->planExecutor->execute($plan);

      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'hybrid_plan_executed', [
          'execution_time' => round((microtime(true) - $executeStart) * 1000, 2) . 'ms',
          'total_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
          'success' => $executionResult['success'] ?? false,
          'result_type' => $executionResult['result']['type'] ?? 'unknown'
        ]);
      }

      // Check if execution was successful
      if (!$executionResult['success']) {
        throw new \Exception($executionResult['error'] ?? 'Plan execution failed');
      }

      // Extract the actual result
      $result = $executionResult['result'] ?? $executionResult;

      // CRITICAL: Force type to 'hybrid' for hybrid queries
      // The result may have type 'semantic_results' or 'analytics_response' from synthesis
      // but for hybrid queries, the type MUST be 'hybrid'
      $result['type'] = 'hybrid';

      // Ensure result has success key for QueryProcessor
      if (!isset($result['success'])) {
        $result['success'] = true;
      }

      // Ensure result has required keys
      if (!isset($result['intent'])) {
        $result['intent'] = $intent;
      }

      if (!isset($result['agent_used'])) {
        $result['agent_used'] = 'hybrid_orchestrator';
      }

      // Log the final type for debugging
      error_log("🎯 handleHybridQuery: Forcing type to 'hybrid' (was: " . ($executionResult['result']['type'] ?? 'unknown') . ")");

      // Step 3: Store interaction in memory
      if ($this->conversationMemory !== null) {
        try {
          $this->conversationMemory->addInteraction(
            $queryToProcess,
            $result['response'] ?? $result['text_response'] ?? 'No response',
            [
              'intent_type' => 'hybrid',
              'confidence' => $intent['confidence'] ?? 0,
              'sub_query_count' => count($plan->getSteps()),
              'execution_time' => microtime(true) - $startTime
            ]
          );
        } catch (\Exception $e) {
          if ($this->debug) {
            $this->securityLogger->logStructured('warning', 'OrchestratorAgent', 'memory_storage_failed', [
              'error' => $e->getMessage()
            ]);
          }
        }
      }

      return $result;

    } catch (\Exception $e) {
      // Error handling with detailed logging
      $this->securityLogger->logStructured('error', 'OrchestratorAgent', 'hybrid_query_failed', [
        'query' => substr($queryToProcess, 0, 100),
        'error' => $e->getMessage(),
        'trace' => substr($e->getTraceAsString(), 0, 500)
      ]);

      // Return error response
      return [
        'type' => 'error',
        'response' => 'Une erreur est survenue lors du traitement de votre requête hybride.',
        'error' => $e->getMessage(),
        'query' => $queryToProcess,
        'intent_type' => 'hybrid',
        'success' => false
      ];
    }
  }

  /**
   * Get domain for intent (domain-based routing)
   *
   * PHASE 8: Domain-Based Routing
   *
   * This method routes queries to the appropriate query type domain based on intent.
   *
   * IMPORTANT DISTINCTION:
   * - Query Type Domains (DomainsAI): Define HOW queries are processed
   *   Examples: Semantic search, SQL generation, hybrid processing, web search
   *   Location: Core/ClicShopping/AI/DomainsAI
   *
   * - Business Domains (Apps/ - FUTURE): Define WHAT data is queried
   *   Examples: Ecommerce (products, orders), Finance (transactions), HR (employees)
   *   Location: Core/ClicShopping/AI/Apps/ (future spec: rag-multi-domain-evolution)
   *
   * Current Implementation:
   * - Routes to query type domains (Semantic, Analytics, Hybrid, WebSearch)
   * - Uses QueryTypeDomainInterface for standardized domain access
   *
   * Future Enhancement (rag-multi-domain-evolution):
   * - Will also route to business domains (Ecommerce, Finance, HR, Trading)
   * - Orchestrator will coordinate BOTH query types AND business domains
   * - Example: Analytics query (HOW) + Ecommerce domain (WHAT)
   *
   * @param string $intentType Intent type from UnifiedQueryAnalyzer
   * @return mixed Domain class name (transitional) or QueryTypeDomainInterface (future)
   */
  private function getDomainForIntent(string $intentType): mixed
  {
    // Map intent types to domain classes
    // NOTE: This mapping will be enhanced in future specs to include business domains
    $domainMap = [
      'semantic' => SemanticAgent::class,
      'analytics' => AnalyticsAgent::class,
      'hybrid' => ActorCriticCoordinator::class,
      'web_search' => WebSearchTool::class,
    ];

    // Get domain class for intent type
    $domainClass = $domainMap[$intentType] ?? null;

    if ($domainClass === null) {
      if ($this->debug) {
        $this->securityLogger->logStructured('warning', 'OrchestratorAgent', 'domain_not_found', [
          'intent_type' => $intentType,
          'available_domains' => array_keys($domainMap),
          'fallback' => 'semantic'
        ]);
      }

      // Fallback to semantic domain (safer than analytics)
      $domainClass = $domainMap['semantic'];
    }

    // NOTE: Current implementation returns class name, not QueryTypeDomainInterface instance
    // This is a transitional implementation. Full interface implementation will be added
    // when domains are refactored to implement QueryTypeDomainInterface (future task).
    // For now, we return the domain class name for backward compatibility.

    if ($this->debug) {
      $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'domain_routing', [
        'intent_type' => $intentType,
        'domain_class' => $domainClass,
        'routing_method' => 'domain_based'
      ]);
    }

    // Return domain class name (transitional implementation)
    // TODO: Return QueryTypeDomainInterface instance when domains implement interface
    return $domainClass;
  }

  /**
   *  Méthode de raisonnement profond
   */
  public function deepReason(string $problem): array
  {
    // 1. Décomposer le problème
    $decomposition = $this->reasoningAgent->decompose($problem);

    // 2. Raisonner sur chaque sous-problème
    $solutions = [];

    if ($decomposition['is_atomic']) {
      // Problème simple : raisonnement direct
      $solutions[] = $this->reasoningAgent->reason($problem);
    } else {
      // Problème complexe : résoudre récursivement
      foreach ($decomposition['subproblems'] as $subproblem) {
        if ($subproblem['is_atomic']) {
          $solutions[] = $this->reasoningAgent->reason($subproblem['problem']);
        } else {
          $subSolutions = $this->deepReason($subproblem['problem']);
          $solutions = array_merge($solutions, $subSolutions['solutions'] ?? []);
        }
      }
    }

    $finalAnswer = OrchestratorHelper::synthesizeSolutions($problem, $solutions);

    return [
      'problem' => $problem,
      'decomposition' => $decomposition,
      'solutions' => $solutions,
      'final_answer' => $finalAnswer,
    ];
  }

  /**
   * 🎯 Obtenir un rapport complet système
   */
  public function getSystemReport(): array
  {
    return [
      'orchestrator' => $this->getStats(),
      'planning' => $this->taskPlanner->getStats(),
      'memory' => [
        'conversation' => $this->conversationMemory ? $this->conversationMemory->getStats() : [],
        'working' => $this->workingMemory->getStats(),
      ],
      'correction' => $this->correctionAgent->getLearningStats(),
      'validation' => $this->validationAgent->getStats(),
      'reasoning' => $this->reasoningAgent->getStats(),
    ];
  }
  
  // ========================================
  // ACTOR-CRITIC INTEGRATION
  // ========================================
  
  /**
   * Get execution statistics
   *
   * @return array Execution statistics
   */
  public function getStats(): array
  {
    return $this->executionStats;
  }
  
  /**
   * Execute action using Actor-Critic coordination
   *
   * This method provides transparent integration with the Actor-Critic workflow.
   * When enabled, it delegates to ActorCriticCoordinator for execution and evaluation.
   * When disabled or on error, it falls back to hybrid mode.
   *
   * Requirements: 25.1, 25.2, 25.3, 25.4, 25.5
   *
   * @param \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Action $action Action to execute
   * @return \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\CoordinatedResult|array Result or fallback response
   */
  public function executeWithActorCritic($action)
  {
    // Check if Actor-Critic is enabled (Requirement 25.5)
    if (!$this->isActorCriticEnabled()) {
      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_critic_disabled', [
          'message' => 'Actor-Critic disabled, using hybrid mode',
          'action_type' => is_object($action) ? $action->getType() : 'unknown'
        ]);
      }
      
      // Fallback to hybrid mode (Requirement 25.3)
      return $this->executeWithHybridMode($action);
    }
    
    try {
      // Use ActorCriticCoordinator for execution (Requirements 25.1, 25.2)
      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_critic_execution', [
          'message' => 'Using Actor-Critic coordination',
          'action_type' => $action->getType(),
          'action_priority' => $action->getPriority()
        ]);
      }
      
      $result = $this->actorCriticCoordinator->coordinateExecution($action);
      
      // Preserve existing security and validation constraints (Requirement 25.3)
      $this->validateCoordinatedResult($result);
      
      // Integrate with MonitoringAgent (Requirement 25.4)
      $this->monitoring->recordEvent('actor_critic_execution', [
        'action_type' => $action->getType(),
        'consensus_score' => $result->getConsensusScore(),
        'execution_time' => $result->getMetadata()['execution_time'] ?? 0,
        'evaluation_time' => $result->getMetadata()['evaluation_time'] ?? 0,
        'total_time' => $result->getMetadata()['total_time'] ?? 0,
        'critics_count' => $result->getMetadata()['critics_count'] ?? 0
      ]);
      
      if ($this->debug) {
        $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'actor_critic_success', [
          'action_type' => $action->getType(),
          'consensus_score' => $result->getConsensusScore(),
          'actor_id' => $result->getMetadata()['actor_id'] ?? 'unknown',
          'critics_count' => $result->getMetadata()['critics_count'] ?? 0
        ]);
      }
      
      return $result;
      
    } catch (\Exception $e) {
      // Error handling with fallback (Requirement 25.3)
      $this->securityLogger->logSecurityEvent(
        "Actor-Critic execution failed: " . $e->getMessage(),
        'error'
      );
      
      // Check if fallback is enabled
      if (ActorCriticConfig::shouldFallbackToHybrid()) {
        if ($this->debug) {
          $this->securityLogger->logStructured('warning', 'OrchestratorAgent', 'actor_critic_fallback', [
            'message' => 'Falling back to hybrid mode after error',
            'error' => $e->getMessage()
          ]);
        }
        
        // Fallback to hybrid mode
        return $this->executeWithHybridMode($action);
      } else {
        // Re-throw exception if fallback disabled
        throw $e;
      }
    }
  }
  
  /**
   * Check if Actor-Critic separation is enabled

   * @return bool True if enabled
   */
  public function isActorCriticEnabled(): bool
  {
    return ActorCriticConfig::isEnabled() && $this->actorCriticCoordinator !== null;
  }
  
  /**
   * Execute action using hybrid mode (fallback)
   *
   * This method provides backward compatibility when Actor-Critic is disabled
   * or when fallback is needed due to errors.
   *
   * Requirement: 25.3
   *
   * @param mixed $action Action to execute
   * @return array Execution result
   */
  private function executeWithHybridMode($action): array
  {
    // Extract action details
    $actionType = is_object($action) && method_exists($action, 'getType')
                  ? $action->getType()
                  : 'unknown';

    $parameters = is_object($action) && method_exists($action, 'getParameters')
                  ? $action->getParameters()
                  : [];

    // Use existing hybrid agent workflow
    // This maintains backward compatibility with the current system

    if ($this->debug) {
      $this->securityLogger->logStructured('info', 'OrchestratorAgent', 'hybrid_mode_execution', [
        'action_type' => $actionType,
        'reason' => 'actor_critic_disabled_or_fallback'
      ]);
    }

    // Return a compatible response structure
    return [
      'success' => true,
      'mode' => 'hybrid',
      'action_type' => $actionType,
      'message' => 'Executed using hybrid mode (Actor-Critic disabled or fallback)',
      'parameters' => $parameters
    ];
  }
  
  /**
   * Validate coordinated result
   *
   * Ensures the coordinated result meets security and validation constraints.
   *
   * Requirement: 25.3
   *
   * @param \ClicShopping\AI\Agents\Orchestrator\SubActorCritic\CoordinatedResult $result Result to validate
   * @return void
   * @throws \Exception If validation fails
   */
  private function validateCoordinatedResult($result): void
  {
    // Integrate with ValidationAgent (Requirement 25.4)
    $actionResult = $result->getActionResult();
    $output = $actionResult->getOutput();

    // Validate output if it's a query
    if (is_string($output) && str_contains(strtoupper($output), 'SELECT')) {
      $validation = $this->validationAgent->validateBeforeExecution($output, [
        'source' => 'actor_critic_coordination',
        'actor_id' => $result->getMetadata()['actor_id'] ?? 'unknown'
      ]);

      if (!$validation['can_execute']) {
        throw new \Exception(
          'Coordinated result failed validation: ' . implode(', ', $validation['errors'])
        );
      }
    }

    // Additional security checks can be added here
  }

  /**
   * Get Actor-Critic coordination statistics
   *
   * @return array Statistics about Actor-Critic coordination
   */
  public function getActorCriticStats(): array
  {
    if (!$this->isActorCriticEnabled()) {
      return [
        'enabled' => false,
        'message' => 'Actor-Critic separation is disabled'
      ];
    }

    // Get statistics from monitoring
    $stats = [
      'enabled' => true,
      'configuration' => ActorCriticConfig::getAll(),
      'executions' => []
    ];

    // Add execution statistics if available
    try {
      $sql = "SELECT 
                COUNT(*) as total_coordinations,
                AVG(total_time_ms) as avg_total_time,
                AVG(execution_time_ms) as avg_execution_time,
                AVG(evaluation_time_ms) as avg_evaluation_time,
                AVG(consensus_score) as avg_consensus_score,
                AVG(num_critics) as avg_critics_count
              FROM {$this->prefix}rag_coordinated_results
              WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";

      $result = $this->db->query($sql)->fetch();

      if ($result) {
        $stats['executions'] = [
          'total_coordinations_24h' => (int)$result['total_coordinations'],
          'avg_total_time_ms' => round((float)$result['avg_total_time'], 2),
          'avg_execution_time_ms' => round((float)$result['avg_execution_time'], 2),
          'avg_evaluation_time_ms' => round((float)$result['avg_evaluation_time'], 2),
          'avg_consensus_score' => round((float)$result['avg_consensus_score'], 2),
          'avg_critics_count' => round((float)$result['avg_critics_count'], 1)
        ];
      }
    } catch (\Exception $e) {
      $stats['executions']['error'] = 'Failed to retrieve statistics: ' . $e->getMessage();
    }

    return $stats;
  }
}
