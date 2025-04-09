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
      return [
        'type' => 'formatted_results',
        'content' => $this->formatAnalyticsResults($results)
      ];
    }

    // If it's a semantic search result, format it
    if (isset($results['type']) && $results['type'] === 'semantic_results') {
      return [
        'type' => 'formatted_results',
        'content' => $this->formatSemanticResults($results)
      ];
    }

    // For unknown types, return a formatted error message
    return [
      'type' => 'formatted_results',
      'content' => $this->formatUnknownResults($results)
    ];
  }

  /**
   * Formats analytics results for display.
   *
   * @param array $results The analytics results to format.
   * @return string The formatted HTML output.
   */
  private function formatAnalyticsResults(array $results): string
  {
    $output = "<div class='analytics-results'>";
    $output .= "<h4>Résultats pour : " . htmlspecialchars($results['question'] ?? $results['query'] ?? 'Requête inconnue') . "</h4>";

    // Display the SQL query if available
    if (!empty($results['sql_query'])) {
      $output .= "<div class='sql-query'><strong>Requête SQL :</strong> <pre>" . htmlspecialchars($results['sql_query']) . "</pre></div>";
    }

    // Display the interpretation
    if (!empty($results['interpretation'])) {
      $output .= "<div class='interpretation'><strong>Interprétation :</strong> " . $results['interpretation'] . "</div>";
    }

    // Display the results as a table if available
    if (!empty($results['results']) && is_array($results['results'])) {
      $output .= "<div class='results-table'>";
      $output .= "<div class='mt-1'></div>";
      $output .= "<h5>Données brutes :</h5>";
      $output .= "<table class='table table-bordered table-striped'>";

      // Table headers
      $output .= "<thead><tr>";
      $firstRow = reset($results['results']);
      if (is_array($firstRow)) {
        foreach (array_keys($firstRow) as $key) {
          if (!is_numeric($key)) { // Avoid duplicate numeric keys
            $output .= "<th>" . htmlspecialchars($key) . "</th>";
          }
        }
      }
      $output .= "</tr></thead>";

      // Table data
      $output .= "<tbody>";
      foreach ($results['results'] as $row) {
        $output .= "<tr>";
        foreach ($row as $key => $value) {
          if (!is_numeric($key)) { // Avoid duplicate numeric keys
            $output .= "<td>" . htmlspecialchars($value) . "</td>";
          }
        }
        $output .= "</tr>";
      }
      $output .= "</tbody>";

      $output .= "</table>";
      $output .= "</div>";
    }

    $output .= "</div>";

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
    $output = "<div class='semantic-results'>";
    $output .= "<h3>Résultats pour : " . htmlspecialchars($results['query'] ?? 'Requête inconnue') . "</h3>";

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