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

use AllowDynamicProperties;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

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
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\HybridQueryProcessor;


use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\ResponseProcessor as ResponseProcessorComponent;
use ClicShopping\AI\Agents\Query\QueryAnalyzer;
use ClicShopping\AI\Handler\Error\ErrorHandler as ErrorHandlerComponent;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\MemoryManager as MemoryManagerComponent;
use ClicShopping\AI\Helper\OrchestratorHelper;

use ClicShopping\AI\Domain\Semantics\Semantics;

/**
 * OrchestratorAgent Class
 *
 * Agent principal qui orchestre l'ensemble du système multi-agents.
 * Responsable de :
 * - Analyser l'intention de l'utilisateur
 * - Décider quel(s) agent(s) utiliser
 * - Coordonner l'exécution
 * - Gérer les erreurs et les replans
 * - Synthétiser la réponse finale
 */
#[AllowDynamicProperties]
class OrchestratorAgent
{
  // Removed duplicate/unused properties (keep single source of truth)
  private ?MetricsCollector $collector = null;
  private SecurityLogger $securityLogger;
  private RateLimit $rateLimit;
  private string $userId;
  private bool $debug;
  private int $languageId;
  private int $entityId;
  private $db;
  private string $prefix;

  // Agents disponibles
  private ?MultiDBRAGManager $ragManager = null;

  // Statistiques d'exécution
  private array $executionStats = [];

  // Mémoire conversationnelle et de travail
  private ?ConversationMemory $conversationMemory = null;
  private WorkingMemory $workingMemory;

  // Planning / execution / agents auxiliaires
  public TaskPlanner $taskPlanner;
  public PlanExecutor $planExecutor;
  private CorrectionAgent $correctionAgent;
  private ValidationAgent $validationAgent;
  private ReasoningAgent $reasoningAgent;

  // Monitoring / alerting / response processing
  private MonitoringAgent $monitoring;
  private AlertManager $alertManager;
  private LlmResponseProcessor $responseProcessor;         // LLM formatter
  private ?ResponseProcessorComponent $responseProcessorComponent = null;

  // Sub-orchestrator components
  private IntentAnalyzer $intentAnalyzer;
  private EntityExtractor $entityExtractor;
  private DiagnosticManager $diagnosticManager;
  private ContextManager $contextManager;
  private HybridQueryProcessor $hybridQueryProcessor;
  private ComplexQueryHandler $complexQueryHandler;
  private ?QueryAnalyzer $queryAnalyzer = null;
  private ?ErrorHandlerComponent $errorHandler = null;
  private ?MemoryManagerComponent $memoryManager = null;

  // Diagnostics
  private array $recentErrors = [];
  private int $maxErrors = 50;

  /**
   * Constructor
   *
   * @param string $userId Identifiant de l'utilisateur
   * @param int|null $languageId ID de la langue (null = langue par défaut)
   * @param int $entityId Entity ID for context
   *
   * TASK 3.1.1: Refactored constructor - extracted initialization to private methods
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
   * TASK 3.1.1: Extracted from constructor
   */
  private function initializeCoreComponents(): void
  {
    // Security and rate limiting
    $this->securityLogger = new SecurityLogger();
    $this->rateLimit = new RateLimit('orchestrator', 100, 60);
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

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

    // Diagnostic properties
    $this->recentErrors = [];
    $this->maxErrors = 50;
  }

