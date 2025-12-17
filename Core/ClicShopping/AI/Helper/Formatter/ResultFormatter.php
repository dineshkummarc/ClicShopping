<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM)  at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Helper\Formatter;

use AllowDynamicProperties;

use ClicShopping\AI\Security\LlmGuardrails;
use ClicShopping\AI\Helper\Formatter\SubResultFormatters\FormatterRouter;
use ClicShopping\AI\Helper\Formatter\SubResultFormatters\AnalyticsFormatter;
use ClicShopping\AI\Helper\Formatter\SubResultFormatters\SemanticFormatter;
use ClicShopping\AI\Helper\Formatter\SubResultFormatters\ComplexQueryFormatter;
use ClicShopping\AI\Helper\Formatter\SubResultFormatters\WebSearchFormatter;
use ClicShopping\AI\Helper\Formatter\SubResultFormatters\AmbiguousResultFormatter;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Hash;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * ResultFormatter Class (Refactored)
 *
 * Main orchestrator that uses FormatterRouter to select the appropriate
 * SubResultFormatter based on result type and complexity.
 */
#[AllowDynamicProperties]
class ResultFormatter
{
  private FormatterRouter $router;
  private bool $debug;
  private bool $displaySql;
  public function __construct()
  {

    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';
    $this->displaySql = defined('CLICSHOPPING_APP_CHATGPT_RA_DISPLAY_SQL') && CLICSHOPPING_APP_CHATGPT_RA_DISPLAY_SQL === 'True';

    // Initialize router
    $this->router = new FormatterRouter($this->debug);

    // Register formatters with priorities (higher = checked first)
    $this->router->registerFormatter(new ComplexQueryFormatter($this->debug, $this->displaySql), 100);
    $this->router->registerFormatter(new AmbiguousResultFormatter($this->debug, $this->displaySql), 90);
    $this->router->registerFormatter(new AnalyticsFormatter($this->debug, $this->displaySql), 80);
    $this->router->registerFormatter(new WebSearchFormatter($this->debug, $this->displaySql), 70);
    $this->router->registerFormatter(new SemanticFormatter($this->debug, $this->displaySql), 60);
  }

