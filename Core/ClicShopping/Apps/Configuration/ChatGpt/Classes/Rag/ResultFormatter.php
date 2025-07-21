<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM)  at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use ClicShopping\OM\Hash;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * ResultFormatter Class
 *
 * This class handles the formatting of analysis results for display in the user interface.
 * It supports different types of results, such as analytics, semantic search, and unknown types.
 */
class ResultFormatter
{
  /**
   * Formats the results based on their type.
   *
   * @param array $results The results to format.
   * @return array The formatted results.
   */
  public function format(array $results): array
  {
    // If it's an error, return it as is
    if (isset($results['type']) && $results['type'] === 'error') {
      return $results;
    }

    // If it's an analytics result, format it properly
    if (isset($results['type']) && ($results['type'] === 'analytics_results' || $results['type'] === 'analytics_response')) {

      $result = [
        'type' => 'formatted_results',
        'content' => $this->formatAnalyticsResults($results)
      ];

      return $result;
    }

    // If it's a semantic search result, format it
    if (isset($results['type']) && $results['type'] === 'semantic_results') {
      $result = [
        'type' => 'formatted_results',
        'content' => $this->formatSemanticResults($results)
      ];

      return $result;
    }

    // For unknown types, return a formatted error message
    $result = [
      'type' => 'formatted_results',
      'content' => $this->formatUnknownResults($results)
    ];
    
    return $result;
  }

  private function generateTableHeaders(array $firstRow): string
  {
    $headers = "<thead><tr>";
    foreach (array_keys($firstRow) as $key) {
      if (!is_numeric($key)) {
        $headers .= "<th>" . htmlspecialchars($key) . "</th>";
      }
    }
    $headers .= "</tr></thead>";
    return $headers;
  }

  private function generateTableRows(array $data): string
  {
    $rows = "<tbody>";

    foreach ($data as $row) {
      $rows .= "<tr>";

      foreach ($row as $key => $value) {
        if (!is_numeric($key)) {
          $value = HTMLOverrideCommon::removeInvisibleCharacters($value);
          $rows .= "<td>" . htmlspecialchars(Hash::displayDecryptedDataText($value)) . "</td>";
        }
      }
      $rows .= "</tr>";
    }
    $rows .= "</tbody>";

    return $rows;
  }

  /**
   * Formats analytics results for display.
   *
   * @param array $results The analytics results to format.
   * @return string The formatted HTML output.
   */
  private function formatAnalyticsResults(array $results): string
  {

    $question = $results['question'] ?? $results['query'] ?? 'Requête inconnue';

    $output = "<div class='analytics-results'>";
    $output .= "<h4>Résultats pour : " . htmlspecialchars($question) . "</h4>";

    if (isset($results['sql_query'])) {
      $output .= "<div class='sql-query'><strong>SQL request :</strong> <pre>" . htmlspecialchars($results['sql_query']) . "</pre></div>";
    }

    if (isset($results['interpretation'])) {
      $output .= "<div class='interpretation'><strong>Interpretation :</strong> " . Hash::displayDecryptedDataText($results['interpretation']) . "</div>";
    }

    if (isset($results['results']) && is_array($results['results'])) {
      $output .= "<div class='results-table'>";
      $output .= "<div class='mt-1'></div>";
      $output .= "<h5>Données brutes :</h5>";
      $output .= "<table class='table table-bordered table-striped'>";

      $firstRow = !empty($results['results']) ? array_values($results['results'])[0] : null;
      if (is_array($firstRow)) {
        $output .= $this->generateTableHeaders($firstRow);
      }

      $output .= $this->generateTableRows($results['results']);
      $output .= "</table>";
      $output .= "</div>";
    }

    $output .= "</div>";

     Gpt::saveData($question, $output);

    return $output;
  }

  /**
   * Formats semantic search results for display.
   *
   * @param array $results The semantic search results to format.
   * @return string The formatted HTML output.
   */
  private function formatSemanticResults(array $results): string
  {
    $question = $results['query'] ?? 'Unknown request';

    $output = "<div class='semantic-results'>";
    $output .= "<h3>Résultats pour : " . htmlspecialchars($question) . "</h3>";

    // Display the response
    if (!empty($results['response'])) {
      $output .= "<div class='response'>" . $results['response'] . "</div>";
    }

    // Display the sources if available
    if (!empty($results['sources']) && is_array($results['sources'])) {
      $output .= "<div class='sources'>";
      $output .= "<h4>Sources :</h4>";
      $output .= "<ul>";

      foreach ($results['sources'] as $source) {
        $output .= "<li>";

        if (isset($source['title'])) {
          $output .= "<strong>" . htmlspecialchars($source['title']) . "</strong>";
        }

        if (isset($source['content'])) {
          $output .= "<p>" . htmlspecialchars(substr($source['content'], 0, 200)) . "...</p>";
        }
        $output .= "</li>";
      }
      $output .= "</ul>";
      $output .= "</div>";
    }

    $output .= "</div>";

    Gpt::saveData($question, $output);

    return $output;
  }

  /**
   * Formats unknown result types for display.
   *
   * @param array $results The unknown results to format.
   * @return string The formatted HTML output.
   */
  private function formatUnknownResults(array $results): string
  {
    $output = "<div class='unknown-results'>";
    $output .= "<h3>Type de résultat non géré : " . htmlspecialchars($results['type'] ?? 'inconnu') . "</h3>";
    $output .= "<p>Contenu brut des résultats : </p>";
    $output .= "<pre>" . htmlspecialchars(json_encode($results, JSON_PRETTY_PRINT)) . "</pre>";
    $output .= "</div>";

    return $output;
  }
}