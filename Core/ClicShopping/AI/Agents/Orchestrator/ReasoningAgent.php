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

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Infrastructure\Metrics\ReasoningAgentStats;

/**
 * ReasoningAgent Class
 *
 * Agent spécialisé dans le raisonnement multi-étapes :
 * - Chain-of-Thought (CoT) reasoning
 * - Tree-of-Thought (ToT) pour problèmes complexes
 * - Self-consistency checking
 * - Décomposition récursive de problèmes
 * - Génération d'hypothèses et vérification
 */
#[AllowDynamicProperties]
class ReasoningAgent
{
  private SecurityLogger $securityLogger;
  private mixed $chat;
  private bool $debug;
  private ?ReasoningAgentStats $persistentStats = null;

  // Configuration
  private string $reasoningMode = 'chain_of_thought'; // chain_of_thought, tree_of_thought, self_consistency
  private int $maxReasoningSteps = 10;
  private int $selfConsistencyPaths = 3;
  private int $treeOfThoughtPaths = 3;

  // Statistiques (session locale)
  private array $stats = [
    'total_reasonings' => 0,
    'avg_steps' => 0,
    'successful_reasonings' => 0,
    'failed_reasonings' => 0,
    // Statistics by mode
    'by_mode' => [
      'chain_of_thought' => [
        'count' => 0,
        'successful' => 0,
        'failed' => 0,
        'avg_steps' => 0,
        'total_steps' => 0,
        'avg_confidence' => 0,
        'total_confidence' => 0,
      ],
      'tree_of_thought' => [
        'count' => 0,
        'successful' => 0,
        'failed' => 0,
        'avg_paths' => 0,
        'total_paths' => 0,
        'avg_confidence' => 0,
        'total_confidence' => 0,
      ],
      'self_consistency' => [
        'count' => 0,
        'successful' => 0,
        'failed' => 0,
        'avg_attempts' => 0,
        'total_attempts' => 0,
        'avg_confidence' => 0,
        'total_confidence' => 0,
        'avg_agreement' => 0,
        'total_agreement' => 0,
      ],
    ],
  ];

  /**
   * Constructor
   * 
   * @param array|null $config Optional configuration array to override defaults
   */
  public function __construct(?array $config = null)
  {
    $this->securityLogger = new SecurityLogger();
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    // Initialize persistent stats
    try {
      $this->persistentStats = new ReasoningAgentStats();
    } catch (\Exception $e) {
      // Silently fail if database is not available
      error_log('ReasoningAgent: Failed to initialize persistent stats - ' . $e->getMessage());
    }

    // Load configuration from various sources (priority order):
    // 1. Constructor parameter (highest priority)
    // 2. Constants from config_clicshopping.php
    // 3. Default values (already set in property declarations)
    
    if ($config !== null) {
      $this->loadConfigurationFromArray($config);
    } else {
      $this->loadConfigurationFromConstants();
    }

    if ($this->debug) {
      $this->logConfiguration();
    }
  }

  /**
   * Load configuration from an array
   * This method allows loading from JSON, database, or any array source
   * 
   * @param array $config Configuration array with keys: reasoning_mode, max_reasoning_steps, self_consistency_paths, tree_of_thought_paths
   */
  public function loadConfigurationFromArray(array $config): void
  {
    if (isset($config['reasoning_mode'])) {
      try {
        $this->setReasoningMode($config['reasoning_mode']);
      } catch (\InvalidArgumentException $e) {
        $this->securityLogger->logSecurityEvent(
          "Invalid reasoning_mode in config: " . $e->getMessage(),
          'warning'
        );
      }
    }

    if (isset($config['max_reasoning_steps'])) {
      try {
        $this->setMaxReasoningSteps((int)$config['max_reasoning_steps']);
      } catch (\InvalidArgumentException $e) {
        $this->securityLogger->logSecurityEvent(
          "Invalid max_reasoning_steps in config: " . $e->getMessage(),
          'warning'
        );
      }
    }

    if (isset($config['self_consistency_paths'])) {
      try {
        $this->setSelfConsistencyPaths((int)$config['self_consistency_paths']);
      } catch (\InvalidArgumentException $e) {
        $this->securityLogger->logSecurityEvent(
          "Invalid self_consistency_paths in config: " . $e->getMessage(),
          'warning'
        );
      }
    }

    if (isset($config['tree_of_thought_paths'])) {
      try {
        $this->setTreeOfThoughtPaths((int)$config['tree_of_thought_paths']);
      } catch (\InvalidArgumentException $e) {
        $this->securityLogger->logSecurityEvent(
          "Invalid tree_of_thought_paths in config: " . $e->getMessage(),
          'warning'
        );
      }
    }
  }