  /**
   * Formats the results based on their type using intelligent routing
   *
   * @param array $results The results to format.
   * @return array The formatted results.
   */
  public function format(array $results): array
  {
    // Ensure we have a valid results array
    if (empty($results) || !is_array($results)) {
      return [
        'type' => 'formatted_results',
        'content' => '<div class="alert alert-warning">Aucun résultat à afficher</div>'
      ];
    }

    // If it's an error, return it as is
    if (isset($results['type']) && $results['type'] === 'error') {
      return $results;
    }

    // If it's a clarification request, format it properly
    if (isset($results['type']) && $results['type'] === 'clarification_needed') {
      $message = $results['message'] ?? 'Votre requête nécessite des précisions.';
      return [
        'type' => 'formatted_results',
        'content' => '<div class="alert alert-warning"><i class="bi bi-question-circle"></i> <strong>Clarification nécessaire:</strong><br>' . htmlspecialchars($message) . '</div>'
      ];
    }

    // Use router to find appropriate formatter
    $formatter = $this->router->route($results);

    if ($formatter) {
      $formatterClass = get_class($formatter);
      $resultType = $results['type'] ?? 'unknown';
      
      // ✅ ALWAYS LOG SYNTHESIS OPERATIONS (Requirement 8.4)
      $startTime = microtime(true);
      $formattedResult = $formatter->format($results);
      $executionTime = round((microtime(true) - $startTime) * 1000, 2);
      
      $contentLength = isset($formattedResult['content']) ? strlen($formattedResult['content']) : 0;
      
      error_log(sprintf(
        '[RAG] Synthesis: type=%s, formatter=%s, length=%d, time=%dms',
        $resultType,
        basename(str_replace('\\', '/', $formatterClass)),
        $contentLength,
        $executionTime
      ));
      
      if ($this->debug) {
        error_log('ResultFormatter: Using ' . $formatterClass);
        error_log($this->router->getComplexityReport($results));
      }

      return $formattedResult;
    }

    // Fallback: return raw data
    if ($this->debug) {
      error_log('ResultFormatter: No formatter found, using fallback');
    }

    return [
      'type' => 'formatted_results',
      'content' => '<div class="alert alert-info"><strong>Résultat brut:</strong><pre>'
        . htmlspecialchars(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
        . '</pre></div>'
    ];
  }

  /**
   * Formats the results with memory context integration
   * 
   * @param array $results The results to format
   * @param array $memoryContext Memory context from ConversationMemory
   * @return array The formatted results with memory context display
   */
  public function formatWithMemory(array $results, array $memoryContext): array
  {
    // First, format the results normally
    $formattedResults = $this->format($results);
    
    // If memory context is empty or not relevant, return normal formatting
    if (empty($memoryContext) || !$this->hasRelevantMemoryContext($memoryContext)) {
      return $formattedResults;
    }
    
    // Add memory context display to the formatted content
    $memoryDisplay = $this->formatMemoryContext($memoryContext);
    
    // Prepend memory context to the content
    if (isset($formattedResults['content'])) {
      $formattedResults['content'] = $memoryDisplay . $formattedResults['content'];
    }
    
    // Add memory metadata
    $formattedResults['has_memory_context'] = true;
    $formattedResults['memory_metadata'] = [
      'short_term_count' => count($memoryContext['short_term_context'] ?? []),
      'long_term_count' => count($memoryContext['long_term_context'] ?? []),
      'feedback_count' => count($memoryContext['feedback_context'] ?? []),
    ];
    
    if ($this->debug) {
      error_log('ResultFormatter: Memory context integrated - ' 
        . 'Short-term: ' . $formattedResults['memory_metadata']['short_term_count']
        . ', Long-term: ' . $formattedResults['memory_metadata']['long_term_count']
        . ', Feedback: ' . $formattedResults['memory_metadata']['feedback_count']);
    }
    
    return $formattedResults;
  }

  /**
   * Checks if memory context contains relevant information
   * 
   * @param array $memoryContext Memory context data
   * @return bool True if context is relevant
   */
  private function hasRelevantMemoryContext(array $memoryContext): bool
  {
    // Check if has_context flag is set
    if (isset($memoryContext['has_context']) && !$memoryContext['has_context']) {
      return false;
    }
    
    // Check if any context arrays have content
    $hasShortTerm = !empty($memoryContext['short_term_context']);
    $hasLongTerm = !empty($memoryContext['long_term_context']);
    $hasFeedback = !empty($memoryContext['feedback_context']);
    
    return $hasShortTerm || $hasLongTerm || $hasFeedback;
  }

  /**
   * Formats memory context for display
   * 
   * @param array $memoryContext Memory context data
   * @return string Formatted HTML for memory context display
   */
  private function formatMemoryContext(array $memoryContext): string
  {
    $output = '<div class="memory-context-display alert alert-secondary" style="margin-bottom: 15px; padding: 10px; border-left: 4px solid #6c757d;">';
    $output .= '<h6 style="margin-top: 0;"><strong>💾 Contexte Mémoire</strong></h6>';
    
    // Short-term (working) memory display
    if (!empty($memoryContext['short_term_context'])) {
      $shortTermCount = count($memoryContext['short_term_context']);
      $output .= '<div class="memory-section" style="margin-bottom: 8px;">';
      $output .= '<span class="badge badge-info" title="Mémoire de travail - Conversation récente">';
      $output .= '🔄 Mémoire de Travail: ' . $shortTermCount . ' message(s)';
      $output .= '</span>';
      
      // Add tooltip with details
      if ($this->debug || $shortTermCount <= 3) {
        $output .= '<div class="memory-details" style="font-size: 0.85em; color: #666; margin-top: 5px; padding-left: 10px;">';
        foreach (array_slice($memoryContext['short_term_context'], -3) as $msg) {
          $role = $msg['role'] ?? 'unknown';
          $content = $msg['content'] ?? '';
          $preview = mb_substr($content, 0, 50) . (mb_strlen($content) > 50 ? '...' : '');
          $output .= '<div style="margin-bottom: 3px;">• <em>' . htmlspecialchars($role) . ':</em> ' . htmlspecialchars($preview) . '</div>';
        }
        $output .= '</div>';
      }
      $output .= '</div>';
    }
    
    // Long-term memory display
    if (!empty($memoryContext['long_term_context'])) {
      $longTermCount = count($memoryContext['long_term_context']);
      $output .= '<div class="memory-section" style="margin-bottom: 8px;">';
      $output .= '<span class="badge badge-primary" title="Mémoire à long terme - Interactions passées pertinentes">';
      $output .= '📚 Mémoire Long-Terme: ' . $longTermCount . ' référence(s)';
      $output .= '</span>';
      
      // Show similarity scores if available
      if ($this->debug) {
        $output .= '<div class="memory-details" style="font-size: 0.85em; color: #666; margin-top: 5px; padding-left: 10px;">';
        foreach (array_slice($memoryContext['long_term_context'], 0, 2) as $ref) {
          // Handle both Document objects and arrays
          if (is_object($ref)) {
            // Document object from LLPhant
            $score = isset($ref->metadata['score']) ? round($ref->metadata['score'] * 100) : 0;
            $timestamp = isset($ref->metadata['timestamp']) ? date('d/m/Y H:i', $ref->metadata['timestamp']) : 'N/A';
          } else {
            // Array format
            $score = isset($ref['similarity_score']) ? round($ref['similarity_score'] * 100) : 0;
            $timestamp = isset($ref['timestamp']) ? date('d/m/Y H:i', $ref['timestamp']) : 'N/A';
          }
          $output .= '<div style="margin-bottom: 3px;">• Score: ' . $score . '% - ' . $timestamp . '</div>';
        }
        $output .= '</div>';
      }
      $output .= '</div>';
    }
    
    // Feedback context display
    if (!empty($memoryContext['feedback_context'])) {
      $feedbackCount = count($memoryContext['feedback_context']);
      $output .= '<div class="memory-section" style="margin-bottom: 8px;">';
      $output .= '<span class="badge badge-success" title="Apprentissage - Feedback utilisateur pris en compte">';
      $output .= '💡 Feedback Appliqué: ' . $feedbackCount . ' correction(s)';
      $output .= '</span>';
      
      // Show feedback types
      if ($this->debug) {
        $output .= '<div class="memory-details" style="font-size: 0.85em; color: #666; margin-top: 5px; padding-left: 10px;">';
        foreach ($memoryContext['feedback_context'] as $feedback) {
          $type = $feedback['feedback_type'] ?? 'unknown';
          $relevance = isset($feedback['relevance_score']) ? round($feedback['relevance_score'] * 100) : 0;
          $output .= '<div style="margin-bottom: 3px;">• Type: ' . htmlspecialchars($type) . ' - Pertinence: ' . $relevance . '%</div>';
        }
        $output .= '</div>';
      }
      $output .= '</div>';
    }
    
    // Add info message about memory usage
    $output .= '<div style="font-size: 0.8em; color: #555; margin-top: 8px; font-style: italic;">';
    $output .= 'ℹ️ Cette réponse utilise le contexte de vos interactions précédentes pour améliorer la pertinence.';
    $output .= '</div>';
    
    $output .= '</div>';
    
    return $output;
  }

  /**
   * Maps technical column names to user-friendly display names using language definitions
   * 
   * @param string $columnName Technical column name from database
   * @return string User-friendly display name
   */
  private function mapColumnName(string $columnName): string
  {
    // Essayer d'obtenir la définition de langue pour cette colonne
    $langKey = 'column_' . $columnName;
    $displayName = CLICSHOPPING::getDef($langKey);

    // Si la définition existe et n'est pas la clé elle-même, l'utiliser
    if ($displayName && $displayName !== $langKey) {
      return $displayName;
    }

    // Sinon, formater le nom technique en nom lisible
    // Remplacer les underscores par des espaces et mettre en majuscule
    $displayName = str_replace('_', ' ', $columnName);
    $displayName = ucwords($displayName);

    return $displayName;
  }

  /**
   * Summary of generateTableHeaders
   * @param array $firstRow
   * @return string
   */
  private function generateTableHeaders(array $firstRow): string
  {
    // Filter out system metadata from headers
    $filteredRow = $this->filterSystemMetadata($firstRow);

    $headers = "<thead><tr>";
    foreach (array_keys($filteredRow) as $key) {
      // Utiliser le mapping intelligent pour les noms de colonnes
      if (is_numeric($key)) {
        $displayKey = "Colonne " . ($key + 1);
      } else {
        $displayKey = $this->mapColumnName($key);
      }
      $headers .= "<th>" . htmlspecialchars($displayKey) . "</th>";
    }
    $headers .= "</tr></thead>";
    return $headers;
  }

  /**
   * Formats a cell value based on its column name and content
   * 
   * @param string $columnName Column name to determine formatting
   * @param mixed $value Value to format
   * @return string Formatted value
   */
  private function formatCellValue(string $columnName, mixed $value): string
  {
    // Si la valeur est null ou vide - utiliser les définitions de langue
    if ($value === null) {
      $nullText = CLICSHOPPING::getDef('value_null');
      return $nullText && $nullText !== 'value_null' ? $nullText : '-';
    }

    if ($value === '') {
      $emptyText = CLICSHOPPING::getDef('value_empty');
      return $emptyText && $emptyText !== 'value_empty' ? $emptyText : '-';
    }

    // Nettoyer les caractères invisibles
    if (is_string($value)) {
      $value = HTMLOverrideCommon::removeInvisibleCharacters($value);
      $value = Hash::displayDecryptedDataText($value);
    }

    // Formatage selon le type de colonne

    // Quantités (DOIT ÊTRE TESTÉ EN PREMIER avant les prix)
    // Note: Noms de colonnes en anglais uniquement (système multilingue)
    if (preg_match('/(quantity|stock|sold|count|total_products|total_quantity|number|items)/i', $columnName)) {
      if (is_numeric($value)) {
        return number_format((int) $value, 0, ',', ' ');
      }
    }

    // Prix et montants (seulement si ce n'est PAS une quantité)
    // Note: Noms de colonnes en anglais uniquement (système multilingue)
    if (preg_match('/(price|amount|revenue|total_amount|subtotal|cost)/i', $columnName)) {
      if (is_numeric($value)) {
        $currency = defined('DEFAULT_CURRENCY') ? DEFAULT_CURRENCY : '€';
        return number_format((float) $value, 2, ',', ' ') . ' ' . $currency;
      }
    }

    // Dates
    if (preg_match('/(date|datetime)/i', $columnName)) {
      // Si c'est un timestamp
      if (is_numeric($value) && $value > 1000000000) {
        return date('d/m/Y H:i', (int) $value);
      }
      // Si c'est une date SQL
      if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        $timestamp = strtotime($value);
        return date('d/m/Y H:i', $timestamp);
      }
    }

    // Statut - utiliser les définitions de langue
    if (preg_match('/(status|statut)/i', $columnName)) {
      // Mapper les valeurs de statut vers les clés de langue
      $statusKeyMap = [
        '0' => 'status_inactive',
        '1' => 'status_active',
        'pending' => 'status_pending',
        'completed' => 'status_completed',
        'cancelled' => 'status_cancelled',
        'processing' => 'status_processing',
      ];

      $lowerValue = strtolower($value);
      if (isset($statusKeyMap[$lowerValue])) {
        $statusText = CLICSHOPPING::getDef($statusKeyMap[$lowerValue]);
        if ($statusText && $statusText !== $statusKeyMap[$lowerValue]) {
          return $statusText;
        }
      }
    }

    // Par défaut, retourner la valeur nettoyée
    return htmlspecialchars((string) $value);
  }

