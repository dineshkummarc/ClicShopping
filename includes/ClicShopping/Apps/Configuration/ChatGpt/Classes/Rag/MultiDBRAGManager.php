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


use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\DoctrineOrm;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\MariaDBVectorStore;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\EmbeddedTableManager;

use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;

/**
 * MultiDBRAGManager Class
 *
 * This class manages multiple vector databases for Retrieval-Augmented Generation (RAG).
 * It provides functionality for document management, similarity search, and question answering
 * across multiple vector stores using OpenAI embeddings.
 *
 * Key features:
 * - Multiple vector store management
 * - Document embedding and storage
 * - Similarity search across multiple databases
 * - Question answering using RAG
 * - Support for different languages and entity types
 *
 * @package ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag
 */
class MultiDBRAGManager
{
  private mixed $app;
  private mixed $db;
  private mixed $language;
  private mixed $embeddingGenerator;
  private array $vectorStores = [];

  private string $systemMessageTemplate;
  private EmbeddedTableManager $embeddedTableManager;

  /**
   * Constructor for MultiDBRAGManager
   * Initializes the RAG system with specified model and tables
   *
   * @param string|null $model OpenAI model to use (null for default configuration)
   * @param array $tableNames List of table names to use (empty for all embedding tables)
   * @param array $modelOptions Additional model options (temperature, etc.)
   * @throws \Exception If initialization fails
   */
  public function __construct(?string $model = null, array $tableNames = [], array $modelOptions = [])
  {
    // Initialisation de l'application ChatGpt via Registry
    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGpt());
    }

    $this->app = Registry::get('ChatGpt');
    $this->db = Registry::get('Db');
    $this->systemMessageTemplate = CLICSHOPPING::getDef('text_rag_system_message_template');
    $this->language = Registry::get('Language');
