<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domain\Embedding;

use AllowDynamicProperties;

use ClicShopping\OM\CLICSHOPPING;

use LLPhant\Chat\OpenAIChat;
use LLPhant\Embeddings\DataReader\FileDataReader;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;
use LLPhant\Embeddings\EmbeddingFormatter\EmbeddingFormatter;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\Mistral\MistralEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\Voyage3EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\Voyage3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\Voyage3LiteEmbeddingGenerator;
use LLPhant\OpenAIConfig;
use LLPhant\VoyageAIConfig;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Security\InputValidator;


#[AllowDynamicProperties]
class NewVector
{
  /**
   * Retourne la clé API appropriée en fonction du modèle configuré
   * 
   * @return string La clé API correspondant au modèle configuré
   */
  private static function getApiKey(): string
  {
    $api_key = CLICSHOPPING_APP_CHATGPT_CH_API_KEY;

    // Déterminer quelle clé API utiliser en fonction du modèle
    if (strpos(CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL, 'mistral') === 0) {
      $api_key = CLICSHOPPING_APP_CHATGPT_CH_API_KEY_MISTRAL;
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL, 'voyage') === 0) {
      $api_key = CLICSHOPPING_APP_CHATGPT_RA_API_KEY_VOYAGE_AI;
    }

    return $api_key;
  }

  /**
   * Vérifie si les clés API nécessaires sont disponibles pour le modèle sélectionné
   * 
   * @param string $model Le modèle d'embedding à vérifier
   * @return bool True si les clés API sont disponibles, false sinon
   */
  private static function checkApiKeys(string $model): bool
  {
    if (strpos($model, 'gpt') === 0) {
      return !empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY);
    } elseif (strpos($model, 'mistral') === 0) {
      return !empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY_MISTRAL);
    } elseif (strpos($model, 'voyage') === 0) {
      return !empty(CLICSHOPPING_APP_CHATGPT_RA_API_KEY_VOYAGE_AI);
    } elseif (strpos($model, 'ollama') === 0) {
      return true; // Ollama n'a pas besoin de clé API
    }

    return false;
  }

  /**
   * Retourne la liste des modèles d'embedding disponibles
   * 
   * @return array Tableau des modèles d'embedding disponibles
   */
  public static function getEmbeddingModel(): array
  {
    $array = [
      ['id' => 'gpt-large', 'text' => 'OpenAI Large embedding (3072 dimensions)'],
      ['id' => 'gpt-medium', 'text' => 'OpenAI Medium embedding (1536 dimensions)'],
      ['id' => 'nomic-embed-text', 'text' => 'Ollama embedding nomic-embed-text (1536 dimensions)'],
      ['id' => 'mistral', 'text' => 'Mistral embedding (1024 dimensions)'],
      ['id' => 'voyage3', 'text' => 'Voyage 3 embedding (1024 dimensions)'],
      ['id' => 'voyage3-large', 'text' => 'Voyage 3 Large embedding (4096 dimensions)'],
      ['id' => 'voyage3-lite', 'text' => 'Voyage 3 Lite embedding (384 dimensions)'],
    ];

    return $array;
  }

  /**
   * Retourne le générateur d'embeddings approprié en fonction du modèle configuré.
   *
   * @return object|null Instance du générateur d'embeddings approprié ou null si la clé API est manquante
   */
  public static function gptEmbeddingsModel(): object|null
  {
    Gpt::getEnvironment();

    $model = CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL;

    if (!$model) {
      return null;
    }

    // Vérifier si les clés API nécessaires sont disponibles
    if (!self::checkApiKeys($model)) {
      return null;
    }

    // Obtenir la clé API appropriée
    $api_key = self::getApiKey();

    if (strpos($model, 'gpt-large') === 0) {
      $config = new OpenAIConfig();
      $config->apiKey = $api_key;
      return new OpenAI3LargeEmbeddingGenerator($config);
    } elseif (strpos($model, 'gpt-medium') === 0) {
      $config = new OpenAIConfig();
      $config->apiKey = $api_key;
      return new OpenAI3SmallEmbeddingGenerator($config);
    } elseif (strpos($model, 'mistral') === 0) {
      $config = new OpenAIConfig();
      $config->apiKey = $api_key;
      return new MistralEmbeddingGenerator($config);
    } elseif (strpos($model, 'voyage3-large') === 0) {
      $config = new VoyageAIConfig();
      $config->apiKey = $api_key;
      return new Voyage3LargeEmbeddingGenerator($config);
    } elseif (strpos($model, 'voyage3-lite') === 0) {
      $config = new VoyageAIConfig();
      $config->apiKey = $api_key;
      return new Voyage3LiteEmbeddingGenerator($config);
    } elseif (strpos($model, 'voyage3') === 0) {
      $config = new VoyageAIConfig();
      $config->apiKey = $api_key;
      return new Voyage3EmbeddingGenerator($config);
    } else {
      // Par défaut, utiliser Ollama pour les embeddings
      return new OllamaEmbeddingGenerator($model);
    }
  }

  /**
   * Generates embeddings for a set of documents or a text description. If a file path is provided, the content of the file
   * is read and converted into embeddings. If a text description is provided instead, it is directly processed for embedding generation.
   *
   * @param string|null $path_file_upload Le chemin du fichier à traiter
   * @param string|null $text_description Le texte à traiter
   * @param int $token_length
   * @return array|null Les embeddings générés ou null en cas d'erreur
   * @throws ClientExceptionInterface
   */
 public static function createEmbedding(string|null $path_file_upload, string|null $text_description, ?int $token_length = null)
 {
    $embeddingGenerator = self::gptEmbeddingsModel();

    if ($embeddingGenerator === null) {
      return null;
    }

    // Utiliser la taille de chunk optimale si non spécifiée
    if ($token_length === null) {
      $token_length = self::getOptimalChunkSize();
    }

    // Validation: ne pas dépasser 90% du contexte du modèle
    $maxContextLength = self::getModelContextLength();
    $safeMaxChunkSize = (int)($maxContextLength * 0.9);

    if ($token_length > $safeMaxChunkSize) {
      error_log("Warning: chunk size {$token_length} exceeds safe limit {$safeMaxChunkSize}, adjusting...");
      $token_length = $safeMaxChunkSize;
    }

    if (!empty($path_file_upload)) {
      $baseDir = CLICSHOPPING::getConfig('dir_root', 'Shop') . 'sources/Download/Private';

      // Define allowed file extensions for security
      $allowedExtensions = ['txt', 'pdf', 'doc', 'docx', 'csv', 'json', 'xml'];

      // Validate the file path using our enhanced validator
      $validatedPath = InputValidator::validateFilePath(
        $path_file_upload,
        [$baseDir],
        $allowedExtensions,
        true // File must exist
      );

      if ($validatedPath === false) {
        throw new \RuntimeException('Invalid or unauthorized file path');
      }

      // Use the validated path for further operations
      $path_file_upload = $validatedPath;
    }

    try {
      if (is_file($path_file_upload)) {
        $filePath = $path_file_upload;
        $reader = new FileDataReader($filePath);
        $documents = $reader->getDocuments();

        // Vérifier la taille totale avant splitting
        $totalContent = '';
        foreach ($documents as $doc) {
          $totalContent .= $doc->content;
        }

        $estimatedTokens = self::estimateTokenCount($totalContent);

        if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
          error_log("Info : File content: ~{$estimatedTokens} tokens, will split into chunks of {$token_length} tokens");
        }

        // Splitter les documents
        $splitDocuments = DocumentSplitter::splitDocuments($documents, $token_length);
        $formattedDocuments = EmbeddingFormatter::formatEmbeddings($splitDocuments);

        // Générer les embeddings
        $embeddedDocuments = $embeddingGenerator->embedDocuments($formattedDocuments);
      } else {
        // Raw data only (text)
        $embeddingGenerator = self::gptEmbeddingsModel();

        // Vérifier la taille du texte
        $estimatedTokens = self::estimateTokenCount($text_description);

        if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
          error_log("Info : Text content: ~{$estimatedTokens} tokens, chunk size: {$token_length}");
        }

        // Si le texte est trop long, le splitter AVANT d'embedder
        if ($estimatedTokens > $token_length) {
          // Créer un document temporaire
          $tempDocument = new Document();
          $tempDocument->content = $text_description;
          $tempDocument->sourceName = 'manual';
          $tempDocument->sourceType = 'manual';

          // Splitter en chunks
          $splitDocuments = DocumentSplitter::splitDocument($tempDocument, $token_length);
          $formattedDocuments = EmbeddingFormatter::formatEmbeddings($splitDocuments);

          // Générer les embeddings pour chaque chunk
          $embeddedDocuments = $embeddingGenerator->embedDocuments($formattedDocuments);

        } else {
          // Texte assez court, traitement normal
          $embedded = $embeddingGenerator->embedText($text_description);

          $document = new Document();
          $document->content = $text_description;
          $document->embedding = $embedded;
          $document->sourceName = 'manual';
          $document->sourceType = 'manual';

          $embeddedDocuments = [$document];
        }
      }

      return $embeddedDocuments;

    } catch (\Exception $e) {
      // Gérer les erreurs avec plus de détails
      $errorMessage = 'Erreur lors de la génération d\'embeddings: ' . $e->getMessage();

      // Ajouter des infos de contexte si disponible
      if (isset($estimatedTokens)) {
        $errorMessage .= " (estimated tokens: {$estimatedTokens}, chunk size: {$token_length})";
      }

      error_log($errorMessage);

      // Si erreur de contexte trop long, retry avec chunk plus petit
      if (strpos($e->getMessage(), 'maximum context length') !== false && $token_length > 200) {
        error_log("Retrying with smaller chunk size...");
        return self::createEmbedding($path_file_upload, $text_description, (int)($token_length / 2));
      }

      return null;
    }
  }

  /**
   * Estimate the number of tokens in a given text based on average characters per token for the selected model.
   *
   * @param string $text The input text to estimate token count for.
   * @return int Estimated number of tokens in the text.
   */
  private static function estimateTokenCount(string $text): int
  {
    // Règles d'estimation selon le modèle
    $model = CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL;

    // Pour les modèles basés sur GPT tokenizer
    if (strpos($model, 'gpt') === 0 || strpos($model, 'voyage') === 0) {
      // ~4 caractères par token en anglais, ~2.5 en français
      $avgCharsPerToken = 3.5; // Moyenne
      return (int)ceil(strlen($text) / $avgCharsPerToken);
    }

    // Pour Mistral et autres
    return (int)ceil(strlen($text) / 4);
  }


  /**
   * Initializes and returns an OpenAIChat instance configured with specified parameters.
   *
   * @return mixed An instance of the OpenAIChat class configured for GPT functionality.
   */
  private static function chat(): mixed // Not use currently
  {
    $api_key = self::getApiKey();
    $parameters = ['model' => CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL];

    $config = new OpenAIConfig();
    $config->apiKey = $api_key;
    $config->model = $parameters['model'];
    $config->modelOptions = $parameters;

    $chat = new OpenAIChat($config);
    return $chat;
  }

  /**
   * Retrieves the content of a document either from a specified file path or from a text description.
   *
   * @param string|null $path_file_upload The file path to upload and read the document from. Can be null.
   * @param string|null $text_description The text description to use if the file path is null or invalid. Can be null.
   *
   * @return string Returns the content of the document, either read from the file or taken from the text description.
   */
  public static function getDocument(string|null $path_file_upload, string|null $text_description): string
  {
    if (is_file($path_file_upload)) {
      $filePath = $path_file_upload;
      $reader = new FileDataReader($filePath);
      $documents = $reader->getDocuments();
      $documents = $documents[0]->content;
    } else {
      $documents = $text_description;
    }

    return $documents;
  }

