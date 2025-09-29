<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Sites\Shop\Pages\RagBI;

use AllowDynamicProperties;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Tools\MCP\MCP;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Message;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\DoctrineOrm;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MultiDBRAGManager;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MariaDBVectorStore;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Semantics;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\MCPConnector;

#[AllowDynamicProperties]
class RagBI extends \ClicShopping\OM\PagesAbstract
{
  /**
   * Database connection instance.
   * @var mixed
   */
  public mixed $db;
  /**
   * ClicShopping application instance.
   * @var mixed
   */
  public mixed $app;
  /**
   * Display the message
   * @var bool
   */
  public mixed $message;

  private mixed $mcpConnector;

  /**
   * Determines if the site template should be used.
   * @var bool
   */
  protected bool $use_site_template = false;

  /**
   * The file name for the page.
   * @var string|null
   */
  protected ?string $file = null;

  /**
   * Initializes the class by setting up dependencies, headers, and request routing.
   * It handles all incoming API requests (GET, POST, OPTIONS) and routes them to the appropriate handler.
   *
   * @return void
   */
  protected function init()
  {
    if (!Registry::exists('MCP')) {
      Registry::set('MCP', new MCP());
    }

    $this->app = Registry::get('MCP');

    if (!Registry::exists('MCPConnector')) {
      Registry::set('MCPConnector', new MCPConnector());
    }
    $this->mcpConnector = Registry::get('MCPConnector');

    $result = $this->mcpConnector->checkSecurity();

    if (!Registry::exists('Message')) {
      Registry::set('Message', new Message());
    }

    $this->message = Registry::get('Message');

    // Set JSON content type
    header('Content-Type: application/json');

    // Enable CORS for MCP server
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    try {
      Gpt::getEnvironment();

      $prompt = HTML::sanitize($_POST['message']);
      //$prompt = 'donne moi un tableau de l evolution du chiffre d\'affaires par mois de l\'annéé 2025'; // test

      $languageId = Registry::get('Language')->getId();

      $ragManager = new MultiDBRAGManager();

      if (defined(
          'CLICSHOPPING_APP_CHATGPT_RA_RAG_MANAGER'
        ) && CLICSHOPPING_APP_CHATGPT_RA_RAG_MANAGER == 'True' && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True') {
        $queryType = isset($_POST['queryType']) ? HTML::sanitize($_POST['queryType']) : 'semantic';
//        $queryType = 'analytics'; // test

        if ($queryType === 'semantic') {
          $queryType = Semantics::classifyQuery($prompt);
        }

        if ($queryType === 'analytics') {
          $analyticsResults = $ragManager->executeAnalyticsQuery($prompt);
          $result = $ragManager->formatResults($analyticsResults);

          if (is_null($result)) {
            error_log("Error: result is null for analytic query.");
          }
        } else {
          if ($queryType === 'semantic') {
            // Approach 2: Use the current aborescence

            $embeddingGenerator = new OpenAI3LargeEmbeddingGenerator();
            $entityManager = DoctrineOrm::getEntityManager();
            $embeddingTables = [];

            $knownTables = $ragManager->knownEmbeddingTable();

            // Add first the known table
            foreach ($knownTables as $tableName) {
              try {
                $vectorStore = new MariaDBVectorStore($embeddingGenerator, $tableName);
                $embeddingTables[$tableName] = $vectorStore;
              } catch (\Exception $e) {
                if (\defined(
                    'CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER'
                  ) && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
                  error_log("Erreur lors de l'initialisation de la table {$tableName} : " . $e->getMessage());
                  // Continuer avec les autres tables en cas d'erreur
                }
              }
            }

            // Other table search inside the DB
            try {
              $tables = DoctrineOrm::getEmbeddingTables();

              foreach ($tables as $tableName) {
                if (!in_array($tableName, $knownTables)) {
                  try {
                    $vectorStore = new MariaDBVectorStore($embeddingGenerator, $tableName);
                    $embeddingTables[$tableName] = $vectorStore;
                  } catch (\Exception $e) {
                    if (\defined(
                        'CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER'
                      ) && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
                      error_log("Erreur lors de l'initialisation de la table {$tableName} : " . $e->getMessage());
                      // If error continue
                    }
                  }
                }
              }
            } catch (\Exception $e) {
              if (\defined(
                  'CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER'
                ) && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
                error_log("Erreur lors de la recherche des tables d'embedding : " . $e->getMessage());
                // If error continue
              }
            }

            // 4️ Search in all vector table inside the DB
            $allResults = [];
            $context = '';

            foreach ($embeddingTables as $tableName => $vectorStore) {
              // Check if the table is valid
              try {
                // Language filter if specified
                $filter = null;

                if ($languageId !== null) {
                  $filter = function ($metadata) use ($languageId) {
                    return isset($metadata['language_id']) && $metadata['language_id'] == $languageId;
                  };
                }

                // USe similaritySearch signature
                $results = $vectorStore->similaritySearch($prompt, 2, 0.5, $filter);

                foreach ($results as $doc) {
                  $entityInfo = '';
                  if (isset($doc->metadata['entity_type']) && isset($doc->metadata['entity_id'])) {
                    $entityInfo = " ({$doc->metadata['entity_type']} #{$doc->metadata['entity_id']})";
                  }

                  $context .= "Source: {$tableName}{$entityInfo}\n";
                  $context .= $doc->content . "\n\n";
                }
              } catch (\Exception $e) {
                error_log("Erreur lors de la recherche dans la table {$tableName} : " . $e->getMessage());
                // If error continue
              }
            }

            // 5️ Si des documents pertinents ont été trouvés, les envoyer à OpenAI pour une réponse enrichie
            if (!empty($context)) {
              $result = Gpt::getGptResponse($context . "\n\nQuestion : " . $prompt);
            } else {
              //If no information found, use openAI directly
              $result = Gpt::getGptResponse($prompt);
            }

            $pos = strstr($result, ':');
            if ($pos !== false) {
              $result = substr($pos, 2);
            }
          }
        }

        Gpt::saveData($prompt, $result, null, true);
        // Retourner du JSON pour l'API MCP
        echo json_encode([
          'status' => 'success',
          'data' => [
            'response' => $result,
            'type' => 'rag-bi',
            'source' => 'clicshopping-ragbi',
            'confidence' => 0.9,
            'metadata' => [
              'query_type' => $queryType,
              'language' => $languageId,
              'processing_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
              'rag_enabled' => true,
              'clicshopping_processed' => true
            ]
          ]
        ]);

        exit;
      } else {
        $result = Gpt::getGptResponse($prompt);

        $pos = strstr($result, ':');

        if ($pos !== false) {
          $result = substr($pos, 2); // Pour enlever les deux-points et l'espace
        }

        Gpt::saveData($prompt, $result, null, true);

        // Retourner du JSON pour l'API MCP
        echo json_encode([
          'status' => 'success',
          'data' => [
            'response' => $result,
            'type' => 'rag-bi',
            'source' => 'clicshopping-ragbi',
            'confidence' => 0.8,
            'metadata' => [
              'query_type' => 'semantic',
              'language' => $languageId,
              'processing_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
              'rag_enabled' => false,
              'clicshopping_processed' => true
            ]
          ]
        ]);
      }
      exit;
    } catch
    (\Exception $e) {
      error_log('Erreur dans le traitement AJAX : ' . $e->getMessage());
      echo json_encode([
        'status' => 'error',
        'data' => [
          'response' => "Une erreur s'est produite lors du traitement de votre requête : " . $e->getMessage(),
          'type' => 'error',
          'source' => 'clicshopping-ragbi',
          'confidence' => 0.1,
          'error' => [
            'type' => 'processing_error',
            'message' => $e->getMessage()
          ],
          'metadata' => [
            'rag_enabled' => false,
            'clicshopping_processed' => false
          ]
        ]
      ]);
      
      exit;
    }
  }
}