// Dans le constructeur, ajouter :
    $this->embeddedTableManager = new EmbeddedTableManager();

    // Préparation des paramètres pour getOpenAiGpt
    $parameters = null;
    if (!is_null($model) || !empty($modelOptions)) {
      $parameters = $modelOptions;
      if (!is_null($model)) {
        $parameters['model'] = $model;
      } elseif (defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL')) {
        $parameters['model'] = CLICSHOPPING_APP_CHATGPT_CH_MODEL;
      }
    }

    // Initialisation de l'environnement OpenAI via la classe Gpt existante
    Gpt::getOpenAiGpt($parameters);

    // Création d'un adaptateur pour utiliser gptOpenAiEmbeddings comme générateur d'embeddings
    $this->embeddingGenerator = new class(Gpt::class) implements EmbeddingGeneratorInterface {
      private $gptClass;

      public function __construct(string $gptClass) {
        $this->gptClass = $gptClass;
      }

      public function embedText(string $text): array {
        return call_user_func([$this->gptClass, 'gptOpenAiEmbeddings'], $text);
      }

      public function embedDocument(Document $document): Document {
        $document->embedding = $this->embedText($document->content);
        return $document;
      }

      public function embedDocuments(array $documents): array {
        $results = [];
        foreach ($documents as $document) {
          $results[] = $this->embedDocument($document);
        }
        return $results;
      }

      public function getEmbeddingLength(): int {
        return 3072; // Valeur par défaut pour OpenAI
      }
    };

    // Si aucune table n'est spécifiée, récupérer toutes les tables d'embedding disponibles
    if (empty($tableNames)) {
      try {
        $tableNames = DoctrineOrm::getEmbeddingTables();
        if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Embedding tables found: " . implode(", ", $tableNames));
        }
      } catch (\Exception $e) {
        if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Error while retrieving the embedding tables: " . $e->getMessage());
        }
        $tableNames = [];
      }
    }

    // Initialisation des vector stores pour chaque table
    foreach ($tableNames as $tableName) {
      try {
        $this->vectorStores[$tableName] = new MariaDBVectorStore($this->embeddingGenerator, $tableName);
        if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Vector store initialized for the table: " . $tableName);
        }
      } catch (\Exception $e) {
        if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Error while initializing the vector store for the table {$tableName}: " . $e->getMessage());
        }
      }
    }
  }

  /**
   * Adds a document to the specified vector store
   *
   * @param string $content Document content to add
   * @param string $tableName Name of the table to store the document
   * @param string $type Document type
   * @param string $sourceType Source type of the document
   * @param string $sourceName Name of the source
   * @param string|null $entityType Entity type (page, category, product, etc.)
   * @param int|null $entityId Entity ID
   * @param int|null $languageId Language ID
   * @return bool True if successful, false otherwise
   */
  public function addDocument(string $content, string $tableName, string $type = 'text', string $sourceType = 'manual', string $sourceName = 'manual', string|null $entityType = null, int|null $entityId = null, int|null $languageId = null): bool
  {
    try {
      // Vérifier si la table existe dans les vector stores
      if (!isset($this->vectorStores[$tableName])) {
        // Si la table n'existe pas, vérifier si elle existe dans la base de données
        if (!DoctrineOrm::checkTableStructure($tableName)) {
          // Si la table n'existe pas dans la base de données, la créer
          if (!DoctrineOrm::createTableStructure($tableName)) {
            throw new \Exception("Unable to create the table {$tableName}");
          }
        }

        // Ajouter la table aux vector stores
        $this->vectorStores[$tableName] = new MariaDBVectorStore($this->embeddingGenerator, $tableName);
      }

      // Création du document avec les métadonnées appropriées
      $document = new Document();
      $document->content = $content;
      $document->sourceType = $sourceType;
      $document->sourceName = $sourceName;
      $document->chunkNumber = 128;

      $document->metadata = [
        'type' => $type,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'language_id' => $languageId,
        'date_modified' => 'now()'
      ];

      $this->vectorStores[$tableName]->addDocument($document);
      return true;
    } catch (\Exception $e) {
      error_log('Error while adding the document: ' . $e->getMessage());
      return false;
    }
  }

  /**
   * Searches for similar documents across all configured tables
   *
   * @param string $query Search query
   * @param int $limit Maximum number of results per table
   * @param float $minScore Minimum similarity score (0-1)
   * @param int|null $languageId Language ID for filtering results
   * @param string|null $entityType Entity type for filtering results
   * @return array Array of matching documents with similarity scores
   */
  public function searchDocuments(string $query, int $limit = 5, float $minScore = 0.5, int|null $languageId = null, string|null $entityType = null): array {
    try {
      $allResults = [];

      if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Starting document search for query: " . $query);
      }
      // Vérifier si des vector stores sont disponibles

      if (empty($this->vectorStores)) {
        if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("No vector store available");
        }
        return [];
      }

      if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Found embedding tables: " . implode(", ", array_keys($this->vectorStores)));
      }

      // Génération de l'embedding pour la requête
      $queryEmbedding = $this->embeddingGenerator->embedText($query);

      if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Generated embedding for query, length: " . count($queryEmbedding));
      }

      // Rechercher dans chaque vector store
      foreach ($this->vectorStores as $tableName => $vectorStore) {
        try {
          if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
            error_log("Table search: " . $tableName);
          }
          // Création d'une fonction de filtrage basée sur les critères
          $filter = function($metadata) use ($languageId, $entityType) {
            $match = true;

            // Filtrage par langue si spécifié
            if ($languageId !== null && isset($metadata['language_id'])) {
              $match = $match && ($metadata['language_id'] == $languageId);
            }

            // Filtrage par type d'entité si spécifié
            if ($entityType !== null && isset($metadata['entity_type'])) {
              $match = $match && ($metadata['entity_type'] == $entityType);
            }

            return $match;
          };

          $results = $vectorStore->similaritySearch($queryEmbedding, $limit, $minScore, $filter);
          if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
            error_log("Results found in table {$tableName}: " . count($results));
          }
          // Ajouter les résultats à la liste complète
          foreach ($results as $document) {
            $allResults[] = $document;
          }
        } catch (\Exception $e) {
          if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
            error_log("Error while searching in table {$tableName}: " . $e->getMessage());
            // Continuer avec les autres tables en cas d'erreur
          }
        }
      }

      // Trier les résultats par score de similarité (du plus élevé au plus bas)
      if (!empty($allResults)) {
        usort($allResults, function ($a, $b) {
          return $b->metadata['score'] <=> $a->metadata['score'];
        });
      }

      // Limiter le nombre total de résultats
      $finalResults = array_slice($allResults, 0, $limit);
      if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Total number of results found: " . count($finalResults));
      }

      return $finalResults;
    } catch (\Exception $e) {
      if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log('Error while searching documents: ' . $e->getMessage());
      }
      return [];
    }
  }

  /**
   * Generates an answer to a question using RAG methodology
   *
   * This method:
   * 1. Searches for relevant documents
   * 2. Creates a context from found documents
   * 3. Generates a response using the OpenAI model
   * 4. Includes relevant links and sources in the response
   *
   * @param string $question User's question
   * @param int $limit Maximum number of documents to retrieve
   * @param float $minScore Minimum similarity score (0-1)
   * @param int|null $languageId Language ID for filtering results
   * @param string|null $entityType Entity type for filtering results
   * @param array $modelOptions Additional options for the model
   * @return string Generated answer
   */
  public function answerQuestion(string $question, int $limit = 5, float $minScore = 0.5, int|null $languageId = null, string|null $entityType = null, array $modelOptions = []): string
  {
    try {
      // Recherche des documents pertinents
      $documents = $this->searchDocuments($question, $limit, $minScore, $languageId, $entityType);

      if (empty($documents)) {
        return CLICSHOPPING::getDef('text_rag_answer_question_not_found');
      }

      // Préparation du contexte et des liens
      $context = '';

      foreach ($documents as $doc) {
        //$tableName = $doc->metadata['table_name'] ?? 'inconnu';
        $score = round(($doc->metadata['score'] ?? 0) * 100, 2);
        $link = '';

        if (!empty($doc->metadata['entity_id']) && !empty($doc->metadata['type'])) {
          $routes = [
            'products' => 'A&Catalog\Products&Products',
            'category' => 'A&Catalog\Categories&Categories',
            'page_manager' => 'A&Communication&\PageManager',
            'orders' => 'A&Orders\Orders',
          ];

          if (isset($routes[$doc->metadata['type']])) {
            $link = "/n" . HTML::link(CLICSHOPPING::link(null, $routes[$doc->metadata['type']]), $doc->metadata['type']);

            $link = str_replace('%5C', '\\', $link);
          }
        }

        $context .= $doc->content . "\n\n";

        if (!empty($link)) {
          $link .= "- {{$doc->metadata['entity_id']}: {$link} \n";
          $score .= "- (accuracy: {$score}%)  \n";
        }
      }

      // Utiliser la classe Gpt existante pour générer la réponse
      $prompt = str_replace(['{context}', '{question}', '{links}', '{score}'], [$context, $question, $link, $score], $this->systemMessageTemplate);

      if (!empty($modelOptions)) {
        $response = Gpt::getGptResponse($prompt);

        return $response;
      } else {
        // Utilisation standard sans options spécifiques
        return Gpt::getGptResponse($prompt);
      }
    } catch (\Exception $e) {
      error_log('Erreur lors de la génération de réponse : ' . $e->getMessage());
      return CLICSHOPPING::getDef('text_rag_answer_question_error');
    }
  }

  /**
   * Sets a custom template for the system message
   *
   * @param string $template New system message template
   */
  public function setSystemMessageTemplate(string $template): void
  {
    $this->systemMessageTemplate = $template;
  }

  /**
   * Return the tablelist of embedding configuréd
   *
   * @return array Liste des noms de tables
   */
  public function getConfiguredTables(): array
  {
    return array_keys($this->vectorStores);
  }

  /**
   * Performs a question answering operation using RAG
   *
   * @param string $query Question to ask
   * @param int $limit Maximum number of results per table
   * @param float $minScore Minimum similarity score (0-1)
   * @param int|null $languageId Language ID for filtering results
   * @param string|null $entityType Entity type for filtering results
   * @return string Answer to the question
   */
  public function askQuestion(string $query, int $limit = 5, float $minScore = 0.5, int|null $languageId = null, string|null $entityType = null): string {
    try {
      $results = $this->searchDocuments($query, $limit, $minScore, $languageId, $entityType);

      if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Number of results found: " . count($results));
      }

      $prompt = $this->systemMessageTemplate . "\n\n" . $query . "\n\n";

      // Ajouter les résultats au prompt
      foreach ($results as $document) {
        $prompt .= "Source: " . $document->sourceName . "\n";
        $prompt .= "Type: " . $document->metadata['type'] . "\n";
        $prompt .= "Entity Type: " . $document->metadata['entity_type'] . "\n";
        $prompt .= "Entity ID: " . $document->metadata['entity_id'] . "\n";
        $prompt .= "Language ID: " . $document->metadata['language_id'] . "\n";
        $prompt .= "Content: " . $document->content . "\n\n";
      }

      // Générer une réponse à la question
      $response = $this->app->askQuestion($prompt);

      return $response;
    } catch (\Exception $e) {
      if (CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log('Error while answering the question: ' . $e->getMessage());
      }
      return 'Error while answering the question: ' . $e->getMessage();
    }
  }

  /*
   * Searches for documents based on a technical identifier (EAN, SKU, REF, ID)
   *
   * @param string $identifier The identifier to search for
   * @param string|null $entityType The type of entity to search for (product, order, etc.)
   * @param int|null $languageId The language ID for filtering results
   * @return array Array of matching documents
   */
  private function searchByIdentifier(string $identifier, ?string $entityType, ?int $languageId): array {
    $CLICSHOPPING_Db = Registry::get('Db');
    // Vérification si un identifiant est fourni
    if (empty($identifier)) {
      return [];
    }

    // Tableau pour stocker les résultats
    $results = [];

    // Rechercher selon le type d'entité (produits, commandes, etc.)
    switch ($entityType) {
      case 'products':
        $query = $this->db->prepare('SELECT products_model,
                                             products_sku,
                                             products_ean
                                     FROM :table_products
                                     WHERE products_model = :identifier 
                                        OR products_sku = :identifier 
                                        OR products_ean = :identifier
                                     LIMIT 1
                                 ');

        $query->bindValue('identifier', $identifier);
        $query->execute();
        $results = $query->fetchAll();
        break;

      case 'orders':
        $query = $this->db->prepare('SELECT *
                                     FROM :table_orders
                                     WHERE orders_id = :identifier 
                                     LIMIT 1
                                 ');

        $query->bindValue('identifier', $identifier);
        $query->execute();
        $results = $query->fetchAll();
        break;

      case 'categories':
        $query = $this->db->prepare('SELECT *
                                     FROM :table_categories
                                     WHERE categories_id = :identifier 
                                     LIMIT 1
                                 ');

        $query->bindValue('identifier', $identifier);
        $query->execute();
        $results = $query->fetchAll();
        break;

      case 'page_manager':
        $query = $this->db->prepare('SELECT *
                                     FROM :table_page_manager
                                     WHERE pages_id = :identifier 
                                     LIMIT 1
                                 ');

        $query->bindValue('identifier', $identifier);
        $query->execute();
        $results = $query->fetchAll();
        break;

      default:
        return [];
    }

    return $results;
  }

  private function searchByPrice(string $query, ?string $entityType): array
  {
    $results = [];

    if (preg_match('/\b(\d+[\.,]?\d*)\b/', $query, $matches)) {
      // Convertir le prix en nombre flottant
      $price = (float)str_replace(',', '.', $matches[1]);

      // Recherche selon le type d'entité
      switch ($entityType) {
        case 'products':
          $query = $this->db->prepare('SELECT *
                                     FROM :table_products
                                     WHERE products_price <= :price
                                     LIMIT :limit
                                 ');

          $query->bindInt(':price', $price);
          $query->bindInt(':limit', 10);
          $query->execute();
          $results = $query->fetchAll();
          break;
        default:
          return [];
      }
    }

    return $results;
  }

  private function searchByStock(string $query, ?string $entityType, ?int $languageId): array
  {
    $results = [];

    switch ($entityType) {
      case 'products':
        // Requête pour récupérer les produits en stock (quantity > 0)
        $query = $this->db->prepare('SELECT *
                                     FROM :table_products
                                     WHERE products_quantity > :quantity
                                      or products_discountinued > :quantity
                                      or products_quantity_alert > :quantity            
                                     LIMIT :limit
                                 ');

        $query->bindInt(':quantity', 0);
        $query->bindInt(':limit', 10);
        $query->execute();

        $results = $query->fetchAll();
        break;

      default:
        return [];
    }

    return $results;
  }

  private function searchForStatistics(string $query, ?string $entityType, ?int $languageId): array
  {
    $results = [];

    // Recherche selon le type d'entité
    switch ($entityType) {
      case 'products':
        // Requête pour obtenir le nombre total de produits
        if (preg_match('/total|nombre|count/i', $query)) {
          $query =  $this->db->prepare('SELECT COUNT(*) AS total_products 
                                        FROM :table_products');
          $query->execute();
          $results = $query->fetch();
        } elseif (preg_match('/somme|total des prix|prix total/i', $query)) {
          $query =  $this->db->prepare('SELECT SUM(products_price) AS total_price 
                                        FROM :table_products');
          $query->execute();
          $results = $query->fetch();
        } elseif (preg_match('/moyenne des prix|average price/i', $query)) {
          $query =  $this->db->prepare('SELECT AVG(products_price) AS average_price 
                                        FROM :table_products');
          $stmt = $this->db->prepare($query);
          $stmt->execute();
          $results = $stmt->fetch();  // Retourne la moyenne des prix des produits
        }
        break;

      case 'orders':
        if (preg_match('/total|nombre|count/i', $query)) {
          $query =  $this->db->prepare('SELECT COUNT(*) AS total_orders 
                                        FROM :table_orders 
                                        order by orders_id desc');
          $query->execute();
          $results = $query->fetch();  // Retourne le nombre total de commandes
        } elseif (preg_match('/somme des commandes|total des ventes/i', $query)) {
          $query =  $this->db->prepare('SELECT SUM(value) AS total_sales 
                                        FROM :table_orders_total 
                                        where class = "ST" 
                                        order by orders_id desc');
          $query->execute();
          $results = $query->fetch();  // Retourne la somme des montants des commandes
        } elseif (preg_match('/moyenne des commandes|average order value/i', $query)) {
          $query = $this->db->prepare('SELECT AVG(value) AS average_order_value 
                                      FROM :table_orders_total 
                                      where class = "ST"  
                                      order by orders_id desc');
          $query->execute();
          $results = $query->fetch();  // Retourne la moyenne des montants des commandes
        }
        break;

      default:
        return [];
    }

    return $results;
  }

  /**
   * Returns a list of entity types and their corresponding taxonomies
   *
   * @return array List of entity types and their taxonomies
   */
  private function entityTypeTaxnonomy(): array
  {
    // Exemples de requêtes par type d'entité et par langue
    $entity = [
      1 => [ // Anglais
        'products' => ['product information', 'product stock', 'product price'],
        'categories' => ['category list', 'products in category'],
        'page_manager' => ['terms of service', 'about page', 'contact information'],
        'orders' => ['order status', 'order history', 'order details']
      ],
      2 => [ // Français
        'products' => ['information produit', 'stock produit', 'prix produit'],
        'categories' => ['liste des catégories', 'produits dans la catégorie'],
        'page_manager' => ['conditions de vente', 'page à propos', 'informations de contact'],
        'orders' => ['statut de commande', 'historique des commandes', 'détails de commande']
      ]
    ];

    return $entity;
  }

  /**
   * Detects entity type using regex patterns
   *
   * @param string $query The search query
   * @return string|null Detected entity type or null
   */
  private function detectEntityTypeWithRegex(string $query): ?string
  {
    $patterns = [
      'products' => '/produit|article|product/i',
      'categories' => '/catégorie|category/i',
      'page_manager' => '/page|condition|vente|terms/i',
      'orders' => '/commande|order|achat|purchase/i'
    ];

    foreach ($patterns as $entityType => $pattern) {
      if (preg_match($pattern, $query)) {
        return $entityType;
      }
    }

    return null;
  }

  /**
   * Performs a question answering operation using RAG
   *
   * This method:
   * 1. Searches for relevant documents across all configured tables.
   * 2. Creates a context from the found documents.
   * 3. Generates a response using the OpenAI model.
   * 4. Includes relevant links and sources in the response.
   *
   * @param string $query The question to be answered
   * @param int $limit Maximum number of results per table
   * @param float $minScore Minimum similarity score (0-1)
   * @param int|null $languageId Language ID for filtering results
   * @return array The answer to the question
   */
  public function enhancedAdminSearch(string $query, int $limit = 5, float $minScore = 0.5, int|null $languageId = null): array {
    $allResults = [];

    // 1. Détection du type d'entité dans la requête
    $targetEntityType = $this->detectEntityTypeWithRegex($query);
var_dump($targetEntityType);
    // 2. Recherche par identifiants techniques (EAN, SKU, REF, ID)
    if (preg_match('/REF-\d+|SKU-\d+|EAN-\d+|\d{8,13}|ID\s*:\s*\d+/i', $query, $matches)) {
      $identifier = $matches[0];
      // Utiliser le nouveau gestionnaire pour la recherche par référence
      $directResults = $this->embeddedTableManager->searchByReference($identifier, $languageId);
      if (!empty($directResults)) {
        return $directResults; // Résultats exacts prioritaires
      }
    }


    // 3. Apply specialized search strategies based on query patterns

    // Requêtes de stock
    if (preg_match('/stock|inventaire|disponible|disponibilité|alerte|niveau|reorder/i', $query)) {
      // Déterminer le type d'analyse de stock
      $analysisType = 'in_stock'; // par défaut

      if (preg_match('/alerte|alert|niveau bas|low level/i', $query)) {
        $analysisType = 'alert_level';
      } elseif (preg_match('/épuisé|out of stock|indisponible/i', $query)) {
        $analysisType = 'out_of_stock';
      } elseif (preg_match('/valeur|value|montant total/i', $query)) {
        $analysisType = 'total_inventory_value';
      }

      $stockResults = $this->embeddedTableManager->analyzeStock($analysisType, $languageId);
      $allResults = array_merge($allResults, $stockResults);
    }

    // Requêtes statistiques
    if (preg_match('/combien|total|nombre|count|somme|sum|moyenne|average|min|max/i', $query)) {
      $statType = 'count'; // par défaut
      $column = null;

      if (preg_match('/somme|sum|total des/i', $query)) {
        $statType = 'sum';
      } elseif (preg_match('/moyenne|average|avg/i', $query)) {
        $statType = 'avg';
      } elseif (preg_match('/minimum|min/i', $query)) {
        $statType = 'min';
      } elseif (preg_match('/maximum|max/i', $query)) {
        $statType = 'max';
      }

      // Détection de la colonne concernée
      if (preg_match('/prix|price|tarif/i', $query)) {
        $column = 'products_price';
      } elseif (preg_match('/quantité|quantity|stock/i', $query)) {
        $column = 'products_quantity';
      } elseif (preg_match('/commande|order|vente|sale/i', $query)) {
        $column = 'orders_total';
      } elseif (preg_match(' /commande|order|vente|achat|facture|client|purchase|invoice/i', $query)) {
        $column = 'orders_list';
      }

      $statResults = $this->embeddedTableManager->calculateStatistics(
        $targetEntityType ?? 'products',
        $statType,
        $column
      );

      $allResults = array_merge($allResults, [$statResults]);
    }

    // 6. Recherche vectorielle standard (toujours exécutée)
    $vectorResults = [];
    if ($targetEntityType !== null) {
      // Recherche vectorielle filtrée par type d'entité
      $vectorResults = $this->searchDocuments($query, $limit, $minScore, $languageId, $targetEntityType);
    } else {
      // Recherche vectorielle sur toutes les entités
      $vectorResults = $this->searchDocuments($query, $limit, $minScore, $languageId);
    }

    $allResults = array_merge($allResults, $vectorResults);

    // 5. Deduplicate results
    $uniqueResults = $this->deDuplicateResults($allResults);

    // 6. Tri par score de pertinence
    usort($uniqueResults, function($a, $b) {
      return $b->metadata['score'] <=> $a->metadata['score'];
    });

    // 7. Limiter le nombre de résultats
    return array_slice(array_values($uniqueResults), 0, $limit);
  }

  /**
   * Removes duplicate results, keeping the highest scored version
   *
   * @param array $results Array of search results
   * @return array Deduplicated results
   */
  private function deDuplicateResults(array $results): array
  {
    $uniqueResults = [];

    foreach ($results as $result) {
      $key = $result->id . '-' . ($result->metadata['type'] ?? 'unknown');

      if (!isset($uniqueResults[$key]) ||
        $result->metadata['score'] > $uniqueResults[$key]->metadata['score']) {
        $uniqueResults[$key] = $result;
      }
    }

    return $uniqueResults;
  }

  /**
   * Recherche les commandes du site
   */
  public function getOrders(?int $limit = 10, ?int $languageId = null): array
  {
    $query = $this->db->prepare('SELECT distinct  o.orders_id, 
                                                 o.customers_name,
                                                 o.date_purchased,
                                                 s.orders_status_name,
                                                 ot.value
                                FROM :table_orders o
                                LEFT JOIN :table_orders_status s ON o.orders_status = s.orders_status_id
                                LEFT JOIN :table_orders_total ot ON o.orders_id = ot.orders_id
                                WHERE ot.class = "ST"
                                ORDER BY o.date_purchased DESC, o.orders_id                                
                                LIMIT :limit
                               ');

    $query->bindInt('limit', $limit);
    $query->execute();

    $results = $query->fetchAll();
var_dump($results);
    if (!empty($results)) {
      return [
        'type' => 'orders_list',
        'count' => count($results),
        'items' => array_map(function($order) {
          return [
            'id' => $order['orders_id'],
            'data' => [
              'customer_name' => $order['customers_name'],
              'date_purchased' => $order['date_purchased'],
              'total' => $order['value'],
              'status' => $order['orders_status_name']
            ]
          ];
        }, $results)
      ];
    }

    return [
      'type' => 'orders_list',
      'count' => 0,
      'items' => []
    ];
  }


  /**
   * Exécute une requête analytique sur les données e-commerce
   *
   * Cette méthode est spécialement conçue pour les requêtes d'analyse
   * qui nécessitent des calculs, des agrégations ou des recherches précises
   * sur des données numériques ou structurées.
   *
   * @param string $query Question ou requête de l'utilisateur
   * @param string|null $entityType Type d'entité à analyser (produits, commandes, etc.)
   * @param int|null $languageId ID de la langue pour le filtrage des résultats
   * @return array Résultats de l'analyse avec données structurées
   */
  public function executeAnalyticsQuery(string $query, ?string $entityType = null, ?int $languageId = null): array
  {
    // Détection du type d'entité si non spécifié
    if ($entityType === null) {
      $entityType = $this->detectEntityTypeWithRegex($query);
    }






print_r($entityType);
    print_r('<br>----<br>');
    // Détection du type d'analyse demandée
    $analysisType = $this->detectAnalysisType($query);
    print_r($analysisType);
    print_r('<br>----<br>');





    // Exécution de l'analyse appropriée
    switch ($analysisType) {
      case 'reference_search':
        // Extraction de la référence
        if (preg_match('/REF[-\s]?(\d+)|SKU[-\s]?(\d+)|EAN[-\s]?(\d+)|\b(\d{8,13})\b|ID\s*:\s*(\d+)/i', $query, $matches)) {
          // Trouver la première valeur non vide dans les groupes capturés
          $reference = '';
          for ($i = 1, $iMax = count($matches); $i < $iMax; $i++) {
            if (!empty($matches[$i])) {
              $reference = 'REF-' . $matches[$i];
              break;
            }
          }
          if (empty($reference) && !empty($matches[0])) {
            $reference = $matches[0];
          }

          // Si la requête contient des mots-clés liés au stock d'alerte
          if (preg_match('/alerte|alert|niveau\s+d[\'e]\s+stock|stock\s+d[\'e]\s+sécurité/i', $query)) {
            return $this->embeddedTableManager->getStockAlertByReference($reference);
          }

          return $this->embeddedTableManager->searchByReference($reference, $languageId);
        }
        break;

      case 'stock_alert_reference':
        // Extraction de la référence
        if (preg_match('/REF[-\s]?(\d+)|SKU[-\s]?(\d+)|EAN[-\s]?(\d+)|\b(\d{8,13})\b|ID\s*:\s*(\d+)/i', $query, $matches)) {
          // Trouver la première valeur non vide dans les groupes capturés
          $reference = '';

          for ($i = 1, $iMax = count($matches); $i < $iMax; $i++) {
            if (!empty($matches[$i])) {
              $reference = 'REF-' . $matches[$i];
              break;
            }
          }
          if (empty($reference) && !empty($matches[0])) {
            $reference = $matches[0];
          }

          return  $this->embeddedTableManager->getStockAlertByReference($reference);
        }
        break;

      case 'stock_analysis':
        // Déterminer le type d'analyse de stock
        $stockAnalysisType = 'in_stock'; // par défaut

        if (preg_match('stock|inventaire|niveau/i', $query)) {
          if (preg_match('/alerte|alert|niveau bas|low level|disponible|disponibilité/i', $query)) {
            $stockAnalysisType = 'alert_level';
          } elseif (preg_match('/épuisé|out of stock|indisponible/i', $query)) {
            $stockAnalysisType = 'out_of_stock';
          } elseif (preg_match('/valeur|value|montant total/i', $query)) {
            $stockAnalysisType = 'total_inventory_value';
          }
        }

        return $this->embeddedTableManager->analyzeStock($stockAnalysisType, $languageId);

      case 'orders_list':
        // Déterminer le nombre de commandes à afficher
        $limit = 10; // Par défaut
        if (preg_match('/\b(\d+)\s+(?:dernières\s+)?commandes\b/i', $query, $matches)) {
          $limit = (int)$matches[1];
        }
        return $this->getOrders($limit, $languageId);


      case 'statistical_analysis':
        // Déterminer le type de statistique
        $statType = 'count'; // par défaut
        $column = null;

        if (preg_match('/somme|sum|total des/i', $query)) {
          $statType = 'sum';
        } elseif (preg_match('/moyenne|average|avg/i', $query)) {
          $statType = 'avg';
        } elseif (preg_match('/minimum|min/i', $query)) {
          $statType = 'min';
        } elseif (preg_match('/maximum|max/i', $query)) {
          $statType = 'max';
        }

        // Détection de la colonne concernée
        if (preg_match('/prix|price|tarif/i', $query)) {
          $column = 'products_price';
        } elseif (preg_match('/quantité|quantity|stock/i', $query)) {
          $column = 'products_quantity';
        } elseif (preg_match('/commande|order|vente|sale/i', $query)) {
          $column = 'value';
        }

        return $this->embeddedTableManager->calculateStatistics(
          $entityType ?? 'products',
          $statType,
          $column
        );

      case 'advanced_search':
        // Extraction des filtres à partir de la requête
        $filters = $this->extractFiltersFromQuery($query);

        return $this->embeddedTableManager->advancedSearch(
          $entityType ?? 'products',
          $filters,
          $languageId
        );

      default:
        // Si aucun type d'analyse spécifique n'est détecté, utiliser la recherche RAG standard
        $results = $this->searchDocuments($query, 5, 0.5, $languageId, $entityType);

        // Convertir les résultats en format compatible
        $formattedResults = [
          'type' => 'semantic_search',
          'count' => count($results),
          'items' => []
        ];

        foreach ($results as $doc) {
          $formattedResults['items'][] = [
            'content' => $doc->content,
            'score' => $doc->metadata['score'] ?? 0,
            'metadata' => $doc->metadata
          ];
        }

        return $formattedResults;
    }

    // En cas d'échec de détection ou d'exécution
    return [
      'type' => 'error',
      'message' => "Impossible d'analyser ou d'exécuter la requête"
    ];
  }

  /**
   * Détecte le type d'analyse demandée dans la requête
   */
  /**
   * Détecte le type d'analyse demandée dans la requête
   */
  private function detectAnalysisType(string $query): string
  {
    // Recherche par référence
    if (preg_match('/REF[-\s]?\d+|SKU[-\s]?\d+|EAN[-\s]?\d+|\b\d{8,13}\b|ID\s*:\s*\d+/i', $query)) {
      // Si la requête contient également des mots-clés liés au stock d'alerte
      if (preg_match('/alerte|alert|niveau\s+d[\'e]\s+stock|stock\s+d[\'e]\s+sécurité/i', $query)) {
        return 'stock_alert_reference';
      }

      return 'reference_search';
    }

    // Analyse de stock
    if (preg_match('/stock|inventaire|disponible|disponibilité/i', $query)) {
      // Déterminer le type spécifique d'analyse de stock
      if (preg_match('/alerte|alert|niveau\s+bas|low\s+level|sécurité/i', $query)) {
        return 'stock_alert';
      } elseif (preg_match('/épuisé|out\s+of\s+stock|indisponible/i', $query)) {
        return 'out_of_stock';
      } elseif (preg_match('/valeur|value|montant\s+total/i', $query)) {
        return 'total_inventory_value';
      }

      return 'in_stock'; // Par défaut
    }

    // Analyse statistique
    if (preg_match('/combien|total|nombre|count|somme|sum|moyenne|average|min|max/i', $query)) {
      return 'statistical_analysis';
    }

    // Recherche avancée
    if (preg_match('/recherche\s+avancée|advanced\s+search|filtrer|filter|trouver\s+tous/i', $query)) {
      return 'advanced_search';
    }

    // Par défaut, utiliser la recherche sémantique
    return 'semantic_search';
  }


  /**
   * Extrait des filtres structurés à partir d'une requête en langage naturel
   */
  private function extractFiltersFromQuery(string $query): array
  {
    $filters = [];

    // Extraction des filtres de prix
    if (preg_match('/prix\s*(>|<|>=|<=|=)\s*(\d+[\.,]?\d*)/i', $query, $matches)) {
      $operator = $matches[1];
      $value = (float)str_replace(',', '.', $matches[2]);

      switch ($operator) {
        case '>':
          $filters['products_price'] = ['operator' => 'gt', 'value' => $value];
          break;
        case '<':
          $filters['products_price'] = ['operator' => 'lt', 'value' => $value];
          break;
        case '>=':
          $filters['products_price'] = ['operator' => 'gte', 'value' => $value];
          break;
        case '<=':
          $filters['products_price'] = ['operator' => 'lte', 'value' => $value];
          break;
        case '=':
          $filters['products_price'] = $value;
          break;
      }
    }

    // Extraction des filtres de stock
    if (preg_match('/quantité\s*(>|<|>=|<=|=)\s*(\d+)/i', $query, $matches)) {
      $operator = $matches[1];
      $value = (int)$matches[2];

      switch ($operator) {
        case '>':
          $filters['products_quantity'] = ['operator' => 'gt', 'value' => $value];
          break;
        case '<':
          $filters['products_quantity'] = ['operator' => 'lt', 'value' => $value];
          break;
        case '>=':
          $filters['products_quantity'] = ['operator' => 'gte', 'value' => $value];
          break;
        case '<=':
          $filters['products_quantity'] = ['operator' => 'lte', 'value' => $value];
          break;
        case '=':
          $filters['products_quantity'] = $value;
          break;
      }
    }

    // Extraction des filtres de nom de produit
    if (preg_match('/nom\s*contient\s*["\']([^"\']+)["\']/i', $query, $matches)) {
      $filters['products_name'] = ['operator' => 'like', 'value' => $matches[1]];
    }

    return $filters;
  }

  /**
   * Formate les résultats d'analyse pour l'affichage
   *
   * @param array $results Résultats d'analyse
   * @param string $prompt Requête originale
   * @return string Résultats formatés pour l'affichage
   */
  public function formatResults(array $results, string $prompt): string
  {
    $formatter = new ResultFormatter();
    return $formatter->formatResults($results, $prompt);
  }
}
