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

use AllowDynamicProperties;
use Doctrine\DBAL\ParameterType;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use LLPhant\Embeddings\VectorStores\VectorStoreBase;
use Doctrine\DBAL\Connection;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\DoctrineOrm;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Security\SecurityLogger;

/**
 * MariaDBVectorStore Class
 *
 * A vector store implementation using MariaDB for storing and retrieving document embeddings.
 * This class extends VectorStoreBase and provides functionality for managing document
 * embeddings in a MariaDB database with vector similarity search capabilities.
 *
 * Features:
 * - Document storage with vector embeddings
 * - Similarity search using vector operations
 * - Document metadata management
 * - Support for different entity types (products, categories, page manager)
 * - Document CRUD operations
 *
 * Requirements:
 * - MariaDB 11.7.0 or higher with vector support
 * - Doctrine ORM configuration
 * - Valid embedding generator implementation
 *
 * @package ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag
 */
 
#[AllowDynamicProperties]
class MariaDBVectorStore extends VectorStoreBase
{
  private Connection $connection;
  private string $tableName;
  private EmbeddingGeneratorInterface $embeddingGenerator;
  private bool $debug = false;
  
  /**
   * Constructor for MariaDBVectorStore
   *
   * Initializes the vector store with a connection to MariaDB and creates
   * the necessary table structure if it doesn't exist.
   *
   * @param EmbeddingGeneratorInterface $embeddingGenerator The embedding generator to use
   * @param string $tableName Optional custom table name (defaults to 'rag_embeddings')
   * @throws \Exception If database connection or table creation fails
   */
  public function __construct(EmbeddingGeneratorInterface $embeddingGenerator, string $tableName = 'rag_embeddings')
  {
    $this->embeddingGenerator = $embeddingGenerator;
    $this->tableName = $tableName;

    // Récupération de la connexion Doctrine
    $entityManager = DoctrineOrm::getEntityManager();
    $this->connection = $entityManager->getConnection();
    $this->debug = \defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER');

    // Initialize security components
    $this->securityLogger = new SecurityLogger();

    // Vérification et création de la structure de la base de données si nécessaire
    DoctrineOrm::createTableStructure($this->tableName);
  }

  /**
   * Validates the format of the embedding
   *
   * Ensures that the embedding is an array and contains only numeric values.
   *
   * @param array $embedding The embedding to validate
   * @throws \InvalidArgumentException If the embedding format is invalid
   */
  private function validateEmbeddingFormat(array $embedding): void {
    if (!is_array($embedding)) {
      throw new \InvalidArgumentException('Embedding must be an array.');
    }

    foreach ($embedding as $value) {
      if (!is_numeric($value)) {
        throw new \InvalidArgumentException('Embedding contains non-numeric values.');
      }
    }

    // Optional: Check for specific length or range of values
    if (empty($embedding)) {
      throw new \InvalidArgumentException('Embedding array cannot be empty.');
    }
  }

  /**
  * * Prepares the embedding and metadata for storage
   *
   * Generates the embedding for the given content and prepares the metadata
   * for insertion into the database.
   *
   * @param string $content The content to embed
   * @param array $metadata Metadata associated with the content
   * @return array Prepared data including embedding and metadata
   */
  private function prepareEmbeddingAndMetadata(string $content, array $metadata): array {
    $embedding = $this->embeddingGenerator->embedText($content);
    $embeddingText = '[' . implode(',', $embedding) . ']';

    return [
      'embeddingText' => $embeddingText,
      'type' => $metadata['type'] ?? null,
      'sourcetype' => $metadata['sourcetype'] ?? 'manual',
      'sourcename' => $metadata['sourcename'] ?? 'manual',
      'chunknumber' => $metadata['chunknumber'] ?? 128,
      'language_id' => $metadata['language_id'] ?? 1,
      'date_modified' => date('Y-m-d H:i:s'),
      'entity_id' => $metadata['entity_id'] ?? null,
    ];
  }

  /**
   * Adds a single document to the vector store
   *
   * Processes the document content, generates embeddings, and stores it in the database
   * along with its metadata and entity information.
   *
   * @param Document $document The document to add, containing content and metadata
   * @throws \Exception If document addition fails
   */
  public function addDocument(Document $document): void
  {
    // Génération de l'embedding pour le document
    $embedding = $this->embeddingGenerator->embedText($document->content);
    $this->validateEmbeddingFormat($embedding);

    // Conversion de l'embedding en format texte pour VEC_FromText
    $preparedData = $this->prepareEmbeddingAndMetadata($document->content, $document->metadata);

    // Insertion dans la base de données
    $this->connection->executeStatement(
      "INSERT INTO {$this->tableName} 
          (content, type, sourcetype, sourcename, embedding, chunknumber, date_modified, entity_id, language_id) 
          VALUES (?, ?, ?, ?, VEC_FromText(?), ?, ?, ?, ?)",
      [
        $document->content,
        $type,
        $sourcetype,
        $sourcename,
        $embeddingText,  // Utilisez $embeddingText au lieu de $embeddingJson
        $chunknumber,
        $date_modified,
        $entity_id,
        $language_id
      ]
    );
  }

