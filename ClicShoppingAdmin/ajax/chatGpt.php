<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM)  at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\DoctrineOrm;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MultiDBRAGManager;
use \ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MariaDBVectorStore;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Semantics;

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../Core/ClicShopping/')  . DIRECTORY_SEPARATOR);

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');

try {
  Gpt::getEnvironment();

  $prompt = HTML::sanitize($_POST['message']);
  $languageId = Registry::get('Language')->getId();

  $ragManager = new MultiDBRAGManager();

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_RAG_MANAGER == 'True' && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True') {
    $queryType = isset($_POST['queryType']) ? HTML::sanitize($_POST['queryType']) : 'semantic';

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

        // Main tables known
        $knownTables = [
          'products_embedding',
          'categories_embedding',
          'pages_manager_embedding',
          'orders_embedding',
          'manufacturers_embedding',
          'suppliers_embedding',
          'reviews_embedding',
          'reviews_sentiment_embedding',
          'return_orders_embedding',
          'suppliers_embedding'
        ];
        // Add first the known table
        foreach ($knownTables as $tableName) {
          try {
            $vectorStore = new MariaDBVectorStore($embeddingGenerator, $tableName);
            $embeddingTables[$tableName] = $vectorStore;
          } catch (\Exception $e) {
            if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
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
                 if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
                  error_log("Erreur lors de l'initialisation de la table {$tableName} : " . $e->getMessage());
                  // If error continue
                }
              }
            }
          }
        } catch (\Exception $e) {
          if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
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

    echo nl2br($result);
  } else {
    $result = Gpt::getGptResponse($prompt);

    $pos = strstr($result, ':');

    if ($pos !== false) {
      $result = substr($pos, 2); // Pour enlever les deux-points et l'espace
      echo nl2br($result);
    } else {
      echo nl2br($result); // Si "Keywords:" n'est pas trouvé, imprimez la chaîne d'origine.
    }
  }
} catch
  (\Exception $e) {
    error_log('Erreur dans le traitement AJAX : ' . $e->getMessage());
    echo "Une erreur s'est produite lors du traitement de votre requête : " . $e->getMessage();
  }
