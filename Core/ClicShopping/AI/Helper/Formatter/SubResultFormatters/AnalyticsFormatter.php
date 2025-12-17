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
 * AnalyticsFormatter - Formats analytics query results
 */
class AnalyticsFormatter extends AbstractFormatter
{
  public function canHandle(array $results): bool
  {
    $type = $results['type'] ?? '';
    return in_array($type, ['analytics_results', 'analytics_response']);
  }

  public function format(array $results): array
  {
    $question = $results['question'] ?? $results['query'] ?? 'Unknown request';

    // TASK 6.2: Check if this is a multi-query result
    if (isset($results['multiple_results']) && is_array($results['multiple_results'])) {
      return $this->formatMultipleResults($results);
    }

    $output = "<div class='analytics-results'>";
    $output .= "<h4>Résultats pour : " . htmlspecialchars($question) . "</h4>";

    // Display source attribution
    if (isset($results['source_attribution'])) {
      $output .= $this->formatSourceAttribution($results['source_attribution']);
    }

    // SQL query display
    if ($this->displaySql && isset($results['sql_query'])) {
      $output .= $this->formatSqlQuery($results['sql_query']);
    }

    // Interpretation
    $interpretationText = '';
    if (isset($results['text_response']) && !empty($results['text_response'])) {
      $interpretationText = $results['text_response'];
    } elseif (isset($results['interpretation']) && $results['interpretation'] !== 'Array') {
      $interpretationText = $results['interpretation'];
    }

    if (!empty($interpretationText)) {
      $output .= "<div class='interpretation'><strong>Interprétation :</strong> " 
              . Hash::displayDecryptedDataText($interpretationText) . "</div>";
    }

    // Guardrails
    $output .= "<div class='mt-2'></div>";
    $lmGuardrails = LlmGuardrails::checkGuardrails($question, Hash::displayDecryptedDataText($interpretationText));

    if (is_array($lmGuardrails)) {
      $output .= $this->formatGuardrailsMetrics($lmGuardrails);
    } else {
      $output .= "<div class='alert alert-warning'>" . htmlspecialchars($lmGuardrails) . "</div>";
    }

    $output .= "<div class='mt-2'></div>";

    // Data table
    if (isset($results['results']) && is_array($results['results']) && !empty($results['results'])) {
      $output .= $this->formatDataTable($results['results']);
    } else {
      $output .= "<div class='alert alert-info'>";
      $output .= "<strong>Note :</strong> Les données détaillées sont disponibles dans l'interprétation ci-dessus.";
      $output .= "</div>";
    }

    $output .= "</div>";

    // Save audit data
    $auditExtra = [
      'embeddings_context' => $results['embeddings_context'] ?? [],
      'similarity_scores'  => $results['similarity_scores'] ?? [],
      'processing_chain'   => $results['processing_chain'] ?? []
    ];
    Gpt::saveData($question, $output, $auditExtra);

    return [
      'type' => 'formatted_results',
      'content' => $output
    ];
  }

