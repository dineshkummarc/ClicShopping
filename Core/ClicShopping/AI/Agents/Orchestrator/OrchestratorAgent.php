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
use ClicShopping\AI\Agents\Query\ComplexQueryHandler;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\HybridQueryProcessor;


use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\ResponseProcessor as ResponseProcessorComponent;
use ClicShopping\AI\Agents\Query\QueryAnalyzer;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\ErrorHandler as ErrorHandlerComponent;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\MemoryManager as MemoryManagerComponent;
use ClicShopping\AI\Helper\OrchestratorHelper;

use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;
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
  private ?AnalyticsAgent $analyticsAgent = null;
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
    $isFastLane = false; // Indicateur clé pour le monitoring
    
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
      
      // 🆕 FAST PATH: Bypass orchestration for simple analytics queries
      if ($this->isSimpleAnalyticsQuery($translatedQuery)) {
        return $this->handleFastPath($queryToProcess, $contextUsed, $startTime);
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
          'status' => $status,
          'fast_lane' => $isFastLane ? 'true' : 'false'
        ]
      );
      
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "⏱️ TASK 4.4.2.3: Query latency recorded: {$latencyMs}ms (fast_lane: " . ($isFastLane ? 'true' : 'false') . ", status: {$status})",
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
   * Retourne les statistiques de latence des requêtes avec distinction fast-lane vs full orchestration
   *
   * @return array Métriques de latence avec statistiques détaillées
   */
  public function getLatencyMetrics(): array
  {
    // Récupérer les statistiques de l'histogramme de latence
    $allStats = $this->collector->getHistogramStats('orchestrator_query_latency_ms');
    
    // Récupérer les statistiques par tag (fast_lane)
    $fastLaneStats = $this->collector->getHistogramStats('orchestrator_query_latency_ms', ['fast_lane' => 'true']);
    $fullOrchestrationStats = $this->collector->getHistogramStats('orchestrator_query_latency_ms', ['fast_lane' => 'false']);
    
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
      ],
      'fast_lane' => $fastLaneStats ?? [
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
      ],
      'full_orchestration' => $fullOrchestrationStats ?? [
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
      ],
      'fast_lane_efficiency' => $this->calculateFastLaneEfficiency($fastLaneStats, $fullOrchestrationStats),
    ];
  }

  /**
   * ✅ TASK 4.4.2.3: Calcule l'efficacité du fast-lane
   * 
   * @param array|null $fastLaneStats Statistiques fast-lane
   * @param array|null $fullStats Statistiques orchestration complète
   * @return array Métriques d'efficacité
   */
  private function calculateFastLaneEfficiency(?array $fastLaneStats, ?array $fullStats): array
  {
    if (!$fastLaneStats || !$fullStats || $fullStats['mean'] == 0) {
      return [
        'speedup_factor' => 0,
        'time_saved_ms' => 0,
        'percentage_faster' => 0,
      ];
    }
    
    $speedupFactor = $fullStats['mean'] / $fastLaneStats['mean'];
    $timeSavedMs = $fullStats['mean'] - $fastLaneStats['mean'];
    $percentageFaster = (($fullStats['mean'] - $fastLaneStats['mean']) / $fullStats['mean']) * 100;
    
    return [
      'speedup_factor' => round($speedupFactor, 2),
      'time_saved_ms' => round($timeSavedMs, 2),
      'percentage_faster' => round($percentageFaster, 1),
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
   * 🆕 Detect if query is a simple analytics query that can bypass orchestration
   * * Simple analytics queries are:
   * - Questions about counts, totals, averages for standard entities.
   * - Likely to be cached and don't need complex reasoning.
   *
   * @param string $query User query
   * @return bool True if simple analytics query
   */
  private function isSimpleAnalyticsQuery(string $query): bool
  {
    $queryLower = strtolower($query);

    // --- 1. Keywords for inclusion (Actionable Analytics) ---

// Get keywords from the dedicated pattern class for better management.
    $analyticsKeywords = AnalyticsPattern::getSimpleAnalyticsKeywords();

    // Create a regex pattern with word boundaries for precise matching
    // Note: 'how many' and multi-word terms must be handled carefully, often better left as strpos
    // But for better overall precision, we use word boundaries for the single words.
    $keywordPatterns = array_map(function($keyword) {
      if (str_contains($keyword, ' ')) {
        return preg_quote($keyword, '/'); // Keep multi-word phrases as is
      }
      return '\b' . preg_quote($keyword, '/') . '\b'; // Add boundaries for single words
    }, $analyticsKeywords);

    $inclusionRegex = '/(?:' . implode('|', $keywordPatterns) . ')/i';


    // Check if query contains any analytics keywords
    if (preg_match($inclusionRegex, $queryLower) === 0) {
      return false; // No analytical intent detected
    }

    // --- 2. Exclusionary Logic (Complex Indicators) ---

    // Exclude complex queries that need reasoning, comparison, or forward-looking analysis
    $complexIndicators = [
      '\bwhy\b', '\bhow to\b', '\bhow can\b',
      '\bexplain\b', '\banalyze\b',
      '\bcompare\b', '\bdifference\b',
      '\btrend\b', '\bpredict\b', '\bforecast\b',
      '\brecommend\b', '\bsuggest\b',
      '\boptimize\b', '\bimprove\b',
    ];

    // Combine all complex indicators into a single exclusion regex
    $exclusionRegex = '/(?:' . implode('|', $complexIndicators) . ')/i';

    if (preg_match($exclusionRegex, $queryLower)) {
      return false; // Complex query, needs full orchestration (LLM, RAG, etc.)
    }

    // Simple analytics query detected
    return true;
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

    // 3. Obtenir le contexte de la conversation
    $rawContext = $this->conversationMemory ? $this->conversationMemory->getRelevantContext($query) : [];
    
    $perfMarkers['after_context'] = microtime(true); // 🆕
    
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
    $perfMarkers['before_memory'] = microtime(true);
    
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
        'context_ms' => round(($perfMarkers['after_context'] - $perfMarkers['after_init']) * 1000, 2),
        'memory_ms' => round(($perfMarkers['after_memory'] - $perfMarkers['before_memory']) * 1000, 2),
        'total_ms' => round((microtime(true) - $startTime) * 1000, 2)
      ]);
    }

    return $response;
  }

  /**
   * Handle fast path for simple analytics queries
   * 
   * TASK 3.2.1: Extracted from processWithValidation()
   * 
   * @param string $queryToProcess The resolved query to process
   * @param bool $contextUsed Whether context was used in resolution
   * @param float $startTime Start time for performance tracking
   * @return array Fast path response
   */
  private function handleFastPath(string $queryToProcess, bool $contextUsed, float $startTime): array
  {
    if ($this->debug) {
      error_log("⚡ FAST PATH: Detected simple analytics query, bypassing orchestration");
    }
    
    // Initialize analyticsAgent if needed
    if (is_null($this->analyticsAgent)) {
      $this->analyticsAgent = new AnalyticsAgent($this->languageId, true, $this->userId);
    }
    
    // TASK 2.8: Use resolved query for analytics
    $result = $this->analyticsAgent->processBusinessQuery($queryToProcess);
    
    // Wrap result in expected structure for chatGpt.php compatibility
    $wrappedResult = [
      'success' => true,
      'agent_used' => 'analytics_fast_path',
      'intent' => [
        'type' => 'analytics',
        'confidence' => 1.0
      ],
      'data' => $result,  // Wrap analytics result in 'data' key
      'text_response' => $result['interpretation'] ?? '',  // For display
      'execution_time' => microtime(true) - $startTime,
      'context_used' => $contextUsed, // TASK 2.8: Indicate if context was used
    ];
    
    // Skip conversation memory storage for fast path (saves 2-3s from embeddings)
    // The query result is already cached in QueryCache, so we don't lose data
    
    $executionTime = microtime(true) - $startTime;

    if ($this->debug) {
      error_log("⚡ FAST PATH completed in " . round($executionTime, 2) . "s (skipped memory storage)");
    }
    
    $this->collector->stopTimer('process_validation');
    
    // ✅ TASK 4.4.2.3: Record latency metric with fast_lane tag
    $latencyMs = $executionTime * 1000;
    $this->collector->recordMetric(
      'orchestrator_query_latency_ms',
      $latencyMs,
      [
        'status' => 'success',
        'fast_lane' => 'true',
        'query_type' => 'analytics'
      ]
    );
    
    return $wrappedResult;
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
