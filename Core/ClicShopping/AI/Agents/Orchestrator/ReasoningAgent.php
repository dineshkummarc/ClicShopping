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

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

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

  // Configuration
  private string $reasoningMode = 'chain_of_thought'; // chain_of_thought, tree_of_thought, self_consistency
  private int $maxReasoningSteps = 10;
  private int $selfConsistencyPaths = 3;

  // Statistiques
  private array $stats = [
    'total_reasonings' => 0,
    'avg_steps' => 0,
    'successful_reasonings' => 0,
    'failed_reasonings' => 0,
  ];

  /**
   * Constructor
   */
  public function __construct()
  {
    $this->securityLogger = new SecurityLogger();
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "ReasoningAgent initialized with mode: {$this->reasoningMode}",
        'info'
      );
    }
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

    try {
      $result = match($this->reasoningMode) {
        'chain_of_thought' => $this->chainOfThought($problem, $context),
        'tree_of_thought' => $this->treeOfThought($problem, $context),
        'self_consistency' => $this->selfConsistency($problem, $context),
        default => $this->chainOfThought($problem, $context),
      };

      $this->stats['successful_reasonings']++;
      $this->updateAverageSteps($result['steps_count'] ?? 0);

      return $result;

    } catch (\Exception $e) {
      $this->stats['failed_reasonings']++;

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
        "Starting Chain-of-Thought reasoning",
        'info'
      );
    }

    $response = Gpt::getGptResponse($prompt, 1000);

    // Parser la réponse
    $parsed = $this->parseCoTResponse($response);

    return [
      'success' => true,
      'method' => 'chain_of_thought',
      'problem' => $problem,
      'reasoning_steps' => $parsed['steps'],
      'final_answer' => $parsed['answer'],
      'confidence' => $parsed['confidence'] ?? 0.8,
      'steps_count' => count($parsed['steps']),
    ];
  }

  /**
   * Construit le prompt pour Chain-of-Thought
   */
  private function buildCoTPrompt(string $problem, array $context): string
  {
    $parts = [];

    $parts[] = "You are an expert problem solver. Use step-by-step reasoning to solve this problem.";
    $parts[] = "";
    $parts[] = "Problem: {$problem}";

    if (!empty($context)) {
      $parts[] = "";
      $parts[] = "Context:";
      foreach ($context as $key => $value) {
        if (is_string($value)) {
          $parts[] = "- {$key}: {$value}";
        }
      }
    }

    $parts[] = "";
    $parts[] = "Instructions:";
    $parts[] = "1. Break down the problem into clear steps";
    $parts[] = "2. For each step, explain your reasoning";
    $parts[] = "3. Show your work and intermediate results";
    $parts[] = "4. Arrive at a final answer";
    $parts[] = "";
    $parts[] = "Format your response as:";
    $parts[] = "STEP 1: [Description]";
    $parts[] = "Reasoning: [Your thought process]";
    $parts[] = "Result: [What you learned/found]";
    $parts[] = "";
    $parts[] = "STEP 2: ...";
    $parts[] = "";
    $parts[] = "FINAL ANSWER: [Your conclusion]";
    $parts[] = "CONFIDENCE: [0.0 to 1.0]";

    return implode("\n", $parts);
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
        "Starting Tree-of-Thought reasoning",
        'info'
      );
    }

    // Générer plusieurs chemins de raisonnement
    $paths = [];

    for ($i = 0; $i < 3; $i++) {
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
    $approaches = [
      "Approach this problem from a data-driven perspective",
      "Approach this problem from a logical reasoning perspective",
      "Approach this problem from a creative problem-solving perspective",
    ];

    $parts = [];
    $parts[] = "Solve this problem using a specific approach.";
    $parts[] = "";
    $parts[] = "Problem: {$problem}";
    $parts[] = "";
    $parts[] = "Your approach: " . ($approaches[$pathId] ?? $approaches[0]);
    $parts[] = "";
    $parts[] = "Provide your reasoning and conclusion.";

    return implode("\n", $parts);
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
    $parts = [];

    $parts[] = "Analyze this problem and determine if it can be broken down into simpler subproblems.";
    $parts[] = "";
    $parts[] = "Problem: {$problem}";
    $parts[] = "";
    $parts[] = "Respond in this format:";
    $parts[] = "IS_ATOMIC: yes/no";
    $parts[] = "REASONING: [Why it is or isn't atomic]";
    $parts[] = "";
    $parts[] = "If not atomic:";
    $parts[] = "SUBPROBLEM 1: [Description]";
    $parts[] = "SUBPROBLEM 2: [Description]";
    $parts[] = "...";

    return implode("\n", $parts);
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
    $parts = [];

    $parts[] = "Verify if this solution correctly solves the problem.";
    $parts[] = "";
    $parts[] = "Problem: {$problem}";
    $parts[] = "";
    $parts[] = "Proposed Solution: {$solution}";
    $parts[] = "";
    $parts[] = "Respond in this format:";
    $parts[] = "IS_CORRECT: yes/no";
    $parts[] = "CONFIDENCE: [0.0 to 1.0]";
    $parts[] = "EXPLANATION: [Why it is or isn't correct]";
    $parts[] = "ISSUES: [List any problems found]";

    return implode("\n", $parts);
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
    ]);
  }

  //******************************
  // Not USed
  //****

  /**
   * Configuration
   */
  public function setReasoningMode(string $mode): void
  {
    $validModes = ['chain_of_thought', 'tree_of_thought', 'self_consistency'];
    if (in_array($mode, $validModes)) {
      $this->reasoningMode = $mode;
    }
  }

  public function setMaxReasoningSteps(int $max): void
  {
    $this->maxReasoningSteps = max(1, $max);
  }

  public function setSelfConsistencyPaths(int $paths): void
  {
    $this->selfConsistencyPaths = max(2, min(5, $paths));
  }
}