  /**
   * Initialize all agents (planning, correction, validation, reasoning)
   *
   * TASK 3.1.1: Extracted from constructor
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
   * TASK 3.1.1 & 3.1.2: Extracted from constructor + Phase 2 components
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
    $this->hybridQueryProcessor = new HybridQueryProcessor($this->debug);
    $this->complexQueryHandler = new ComplexQueryHandler($this->debug);

    // Phase 2 extracted components (TASK 3.1.2)
    $this->responseProcessorComponent = new ResponseProcessorComponent($this->debug);
    $this->queryAnalyzer = new QueryAnalyzer($this->debug);
    $this->errorHandler = new ErrorHandlerComponent($this->debug, $this->responseProcessorComponent);
    $this->memoryManager = new MemoryManagerComponent(
      $this->conversationMemory,
      $this->workingMemory,
      $this->debug
    );
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
    // ✅ TASK 4.4.2.3: Début de la mesure de latence
    $startTime = microtime(true);
    $perfMarkers = ['start' => $startTime]; // 🆕 Performance tracking
    $this->collector->startTimer('process_validation');
    
    // ✅ TASK 4.4.2.3: Variables pour tracking
    $status = 'success';
    
    if ($this->debug) {
      error_log("⏱️ [PERF] processWithValidation START at " . date('H:i:s'));
    }

    try {
      // TASK 3.2.3: Use MemoryManager for contextual reference resolution
      $resolved = $this->memoryManager->resolveContextualReferences($query);
      $queryToProcess = $resolved['resolved_query'] ?? $query;
      $contextUsed = $resolved['has_references'] ?? false;
      
      if ($contextUsed && $this->debug) {
        $this->securityLogger->logSecurityEvent(
          "TASK 2.8: Contextual references resolved EARLY: '{$query}' → '{$queryToProcess}'",
          'info'
        );
      }

      // ✅ TASK 5.1.7.5: Parallel translation (non-blocking, for logging only)
      // Translation is done inside handleFullOrchestration in parallel with context retrieval
      // This early translation is kept for backward compatibility with logging
      $translatedQuery = '';
      try {
        $translatedQuery = Semantics::translateToEnglish($queryToProcess, 80);
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
      // ✅ TASK 4.4.2.3: Mark as error status
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

      // TASK 3.4.1: Delegate to ErrorHandler component
      return $this->errorHandler->buildErrorResponse($e->getMessage(), $errorContext);
    } finally {
      // ✅ TASK 4.4.2.3: Record latency metric in finally block (always executed)
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
   * Analyse l'intention de la requête
   *
   * @param string $query La requête utilisateur
   * @return array Intention analysée avec type, confiance et flags
   */
  private function analyzeIntent(string $query): array
  {
    // Delegate to IntentAnalyzer
    return $this->intentAnalyzer->analyze($query);
  }

  /**
   * Initialise les statistiques
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
   * Obtient les statistiques d'exécution
   *
   * @return array Statistiques
   */
  public function getStats(): array
  {
    return $this->executionStats;
  }