  /**
   * Filters out system metadata fields from data rows
   * 
   * System fields are used internally but should not be displayed to users
   * 
   * @param array $row Data row with potential system fields
   * @return array Filtered row with only business data
   */
  private function filterSystemMetadata(array $row): array
  {
    // List of system metadata fields to exclude from display
    $systemFields = [
      'entity_id',
      'entity_type',
      'language_id',
      'timestamp',
      'user_id',
      'created_at',
      'updated_at',
      'metadata',
      '_entity_metadata',
      'internal_id',
      'system_id'
    ];

    $filteredRow = [];

    foreach ($row as $key => $value) {
      // Skip system fields
      if (!in_array($key, $systemFields)) {
        $filteredRow[$key] = $value;
      }
    }

    return $filteredRow;
  }

  private function generateTableRows(array $data): string
  {
    $rows = "<tbody>";

    foreach ($data as $row) {
      $rows .= "<tr>";

      // Filter out system metadata before displaying
      $filteredRow = $this->filterSystemMetadata($row);

      foreach ($filteredRow as $key => $value) {
        // Utiliser le formatage intelligent pour chaque cellule
        $formattedValue = $this->formatCellValue($key, $value);
        $rows .= "<td>" . $formattedValue . "</td>";
      }
      $rows .= "</tr>";
    }
    $rows .= "</tbody>";

    return $rows;
  }