  /**
   * Load configuration from config_clicshopping.php constants
   * This method is kept for backward compatibility but will be deprecated
   * when configuration moves to database/JSON
   */
  private function loadConfigurationFromConstants(): void
  {
    // Load reasoning mode
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_REASONING_MODE')) {
      try {
        $this->setReasoningMode(CLICSHOPPING_APP_CHATGPT_RA_REASONING_MODE);
      } catch (\InvalidArgumentException $e) {
        // Log error but continue with default
        $this->securityLogger->logSecurityEvent(
          "Invalid CLICSHOPPING_APP_CHATGPT_RA_REASONING_MODE constant: " . $e->getMessage(),
          'warning'
        );
      }
    }

    // Load max steps
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_REASONING_STEPS')) {
      try {
        $this->setMaxReasoningSteps((int)CLICSHOPPING_APP_CHATGPT_RA_MAX_REASONING_STEPS);
      } catch (\InvalidArgumentException $e) {
        $this->securityLogger->logSecurityEvent(
          "Invalid CLICSHOPPING_APP_CHATGPT_RA_MAX_REASONING_STEPS constant: " . $e->getMessage(),
          'warning'
        );
      }
    }

    // Load consistency paths
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_CONSISTENCY_PATHS')) {
      try {
        $this->setSelfConsistencyPaths((int)CLICSHOPPING_APP_CHATGPT_RA_CONSISTENCY_PATHS);
      } catch (\InvalidArgumentException $e) {
        $this->securityLogger->logSecurityEvent(
          "Invalid CLICSHOPPING_APP_CHATGPT_RA_CONSISTENCY_PATHS constant: " . $e->getMessage(),
          'warning'
        );
      }
    }

    // Load tree paths
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_TREE_PATHS')) {
      try {
        $this->setTreeOfThoughtPaths((int)CLICSHOPPING_APP_CHATGPT_RA_TREE_PATHS);
      } catch (\InvalidArgumentException $e) {
        $this->securityLogger->logSecurityEvent(
          "Invalid CLICSHOPPING_APP_CHATGPT_RA_TREE_PATHS constant: " . $e->getMessage(),
          'warning'
        );
      }
    }
  }

  /**
   * Log current configuration
   */
  private function logConfiguration(): void
  {
    $config = $this->getConfiguration();
    $this->securityLogger->logSecurityEvent(
      "ReasoningAgent configuration: " . json_encode($config),
      'info'
    );
  }

  /**
   * Raisonne sur un problème avec Chain-of-Thought
   *
   * @param string $problem Problème à résoudre
   * @param array $context Contexte additionnel
   * @return array Résultat du raisonnement
   */
  public function reason(string $problem, array $context = []): array
  {
    $this->stats['total_reasonings']++;
    $startTime = microtime(true);

    try {
      $result = match($this->reasoningMode) {
        'chain_of_thought' => $this->chainOfThought($problem, $context),
        'tree_of_thought' => $this->treeOfThought($problem, $context),
        'self_consistency' => $this->selfConsistency($problem, $context),
        default => $this->chainOfThought($problem, $context),
      };

      $this->stats['successful_reasonings']++;
      $this->updateAverageSteps($result['steps_count'] ?? 0);
      
      // Calculate response time
      $responseTime = (int)round((microtime(true) - $startTime) * 1000);
      
      // Update mode-specific statistics
      $this->updateModeStats($this->reasoningMode, $result, true);
      
      // Save statistics to database via ReasoningAgentStats
      if ($this->persistentStats !== null) {
        $this->persistentStats->saveStatistics($this->reasoningMode, $result, $responseTime, true);
      }

      return $result;

    } catch (\Exception $e) {
      $this->stats['failed_reasonings']++;
      
      // Calculate response time
      $responseTime = (int)round((microtime(true) - $startTime) * 1000);
      
      // Update mode-specific statistics for failure
      $this->updateModeStats($this->reasoningMode, [], false);
      
      // Save failure statistics to database via ReasoningAgentStats
      if ($this->persistentStats !== null) {
        $this->persistentStats->saveStatistics($this->reasoningMode, ['error' => $e->getMessage()], $responseTime, false);
      }

      $this->securityLogger->logSecurityEvent(
        "Reasoning failed: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'error' => $e->getMessage(),
        'problem' => $problem,
      ];
    }
  }

  /**
   * Chain-of-Thought: Raisonnement étape par étape
   */
  private function chainOfThought(string $problem, array $context): array
  {
    $prompt = $this->buildCoTPrompt($problem, $context);

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Starting Chain-of-Thought reasoning (max steps: {$this->maxReasoningSteps})",
        'info'
      );
    }

    $response = Gpt::getGptResponse($prompt, 1000);

    // Parser la réponse
    $parsed = $this->parseCoTResponse($response);
    
    // Enforce max reasoning steps
    $truncated = false;
    if (count($parsed['steps']) > $this->maxReasoningSteps) {
      $parsed['steps'] = array_slice($parsed['steps'], 0, $this->maxReasoningSteps);
      $truncated = true;
      
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Reasoning steps truncated to {$this->maxReasoningSteps}",
          'warning'
        );
      }
    }

    return [
      'success' => true,
      'method' => 'chain_of_thought',
      'problem' => $problem,
      'reasoning_steps' => $parsed['steps'],
      'final_answer' => $parsed['answer'],
      'confidence' => $parsed['confidence'] ?? 0.8,
      'steps_count' => count($parsed['steps']),
      'truncated' => $truncated,
    ];
  }

  /**
   * Construit le prompt pour Chain-of-Thought
   */
  private function buildCoTPrompt(string $problem, array $context): string
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    
    // Load language file in English for internal processing
    $CLICSHOPPING_Language->loadDefinitions('rag_reasoning_agent', 'en', null, 'ClicShoppingAdmin');
    
    // Get the prompt template
    $prompt = $CLICSHOPPING_Language->getDef('text_reasoning_cot_prompt');
    
    // Build context string
    $contextStr = '';
    if (!empty($context)) {
      $contextParts = ["", "Context:"];
      foreach ($context as $key => $value) {
        if (is_string($value)) {
          $contextParts[] = "- {$key}: {$value}";
        }
      }
      $contextStr = implode("\n", $contextParts);
    }
    
    // Replace variables
    $prompt = str_replace('{{problem}}', $problem, $prompt);
    $prompt = str_replace('{{context}}', $contextStr, $prompt);
    
    return $prompt;
  }

  /**
   * Parse la réponse Chain-of-Thought
   */
  private function parseCoTResponse(string $response): array
  {
    $steps = [];
    $answer = '';
    $confidence = 0.8;

    // Extraire les étapes
    preg_match_all('/STEP\s+(\d+):\s*(.+?)(?=STEP\s+\d+:|FINAL ANSWER:|$)/is', $response, $stepMatches, PREG_SET_ORDER);

    foreach ($stepMatches as $match) {
      $stepNum = (int)$match[1];
      $stepContent = trim($match[2]);

      $step = [
        'number' => $stepNum,
        'description' => '',
        'reasoning' => '',
        'result' => '',
      ];

      // Extraire description
      if (preg_match('/^(.+?)(?:\n|Reasoning:)/i', $stepContent, $descMatch)) {
        $step['description'] = trim($descMatch[1]);
      }

      // Extraire reasoning
      if (preg_match('/Reasoning:\s*(.+?)(?:\n|Result:|$)/is', $stepContent, $reasonMatch)) {
        $step['reasoning'] = trim($reasonMatch[1]);
      }

      // Extraire result
      if (preg_match('/Result:\s*(.+?)$/is', $stepContent, $resultMatch)) {
        $step['result'] = trim($resultMatch[1]);
      }

      $steps[] = $step;
    }

    // Extraire la réponse finale
    if (preg_match('/FINAL ANSWER:\s*(.+?)(?=CONFIDENCE:|$)/is', $response, $answerMatch)) {
      $answer = trim($answerMatch[1]);
    }

    // Extraire la confiance
    if (preg_match('/CONFIDENCE:\s*([\d\.]+)/i', $response, $confMatch)) {
      $confidence = (float)$confMatch[1];
    }

    return [
      'steps' => $steps,
      'answer' => $answer,
      'confidence' => $confidence,
    ];
  }

  /**
   * Tree-of-Thought: Explore plusieurs branches de raisonnement
   */
  private function treeOfThought(string $problem, array $context): array
  {
    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Starting Tree-of-Thought reasoning (paths: {$this->treeOfThoughtPaths})",
        'info'
      );
    }

    // Générer plusieurs chemins de raisonnement
    $paths = [];

    for ($i = 0; $i < $this->treeOfThoughtPaths; $i++) {
      $prompt = $this->buildToTPrompt($problem, $context, $i);
      $response = Gpt::getGptResponse($prompt, 800);

      $paths[] = [
        'path_id' => $i + 1,
        'reasoning' => $response,
        'score' => $this->evaluatePath($response),
      ];
    }

    // Sélectionner le meilleur chemin
    usort($paths, fn($a, $b) => $b['score'] <=> $a['score']);
    $bestPath = $paths[0];

    return [
      'success' => true,
      'method' => 'tree_of_thought',
      'problem' => $problem,
      'explored_paths' => count($paths),
      'best_path' => $bestPath,
      'all_paths' => $paths,
      'final_answer' => $this->extractAnswer($bestPath['reasoning']),
      'confidence' => $bestPath['score'],
      'steps_count' => count($paths),
    ];
  }

  /**
   * Construit le prompt pour Tree-of-Thought
   */
  private function buildToTPrompt(string $problem, array $context, int $pathId): string
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    
    // Load language file in English for internal processing
    $CLICSHOPPING_Language->loadDefinitions('rag_reasoning_agent', 'en', null, 'ClicShoppingAdmin');
    
    // Get the prompt template
    $prompt = $CLICSHOPPING_Language->getDef('text_reasoning_tot_prompt');
    
    // Get approach strings
    $approaches = [
      $CLICSHOPPING_Language->getDef('text_reasoning_tot_approach_data'),
      $CLICSHOPPING_Language->getDef('text_reasoning_tot_approach_logical'),
      $CLICSHOPPING_Language->getDef('text_reasoning_tot_approach_creative'),
    ];
    
    $approach = $approaches[$pathId] ?? $approaches[0];
    
    // Replace variables
    $prompt = str_replace('{{problem}}', $problem, $prompt);
    $prompt = str_replace('{{approach}}', $approach, $prompt);
    
    return $prompt;
  }

  /**
   * Évalue la qualité d'un chemin de raisonnement
   */
  private function evaluatePath(string $reasoning): float
  {
    $score = 0.5;

    // Critères de qualité
    $wordCount = str_word_count($reasoning);
    if ($wordCount > 50) $score += 0.1;
    if ($wordCount > 100) $score += 0.1;

    // Présence de structure
    if (preg_match('/\b(first|second|third|finally)\b/i', $reasoning)) {
      $score += 0.1;
    }

    // Présence de justification
    if (preg_match('/\b(because|therefore|thus|hence)\b/i', $reasoning)) {
      $score += 0.1;
    }

    // Présence de conclusion
    if (preg_match('/\b(conclusion|answer|result)\b/i', $reasoning)) {
      $score += 0.1;
    }

    return min(1.0, $score);
  }

  /**
   * Extrait la réponse d'un raisonnement
   */
  private function extractAnswer(string $reasoning): string
  {
    // Chercher une conclusion explicite
    if (preg_match('/(?:conclusion|answer|result):\s*(.+?)(?:\n|$)/i', $reasoning, $match)) {
      return trim($match[1]);
    }

    // Sinon, prendre les dernières phrases
    $sentences = preg_split('/[.!?]+/', $reasoning);
    $sentences = array_filter(array_map('trim', $sentences));

    return end($sentences) ?: $reasoning;
  }

  /**
   * Self-Consistency: Générer plusieurs réponses et voter
   */
  private function selfConsistency(string $problem, array $context): array
  {
    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Starting Self-Consistency reasoning with {$this->selfConsistencyPaths} paths",
        'info'
      );
    }

    $answers = [];

    // Générer plusieurs réponses
    for ($i = 0; $i < $this->selfConsistencyPaths; $i++) {
      $prompt = $this->buildCoTPrompt($problem, $context);
      $response = Gpt::getGptResponse($prompt, 800);

      $parsed = $this->parseCoTResponse($response);
      $answers[] = [
        'attempt' => $i + 1,
        'answer' => $parsed['answer'],
        'confidence' => $parsed['confidence'] ?? 0.8,
        'steps' => $parsed['steps'],
      ];
    }

    // Voter pour la meilleure réponse
    $finalAnswer = $this->voteForBestAnswer($answers);

    return [
      'success' => true,
      'method' => 'self_consistency',
      'problem' => $problem,
      'attempts' => count($answers),
      'all_answers' => $answers,
      'final_answer' => $finalAnswer['answer'],
      'confidence' => $finalAnswer['confidence'],
      'agreement_rate' => $finalAnswer['agreement_rate'],
      'steps_count' => count($answers),
    ];
  }

  /**
   * Vote pour la meilleure réponse
   */
  private function voteForBestAnswer(array $answers): array
  {
    // Compter les occurrences de chaque réponse
    $votes = [];

    foreach ($answers as $answer) {
      $normalizedAnswer = $this->normalizeAnswer($answer['answer']);

      if (!isset($votes[$normalizedAnswer])) {
        $votes[$normalizedAnswer] = [
          'answer' => $answer['answer'],
          'count' => 0,
          'total_confidence' => 0,
        ];
      }

      $votes[$normalizedAnswer]['count']++;
      $votes[$normalizedAnswer]['total_confidence'] += $answer['confidence'];
    }

    // Trouver la réponse avec le plus de votes
    $winner = null;
    $maxVotes = 0;

    foreach ($votes as $normalizedAnswer => $voteData) {
      if ($voteData['count'] > $maxVotes) {
        $maxVotes = $voteData['count'];
        $winner = $voteData;
      }
    }

    if (!$winner) {
      $winner = $votes[array_key_first($votes)];
    }

    $agreementRate = $winner['count'] / count($answers);
    $avgConfidence = $winner['total_confidence'] / $winner['count'];

    return [
      'answer' => $winner['answer'],
      'confidence' => $avgConfidence * $agreementRate, // Ajusté par l'accord
      'agreement_rate' => $agreementRate,
      'votes' => $winner['count'],
    ];
  }

  /**
   * Normalise une réponse pour comparaison
   */
  private function normalizeAnswer(string $answer): string
  {
    $normalized = strtolower(trim($answer));
    $normalized = preg_replace('/[^\w\s]/', '', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    return $normalized;
  }

  /**
   * Décompose un problème complexe récursivement
   *
   * @param string $problem Problème complexe
   * @param int $depth Profondeur actuelle
   * @return array Décomposition hiérarchique
   */
  public function decompose(string $problem, int $depth = 0): array
  {
    if ($depth >= 3) {
      // Limite de profondeur atteinte
      return [
        'problem' => $problem,
        'is_atomic' => true,
        'subproblems' => [],
      ];
    }

    $prompt = $this->buildDecomposePrompt($problem);
    $response = Gpt::getGptResponse($prompt, 500);

    $parsed = $this->parseDecomposition($response);

    // Si le problème est atomique, arrêter
    if ($parsed['is_atomic']) {
      return [
        'problem' => $problem,
        'is_atomic' => true,
        'subproblems' => [],
        'reasoning' => $parsed['reasoning'] ?? '',
      ];
    }

    // Sinon, décomposer récursivement les sous-problèmes
    $subproblems = [];
    foreach ($parsed['subproblems'] as $subproblem) {
      $subproblems[] = $this->decompose($subproblem, $depth + 1);
    }

    return [
      'problem' => $problem,
      'is_atomic' => false,
      'subproblems' => $subproblems,
      'reasoning' => $parsed['reasoning'] ?? '',
      'depth' => $depth,
    ];
  }

  /**
   * Construit le prompt de décomposition
   */
  private function buildDecomposePrompt(string $problem): string
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    
    // Load language file in English for internal processing
    $CLICSHOPPING_Language->loadDefinitions('rag_reasoning_agent', 'en', null, 'ClicShoppingAdmin');
    
    // Get the prompt template
    $prompt = $CLICSHOPPING_Language->getDef('text_reasoning_decompose_prompt');
    
    // Replace variables
    $prompt = str_replace('{{problem}}', $problem, $prompt);
    
    return $prompt;
  }

  /**
   * Parse la décomposition
   */
  private function parseDecomposition(string $response): array
  {
    $result = [
      'is_atomic' => true,
      'reasoning' => '',
      'subproblems' => [],
    ];

    // Extraire IS_ATOMIC
    if (preg_match('/IS_ATOMIC:\s*(yes|no)/i', $response, $match)) {
      $result['is_atomic'] = strtolower($match[1]) === 'yes';
    }

    // Extraire REASONING
    if (preg_match('/REASONING:\s*(.+?)(?=SUBPROBLEM|$)/is', $response, $match)) {
      $result['reasoning'] = trim($match[1]);
    }

    // Extraire SUBPROBLEMS
    if (!$result['is_atomic']) {
      preg_match_all('/SUBPROBLEM\s+\d+:\s*(.+?)(?=SUBPROBLEM|\n\n|$)/is', $response, $matches);
      $result['subproblems'] = array_map('trim', $matches[1]);
    }

    return $result;
  }

  /**
   * Vérifie la cohérence d'une solution
   *
   * @param string $problem Problème original
   * @param string $solution Solution proposée
   * @return array Résultat de vérification
   */
  public function verifySolution(string $problem, string $solution): array
  {
    $prompt = $this->buildVerificationPrompt($problem, $solution);
    $response = Gpt::getGptResponse($prompt, 400);

    $parsed = $this->parseVerification($response);

    return [
      'is_correct' => $parsed['is_correct'],
      'confidence' => $parsed['confidence'],
      'explanation' => $parsed['explanation'],
      'issues' => $parsed['issues'] ?? [],
    ];
  }

  /**
   * Construit le prompt de vérification
   */
  private function buildVerificationPrompt(string $problem, string $solution): string
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    
    // Load language file in English for internal processing
    $CLICSHOPPING_Language->loadDefinitions('rag_reasoning_agent', 'en', null, 'ClicShoppingAdmin');
    
    // Get the prompt template
    $prompt = $CLICSHOPPING_Language->getDef('text_reasoning_verification_prompt');
    
    // Replace variables
    $prompt = str_replace('{{problem}}', $problem, $prompt);
    $prompt = str_replace('{{solution}}', $solution, $prompt);
    
    return $prompt;
  }

  /**
   * Parse la vérification
   */
  private function parseVerification(string $response): array
  {
    $result = [
      'is_correct' => false,
      'confidence' => 0.5,
      'explanation' => '',
      'issues' => [],
    ];

    if (preg_match('/IS_CORRECT:\s*(yes|no)/i', $response, $match)) {
      $result['is_correct'] = strtolower($match[1]) === 'yes';
    }

    if (preg_match('/CONFIDENCE:\s*([\d\.]+)/i', $response, $match)) {
      $result['confidence'] = (float)$match[1];
    }

    if (preg_match('/EXPLANATION:\s*(.+?)(?=ISSUES:|$)/is', $response, $match)) {
      $result['explanation'] = trim($match[1]);
    }

    if (preg_match('/ISSUES:\s*(.+?)$/is', $response, $match)) {
      $issuesText = trim($match[1]);
      $result['issues'] = array_filter(explode("\n", $issuesText));
    }

    return $result;
  }

  /**
   * Met à jour la moyenne des étapes
   */
  private function updateAverageSteps(int $steps): void
  {
    $total = $this->stats['total_reasonings'];
    $current = $this->stats['avg_steps'];

    $this->stats['avg_steps'] = (($current * ($total - 1)) + $steps) / $total;
  }

  /**
   * Obtient les statistiques
   */
  public function getStats(): array
  {
    $total = $this->stats['successful_reasonings'] + $this->stats['failed_reasonings'];
    $successRate = $total > 0
      ? round(($this->stats['successful_reasonings'] / $total) * 100, 2)
      : 0;

    return array_merge($this->stats, [
      'success_rate' => $successRate . '%',
      'avg_steps' => round($this->stats['avg_steps'], 2),
      'configuration' => $this->getConfiguration(),
    ]);
  }

  /**
   * Set reasoning mode
   * 
   * @param string $mode Reasoning mode (default: 'chain_of_thought')
   * @throws \InvalidArgumentException if mode is invalid
   */
  public function setReasoningMode(string $mode = 'chain_of_thought'): void
  {
    $validModes = ['chain_of_thought', 'tree_of_thought', 'self_consistency'];
    
    if (!in_array($mode, $validModes, true)) {
      throw new \InvalidArgumentException(
        "Invalid reasoning mode '{$mode}'. Valid modes: " . implode(', ', $validModes)
      );
    }
    
    $this->reasoningMode = $mode;
    
    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Reasoning mode changed to: {$mode}",
        'info'
      );
    }
  }

  /**
   * Set maximum reasoning steps
   * 
   * @param int $max Maximum steps (default: 10, range: 1-50)
   * @throws \InvalidArgumentException if value is out of range
   */
  public function setMaxReasoningSteps(int $max = 10): void
  {
    if ($max < 1 || $max > 50) {
      throw new \InvalidArgumentException(
        "Max reasoning steps must be between 1 and 50, got: {$max}"
      );
    }
    
    $this->maxReasoningSteps = $max;
    
    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Max reasoning steps set to: {$max}",
        'info'
      );
    }
  }

  /**
   * Set self-consistency paths
   * 
   * @param int $paths Number of paths (default: 3, range: 2-10)
   * @throws \InvalidArgumentException if value is out of range
   */
  public function setSelfConsistencyPaths(int $paths = 3): void
  {
    if ($paths < 2 || $paths > 10) {
      throw new \InvalidArgumentException(
        "Self-consistency paths must be between 2 and 10, got: {$paths}"
      );
    }
    
    $this->selfConsistencyPaths = $paths;
    
    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Self-consistency paths set to: {$paths}",
        'info'
      );
    }
  }

  /**
   * Set tree-of-thought paths
   * 
   * @param int $paths Number of paths (default: 3, range: 2-10)
   * @throws \InvalidArgumentException if value is out of range
   */
  public function setTreeOfThoughtPaths(int $paths = 3): void
  {
    if ($paths < 2 || $paths > 10) {
      throw new \InvalidArgumentException(
        "Tree-of-thought paths must be between 2 and 10, got: {$paths}"
      );
    }

    $this->treeOfThoughtPaths = $paths;

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Tree-of-thought paths set to: {$paths}",
        'info'
      );
    }
  }

  /**
   * Get current configuration
   * 
   * @return array Configuration settings
   */
  public function getConfiguration(): array
  {
    return [
      'reasoning_mode' => $this->reasoningMode,
      'max_reasoning_steps' => $this->maxReasoningSteps,
      'self_consistency_paths' => $this->selfConsistencyPaths,
      'tree_of_thought_paths' => $this->treeOfThoughtPaths,
    ];
  }

  /**
   * Update mode-specific statistics
   * 
   * @param string $mode The reasoning mode used
   * @param array $result The result from reasoning
   * @param bool $success Whether the reasoning was successful
   */
  private function updateModeStats(string $mode, array $result, bool $success): void
  {
    if (!isset($this->stats['by_mode'][$mode])) {
      return;
    }

    $this->stats['by_mode'][$mode]['count']++;

    if ($success) {
      $this->stats['by_mode'][$mode]['successful']++;

      // Update mode-specific metrics
      switch ($mode) {
        case 'chain_of_thought':
          if (isset($result['steps_count'])) {
            $this->stats['by_mode'][$mode]['total_steps'] += $result['steps_count'];
            $this->stats['by_mode'][$mode]['avg_steps'] = 
              $this->stats['by_mode'][$mode]['total_steps'] / $this->stats['by_mode'][$mode]['successful'];
          }
          if (isset($result['confidence'])) {
            $this->stats['by_mode'][$mode]['total_confidence'] += $result['confidence'];
            $this->stats['by_mode'][$mode]['avg_confidence'] = 
              $this->stats['by_mode'][$mode]['total_confidence'] / $this->stats['by_mode'][$mode]['successful'];
          }
          break;

        case 'tree_of_thought':
          if (isset($result['explored_paths'])) {
            $this->stats['by_mode'][$mode]['total_paths'] += $result['explored_paths'];
            $this->stats['by_mode'][$mode]['avg_paths'] = 
              $this->stats['by_mode'][$mode]['total_paths'] / $this->stats['by_mode'][$mode]['successful'];
          }
          if (isset($result['confidence'])) {
            $this->stats['by_mode'][$mode]['total_confidence'] += $result['confidence'];
            $this->stats['by_mode'][$mode]['avg_confidence'] = 
              $this->stats['by_mode'][$mode]['total_confidence'] / $this->stats['by_mode'][$mode]['successful'];
          }
          break;

        case 'self_consistency':
          if (isset($result['attempts'])) {
            $this->stats['by_mode'][$mode]['total_attempts'] += $result['attempts'];
            $this->stats['by_mode'][$mode]['avg_attempts'] = 
              $this->stats['by_mode'][$mode]['total_attempts'] / $this->stats['by_mode'][$mode]['successful'];
          }
          if (isset($result['confidence'])) {
            $this->stats['by_mode'][$mode]['total_confidence'] += $result['confidence'];
            $this->stats['by_mode'][$mode]['avg_confidence'] = 
              $this->stats['by_mode'][$mode]['total_confidence'] / $this->stats['by_mode'][$mode]['successful'];
          }
          if (isset($result['agreement_rate'])) {
            $this->stats['by_mode'][$mode]['total_agreement'] += $result['agreement_rate'];
            $this->stats['by_mode'][$mode]['avg_agreement'] = 
              $this->stats['by_mode'][$mode]['total_agreement'] / $this->stats['by_mode'][$mode]['successful'];
          }
          break;
      }
    } else {
      $this->stats['by_mode'][$mode]['failed']++;
    }
  }

  /**
   * Get statistics from database (persistent across sessions)
   * 
   * @param int $days Number of days to look back
   * @return array Statistics from database
   */
  public function getPersistentStats(int $days = 30): array
  {
    if ($this->persistentStats === null) {
      return [
        'total_reasonings' => 0,
        'successful_reasonings' => 0,
        'failed_reasonings' => 0,
        'success_rate' => '0%',
        'by_mode' => [],
        'period_days' => $days,
      ];
    }

    try {
      return $this->persistentStats->getStats($days);
    } catch (\Exception $e) {
      error_log('ReasoningAgent: Failed to get persistent stats - ' . $e->getMessage());
      return [
        'total_reasonings' => 0,
        'successful_reasonings' => 0,
        'failed_reasonings' => 0,
        'success_rate' => '0%',
        'by_mode' => [],
        'period_days' => $days,
      ];
    }
  }
}
