<?php
/**
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */




/* amélioration posislve utilisé acpu,memecahched, ou redis*/
/*
 *
 * use ClicShopping\AI\Infrastructure\Cache
 *
 * $cacheKey = 'rag_cache_' . md5($query);
$cached = apcu_fetch($cacheKey);
if ($cached !== false) {
    return $cached;
}
$results = $this->vectorStore->similaritySearch($query, $limit, 0.75, $filter);
apcu_store($cacheKey, $results, 300); // TTL 5 minutes
 */


namespace ClicShopping\AI\Domains\WebSearch\Cache;

use AllowDynamicProperties;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;
use ClicShopping\AI\Infrastructure\Storage\MariaDBVectorStore;
use ClicShopping\AI\Domains\CoreAI\Embedding\NewVector;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;

/**
 * SearchCacheManager Class
 *
 * Gère le cache intelligent (Learning RAG) pour les résultats de recherche web.
 * Stocke les résultats de haute qualité dans `rag_web_cache_vectors` pour :
 * - Réduire les coûts d'API
 * - Accélérer les requêtes futures
 * - Apprendre des recherches passées (self-healing)
 */
#[AllowDynamicProperties]
class SearchCacheManager
{
  private MariaDBVectorStore $vectorStore;
  private $embeddingGenerator;
  private SecurityLogger $logger;
  private bool $debug;
  private string $tableName; // 🆕 Stocker le nom de la table

  // Configuration
  private int $maxChunkSize = 800;
  private int $chunkOverlap = 50;
  private float $qualityThreshold = 0.7;

  /**
   * Constructor
   *
   * @param string $tableName Table pour le cache (défaut: rag_web_cache_vectors)
   */
   public function __construct(string $tableName = 'rag_web_cache_embedding')
  {
    $this->logger = new SecurityLogger();
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';
    $this->embeddingGenerator = NewVector::gptEmbeddingsModel();
/*
    if (!$this->embeddingGenerator) {
      // Mode dégradé : désactiver le cache de recherche
      $this->embeddingGenerator = null;

      $this->vectorStore = null;
      error_log('SearchCacheManager: Disabled due to missing embedding generator');
      return;
    }
*/
    // Initialiser le vector store
    $prefix = CLICSHOPPING::getConfig('db_table_prefix');
    $fullTableName = $prefix . $tableName;

    // 🆕 Stocker le nom de la table localement
    $this->tableName = $fullTableName;

    $this->vectorStore = new MariaDBVectorStore($this->embeddingGenerator, $fullTableName);

    if ($this->debug) {
      $this->logger->logSecurityEvent("SearchCacheManager initialized with table: {$fullTableName}", 'info');
    }
  }

  /**
   * 🆕 Return the table name used for storage
   *
   * @return string Table name
   */
  public function getTableName(): string
  {
    return $this->tableName;
  }

