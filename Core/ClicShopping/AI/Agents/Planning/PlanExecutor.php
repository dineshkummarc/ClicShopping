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
use ClicShopping\AI\Rag\MultiDBRAGManager;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\DomainsAI\Analytics\Agent\AnalyticsAgent;
use ClicShopping\AI\Infrastructure\Monitoring\MetricsCollector;
use ClicShopping\AI\Agents\Planning\SubPlanExecutor\AnalyticsExecutor;
use ClicShopping\AI\Agents\Planning\SubPlanExecutor\ResultSynthesizer;
use ClicShopping\AI\Agents\Planning\SubPlanExecutor\SemanticExecutor;
use ClicShopping\AI\Agents\Planning\SubPlanExecutor\StepExecutor;
use ClicShopping\AI\Agents\Planning\SubPlanExecutor\ToolExecutor;
use ClicShopping\AI\Infrastructure\Metrics\CalculatorTool;
use ClicShopping\AI\DomainsAI\WebSearch\Cache\SearchCacheManager;
use ClicShopping\AI\DomainsAI\WebSearch\Tool\WebSearchTool;
use ClicShopping\OM\Registry;

// 🆕 Refactored SubPlanExecutor components

/**
 * PlanExecutor Class
 * Executes plans with step-by-step execution, parallel processing, result transmission, error handling, and result synthesis
 */
#[AllowDynamicProperties]
class PlanExecutor
{
  private SecurityLogger $securityLogger;
  private TaskPlanner $planner;
  private ?AnalyticsAgent $analyticsAgent = null;
  private ?MultiDBRAGManager $ragManager = null;
  private bool $debug;
  private string $userId;
  private int $languageId;

  // Configuration
  private int $maxRetries = 2;
  private bool $enableParallelExecution = false; // For future implementation

  private ?CalculatorTool $calculatorTool = null;
  private mixed $webSearchTool;
  private mixed $cacheManager;
  private mixed $collector;

  // 🆕 Refactored components
  private StepExecutor $stepExecutor;
  private AnalyticsExecutor $analyticsExecutor;
  private SemanticExecutor $semanticExecutor;
  private ToolExecutor $toolExecutor;
  private ResultSynthesizer $resultSynthesizer;

  /**
   * Constructor
   *
   * @param TaskPlanner $planner Planner instance
   * @param string $userId User identifier
   * @param int $languageId Language ID
   */
  public function __construct(TaskPlanner $planner, string $userId = 'system', int $languageId = 1)
  {
    $this->planner = $planner;
    $this->userId = $userId;
    $this->languageId = $languageId;
    $this->securityLogger = new SecurityLogger();
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    // 🆕 NEW: Initialize CalculatorTool if enabled
    if (defined('CLICSHOPPING_APP_CHATGPT_CALCULATOR_ENABLED') && CLICSHOPPING_APP_CHATGPT_CALCULATOR_ENABLED === 'True') {
      Registry::set('CalculatorTool', new CalculatorTool());
      $this->calculatorTool = Registry::get('CalculatorTool');

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("PlanExecutor initialized with CalculatorTool", 'info');
      }
    }


    // Direct SerpApi verification (without Gpt dependency)
    error_log("🔍 PlanExecutor: Direct SerpApi verification...");

    $serpApiKey = "";

    // 1. Environment variable
    $envKey = getenv('SERP_API_KEY');
    if (!empty($envKey)) {
      $serpApiKey = $envKey;
      error_log("🔑 PlanExecutor: Key found in environment variable");
    }
    // 2. ClicShopping constant
    elseif (defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI')) {
      $constKey = CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI;
      if (!empty($constKey)) {
        $serpApiKey = $constKey;
        error_log("🔑 PlanExecutor: Key found in constant");
      }
    }