  /**
   * Format multiple query results with clear separation
   * 
   * TASK 6.2: Display results for multiple queries with clear labels
   * Each sub-query gets its own section, even if one fails
   *
   * @param array $results Results containing multiple_results array
   * @return array Formatted output
   */
  private function formatMultipleResults(array $results): array
  {
    $originalQuery = $results['question'] ?? $results['query'] ?? 'Unknown request';
    $multipleResults = $results['multiple_results'] ?? [];
    $queryCount = count($multipleResults);

    $output = "<div class='analytics-results multiple-queries'>";
    $output .= "<h4>Résultats pour : " . htmlspecialchars($originalQuery) . "</h4>";
    $output .= "<div class='alert alert-info'>";
    $output .= "<strong>Note :</strong> Cette requête contient {$queryCount} sous-requêtes. Les résultats sont affichés séparément ci-dessous.";
    $output .= "</div>";

    // Display source attribution
    if (isset($results['source_attribution'])) {
      $output .= $this->formatSourceAttribution($results['source_attribution']);
    }

    // Process each sub-query result
    foreach ($multipleResults as $index => $subResult) {
      $subQueryNum = $index + 1;
      $subQuery = $subResult['query'] ?? "Sous-requête {$subQueryNum}";
      
      $output .= "<div class='sub-query-result' style='margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px;'>";
      $output .= "<h5 style='color: #0066cc;'>📊 Requête {$subQueryNum} : " . htmlspecialchars($subQuery) . "</h5>";

      // Check if this sub-query failed
      if (isset($subResult['error']) || (isset($subResult['success']) && $subResult['success'] === false)) {
        $errorMsg = $subResult['error'] ?? 'Erreur inconnue';
        $output .= "<div class='alert alert-warning'>";
        $output .= "<strong>⚠️ Erreur :</strong> " . htmlspecialchars($errorMsg);
        $output .= "</div>";
        $output .= "</div>"; // Close sub-query-result
        continue;
      }

      // SQL query display
      if ($this->displaySql && isset($subResult['sql'])) {
        $output .= $this->formatSqlQuery($subResult['sql']);
      }

      // Interpretation
      if (isset($subResult['interpretation']) && !empty($subResult['interpretation'])) {
        $output .= "<div class='interpretation'><strong>Interprétation :</strong> " 
                . Hash::displayDecryptedDataText($subResult['interpretation']) . "</div>";
      }

      // Data table
      if (isset($subResult['rows']) && is_array($subResult['rows']) && !empty($subResult['rows'])) {
        $output .= "<div class='results-table'>";
        $output .= "<h6>Données :</h6>";
        $output .= $this->generateTable($subResult['rows'], 'table table-bordered table-striped');
        $output .= "<div class='result-count'><em>Nombre de résultats : " . $subResult['row_count'] . "</em></div>";
        $output .= "</div>";
      } else {
        $output .= "<div class='alert alert-info'>";
        $output .= "<strong>Note :</strong> Aucune donnée trouvée pour cette requête.";
        $output .= "</div>";
      }

      $output .= "</div>"; // Close sub-query-result
    }

    // Display all SQL queries if requested
    if ($this->displaySql && isset($results['sql_queries']) && is_array($results['sql_queries'])) {
      $output .= "<div class='all-sql-queries' style='margin-top: 20px;'>";
      $output .= "<h5>Toutes les requêtes SQL exécutées :</h5>";
      foreach ($results['sql_queries'] as $index => $sql) {
        $output .= "<div class='sql-query-item'>";
        $output .= "<strong>Requête " . ($index + 1) . " :</strong>";
        $output .= $this->formatSqlQuery($sql);
        $output .= "</div>";
      }
      $output .= "</div>";
    }

    $output .= "</div>"; // Close analytics-results

    // Save audit data
    $auditExtra = [
      'multiple_queries' => true,
      'query_count' => $queryCount,
      'embeddings_context' => $results['embeddings_context'] ?? [],
      'similarity_scores'  => $results['similarity_scores'] ?? [],
      'processing_chain'   => $results['processing_chain'] ?? []
    ];
    Gpt::saveData($originalQuery, $output, $auditExtra);

    return [
      'type' => 'formatted_results',
      'content' => $output
    ];
  }

  private function formatSqlQuery(string $sql): string
  {
    $formatted = $this->prettySql($sql);
    $escaped = htmlspecialchars($formatted, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return "<div class='col-md-12 row sql-query'>
            <strong>Requête SQL :</strong>
            <pre>{$escaped}</pre>
          </div>";
  }

  private function formatDataTable(array $data): string
  {
    if (empty($data)) {
      return '';
    }

    $output = "<div class='results-table'>";
    $output .= "<h5>Données :</h5>";
    
    // Use inherited method from AbstractFormatter
    $output .= $this->generateTable($data, 'table table-bordered table-striped');
    
    $output .= "</div>";

    return $output;
  }

  private function formatGuardrailsMetrics(array $guardrails): string
  {
    // Implementation similar to original ResultFormatter
    $output = "<div class='guardrails-metrics'>";
    // Add guardrails display logic here
    $output .= "</div>";
    return $output;
  }
}
