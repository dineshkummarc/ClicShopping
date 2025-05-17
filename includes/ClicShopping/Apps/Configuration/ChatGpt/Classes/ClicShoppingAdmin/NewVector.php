<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin;

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
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Security\InputValidator;

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
    if (strpos(CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL, 'mistral') === 0) {
      $api_key = CLICSHOPPING_APP_CHATGPT_CH_API_KEY_MISTRAL;
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL, 'voyage') === 0) {
      $api_key = CLICSHOPPING_APP_CHATGPT_CH_API_KEY_VOYAGE_AI;
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
      return !empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY_VOYAGE_AI);
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

    $model = CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL;

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
 public static function createEmbedding(string|null $path_file_upload, string|null $text_description, int $token_length = 128)
 {
    $embeddingGenerator = self::gptEmbeddingsModel();

    if ($embeddingGenerator === null) {
      return null;
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
        $splitDocuments = DocumentSplitter::splitDocuments($documents, $token_length);
        $formattedDocuments = EmbeddingFormatter::formatEmbeddings($splitDocuments);
        $embeddingGenerator = self::gptEmbeddingsModel();
        $embeddedDocuments = $embeddingGenerator->embedDocuments($formattedDocuments);
      } else {
        // Raw data only (text)
        $embeddingGenerator = self::gptEmbeddingsModel();
        $embedded = $embeddingGenerator->embedText($text_description);

        $document = new Document();
        $document->content = $text_description;
        $document->embedding = $embedded;
        $document->sourceName = 'manual';
        $document->sourceType = 'manual';

        $splitDocuments = DocumentSplitter::splitDocument($document, $token_length);
        $formattedDocuments = EmbeddingFormatter::formatEmbeddings($splitDocuments);

        // Generation of embeddings on the split document.
        $embeddedDocuments = $embeddingGenerator->embedDocuments($formattedDocuments);
      }

      return $embeddedDocuments;
    } catch (\Exception $e) {
      // Gérer les erreurs potentielles lors de la génération d'embeddings
      error_log('Erreur lors de la génération d\'embeddings: ' . $e->getMessage());
      return null;
    }
 }

  /**
   * Initializes and returns an OpenAIChat instance configured with specified parameters.
   *
   * @return mixed An instance of the OpenAIChat class configured for GPT functionality.
   */
  private static function chat(): mixed // Not use currently
  {
    $api_key = self::getApiKey();
    $parameters = ['model' => CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL];

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

  /**
   * Retourne la longueur des embeddings en fonction du modèle utilisé.
   * 
   * @param int $vector Valeur par défaut pour OpenAI (3072)
   * @return int La longueur des embeddings
   */
  public static function getEmbeddingLength(): int
  {
    if (strpos(CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL, 'gpt-large') === 0) {
      return 3072; // OpenAI Large
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL, 'gpt-medium') === 0) {
      return 1536; // OpenAI Medium
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL, 'mistral') === 0) {
      return 1024; // Mistral
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL, 'voyage3-large') === 0) {
      return 4096; // Voyage3 Large
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL, 'voyage3-lite') === 0) {
      return 384; // Voyage3 Lite
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_CH_EMBEDDING_MODEL, 'voyage3') === 0) {
      return 1024; // Voyage3 standard
    } else {
      return 1536; // Ollama (valeur par défaut)
    }
  }
}