    if (!empty($serpApiKey)) {
      error_log("🔑 SERPAPI Key loaded: " . substr($serpApiKey, 0, 10) . "...");

      // Set environment variable for WebSearchTool
      putenv('SERP_API_KEY=' . $serpApiKey);
      error_log("🔍 PlanExecutor: putenv('SERP_API_KEY') set");

      $hasValidKey = true;
    } else {
      error_log("❌ SERPAPI Key not loaded - no source found");
      $hasValidKey = false;
    }

    if ($hasValidKey) {
      try {
        Registry::set('webSearchTool', new webSearchTool());
        $this->webSearchTool = Registry::get('webSearchTool');

        if ($this->debug) {
          $this->securityLogger->logSecurityEvent("WebSearchTool initialized successfully", 'info');
        }
      } catch (Exception $e) {
        error_log("⚠️ WebSearchTool initialization failed: " . $e->getMessage());
        $this->webSearchTool = null;

        if ($this->debug) {
          $this->securityLogger->logSecurityEvent("WebSearchTool initialization failed: " . $e->getMessage(), 'warning');
        }
      }
    } else {
      error_log("ℹ️ SerpApi not configured - Web search disabled");
      $this->webSearchTool = null;

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("SerpApi not configured - Web search disabled", 'info');
      }
    }

    $this->collector = new MetricsCollector();
    $this->cacheManager = new SearchCacheManager();

    // 🆕 Initialize refactored components
    $this->stepExecutor = new StepExecutor($this->debug);
    $this->analyticsExecutor = new AnalyticsExecutor($this->userId, $this->languageId, $this->debug);
    $this->semanticExecutor = new SemanticExecutor($this->userId, $this->languageId, $this->debug);
    $this->toolExecutor = new ToolExecutor($this->debug);
    $this->resultSynthesizer = new ResultSynthesizer($this->debug);

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent("PlanExecutor initialized with SubPlanExecutor components", 'info');
    }
  }

  /**
   * Exécute un plan d'exécution
   *
   * @param ExecutionPlan $plan Plan à exécuter
   * @return array Résultat de l'exécution
   */
  public function execute(ExecutionPlan $plan): array
  {
    // 🔍 TRACE: Log entry to verify this method is called
    $this->securityLogger->logSecurityEvent(
      "🚀 PlanExecutor.execute() CALLED - Plan has " . count($plan->getSteps()) . " steps",
      'info'
    );

    $startTime = microtime(true);

    try {
      $plan->start();

      if ($this->debug) {
        $stepCount = count($plan->getSteps());
        $this->securityLogger->logSecurityEvent("Starting plan execution: {$stepCount} steps", 'info');
        error_log("⏱️ [PERF] PlanExecutor: Starting execution of {$stepCount} steps");
      }

      // Exécuter les étapes
      $retryCount = 0;
      $currentPlan = $plan;

      while ($retryCount <= $this->maxRetries) {
        try {
          // Déléguer l'exécution des étapes au StepExecutor
          $stepsStart = microtime(true);
          $this->stepExecutor->executeSteps($currentPlan, function ($step, $plan) {
            return $this->executeStepByType($step, $plan);
          });
          if ($this->debug) {
            error_log("⏱️ [PERF] PlanExecutor: executeSteps took " . round((microtime(true) - $stepsStart), 2) . "s");
          }

          // Si toutes les étapes sont complétées, synthétiser le résultat
          if ($currentPlan->isComplete()) {
            $synthesizeStart = microtime(true);
            $finalResult = $this->synthesizeResults($currentPlan);
            if ($this->debug) {
              error_log("⏱️ [PERF] PlanExecutor: synthesizeResults took " . round((microtime(true) - $synthesizeStart), 2) . "s");
            }
            // synthesizeResults() returns an array, extract text_response for complete()
            $textResponse = is_array($finalResult) ? ($finalResult['text_response'] ?? json_encode($finalResult)) : $finalResult;
            $currentPlan->complete($textResponse);
            $this->planner->markPlanSuccess();

            $executionTime = microtime(true) - $startTime;
            $currentPlan->setExecutionTime($executionTime);

            $this->collector->recordHistogram('execution_time', microtime(true) - $startTime);

            $array_execute = [
              'success' => true,
              'result' => $finalResult,
              'plan' => $currentPlan,
              'execution_time' => $executionTime,
            ];

            if ($this->debug) {
              $this->securityLogger->logSecurityEvent('result', 'info', $array_execute);
              
              // 🆕 Debug: Check if source_attribution is in finalResult
              error_log("🔍 PlanExecutor: finalResult has source_attribution: " . 
                (isset($finalResult['source_attribution']) ? 'YES' : 'NO'));
              if (isset($finalResult['source_attribution'])) {
                error_log("   Source type: " . ($finalResult['source_attribution']['source_type'] ?? 'N/A'));
              }
            }

            return $array_execute;
          }

          break;
        } catch (\Exception $e) {
          if ($this->debug) {
            $this->securityLogger->logSecurityEvent(
              "Plan execution failed (attempt " . ($retryCount + 1) . "): " . $e->getMessage(),
              'warning'
            );
          }

          // Tenter une replanification
          if ($retryCount < $this->maxRetries) {
            $currentPlan = $this->planner->replan($currentPlan, [
              'error' => $e->getMessage(),
              'failed_step' => $this->getLastFailedStep($currentPlan),
            ]);
            $retryCount++;

            $this->collector->increment('executions_failed');
            throw $e;
          }
        } finally {
          $this->collector->stopTimer('plan_execution');
        }
      }

      // Si on arrive ici sans avoir complété, c'est un échec
      throw new \Exception("Plan execution incomplete after {$retryCount} retries");
    } catch (\Exception $e) {
      $plan->fail($e->getMessage());
      $this->planner->markPlanFailure();

      $this->securityLogger->logSecurityEvent(
        "Plan execution failed: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'error' => $e->getMessage(),
        'plan' => $plan,
        'execution_time' => microtime(true) - $startTime,
      ];
    }
  }

  /**
   * Exécute une étape selon son type
   *
   * @param TaskStep $step Étape à exécuter
   * @param ExecutionPlan $plan Plan parent
   * @return mixed Résultat de l'exécution
   */
  private function executeStepByType(TaskStep $step, ExecutionPlan $plan)
  {
    try {
      $step->start();

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Executing step: {$step->getId()} ({$step->getType()})",
          'info'
        );
      }

      // Prepare context
      $context = [
        'plan_intent' => $plan->getIntent(),
        'previous_results' => $plan->getAllStepResults(),
        'query' => $plan->getQuery(),
      ];

      // Exécuter selon le type
      $result = null;
      switch ($step->getType()) {
        case 'analytics_query':
          $result = $this->executeAnalyticsQuery($step, $context);
          break;

        case 'semantic_search':
          $result = $this->executeSemanticSearch($step, $context);
          break;

        case 'calculator':
          $result = $this->executeCalculator($step, $context);
          break;

        case 'web_search':
        case 'web': // Backward compatibility (QueryClassifier normalizes web_search → web)
          $result = $this->executeWebSearch($step, $context);
          break;

        case 'synthesis':
          $result = $this->executeSynthesis($step, $context);
          break;

        default:
          throw new \Exception("Unknown step type: {$step->getType()}");
      }

      // Marquer comme complété
      $step->complete($result);
      $plan->setStepResult($step->getId(), $result);

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Step completed: {$step->getId()}",
          'info'
        );
      }

      return $result;

    } catch (\Exception $e) {
      $step->fail($e->getMessage());

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Step failed: {$step->getId()} - {$e->getMessage()}",
          'error'
        );
      }

      throw $e;
    }
  }

  /**
   * Exécute une requête analytique
   * 🆕 REFACTORED: Délègue à AnalyticsExecutor
   */
  private function executeAnalyticsQuery(TaskStep $step, array $context): array
  {
    // 🔧 TASK 4.3.4.3: Add logging to trace query extraction
    error_log("\n" . str_repeat("-", 100));
    error_log("TASK 4.3.4.3: PlanExecutor.executeAnalyticsQuery() CALLED");
    error_log("-" . str_repeat("-", 99));
    error_log("Step ID: " . $step->getId());
    error_log("Step Type: " . $step->getType());
    error_log("Step Description: " . $step->getDescription());
    
    // Try to get sub_query from metadata
    $subQuery = $step->getMeta('sub_query', null);
    error_log("sub_query from metadata: " . ($subQuery ?? 'NULL'));
    
    // Fallback to description
    $query = $step->getMeta('sub_query', $step->getDescription());
    error_log("Final query (after fallback): '{$query}'");
    error_log("Query length: " . strlen($query));
    error_log("Query is empty: " . (empty($query) ? 'YES' : 'NO'));
    
    if (empty($query)) {
      error_log("❌ WARNING: Query is EMPTY in PlanExecutor!");
      error_log("This means either:");
      error_log("  1. sub_query metadata is not set");
      error_log("  2. step description is empty");
      error_log("  3. Both are empty");
    }
    
    error_log("Calling AnalyticsExecutor.executeAnalyticsQuery()...");
    error_log("-" . str_repeat("-", 99) . "\n");
    
    return $this->analyticsExecutor->executeAnalyticsQuery($query, $context);
  }

  /**
   * Execute a semantic search
   * Delegates to SemanticExecutor which handles the fallback chain: ConversationMemory → Documents → LLM → Web
   * 
   * @param TaskStep $step Step to execute
   * @param array $context Execution context
   * @return array Semantic search result
   */
  private function executeSemanticSearch(TaskStep $step, array $context): array
  {
    $query = $step->getMeta('sub_query', $step->getDescription());

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "🔄 PlanExecutor.executeSemanticSearch() - Delegating to SemanticExecutor",
        'info'
      );
    }

    // Use SemanticExecutor which has the fallback chain
    $result = $this->semanticExecutor->executeSemanticSearch($query, $context);

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "✅ SemanticExecutor returned: " . json_encode([
          'success' => $result['success'] ?? false,
          'source' => $result['source'] ?? 'unknown',
          'has_response' => !empty($result['text_response'])
        ]),
        'info'
      );
    }

    return $result;
  }

  /**
   * Execute a calculation
   * 
   * @param TaskStep $step Step to execute
   * @param array $context Execution context
   * @return array Calculation result
   */
  private function executeCalculator(TaskStep $step, array $context): array
  {
    if (!$this->calculatorTool) {
      throw new \Exception("Calculator tool not available");
    }

    $expression = $step->getMeta('expression', $step->getDescription());
    $result = $this->calculatorTool->calculate($expression);

    return [
      'type' => 'calculator_result',
      'expression' => $expression,
      'result' => $result,
    ];
  }

  /**
   * Exécute une recherche web via SERAPI
   * 
   * @param TaskStep $step Step to execute
   * @param array $context Execution context
   * @return array Web search results in standard format
   */
  private function executeWebSearch(TaskStep $step, array $context): array
  {
    if (!$this->webSearchTool) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Web search tool not available - returning empty result",
          'warning'
        );
      }
      
      return [
        'type' => 'web_search_response',
        'success' => false,
        'error' => 'Web search tool not configured',
        'query' => $step->getDescription(),
        'results' => [],
        'text_response' => 'Web search is not available. Please configure SERAPI key.',
      ];
    }

    $query = $step->getMeta('search_query', $step->getDescription());
    
    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Executing web search for query: {$query}",
        'info'
      );
    }

    try {
      // Call WebSearchTool which handles caching, rate limiting, and SERAPI
      $searchResult = $this->webSearchTool->search($query, [
        'max_results' => $step->getMeta('max_results', 10),
        'engine' => $step->getMeta('search_engine', 'google'),
        'language' => $this->languageId == 1 ? 'en' : 'fr',
      ]);

      // Check if search was successful
      if (!isset($searchResult['success']) || $searchResult['success'] === false) {
        $errorMsg = $searchResult['error'] ?? 'Unknown error';
        
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Web search failed: {$errorMsg}",
            'error'
          );
        }

        return [
          'type' => 'web_search_response',
          'success' => false,
          'error' => $errorMsg,
          'query' => $query,
          'results' => [],
          'text_response' => "Web search failed: {$errorMsg}",
        ];
      }

      // Format results for display
      $items = $searchResult['items'] ?? [];
      $formattedResults = [];
      
      foreach ($items as $item) {
        $formattedResults[] = [
          'title' => $item['title'] ?? '',
          'snippet' => $item['snippet'] ?? '',
          'link' => $item['link'] ?? '',
          'source' => $item['source'] ?? '',
          'price' => $item['price'] ?? null,
        ];
      }

      // Create text response using WebSearchResultFormatter
      $textResponse = \ClicShopping\AI\Helper\Formatter\WebSearchResultFormatter::formatAsHtml($query, $formattedResults);

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Web search completed: " . count($formattedResults) . " results found",
          'info'
        );
      }

      return [
        'type' => 'web_search_response',
        'success' => true,
        'query' => $query,
        'results' => $formattedResults,
        'total_results' => $searchResult['total_results'] ?? count($formattedResults),
        'text_response' => $textResponse,
        'metadata' => $searchResult['metadata'] ?? [],
        'cached' => $searchResult['cached'] ?? false,
        'cache_source' => $searchResult['cache_source'] ?? 'none',
        // 🔧 FIX: Add source_attribution for ResultSynthesizer validation
        'source_attribution' => [
          'source_type' => 'web_search',
          'primary_source' => 'Web Search',
          'source_icon' => '🌐',
          'details' => [
            'url_count' => count($formattedResults),
            'cache_source' => $searchResult['cache_source'] ?? 'none',
            'cached' => $searchResult['cached'] ?? false,
          ],
          'confidence' => 0.7,
        ],
      ];

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Web search exception: " . $e->getMessage(),
        'error'
      );

      return [
        'type' => 'web_search_response',
        'success' => false,
        'error' => $e->getMessage(),
        'query' => $query,
        'results' => [],
        'text_response' => "Web search error: " . $e->getMessage(),
      ];
    }
  }

  /**
   * Execute a synthesis
   * 
   * @param TaskStep $step Step to execute
   * @param array $context Execution context
   * @return array Synthesis result
   */
  private function executeSynthesis(TaskStep $step, array $context): array
  {
    // Get all previous results
    $previousResults = $context['previous_results'] ?? [];

    // Use ResultSynthesizer to combine results
    $synthesized = $this->resultSynthesizer->synthesize($previousResults, $context);

    return [
      'type' => 'synthesis_result',
      'synthesized' => $synthesized,
    ];
  }

  /**
   * Synthesize final plan results
   * 🆕 REFACTORED: Delegates to ResultSynthesizer
   *
   * @param ExecutionPlan $plan Completed plan
   * @return array Synthesized final result
   */
  private function synthesizeResults(ExecutionPlan $plan): array
  {
    return $this->resultSynthesizer->synthesizeResults($plan);
  }

  /**
   * Get the last failed step
   * 
   * @param ExecutionPlan $plan Execution plan
   * @return TaskStep|null Last failed step, or null
   */
  public function getLastFailedStep(ExecutionPlan $plan): ?TaskStep
  {
    // Iterate through plan steps to find the last failed one
    $failedStep = null;

    foreach ($plan->getSteps() as $step) {
      if ($step->getStatus() === 'failed') {
        $failedStep = $step;
      }
    }

    return $failedStep;
  }

  /**
   * Enable/disable parallel execution
   * 
   * @param bool $enable Enable parallel execution
   * @return void
   */
  public function setEnableParallelExecution(bool $enable): void
  {
    $this->enableParallelExecution = $enable;
  }
}
