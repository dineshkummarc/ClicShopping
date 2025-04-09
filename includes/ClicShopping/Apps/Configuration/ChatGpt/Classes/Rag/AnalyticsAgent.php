<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
* AnalyticsAgent Class
*
* This class uses LLPhant to create an intelligent agent capable
* of interpreting complex business queries and generating SQL queries
* for e-commerce data analysis.
*/
class AnalyticsAgent
{
  private mixed $chat;
  private mixed $db;
  private int $languageId;

/**
* Constructor for AnalyticsAgent
*
* @param int|null $languageId Language ID for filtering results
*/
  public function __construct(?int $languageId = null)
  {
    $this->db = Registry::get('Db');
    $this->languageId = $languageId ?? Registry::get('Language')->getId();
    $this->chat = Gpt::getOpenAiGpt(null);
    $this->setSystemMessage();
  }

 /**
 * Configures the system message for the LLPhant agent
 */
  private function setSystemMessage(): void
  {
    $systemMessage = CLICSHOPPING::getDef('text_system_message', ['language_id' => $this->languageId]);
    $this->chat->setSystemMessage($systemMessage);
  }

 /**
    * Generates only the SQL query for a given question
    *
    * @param string $question The question in natural language
    * @return string The generated SQL query
    */
  public function getRequeteSQL(string $question): string
  {
    $response = $this->chat->generateText($question);
    $cleanedResponse = $this->cleanSqlResponse($response);

    if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
      error_log("Analytics SQL Query for '$question': " . $cleanedResponse);
    }

    return $cleanedResponse;
  }

/**
 * Cleans the SQL response by removing formatting tags and other unwanted elements.
 *
 * @param string $response Raw response from the model.
 * @return string Cleaned SQL query.
 */
  private function cleanSqlResponse(string $response): string
  {
    $cleaned = preg_replace('/```sql\s*|\s*```/', '', $response);
    $cleaned = strip_tags($cleaned);
    $cleaned = trim($cleaned);

    return $cleaned;
  }

/**
 * Executes the generated SQL query and returns the results.
 *
 * @param string $question The business question in natural language.
 * @return array The query results.
 */
  public function executeQuery(string $question): array
  {
    try {
      $sqlQuery = $this->getRequeteSQL($question);
      $query = $this->db->prepare($sqlQuery);
      $query->execute();
      $results = $query->fetchAll();

      return [
        'type' => 'analytics_results',
        'query' => $question,
        'sql_query' => $sqlQuery,
        'results' => $results,
        'count' => count($results)
      ];
    } catch (\Exception $e) {
      // Log de l'erreur pour débogage
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Analytics SQL Error: " . $e->getMessage());
        error_log("Failed SQL Query: " . ($sqlQuery ?? 'Requête non générée'));
      }

      return [
        'type' => 'error',
        'message' => $e->getMessage(),
        'query' => $question,
        'sql_query' => $sqlQuery ?? 'Requête non générée'
      ];
    }
  }

/**
* Processes a complete business query (SQL generation + execution + interpretation)
*
* @param string $question The business question in natural language
* @param bool $includeSQL Whether to include the SQL query in the response
* @return array Complete results with interpretation
*/
  public function processBusinessQuery(string $question, bool $includeSQL = true): array
  {
    try {
      $results = $this->executeQuery($question);

      if ($results['type'] === 'error') {
        return $results;
      }

      $interpretation = $this->interpretResults($question, $results['results']);

      // Préparer la réponse
      $response = [
        'type' => 'analytics_response',
        'question' => $question,
        'sql_query' => $results['sql_query'],
        'interpretation' => $interpretation,
        'count' => $results['count'],
        'results' => $results['results']
      ];

      return $response;
    } catch (\Exception $e) {
      // Log de l'erreur pour débogage
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Analytics Processing Error: " . $e->getMessage());
      }

      return [
        'type' => 'error',
        'message' => $e->getMessage(),
        'question' => $question
      ];
    }
  }

 /**
  * Interprets the results of a query in natural language.
  *
  * @param string $question The original question.
  * @param array $results The query results.
  * @return string The interpretation in natural language.
  */
  private function interpretResults(string $question, array $results): string
  {
    $array = [
      'question' => $question,
      'results' => json_encode($results, JSON_PRETTY_PRINT)
    ];

    $prompt = CLICSHOPPING::getDef('text_interpret_results', $array);

    $interpretation = $this->chat->generateText($prompt);

    return $interpretation;
  }

 /**
 * Checks if a query is of analytical type
 *
 * @param string $query Query to check
 * @return bool True if the query is analytical, false otherwise
 */
  public function isAnalyticsQuery(string $query): bool
  {
    $analyticsPatterns = [
      '/combien|total|nombre|count|somme|sum|moyenne|average|min|max/i',
      '/stock|inventaire|disponible|disponibilité|alerte|niveau|reorder/i',
      '/REF[-\s]?\d+|SKU[-\s]?\d+|EAN[-\s]?\d+|\b\d{8,13}\b|ID\s*:\s*\d+/i',
      '/prix\s*(>|<|>=|<=|=)\s*(\d+[\.,]?\d*)/i',
      '/quantité\s*(>|<|>=|<=|=)\s*(\d+)/i',
      '/par catégorie|par client|par produit|par mois|par jour|par semaine|par an/i',
      '/top|meilleur|plus vendu|best seller|populaire/i',
      '/chiffre d\'affaires|ca|revenu|vente/i'
    ];

    foreach ($analyticsPatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }

    return false;
  }
}