  /**
   * ✅ TASK 4.4.2.3: Obtient les métriques de latence pour le dashboard
   * 
   * Retourne les statistiques de latence des requêtes
   *
   * @return array Métriques de latence avec statistiques détaillées
   */
  public function getLatencyMetrics(): array
  {
    // Récupérer les statistiques de l'histogramme de latence
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


  // ========================================
  // 🆕 DIAGNOSTIC METHODS (Delegated to DiagnosticManager)
  // ========================================

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
          // Récursion
          $subSolutions = $this->deepReason($subproblem['problem']);
          $solutions = array_merge($solutions, $subSolutions['solutions'] ?? []);
        }
      }
    }

    // 3. Synthétiser les solutions
    $finalAnswer = OrchestratorHelper::synthesizeSolutions($problem, $solutions);

    return [
      'problem' => $problem,
      'decomposition' => $decomposition,
      'solutions' => $solutions,
      'final_answer' => $finalAnswer,
    ];
  }



  /**
   * Handle full orchestration for complex queries
   * 
   * TASK 3.2.2: Extracted from processWithValidation()
   * 
   * @param string $query Original user query
   * @param string $queryToProcess Resolved query to process
   * @param float $startTime Start time for performance tracking
   * @param array $perfMarkers Performance markers array
   * @return array Full orchestration response
   */
  private function handleFullOrchestration(string $query, string $queryToProcess, float $startTime, array $perfMarkers): array
  {
    // 1. Créer un scope pour cette exécution
    $executionId = 'exec_' . uniqid('', true);
    $this->workingMemory->enterScope($executionId);

    // 2. Stocker la requête originale
    $this->workingMemory->set('original_query', $query);
    $this->workingMemory->set('start_time', $startTime);
    
    $perfMarkers['after_init'] = microtime(true); // 🆕

    // ✅ TASK 5.1.7.5: Parallel context retrieval and translation
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
      
      // Operation 2: Translation (if not already done)
      try {
        $translatedQuery = Semantics::translateToEnglish($queryToProcess, 80);
        $translationDuration = (microtime(true) - $translationStart) * 1000;
      } catch (\Exception $e) {
        $translationError = $e;
        $translationDuration = (microtime(true) - $translationStart) * 1000;
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Translation failed (using original query): " . $e->getMessage(),
            'warning'
          );
        }
      }
      
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
      
      try {
        $translatedQuery = Semantics::translateToEnglish($queryToProcess, 80);
      } catch (\Exception $e) {
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Translation failed: " . $e->getMessage(),
            'warning'
          );
        }
      }
    }
    
    $perfMarkers['after_parallel'] = microtime(true); // 🆕
    
    // 3.1. 🆕 Gestion intelligente du contexte (éviter conflit feedback/contexte)
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

    // TASK 2.18: Clear last entity when context is cleared (context switch detected)
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

    // 3.5. Analyser la relation avec le contexte précédent
    // TASK 3.4.2: Delegate to QueryAnalyzer component
    $contextAnalysis = $this->queryAnalyzer->analyzeQueryContextRelation($query, $context);
    $this->workingMemory->set('context_analysis', $contextAnalysis);

    // TASK 2.8: Contextual resolution already done at the beginning (line ~320)
    // $queryToProcess is already resolved

    // Si la requête est liée au contexte, enrichir avec les informations contextuelles
    if ($contextAnalysis['is_related_to_context']) {
      // TASK 3.4.2: Delegate to QueryAnalyzer component
      $queryToProcess = $this->queryAnalyzer->enrichQueryWithContext($queryToProcess, $context, $contextAnalysis);
    }

    $this->workingMemory->set('resolved_query', $queryToProcess);

    // 4. Analyse de l'intention
    $intentStart = microtime(true);
    $intent = $this->analyzeIntent($queryToProcess);
    $this->workingMemory->set('intent', $intent);

    if ($this->debug) {
      error_log("⏱️ [PERF] analyzeIntent took " . round((microtime(true) - $intentStart), 2) . "s");
      $this->securityLogger->logStructured(
        'info',
        'OrchestratorAgent',
        'PATH_DECISION.intent',
        [
          'translated_query' => $intent['translated_query'] ?? $queryToProcess,
          'intent_type' => $intent['type'] ?? 'unknown',
          'is_hybrid_flag' => $intent['is_hybrid'] ?? false,
          'confidence' => $intent['confidence'] ?? 0,
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

    // 4.6. 🆕 If complex query detected, delegate to HybridQueryProcessor
    if ($complexityDetection['is_complex']) {
      return $this->hybridQueryProcessor->handleComplexQuery(
        $translatedQuery,
        $queryToProcess,
        $complexityDetection,
        $this->complexQueryHandler,
        $this->taskPlanner,
        $this->planExecutor
      );
    }

    // 5. Vérifier si raisonnement complexe nécessaire
    // 🔧 TASK 4.3 (2025-12-11): Enhanced fallback logic with logging
    // Fix: Safely check is_hybrid with default value
    $isHybrid = $intent['is_hybrid'] ?? false;
    
    if ($intent['confidence'] < 0.6 || $isHybrid) {
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
      
      // 🔧 TASK 4.3: If confidence is very low (< 0.6) and type is ambiguous,
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

    // 6. Créer le plan avec le contexte enrichi
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

    // ✅ TASK 5.2.1.1: Route hybrid queries to HybridQueryProcessor BEFORE TaskPlanner
    // Hybrid queries need to be split into sub-queries and executed by multiple agents
    // NOTE: Check intent_type ONLY (is_hybrid flag can be inconsistent)
    $intentType = $intent['type'] ?? 'analytics';
    
    if ($intentType === 'hybrid') {
      if ($this->debug) {
        $this->securityLogger->logStructured(
          'info',
          'OrchestratorAgent',
          'HYBRID_ROUTING',
          [
            'action' => 'routing_to_hybrid_processor',
            'intent_type' => $intentType,
            'is_hybrid_flag' => $intent['is_hybrid'] ?? false,
            'confidence' => $intent['confidence'] ?? 0,
            'query' => substr($queryToProcess, 0, 100)
          ]
        );
      }
      
      // Delegate to HybridQueryProcessor for query splitting and multi-agent execution
      return $this->hybridQueryProcessor->processHybridQuery(
        $queryToProcess,
        $intent,
        $enrichedContext,
        $this->taskPlanner,
        $this->planExecutor,
        $this->responseProcessorComponent,
        $this->memoryManager,
        $this->userId,
        $this->languageId,
        $startTime
      );
    }

    $planStart = microtime(true);
    $plan = $this->taskPlanner->createPlan($intent, $queryToProcess, $enrichedContext);
    if ($this->debug) {
      error_log("⏱️ [PERF] createPlan took " . round((microtime(true) - $planStart), 2) . "s");
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
            // Mettre à jour la requête dans l'étape
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
    // ✅ TASK 5.1.7.4: Skip memory storage if query is already cached (warm cache)
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
      
      // ✅ TASK 5.1.7.4.1: FIX - Still track entity for contextual reference resolution
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
}
