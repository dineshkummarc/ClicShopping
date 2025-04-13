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

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../includes/ClicShopping/')  . DIRECTORY_SEPARATOR);

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');

try {
  // Sanitize the incoming message from the AJAX request
  $prompt = HTML::sanitize($_POST['message']);
  $saveGpt = isset($_POST['saveGpt']) ? HTML::sanitize($_POST['saveGpt']) : null;
  $languageId = Registry::get('Language')->getId();
  $queryType = isset($_POST['queryType']) ? HTML::sanitize($_POST['queryType']) : 'semantic';

  if ($queryType === 'semantic') {
    $queryType = Semantics::classifyQuery($prompt); // gère la traduction + détection
  }

  Gpt::getEnvironment();

  $ragManager = new MultiDBRAGManager();

  if ($queryType === 'analytics') {
    $analyticsResults = $ragManager->executeAnalyticsQuery($prompt);
    $result = $ragManager->formatResults($analyticsResults);
  } else {
    // Approach 1 or 2 with the current configuration
    if (defined('CLICSHOPPING_APP_CHATGPT_CH_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_RAG_MANAGER == 'False') {
      $result = $ragManager->answerQuestion($prompt, 5, 0.5, $languageId);
    } else {
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
      ];

      // Add first the known table
      foreach ($knownTables as $tableName) {
        try {
          $vectorStore = new MariaDBVectorStore($embeddingGenerator, $tableName);
          $embeddingTables[$tableName] = $vectorStore;
        } catch (\Exception $e) {
          if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
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
              if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
                error_log("Erreur lors de l'initialisation de la table {$tableName} : " . $e->getMessage());
                // If error continue
              }
            }
          }
        }
      } catch (\Exception $e) {
        if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Erreur lors de la recherche des tables d'embedding : " . $e->getMessage());
          // If error continue
        }
      }

      // 4️⃣ Search in all vector table inside the DB
      $allResults = [];
      $context = '';

      foreach ($embeddingTables as $tableName => $vectorStore) {
        try {
          // Language filter if specified
          $filter = null;
          if ($languageId !== null) {
            $filter = function ($metadata) use ($languageId) {
              return isset($metadata['language_id']) && $metadata['language_id'] == $languageId;
            };
          }

          // UUSe similaritySearch signature
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

  // 8️ Sauvegarder la conversation si demandé
  if ($saveGpt === 'true') {
    // Implémentation de la sauvegarde si nécessaire
    // ...
  }

  echo nl2br($result);

} catch (\Exception $e) {
  error_log('Erreur dans le traitement AJAX : ' . $e->getMessage());
  echo "Une erreur s'est produite lors du traitement de votre requête : " . $e->getMessage();
}