  /**
   * Formats SQL queries for better readability.
   *
   * @param string $sql The SQL query to format.
   * @return string The formatted SQL query.
   */
  private function prettySql(string $sql): string
  {
    // On normalise tous les retours à la ligne existants
    $sql = preg_replace('/\s+/', ' ', trim($sql));

    // Liste de mots clés devant être passés à la ligne
    $keywords = [
      'SELECT',
      'FROM',
      'JOIN',
      'WHERE',
      'GROUP BY',
      'ORDER BY',
      'LIMIT',
      'ON',
      'AND',
      'OR'
    ];

    // On place un \n avant chaque mot clé
    foreach ($keywords as $kw) {
      // \b pour ne matcher QUE le mot exact (cas‐insensible)
      $sql = preg_replace("/\b$kw\b/i", "\n$kw", $sql);
    }

    // On place un \n après chaque virgule
    $sql = str_replace(',', ",\n    ", $sql);

    // On débarrasse d'éventuels sauts de ligne consécutifs
    $sql = preg_replace("/\n{2,}/", "\n", $sql);

    return trim($sql);
  }

  /**
   * Formats analytics results for display.
   *
   * @param array $results The analytics results to format.
   * @return string The formatted HTML output.
   */
  private function formatAnalyticsResults(array $results): string
  {
    $question = $results['question'] ?? $results['query'] ?? 'Unknown request';

    $output = "<div class='analytics-results'>";
    $output .= "<h4>Résultats pour : " . htmlspecialchars($question) . "</h4>";

    $display_sql = defined('CLICSHOPPING_APP_CHATGPT_RA_DISPLAY_SQL') && CLICSHOPPING_APP_CHATGPT_RA_DISPLAY_SQL === 'True';

    if (isset($results['sql_query']) && $display_sql) {
      $formatted = $this->prettySql($results['sql_query']);

      $escaped = htmlspecialchars($formatted, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

      $output .= "<div class='col-md-12 row sql-query'>
                  <strong>SQL request :</strong>
                  <pre>{$escaped}</pre>
                </div>";
    }

    // Use text_response if available, fallback to interpretation
    $interpretationText = '';
    if (isset($results['text_response']) && !empty($results['text_response'])) {
      $interpretationText = $results['text_response'];
    } elseif (isset($results['interpretation']) && $results['interpretation'] !== 'Array') {
      $interpretationText = $results['interpretation'];
    }

    if (!empty($interpretationText)) {
      $output .= "<div class='interpretation'><strong>Interpretation :</strong> " . Hash::displayDecryptedDataText($interpretationText) . "</div>";
    }

    // Display source attribution
    if (isset($results['source_attribution'])) {
      $output .= $this->formatSourceAttribution($results['source_attribution']);
    }

    // Toujours afficher les guardrails
    $output .= "<div class='mt-2'></div>";

    $lmGuardrails = LlmGuardrails::checkGuardrails($question, Hash::displayDecryptedDataText($interpretationText));

    if (is_array($lmGuardrails)) {
      $output .= $this->formatGuardrailsMetrics($lmGuardrails);
    } else {
      $output .= "<div class='alert alert-warning'>" . htmlspecialchars($lmGuardrails) . "</div>";
    }

    $output .= "<div class='mt-2'></div>";

    // Afficher les données structurées si disponibles
    if (isset($results['results']) && is_array($results['results']) && !empty($results['results'])) {
      $output .= "<div class='results-table'>";
      $output .= "<h5>Données :</h5>";
      $output .= "<table class='table table-bordered table-striped'>";

      $firstRow = !empty($results['results']) ? array_values($results['results'])[0] : null;
      if (is_array($firstRow)) {
        $output .= $this->generateTableHeaders($firstRow);
      }

      $output .= $this->generateTableRows($results['results']);
      $output .= "</table>";
      $output .= "</div>";
    } else {
      // Si pas de données structurées, afficher un message informatif
      $output .= "<div class='alert alert-info'>";
      $output .= "<strong>Note :</strong> Les données détaillées sont disponibles dans l'interprétation ci-dessus.";
      $output .= "</div>";
    }

    $output .= "</div>";

    $auditExtra = [
      'embeddings_context' => $results['embeddings_context'] ?? [],
      'similarity_scores' => $results['similarity_scores'] ?? [],
      'processing_chain' => $results['processing_chain'] ?? []
    ];

    Gpt::saveData($question, $output, $auditExtra);

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
    if ($this->debug) {
      error_log("=== FORMAT SEMANTIC RESULTS ===");
      error_log("Results keys: " . implode(', ', array_keys($results)));
      error_log("Has response: " . (isset($results['response']) ? 'YES (' . strlen($results['response']) . ' chars)' : 'NO'));
      error_log("Has interpretation: " . (isset($results['interpretation']) ? 'YES (' . strlen($results['interpretation']) . ' chars)' : 'NO'));
      error_log("Has query: " . (isset($results['query']) ? 'YES' : 'NO'));
    }

    $question = $results['query'] ?? $results['question'] ?? 'Unknown request';

    $output = "<div class='semantic-results'>";
    $output .= "<h3>Résultats pour : " . htmlspecialchars($question) . "</h3>";

    // Display source attribution
    if (isset($results['source_attribution'])) {
      $output .= $this->formatSourceAttribution($results['source_attribution']);
    }

    // 🔧 NOUVEAU: Ajouter les métriques de guardrails pour les résultats sémantiques aussi
    $output .= "<div class='mt-2'></div>";

    // Utiliser 'interpretation' au lieu de 'response' pour compatibilité avec LlmResponseProcessor
    $responseContent = $results['response'] ?? $results['interpretation'] ?? '';

    if ($this->debug) {
      error_log("Response content length: " . strlen($responseContent));
      error_log("Response content preview: " . substr($responseContent, 0, 200));
    }

    $lmGuardrails = LlmGuardrails::checkGuardrails($question, Hash::displayDecryptedDataText($responseContent));

    if (is_array($lmGuardrails)) {
      $output .= $this->formatGuardrailsMetrics($lmGuardrails);
    } else {
      $output .= "<div class='alert alert-warning'>" . htmlspecialchars($lmGuardrails) . "</div>";
    }

    $output .= "<div class='mt-2'></div>";

    // Display the response - Support both 'response' and 'interpretation' fields
    if (!empty($results['response'])) {
      $output .= "<div class='response'><strong>Réponse :</strong><br>" . Hash::displayDecryptedDataText($results['response']) . "</div>";
      if ($this->debug) {
        error_log("✓ Using 'response' field for display");
      }
    } elseif (!empty($results['interpretation'])) {
      $output .= "<div class='response'><strong>Réponse :</strong><br>" . Hash::displayDecryptedDataText($results['interpretation']) . "</div>";
      if ($this->debug) {
        error_log("✓ Using 'interpretation' field for display");
      }
    } else {
      if ($this->debug) {
        error_log("✗ NO response or interpretation field found!");
      }
    }

    // 🗑️ REMOVED: Display the sources - Hidden from chat display
    // if (!empty($results['sources']) && is_array($results['sources'])) {
    //   $output .= "<div class='sources mt-3'>";
    //   $output .= "<h5>Sources :</h5>";
    //   $output .= "<ul>";
    //   foreach ($results['sources'] as $source) {
    //     $output .= "<li>";
    //     if (isset($source['title'])) {
    //       $output .= "<strong>" . htmlspecialchars($source['title']) . "</strong>";
    //     }
    //     if (isset($source['content'])) {
    //       $output .= "<p>" . htmlspecialchars(substr($source['content'], 0, 200)) . "...</p>";
    //     }
    //     $output .= "</li>";
    //   }
    //   $output .= "</ul>";
    //   $output .= "</div>";
    // }

    $output .= "</div>";

    $auditExtra = [
      'embeddings_context' => $results['embeddings_context'] ?? [],
      'similarity_scores' => $results['similarity_scores'] ?? [],
      'processing_chain' => $results['processing_chain'] ?? []
    ];

    Gpt::saveData($question, $output, $auditExtra);

    return $output;
  }

  /**
   * Formats unknown result types for display.
   *
   * @param array $results The unknown results to format.
   * @return string The formatted HTML output.
   */
  /*
  private function formatUnknownResults(array $results): string
  {
    $output = "<div class='unknown-results'>";
    $output .= "<h3>Unhandled result type: " . htmlspecialchars($results['type'] ?? 'unknown') . "</h3>";
    $output .= "<p>Raw result content:</p>";
    $output .= "<pre>" . htmlspecialchars(json_encode($results, JSON_PRETTY_PRINT)) . "</pre>";
    $output .= "</div>";

    return $output;
  }
*/
  private function formatUnknownResults(array $results): string
  {
    $formatted = [
      'status' => 'unhandled_result_type',
      'type' => htmlspecialchars($results['type'] ?? 'unknown'),
      'raw_content' => $results
    ];

    return json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Formats source attribution for display
   *
   * @param array $sourceAttribution Source attribution data
   * @return string Formatted HTML output with source information
   */
  protected function formatSourceAttribution(array $sourceAttribution): string
  {
    if (empty($sourceAttribution)) {
      return '';
    }

    $output = '<div class="source-attribution alert alert-info" style="margin-top: 10px; padding: 10px; border-left: 4px solid #17a2b8;">';
    $output .= '<h6 style="margin-top: 0;"><strong>📍 Source d\'Information</strong></h6>';
    
    // Main source type with icon
    $icon = $sourceAttribution['source_icon'] ?? '📄';
    $sourceType = $sourceAttribution['source_type'] ?? 'Unknown';
    $sourceDetails = $sourceAttribution['source_details'] ?? '';
    
    $output .= '<div style="margin-bottom: 5px;">';
    $output .= '<span style="font-size: 1.2em;">' . $icon . '</span> ';
    $output .= '<strong>' . htmlspecialchars($sourceType) . '</strong>';
    $output .= '</div>';
    
    if (!empty($sourceDetails)) {
      $output .= '<div style="font-size: 0.9em; color: #666; margin-bottom: 8px;">';
      $output .= htmlspecialchars($sourceDetails);
      $output .= '</div>';
    }
    
    // Additional details based on source type
    if (isset($sourceAttribution['table_name']) && $sourceAttribution['table_name'] !== 'database') {
      $output .= '<div style="font-size: 0.85em; color: #555;">';
      $output .= '📋 Table: <code>' . htmlspecialchars($sourceAttribution['table_name']) . '</code>';
      $output .= '</div>';
    }
    
    if (isset($sourceAttribution['document_count']) && $sourceAttribution['document_count'] > 0) {
      $output .= '<div style="font-size: 0.85em; color: #555;">';
      $output .= '📚 Documents: ' . $sourceAttribution['document_count'];
      $output .= '</div>';
    }
    
    if (isset($sourceAttribution['urls']) && is_array($sourceAttribution['urls']) && !empty($sourceAttribution['urls'])) {
      $output .= '<div style="font-size: 0.85em; color: #555; margin-top: 5px;">';
      $output .= '🔗 URLs: ';
      $urlCount = count($sourceAttribution['urls']);
      $output .= '<span class="badge badge-secondary">' . $urlCount . ' source(s)</span>';
      $output .= '</div>';
    }
    
    if (isset($sourceAttribution['sources']) && is_array($sourceAttribution['sources'])) {
      $output .= '<div style="font-size: 0.85em; color: #555; margin-top: 5px;">';
      $output .= '🔀 Sources multiples: ';
      $output .= '<ul style="margin: 5px 0; padding-left: 20px;">';
      foreach ($sourceAttribution['sources'] as $source) {
        $output .= '<li>' . htmlspecialchars($source) . '</li>';
      }
      $output .= '</ul>';
      $output .= '</div>';
    }
    
    $output .= '</div>';
    
    return $output;
  }

  /**
   * Formats guardrails metrics with enhanced security information
   *
   * @param array $lmGuardrails The guardrails evaluation results
   * @return string Formatted HTML output with metrics and security indicators
   */
  protected function formatGuardrailsMetrics(array $lmGuardrails): string
  {
    $output = '<div class="guardrails-metrics">';
    $output .= '<h6>🔍 Métriques de Qualité et Sécurité:</h6>';
    $output .= '<div class="row">';

    // Colonne 1: Métriques de qualité
    $output .= '<div class="col-md-6">';
    $output .= '<ul class="list-unstyled">';

    // Relevance
    $relevance = round($lmGuardrails['relevance'] * 100);
    $relevanceClass = $this->getScoreClass($relevance);
    $output .= '<li class="' . $relevanceClass . '">📊 ' . CLICSHOPPING::getDef('llm_guardrails_relevance') . ' : ' . $relevance . '%</li>';

    // Accuracy
    $accuracy = round($lmGuardrails['accuracy'] * 100);
    $accuracyClass = $this->getScoreClass($accuracy);
    $output .= '<li class="' . $accuracyClass . '">🎯 ' . CLICSHOPPING::getDef('llm_guardrails_accuracy') . ' : ' . $accuracy . '%</li>';

    // Completeness
    $completeness = round($lmGuardrails['completeness'] * 100);
    $completenessClass = $this->getScoreClass($completeness);
    $output .= '<li class="' . $completenessClass . '">📋 ' . CLICSHOPPING::getDef('llm_guardrails_completeness') . ' : ' . $completeness . '%</li>';

    // Clarity
    $clarity = round($lmGuardrails['clarity'] * 100);
    $clarityClass = $this->getScoreClass($clarity);
    $output .= '<li class="' . $clarityClass . '">💡 ' . CLICSHOPPING::getDef('llm_guardrails_clarity') . ' : ' . $clarity . '%</li>';

    $output .= '</ul>';
    $output .= '</div>';

    // Colonne 2: Métriques de sécurité
    $output .= '<div class="col-md-6">';
    $output .= '<ul class="list-unstyled">';

    // Security Score (calculé à partir des validations)
    if (isset($lmGuardrails['security_analysis']['overall_security_score'])) {
      $securityScore = $lmGuardrails['security_analysis']['overall_security_score'];
      $securityClass = $this->getSecurityClass($securityScore);
      $output .= '<li class="' . $securityClass . '">🔒 Score de Sécurité : ' . round($securityScore * 100) . '%</li>';
    }

    // Hallucination Risk
    if (isset($lmGuardrails['hallucination_risk'])) {
      $hallucinationRisk = round($lmGuardrails['hallucination_risk'] * 100);
      $riskClass = $this->getRiskClass($hallucinationRisk);
      $output .= '<li class="' . $riskClass . '">⚠️ Risque d\'Hallucination : ' . $hallucinationRisk . '%</li>';
    }

    // Source Quality
    if (isset($lmGuardrails['source_quality'])) {
      $sourceQuality = round($lmGuardrails['source_quality'] * 100);
      $sourceClass = $this->getScoreClass($sourceQuality);
      $output .= '<li class="' . $sourceClass . '">📚 Qualité des Sources : ' . $sourceQuality . '%</li>';
    }

    // Suspicious Patterns Detected
    if (isset($lmGuardrails['suspicious_patterns_count']) && $lmGuardrails['suspicious_patterns_count'] > 0) {
      $output .= '<li class="text-warning">🚨 Patterns Suspects : ' . $lmGuardrails['suspicious_patterns_count'] . '</li>';
    }

    $output .= '</ul>';
    $output .= '</div>';
    $output .= '</div>';

    // Overall Score
    $overallScore = round($lmGuardrails['overall_score'] * 100);
    $overallClass = $this->getScoreClass($overallScore);
    $output .= '<div class="text-center mt-2">';
    $output .= '<strong class="' . $overallClass . '">🏆 ' . CLICSHOPPING::getDef('llm_guardrails_overall_score') . ' : ' . $overallScore . '%</strong>';
    $output .= '</div>';

    $output .= '</div>';

    return $output;
  }

  /**
   * Get CSS class based on score value
   */
  protected function getScoreClass(int $score): string
  {
    if ($score >= 80)
      return 'text-success';
    if ($score >= 60)
      return 'text-warning';
    return 'text-danger';
  }

  /**
   * Get CSS class for security scores
   */
  protected function getSecurityClass(float $score): string
  {
    if ($score >= 0.8)
      return 'text-success';
    if ($score >= 0.6)
      return 'text-warning';
    return 'text-danger';
  }

  /**
   * Get CSS class for risk indicators (inverted logic)
   */
  protected function getRiskClass(int $risk): string
  {
    if ($risk <= 20)
      return 'text-success';
    if ($risk <= 50)
      return 'text-warning';
    return 'text-danger';
  }

  /**
   * Calculate overall security score from validation results
   */
  protected function calculateSecurityScore(array $guardrails): float
  {
    $scores = [];

    // Structural validation
    if (isset($guardrails['structural']['score'])) {
      $scores[] = $guardrails['structural']['score'];
    }

    // Content validation
    if (isset($guardrails['content']['score'])) {
      $scores[] = $guardrails['content']['score'];
    }

    // Hallucination check (inverted)
    if (isset($guardrails['hallucination']['score'])) {
      $scores[] = $guardrails['hallucination']['score'];
    }

    // Numerical validation
    if (isset($guardrails['numerical']['score'])) {
      $scores[] = $guardrails['numerical']['score'];
    }

    // Sources validation
    if (isset($guardrails['sources']['score'])) {
      $scores[] = $guardrails['sources']['score'];
    }

    return empty($scores) ? 0.5 : array_sum($scores) / count($scores);
  }

  /**
   * Format analytics results as plain text table
   * Moved from HybridQueryProcessor (Task 2.11.3)
   *
   * @param array $data Analytics data with rows
   * @return string Formatted text table
   */
  public static function formatAnalyticsAsText(array $data): string
  {
    $formatted = "Analytics Results:\n\n";
    
    foreach ($data as $index => $result) {
      if (isset($result['rows']) && is_array($result['rows'])) {
        $formatted .= "Result " . ($index + 1) . ":\n";
        $formatted .= "Rows: " . count($result['rows']) . "\n";
        
        // Format as simple table
        if (!empty($result['rows'])) {
          $firstRow = $result['rows'][0];
          if (is_array($firstRow)) {
            $formatted .= implode(" | ", array_keys($firstRow)) . "\n";
            $formatted .= str_repeat("-", 50) . "\n";
            
            foreach ($result['rows'] as $row) {
              $formatted .= implode(" | ", array_values($row)) . "\n";
            }
          }
        }
        $formatted .= "\n";
      }
    }
    
    return $formatted;
  }

  /**
   * Format web search results with citations as plain text
   * Moved from HybridQueryProcessor (Task 2.11.3)
   *
   * @param array $texts Text results
   * @param array $sources Source URLs with titles and snippets
   * @return string Formatted text with citations
   */
  public static function formatWebSearchAsText(array $texts, array $sources): string
  {
    $formatted = "Web Search Results:\n\n";
    
    // If we have structured sources with snippets, format them nicely
    if (!empty($sources)) {
      foreach ($sources as $index => $source) {
        if (is_array($source)) {
          $formatted .= ($index + 1) . ". " . ($source['title'] ?? 'Source') . "\n";
          
          if (!empty($source['snippet'])) {
            $formatted .= "   " . $source['snippet'] . "\n";
          }
          
          if (!empty($source['url'])) {
            $formatted .= "   Source: " . $source['url'] . "\n";
          }
          
          $formatted .= "\n";
        } elseif (is_string($source)) {
          $formatted .= ($index + 1) . ". " . $source . "\n\n";
        }
      }
    }
    
    // Add any additional text results
    if (!empty($texts)) {
      $formatted .= "\nAdditional Information:\n";
      $formatted .= implode("\n\n", $texts) . "\n";
    }
    
    return trim($formatted);
  }

  /**
   * Format price comparison report as plain text
   * Moved from HybridQueryProcessor (Task 2.11.3)
   *
   * @param array $comparison Comparison data from comparePrice()
   * @return string Formatted price comparison report
   */
  public static function formatPriceComparisonAsText(array $comparison): string
  {
    $output = "📊 PRICE COMPARISON REPORT\n";
    $output .= str_repeat("=", 60) . "\n\n";
    
    $output .= "Product: {$comparison['product_name']}\n";
    $output .= "Your Price: \${$comparison['internal_price']}\n\n";
    
    if ($comparison['total_competitors_found'] > 0) {
      $output .= "Competitors Analyzed: {$comparison['total_competitors_found']}\n";
      $output .= "Average Competitor Price: \$" . $comparison['comparison']['average_competitor_price'] . "\n\n";
      
      // Display competitor prices
      $output .= "Competitor Prices:\n";
      foreach ($comparison['competitor_prices'] as $i => $competitor) {
        $output .= "  " . ($i + 1) . ". {$competitor['source']}: \${$competitor['price']}\n";
      }
      $output .= "\n";
      
      // Display cheapest and most expensive
      if ($comparison['comparison']['cheapest']) {
        $cheapest = $comparison['comparison']['cheapest'];
        $output .= "💰 Cheapest: {$cheapest['source']} at \${$cheapest['competitor_price']}\n";
        $output .= "   Difference: \${$cheapest['difference']} ({$cheapest['percentage_difference']}%)\n\n";
      }
      
      if ($comparison['comparison']['most_expensive']) {
        $expensive = $comparison['comparison']['most_expensive'];
        $output .= "💎 Most Expensive: {$expensive['source']} at \${$expensive['competitor_price']}\n";
        $output .= "   Difference: \${$expensive['difference']} ({$expensive['percentage_difference']}%)\n\n";
      }
      
      // Display competitive status
      $statusEmoji = [
        'very_competitive' => '🟢',
        'competitive' => '🟡',
        'not_competitive' => '🔴',
        'unknown' => '⚪',
      ];
      
      $emoji = $statusEmoji[$comparison['competitive_status']] ?? '⚪';
      $output .= "{$emoji} Competitive Status: " . strtoupper($comparison['competitive_status']) . "\n\n";
      
      // Display recommendation
      $output .= "💡 RECOMMENDATION:\n";
      $output .= str_repeat("-", 60) . "\n";
      $output .= $comparison['recommendation'] . "\n";
      $output .= str_repeat("-", 60) . "\n";
      
    } else {
      $output .= "⚠️  No competitor prices found for comparison\n";
      $output .= "Recommendation: {$comparison['recommendation']}\n";
    }
    
    return $output;
  }
}