  /**
   * Adds multiple documents to the vector store
   *
   * Processes and stores multiple documents in sequence.
   *
   * @param array $documents Array of Document objects to add
   * @throws \Exception If any document addition fails
   */
  public function addDocuments(array $documents): void
  {
    foreach ($documents as $document) {
      $this->addDocument($document);
    }
  }

  /**
   * Performs a similarity search for documents
   *
   * Searches for documents similar to the provided query using vector similarity.
   * Supports both text queries and direct embedding vectors.
   *
   * @param mixed $query Search query (string) or embedding vector (array)
   * @param int $k Maximum number of results to return
   * @param mixed $minScore Minimum similarity score (0-1) for results
   * @param callable|null $filter Optional callback function for filtering results
   * @return iterable Collection of matching Document objects with similarity scores
   * @throws \Exception If search operation fails
   */
  public function similaritySearch(mixed $query, int $k = 4, mixed $minScore = 0.0, ?callable $filter = null): iterable
  {
    try {
      // Déterminer si la requête est déjà un embedding ou un texte à convertir
      $embedding = is_array($query) ? $query : $this->embeddingGenerator->embedText($query);

// Conversion de l'embedding en format texte pour VEC_FromText
      $embeddingText = '[' . implode(',', $embedding) . ']';

      // Construction de la requête SQL avec filtrage optionnel
      $sql = "SELECT *, 
              VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) AS distance 
          FROM {$this->tableName} 
          WHERE 1=1 
          ORDER BY distance ASC 
          LIMIT ?";

// Préparation des paramètres
      $params = [$embeddingText, $k];  // Utilisez $embeddingText au lieu de $embeddingJson
      $types = [ParameterType::STRING, ParameterType::INTEGER];

      // Exécution de la requête
      $stmt = $this->connection->executeQuery($sql, $params, $types);
      $results = $stmt->fetchAllAssociative();

      // Conversion des résultats en objets Document
      $documents = [];

      foreach ($results as $result) {
        $similarity = 1 - $result['distance'];

        if ($similarity < $minScore) {
          continue;
        }

        // Création du document
        $document = new Document();
        $document->id = $result['id'];
        $document->content = $result['content'];
        $document->sourceType = $result['sourcetype'] ?? 'manual';
        $document->sourceName = $result['sourcename'] ?? 'manual';
        $document->chunkNumber = $result['chunknumber'] ?? 128;
        $document->type = $result['type']  ?? null;
        $document->language_id = $result['language_id'] ?? 1;

        // Ajout des métadonnées
        $document->metadata = [
          'id' => $result['id'],
          'type' => $result['type'] ?? null,
          'date_modified' => $result['date_modified'] ?? null,
          'entity_id' => $result['entity_id'] ?? null,
          'language_id' => $result['language_id'] ?? 1,
          'score' => $similarity,
          'table_name' => $this->tableName,
          'distance' => $result['distance']
        ];

        // Application du filtre personnalisé si fourni
        if ($filter !== null && !$filter($document->metadata)) {
          continue;
        }

        $documents[] = $document;
      }

      return $documents;
    } catch (\Exception $e) {
      if ($this->debug == 'True') {
        $this->securityLogger->logSecurityEvent('Error while searching in the table ' . $this->tableName . ' : ' . $e->getMessage(), 'error');
      }

      return [];
    }
  }

  /**
   * Deletes a document from the vector store
   *
   * Removes a document and its embeddings from the database.
   *
   * @param int $id ID of the document to delete
   * @return bool True if successful, false if deletion fails
   */
  public function deleteDocument(int $id): bool
  {
    try {
      $this->connection->executeStatement(
        "DELETE FROM {$this->tableName} WHERE id = ?",
        [$id]
      );

      return true;
    } catch (\Exception $e) {
      if ($this->debug == 'True') {
        $this->securityLogger->logSecurityEvent('Error while deleting the document: ' . $e->getMessage(), 'error');
      }

      return false;
    }
  }

  /**
   * Updates an existing document in the vector store
   *
   * Updates document content, regenerates embeddings, and updates metadata.
   * Maintains entity relationships and historical data.
   *
   * @param int $id ID of the document to update
   * @param string $content New content for the document
   * @param array $metadata Updated metadata for the document
   * @return bool True if successful, false if update fails
   */
  public function updateDocument(int $id, string $content, array $metadata = []): bool
  {
    try {
      $preparedData = $this->prepareEmbeddingAndMetadata($content, $metadata);

      $this->connection->executeStatement(
        "UPDATE {$this->tableName}
        SET content = ?, type = ?, sourcetype = ?, sourcename = ?,
        embedding = VEC_FromText(?), chunknumber = ?, date_modified = ?,
        entity_id = ?, language_id = ?
        WHERE id = ?",
        [
          $content,
          $preparedData['type'],
          $preparedData['sourcetype'],
          $preparedData['sourcename'],
          $preparedData['embeddingText'],
          $preparedData['chunknumber'],
          $preparedData['date_modified'],
          $preparedData['entity_id'],
          $preparedData['language_id'],
          $id
        ]
      );

      return true;
    } catch (\Exception $e) {
      if ($this->debug == 'True') {
        $this->securityLogger->logSecurityEvent('Error while updating the document: ' . $e->getMessage(), 'error');
      }

      return false;
    }
  }
}