  /**
   * 🎯 Stocke les résultats web dans le RAG d'apprentissage
   *
   * @param string $query Requête originale
   * @param array $results Résultats formatés de WebSearchTool
   * @param array $metadata Métadonnées additionnelles
   * @return bool True si succès
   */
  public function storeInLearningRAG(string $query, array $results, array $metadata = []): bool
  {
    // Vérifier si le vector store est disponible
    if ($this->vectorStore === null) {
      return false; // Pas de stockage possible
    }
    
    try {
      // 1. Vérifier la qualité (ne stocker que les bons résultats)
      $qualityScore = $this->calculateQualityScore($results);

      if ($qualityScore < $this->qualityThreshold) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Quality too low ({$qualityScore}), skipping storage",
            'info'
          );
        }
        return false;
      }

      // 2. Créer le contenu formaté
      $content = $this->formatContentForStorage($query, $results);

      // Vérifier que le contenu n'est pas vide
      if (empty(trim($content))) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Empty content generated, cannot store",
            'warning'
          );
        }
        return false;
      }

      // 3. Créer le document
      $document = new Document();
      $document->content = $content;
      $document->sourceType = 'web_search';
      $document->sourceName = $results['metadata']['search_engine'] ?? 'serpapi';

      // 4. Métadonnées enrichies
      $document->metadata = [
        'type' => 'web_search_cache',
        'original_query' => $query,
        'search_engine' => $results['metadata']['search_engine'] ?? 'serpapi',
        'quality_score' => $qualityScore,
        'usage_count' => 0,
        'last_used' => null,
        'total_results' => $results['total_results'] ?? 0,
        'date_cached' => date('Y-m-d H:i:s'),
      ];

      // Ajouter métadonnées custom
      if (!empty($metadata)) {
        $document->metadata = array_merge($document->metadata, $metadata);
      }

      // 5. Vérifier la taille et splitter si nécessaire
      $estimatedTokens = $this->estimateTokenCount($content);

      if ($estimatedTokens > $this->maxChunkSize) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Content large ({$estimatedTokens} tokens), splitting into chunks",
            'info'
          );
        }

        // Splitter le document
        $splitDocs = DocumentSplitter::splitDocument($document, $this->maxChunkSize, $this->chunkOverlap);

        // Stocker chaque chunk
        $storedCount = 0;

        foreach ($splitDocs as $chunk) {
          if (empty($chunk->content)) {
            continue; // Skip les chunks vides
          }

          // Enrichir métadonnées du chunk
          $chunk->metadata['is_chunked'] = true;
          $chunk->metadata['chunk_parent_query'] = $query;

          $this->vectorStore->addDocument($chunk);
          $storedCount++;
        }

        if ($this->debug) {
          $this->logger->logSecurityEvent("Stored {$storedCount} chunks in learning RAG", 'info' );
        }

      } else {
        // Document assez petit, stockage direct
        $this->vectorStore->addDocument($document);

        if ($this->debug) {
          $this->logger->logSecurityEvent( "Stored single document in learning RAG", 'info'
          );
        }
      }

      return true;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent("Error storing in learning RAG: " . $e->getMessage(), 'error');
      return false;
    }
  }

  /**
   * 🔍 Recherche dans le cache RAG
   *
   * @param string $query Requête de recherche
   * @param int $limit Nombre de résultats max
   * @return array|null Résultats du cache ou null si rien trouvé
   */
  public function searchInCache(string $query, int $limit = 3): ?array
  {
    // Vérifier si le vector store est disponible
    if ($this->vectorStore === null) {
      return null; // Pas de cache disponible
    }
    
    try {
      // Recherche par similarité
      $results = $this->vectorStore->similaritySearch(
        $query,
        $limit,
        0.75, // Seuil élevé pour le cache
        function($metadata) {
          // Filtrer : uniquement les résultats de cache web
          return isset($metadata['type'])
            && $metadata['type'] === 'web_search_cache';
        }
      );

      if (empty($results)) {
        return null;
      }

      // Incrémenter usage_count et last_used
      foreach ($results as $doc) {
        $this->incrementUsageCount($doc->id);
      }

      $formatted = [];

      foreach ($results as $doc) {
        $formatted[] = [
          'content' => $doc->content,
          'original_query' => $doc->metadata['original_query'] ?? '',
          'quality_score' => $doc->metadata['quality_score'] ?? 0,
          'similarity_score' => $doc->metadata['score'] ?? 0,
          'usage_count' => $doc->metadata['usage_count'] ?? 0,
        ];
      }

      if ($this->debug) {
        $this->logger->logSecurityEvent("Cache hit: Found " . count($formatted) . " results for query", 'info');
      }

      return $formatted;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent("Error searching cache: " . $e->getMessage(), 'error');
      return null;
    }
  }

  /**
   * Formate le contenu pour stockage
   *
   * @param string $query Requête originale
   * @param array $results Résultats de recherche
   * @return string Contenu formaté
   */
  private function formatContentForStorage(string $query, array $results): string
  {
    $parts = [];

    $parts[] = "Query: {$query}";
    $parts[] = "";

    if (isset($results['featured_snippet'])) {
      $parts[] = "Featured Answer:";
      $parts[] = $results['featured_snippet']['answer'];
      $parts[] = "Source: " . $results['featured_snippet']['source'];
      $parts[] = "";
    }

    $parts[] = "Top Results:";
    $items = array_slice($results['items'] ?? [], 0, 5); // Max 5 résultats

    foreach ($items as $i => $item) {
      $parts[] = ($i + 1) . ". " . $item['title'];
      $parts[] = "   " . $item['snippet'];
      
      // Store full URL if available, otherwise just domain
      if (!empty($item['link'])) {
        $parts[] = "   Source: " . $item['link'];
      } else {
        $parts[] = "   Source: " . $item['source'];
      }
      
      $parts[] = "";
    }

    return implode("\n", $parts);
  }

  /**
   * Calcule un score de qualité pour les résultats
   *
   * @param array $results Résultats de recherche
   * @return float Score de qualité (0-1)
   */
  private function calculateQualityScore(array $results): float
  {
    $score = 0.5; // Base

    if (isset($results['featured_snippet']) && !empty($results['featured_snippet']['answer'])) {
      $score += 0.2;
    }

    $relevantCount = 0;
    foreach ($results['items'] ?? [] as $item) {
      if (($item['relevance_score'] ?? 0) > 0.7) {
        $relevantCount++;
      }
    }

    $score += min($relevantCount * 0.1, 0.3);

    if (($results['total_results'] ?? 0) > 100) {
      $score += 0.1;
    }

    return min($score, 1.0);
  }

  /**
   * Estime le nombre de tokens dans un texte
   *
   * Règle approximative : ~4 caractères par token
   * @param string $text Texte
   * @return int Nombre de tokens estimé
   */
  private function estimateTokenCount(string $text): int
  {
    return (int)ceil(strlen($text) / 4);
  }

  /**
   * Incrémente le compteur d'usage d'un document
   *
   * @param int $documentId ID du document
   */
  private function incrementUsageCount(int $documentId): void
  {
    try {
      // 🔧 TASK 4.4.1 PHASE 5: Migrated to DoctrineOrm
      $tableName = $this->tableName;

      $sql = "UPDATE {$tableName}
              SET usage_count = COALESCE(usage_count, 0) + 1,
                  last_used = NOW()
              WHERE id = :id";

      DoctrineOrm::execute($sql, ['id' => $documentId]);

    } catch (\Exception $e) {
      // Log mais ne pas bloquer
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Failed to increment usage count: " . $e->getMessage(),
          'warning'
        );
      }
    }
  }

  /**
   * Nettoie les vieux documents peu utilisés
   *
   * @param int $daysOld Age en jours
   * @param int $minUsageCount Usage minimum pour conserver
   * @return int Nombre de documents supprimés
   */
  public function cleanOldCache(int $daysOld = 90, int $minUsageCount = 2): int
  {
    try {
      // 🔧 TASK 4.4.1 PHASE 5: Migrated to DoctrineOrm
      $tableName = $this->tableName;

      $sql = "DELETE FROM {$tableName} 
              WHERE type = 'web_search_cache'
                AND date_modified < DATE_SUB(NOW(), INTERVAL :days DAY)
                AND COALESCE(usage_count, 0) < :min_usage";

      $deleted = DoctrineOrm::execute($sql, [
        'days' => $daysOld,
        'min_usage' => $minUsageCount
      ]);

      if ($this->debug && $deleted > 0) {
        $this->logger->logSecurityEvent( "Cleaned {$deleted} old cache entries (>{$daysOld} days, usage<{$minUsageCount})",'info');
      }

      return $deleted;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent("Error cleaning cache: " . $e->getMessage(),'error');
      return 0;
    }
  }

  /**
   * Obtient des statistiques sur le cache
   *
   * @return array Statistiques
   */
  public function getCacheStats(): array
  {
    try {
      // 🔧 TASK 4.4.1 PHASE 5: Migrated to DoctrineOrm
      $tableName = $this->tableName;

      $sql = "SELECT 
                COUNT(*) as total_entries,
                AVG(quality_score) as avg_quality,
                SUM(usage_count) as total_usage,
                MAX(usage_count) as max_usage,
                COUNT(DISTINCT original_query) as unique_queries
              FROM {$tableName}
              WHERE type = 'web_search_cache'";

      $stats = DoctrineOrm::selectOne($sql);

      if (!$stats) {
        return [
          'total_cached_entries' => 0,
          'average_quality_score' => 0,
          'total_reuses' => 0,
          'max_reuses' => 0,
          'unique_queries_cached' => 0,
        ];
      }

      return [
        'total_cached_entries' => (int)$stats['total_entries'],
        'average_quality_score' => round((float)$stats['avg_quality'], 2),
        'total_reuses' => (int)$stats['total_usage'],
        'max_reuses' => (int)$stats['max_usage'],
        'unique_queries_cached' => (int)$stats['unique_queries'],
      ];

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Error getting cache stats: " . $e->getMessage(),
        'error'
      );

      return [
        'total_cached_entries' => 0,
        'average_quality_score' => 0,
        'total_reuses' => 0,
        'max_reuses' => 0,
        'unique_queries_cached' => 0,
      ];
    }
  }

  /**
   * Configure le seuil de qualité
   *
   * @param float $threshold Seuil (0-1)
   */
  public function setQualityThreshold(float $threshold): void
  {
    $this->qualityThreshold = max(0.0, min(1.0, $threshold));
  }

  /**
   * Configure la taille de chunk
   *
   * @param int $size Taille en tokens
   */
  public function setMaxChunkSize(int $size): void
  {
    $this->maxChunkSize = max(100, $size);
  }

  /**
   * Obtient le seuil de qualité actuel
   *
   * @return float Seuil (0-1)
   */
  public function getQualityThreshold(): float
  {
    return $this->qualityThreshold ?? 0.75;
  }
}