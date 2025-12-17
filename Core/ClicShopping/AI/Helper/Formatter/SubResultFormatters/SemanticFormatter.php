<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Helper\Formatter\SubResultFormatters;

use ClicShopping\OM\Hash;
use ClicShopping\AI\Security\LlmGuardrails;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * SemanticFormatter - Formats semantic search results
 */
class SemanticFormatter extends AbstractFormatter
{
  public function canHandle(array $results): bool
  {
    $type = $results['type'] ?? '';
    // Accept both 'semantic' (from AgentResponseHelper) and 'semantic_results' (legacy)
    return $type === 'semantic' || $type === 'semantic_results';
  }

  public function format(array $results): array
  {
    $question = $results['query'] ?? $results['question'] ?? 'Unknown request';

    $output = "<div class='semantic-results'>";
    $output .= "<h4>Résultats pour : " . htmlspecialchars($question) . "</h4>";

    // Display source attribution
    if (isset($results['source_attribution'])) {
      $output .= $this->formatSourceAttribution($results['source_attribution']);
    }

    // Guardrails
    $output .= "<div class='mt-2'></div>";
    $responseContent = $results['response'] ?? $results['interpretation'] ?? '';
    $lmGuardrails = LlmGuardrails::checkGuardrails($question, Hash::displayDecryptedDataText($responseContent));

    if (is_array($lmGuardrails)) {
      $output .= $this->formatGuardrailsMetrics($lmGuardrails);
    } else {
      $output .= "<div class='alert alert-warning'>" . htmlspecialchars($lmGuardrails) . "</div>";
    }

    $output .= "<div class='mt-2'></div>";

    // Display the response
    if (!empty($results['response'])) {
      $output .= "<div class='response'><strong>Réponse :</strong><br>" 
              . Hash::displayDecryptedDataText($results['response']) . "</div>";
    } elseif (!empty($results['interpretation'])) {
      $output .= "<div class='response'><strong>Réponse :</strong><br>" 
              . Hash::displayDecryptedDataText($results['interpretation']) . "</div>";
    }

    $output .= "</div>";

    // Save audit data
    $auditExtra = [
      'embeddings_context' => $results['embeddings_context'] ?? [],
      'similarity_scores'  => $results['similarity_scores'] ?? [],
      'processing_chain'   => $results['processing_chain'] ?? []
    ];
    //Gpt::saveData($question, $output, $auditExtra);

    if(!empty($results['similarity_scores'])) {
      $output .= '<div class="mt-2"></div>';
      $output .= 'Similarity_scores : ' . $results['similarity_scores'] ?? [];
    }

    return [
      'type' => 'formatted_results',
      'content' => $output
    ];
  }

  private function formatGuardrailsMetrics(array $guardrails): string
  {
    $output = "<div class='guardrails-metrics'>";
    // Add guardrails display logic here
    $output .= "</div>";
    return $output;
  }
}