//***********
// Statistics
//***********
  /**
   * Calculates the mean (average) value of the given array of numbers.
   *
   * @param array $values The array of numerical values to calculate the mean from.
   * @return float The calculated mean value of the array.
   * @throws DivisionByZeroError If the array is empty, causing a division by zero.
   */
  private function calculateMean(array $values)
  {
    return array_sum($values) / count($values);
  }

  /**
   * Calculates the variance of a given array of numeric values.
   *
   * @param array $values An array of numeric values for which to calculate the variance.
   * @return float The calculated variance of the provided values.
   * @throws \InvalidArgumentException If the input array is empty.
   */
   private function calculateVariance(array $values): float
  {
    $mean = $this->calculateMean($values);
    $sum_of_squared_diff = 0;

    foreach ($values as $value) {
      $sum_of_squared_diff += pow($value - $mean, 2);
    }

    if (empty($values)) {
      throw new \InvalidArgumentException('The array should not be empty.');
    }

    return $sum_of_squared_diff / count($values);
  }


  /**
   * Returns the embedding length for the configured embedding model.
   *
   * @return int The embedding length in dimensions for the selected model.
   */
  public static function getEmbeddingLength(): int
  {
    if (strpos(CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL, 'gpt-large') === 0) {
      return 3072; // OpenAI Large
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL, 'gpt-medium') === 0) {
      return 1536; // OpenAI Medium
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL, 'mistral') === 0) {
      return 1024; // Mistral
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL, 'voyage3-large') === 0) {
      return 4096; // Voyage3 Large
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL, 'voyage3-lite') === 0) {
      return 384; // Voyage3 Lite
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL, 'voyage3') === 0) {
      return 1024; // Voyage3 standard
    } else {
      return 1536; // Ollama (valeur par défaut)
    }
  }

  /**
   * Returns the maximum context length for the configured embedding model.
   *
   * @return int The maximum context length in tokens for the selected model.
   */
  public static function getModelContextLength(): int
  {
    $model = CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL;

    $contextLengths = [
      'gpt-large' => 8192,
      'gpt-medium' => 8192,
      'mistral' => 4096,
      'voyage3-large' => 16000,
      'voyage3' => 8000,
      'voyage3-lite' => 4000,
      'nomic-embed-text' => 8192,
    ];

    foreach ($contextLengths as $modelPrefix => $length) {
      if (strpos($model, $modelPrefix) === 0) {
        return $length;
      }
    }

    return 4096; // Par défaut conservateur
  }
  
  /**
   * Returns the optimal chunk size for the configured embedding model.
   *
   * @return int The optimal chunk size in tokens for the selected model.
   */
  public static function getOptimalChunkSize(): int
  {
    $model = CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL;

    // Configuration par modèle
    $chunkSizes = [
      'gpt-large' => 800,      // OpenAI Large (context: 8192)
      'gpt-medium' => 800,     // OpenAI Medium (context: 8192)
      'mistral' => 500,        // Mistral (context plus limité)
      'voyage3-large' => 1000, // Voyage3 Large (context: 16k)
      'voyage3' => 800,        // Voyage3 standard (context: 8k)
      'voyage3-lite' => 300,   // Voyage3 Lite (modèle léger)
      'nomic-embed-text' => 800, // Ollama (context: 8192)
    ];

    // Trouver la taille appropriée
    foreach ($chunkSizes as $modelPrefix => $size) {
      if (strpos($model, $modelPrefix) === 0) {
        return $size;
      }
    }

    // Taille par défaut sécuritaire
    return 500;
  }

  /**
   * Identifies the embedding model provider based on its name.
   * @param string $model
   * @return string
   */
  private static function getModelProvider(string $model): string
  {
    if (strpos($model, 'gpt') === 0) return 'openai';
    if (strpos($model, 'mistral') === 0) return 'mistral';
    if (strpos($model, 'voyage') === 0) return 'voyageai';
    if (strpos($model, 'nomic') === 0) return 'ollama';

    return 'unknown';
  }

  //*********************
  // Not Used
  //*********************


  /**
   * Returns the configuration details for the current embedding model.
   *
   * @return array An associative array containing model configuration details such as model name, embedding length,
   *               context length, optimal chunk size, safe maximum chunk size, and provider.
   */
  public static function getModelConfiguration(): array
  {
    $model = CLICSHOPPING_APP_CHATGPT_RA_EMBEDDING_MODEL;

    return [
      'model' => $model,
      'embedding_length' => self::getEmbeddingLength(),
      'context_length' => self::getModelContextLength(),
      'optimal_chunk_size' => self::getOptimalChunkSize(),
      'safe_max_chunk_size' => (int)(self::getModelContextLength() * 0.9),
      'provider' => self::getModelProvider($model),
    ];
  }
  
  
   /**
   * Validates whether a document can be embedded based on its estimated token count and the specified maximum token limit.
   *
   * @param string $content The content of the document to validate.
   * @param int|null $maxTokens The maximum number of tokens allowed. If null, the optimal chunk size for the model will be used.
   * @return array An associative array containing:
   *               - 'valid' (bool): Whether the document is valid for embedding.
   *               - 'estimated_tokens' (int): The estimated number of tokens in the document.
   *               - 'max_tokens' (int): The maximum token limit used for validation.
   *               - 'chunks_needed' (int): The number of chunks needed if the document exceeds the max token limit.
   *               - 'should_split' (bool): Whether the document should be split into multiple chunks.
   */
  public static function validateDocumentSize(string $content, ?int $maxTokens = null): array
  {
    if ($maxTokens === null) {
      $maxTokens = self::getOptimalChunkSize();
    }

    $estimatedTokens = self::estimateTokenCount($content);
    $chunksNeeded = (int)ceil($estimatedTokens / $maxTokens);

    return [
      'valid' => $estimatedTokens <= $maxTokens,
      'estimated_tokens' => $estimatedTokens,
      'max_tokens' => $maxTokens,
      'chunks_needed' => $chunksNeeded,
      'should_split' => $chunksNeeded > 1,
    ];
  }

  /**
   * Calculates the standard deviation of the given array of values.
   *
   * @param array $values The array of numerical values to calculate the standard deviation for.
   * @return float The calculated standard deviation of the values.
   * @throws \InvalidArgumentException If the provided array is empty.
   */
  public function calculateStandardDeviation(array $values): float
  {
    $variance = $this->calculateVariance($values);

    if (empty($values)) {
      throw new \InvalidArgumentException('The array should not be empty.');
    }

    return sqrt($variance);
  }

  /**
   * Calculates the cosine similarity between two vectors.
   *
   * @param array $vec1 An array representing the first vector.
   * @param array $vec2 An array representing the second vector. Must have the same length as $vec1.
   * @return float The cosine similarity value, which ranges from -1 to 1. Returns 0.0 if either vector has zero magnitude.
   * @throws InvalidArgumentException If the input vectors do not have the same length.
   */
  public static function cosineSimilarity(array $vec1, array $vec2) :float
  {
    if (count($vec1) !== count($vec2)) {
      throw new InvalidArgumentException('Vectors must have the same length.');
    }

    $dot_product = 0;
    $magnitude_vec1 = 0;
    $magnitude_vec2 = 0;

    foreach ($vec1 as $i => $value) {
      $dot_product += $value * $vec2[$i];
      $magnitude_vec1 += $value * $value;
      $magnitude_vec2 += $vec2[$i] * $vec2[$i];
    }

    if ($magnitude_vec1 == 0 || $magnitude_vec2 == 0) {
      return 0.0; // Return 0 for vectors with no magnitude
    }

    return $dot_product / (sqrt($magnitude_vec1) * sqrt($magnitude_vec2));
  }
}
