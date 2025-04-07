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

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\DoctrineOrm;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MultiDBRAGManager;
use \ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MariaDBVectorStore;

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../includes/ClicShopping/')  . DIRECTORY_SEPARATOR);

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');

try {
  // Sanitize the incoming message from the AJAX request
  $prompt = HTML::sanitize($_POST['message']);
  $saveGpt = isset($_POST['saveGpt']) ? HTML::sanitize($_POST['saveGpt']) : null;
  $languageId = isset($_POST['languageId']) ? (int)$_POST['languageId'] : null;
  // Nouveau paramètre pour identifier le type de requête
  $queryType = isset($_POST['queryType']) ? HTML::sanitize($_POST['queryType']) : 'semantic';

  // Détection côté serveur comme solution de secours
  if ($queryType === 'semantic') {
    // Patterns pour détecter les requêtes d'analyse
    $analyticsPatterns = [
      '/combien|total|nombre|count|somme|sum|moyenne|average|min|max/i',
      '/stock|inventaire|disponible|disponibilité|alerte|niveau|reorder/i',
      '/REF[-\s]?\d+|SKU[-\s]?\d+|EAN[-\s]?\d+|\b\d{8,13}\b|ID\s*:\s*\d+/i',
      '/prix\s*(>|<|>=|<=|=)\s*(\d+[\.,]?\d*)/i',
      '/quantité\s*(>|<|>=|<=|=)\s*(\d+)/i'
    ];

    foreach ($analyticsPatterns as $pattern) {
      if (preg_match($pattern, $prompt)) {
        $queryType = 'analytics';
       // error_log("Type de requête corrigé côté serveur: analytics");
        break;
      }
    }
  }

  // Récupération de la clé API OpenAI depuis la configuration
  Gpt::getEnvironment();

  // Initialisation du gestionnaire RAG multi-bases
  $ragManager = new MultiDBRAGManager();

  // Traitement selon le type de requête
  if ($queryType === 'analytics') {
    // Utiliser la nouvelle méthode pour les requêtes d'analyse numérique
    $analyticsResults = $ragManager->executeAnalyticsQuery($prompt, null, $languageId);
    // La mise en forme est maintenant gérée par la classe ResultFormatter dans le répertoire RAG
    $result = $ragManager->formatResults($analyticsResults, $prompt);
  } else {
    // APPROCHE 1 ou 2 selon la configuration existante
    if (defined('CLICSHOPPING_APP_CHATGPT_CH_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_RAG_MANAGER == 'True') {
      // Génération de la réponse avec l'approche 1
      $result = $ragManager->answerQuestion($prompt, 5, 0.5, $languageId);
    } else {
      // APPROCHE 2: Utilisation de l'approche existante

      // 1️ Initialisation du générateur d'embedding
      $embeddingGenerator = new OpenAI3LargeEmbeddingGenerator();

      // 2️ Récupérer l'EntityManager de Doctrine via la classe DoctrineOrm
      $entityManager = DoctrineOrm::getEntityManager();

      // 3️ Récupérer toutes les tables d'embedding disponibles
      $embeddingTables = [];

      // Tables principales connues
      $knownTables = [
        'products_embedding',
        'categories_embedding',
        'pages_manager_embedding',
        'orders_embedding',
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

      // 4️⃣ Recherche dans toutes les bases de données vectorielles
      $allResults = [];
      $context = '';

      foreach ($embeddingTables as $tableName => $vectorStore) {
        try {
          // Créer un filtre pour la langue si spécifié
          $filter = null;
          if ($languageId !== null) {
            $filter = function ($metadata) use ($languageId) {
              return isset($metadata['language_id']) && $metadata['language_id'] == $languageId;
            };
          }

          // Utiliser la nouvelle signature de similaritySearch
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
          // Continuer avec les autres tables en cas d'erreur
        }
      }

      // 5️ Si des documents pertinents ont été trouvés, les envoyer à OpenAI pour une réponse enrichie
      if (!empty($context)) {
        $result = Gpt::getGptResponse($context . "\n\nQuestion : " . $prompt);
      } else {
        // 6 Si aucune information pertinente n'a été trouvée, poser directement la question à OpenAI
        $result = Gpt::getGptResponse($prompt);
      }

      // 7️⃣ Traitement de la réponse d'OpenAI
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

  // 9️⃣ Afficher la réponse formatée avec les sauts de ligne HTML
  echo nl2br($result);

} catch (\Exception $e) {
  // Gestion des erreurs
  error_log('Erreur dans le traitement AJAX : ' . $e->getMessage());
  echo "Une erreur s'est produite lors du traitement de votre requête : " . $e->getMessage();
}
