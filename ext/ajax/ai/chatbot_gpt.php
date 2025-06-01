<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\Shop\GptShop;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\DoctrineOrm;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MultiDBRAGManager;
use \ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MariaDBVectorStore;

define('CLICSHOPPING_BASE_DIR', __DIR__ . '/../../../includes/ClicShopping/');

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();

CLICSHOPPING::loadSite('Shop');

$CLICSHOPPING_Db = Registry::get('Db');
$CLICSHOPPING_Language = Registry::get('Language');
$language_id = isset($_POST['languageId']) ? (int)$_POST['languageId'] : null;
$language_name = $CLICSHOPPING_Language->getLanguagesName($language_id);

if (GptShop::checkGptStatus() === false) {
  return false;
}

if (\defined('MODULES_FOOTER_CHATBOT_GPT_STATUS') && MODULES_FOOTER_CHATBOT_GPT_STATUS == 'False') {
  return false;
}
//If you cannot find the answer in the provided information, clearly state this and offer an answer based on your general knowledge.
if (isset($_POST['message'])) {
  $question = HTML::sanitize($_POST['message']);
  $context = 'Use the following information to answer the user\'s question';
  
  $prompt = "You are an expert in marketing and e-commerce. 
   Use the following information to answer the user's question.
Information:
{context}
Question: {$question}.
Instructions:
- Do not provide information about competitors. instead, focus on the general information to help the customer or provide information on other product related to the context.
- Respond in clear and concise in {$language_name}.
- For each piece of information, indicate its origin and provide the reference link if available.
- Reference links: {links}. /n
- Detailed answer:
   
   : ";


  // Récupération de la clé API OpenAI depuis la configuration
  GptShop::getEnvironment();
  
  
// Deux approches possibles selon la configuration :

//
// APPROCHE 1: Utilisation de MultiDBRAGManager (nouvelle implémentation)
//
  if (defined('CLICSHOPPING_APP_CHATGPT_RA_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_RAG_MANAGER == 'True' && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True') {
    // Initialisation du gestionnaire RAG multi-bases
    // Si aucune table n'est spécifiée, toutes les tables d'embedding seront utilisées automatiquement
    $ragManager = new MultiDBRAGManager();
    // Génération de la réponse


    $result = $ragManager->answerQuestion($prompt, 5, 0.7, $language_id);
  } else {
//
// APPROCHE 2: Utilisation de l'approche existante
//

//
// 1️ Initialisation du générateur d'embedding
// 
    $embeddingGenerator = new OpenAI3LargeEmbeddingGenerator();

//
// 2️ Récupérer l'EntityManager de Doctrine via la classe DoctrineOrm
//
    $entityManager = DoctrineOrm::getEntityManager();

//
// 3️ Récupérer toutes les tables d'embedding disponibles
//
    $embeddingTables = [];

// Tables principales connues
    $knownTables = [
      'products_embedding',
      'categories_embedding',
      'pages_manager_embedding'
    ];

    // Ajouter d'abord les tables connues
    foreach ($knownTables as $tableName) {
      try {
        // Utiliser notre implémentation personnalisée MariaDBVectorStore au lieu de DoctrineVectorStore
        $vectorStore = new MariaDBVectorStore($embeddingGenerator, $tableName);
        $embeddingTables[$tableName] = $vectorStore;
      } catch (\Exception $e) {
        if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Erreur lors de l'initialisation de la table {$tableName} : " . $e->getMessage());
          // Continuer avec les autres tables en cas d'erreur
        }
      }
    }

    // Rechercher d'autres tables d'embedding dans la base de données
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
              // Continuer avec les autres tables en cas d'erreur
            }
          }
        }
      }
    } catch (\Exception $e) {
      if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Erreur lors de la recherche des tables d'embedding : " . $e->getMessage());
        // Continuer avec les tables connues en cas d'erreur
      }
    }

//
// 4️⃣ Recherche dans toutes les bases de données vectorielles
//
    $allResults = [];
    $context = '';

    foreach ($embeddingTables as $tableName => $vectorStore) {
      try {
        // Créer un filtre pour la langue si spécifié
        $filter = null;
        if ($language_id !== null) {
          $filter = function ($metadata) use ($language_id) {
            return isset($metadata['language_id']) && $metadata['language_id'] == $language_id;
          };
        }

        // Utiliser la nouvelle signature de similaritySearch
        $results = $vectorStore->similaritySearch($prompt, 2, 0.7, $filter);

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
        // Continuer avec les autres tables en cas d'erreur
      }
    }
//
// 5️ Si des documents pertinents ont été trouvés, les envoyer à OpenAI pour une réponse enrichie
//
    if (!empty($context)) {
      //$result = Gpt::getGptResponse($context . "\n\nQuestion : " . $prompt);
       $result = GptShop::getGptResponse($$context . "\n\nQuestion : " . $prompt, $max_token = 100, $temperature = 0);
    } else {
      // 6 Si aucune information pertinente n'a été trouvée, poser directement la question à OpenAI
      //$result = Gpt::getGptResponse($prompt);
      $result = GptShop::getGptResponse($prompt, $max_token = 100, $temperature = 0);
    }

    // 7️⃣ Traitement de la réponse d'OpenAI
    $pos = strstr($result, ':');
    if ($pos !== false) {
      $result = substr($pos, 2);
    }
  }

//    
// 8️ Sauvegarder la conversation si demandé
//
  /*
  if ($saveGpt === 'true') {
    // Implémentation de la sauvegarde si nécessaire
    // ...
  }
*/
  // 9️⃣ Afficher la réponse formatée avec les sauts de ligne HTML
  echo nl2br($result);
}