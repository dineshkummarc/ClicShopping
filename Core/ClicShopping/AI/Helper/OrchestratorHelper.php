<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Helper;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * OrchestratorHelper Class
 *
 * Provides utility methods and helper functions for orchestration.
 * Separated from OrchestratorAgent to follow Single Responsibility Principle.
 *
 * Responsibilities:
 * - Synthesize multiple solutions into final answer
 * - General utility methods that don't fit in other components
 *
 * TASK 2.5: Extracted from OrchestratorAgent (Phase 2 - Component Extraction)
 * REORGANIZATION: Moved from SubOrchestrator to Helper (2025-12-10)
 * Requirements: REQ-4.6, REQ-8.1
 *
 * Note: extractKeywords() was moved to QueryAnalyzer as it's primarily used for query analysis.
 * Note: deepReason() remains in OrchestratorAgent for now - may be moved to ReasoningAgent in future.
 */
class OrchestratorHelper
{
  /**
   * Synthesize multiple solutions into final answer
   *
   * Takes multiple sub-solutions and synthesizes them into a comprehensive
   * final answer using GPT.
   *
   * @param string $problem Original problem statement
   * @param array $solutions Array of sub-solutions (each with 'final_answer' key)
   * @return string Synthesized comprehensive answer
   */
  public static function synthesizeSolutions(string $problem, array $solutions): string
  {
    $parts = [];
    $parts[] = "Problem: {$problem}";
    $parts[] = "";
    $parts[] = "Sub-solutions found:";

    foreach ($solutions as $i => $solution) {
      $parts[] = ($i + 1) . ". " . ($solution['final_answer'] ?? 'No answer');
    }

    $parts[] = "";
    $parts[] = "Synthesize these into a comprehensive answer.";

    $prompt = implode("\n", $parts);

    $response = Gpt::getGptResponse($prompt, 300);

    return $response;
  }
}
