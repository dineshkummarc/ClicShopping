<?php
/**
 * Query Cache System for RAG Analytics
 * Orchestrates different cache backends (DB, File, Memcached, Redis)
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\AI\Infrastructure\Cache;

use ClicShopping\OM\Registry;
use ClicShopping\AI\Infrastructure\Cache\SubQueryCache\CacheKeyGenerator;
use ClicShopping\AI\Infrastructure\Cache\SubQueryCache\CacheStorage;
use ClicShopping\AI\Infrastructure\Cache\SubQueryCache\CacheFileStorage;
use ClicShopping\AI\Infrastructure\Cache\SubQueryCache\CacheCleanup;
use ClicShopping\AI\Infrastructure\Cache\SubQueryCache\CacheStatistics;
use ClicShopping\AI\Infrastructure\Cache as RagCache;

/**
 * Système de cache pour les requêtes SQL et leurs résultats
 * Réduit les temps de réponse de 57s à < 1s pour les requêtes répétées
 * Supporte: Database, File, Memcached, Redis
 */
class QueryCache
{
  private int $defaultTTL;
  private int $maxCacheSize = 1000; // Nombre max d'entrées
  private bool $enabled = true;
  private bool $debug = false;

  // Backend storage
  private string $backend = 'database'; // 'database', 'file', 'memcached', 'redis'
  private ?CacheStorage $dbStorage = null;
  private ?CacheFileStorage $fileStorage = null;
  private ?RagCache $ragCache = null;

  // Sub-components
  private CacheCleanup $cleanup;
  private CacheStatistics $statistics;
  private mixed $db = null;
  public function __construct()
  {
    // Activer le cache par défaut sauf si explicitement désactivé
    $this->enabled = !defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER') || CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True';
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    // 🆕 Configurer le TTL depuis une constante (défaut: 1 mois = 2592000 secondes)
    $this->defaultTTL = defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_TTL')? (int)CLICSHOPPING_APP_CHATGPT_RA_CACHE_TTL  : 2592000; // 30 jours par défaut

    // Toujours initialiser $db (nécessaire même si cache désactivé)
    try {
      $this->db = Registry::get("Db");
      if ($this->db === null) {
        error_log("QueryCache: ERROR - Registry::get('Db') returned null");
      }
    } catch (\Exception $e) {
      error_log("QueryCache: ERROR initializing \$db: " . $e->getMessage());
      $this->db = null;
    }

    // Initialiser les composants de base
    $this->cleanup = new CacheCleanup($this->maxCacheSize, $this->debug);
    $this->statistics = new CacheStatistics();

    if (!$this->enabled) {
      if ($this->debug) {
        error_log("QueryCache: Cache disabled (CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER = False)");
      }
      return;
    }

    // Déterminer le backend à utiliser
    $this->determineBackend();


    if ($this->debug) {
      error_log("QueryCache: Initialized with backend: {$this->backend}");
    }
  }

  /**
   * Détermine quel backend de cache utiliser selon la configuration
   */
  private function determineBackend(): void
  {
    // Priorité: Memcached > Redis > Database > File

    if (defined('USE_MEMCACHED') && USE_MEMCACHED === 'True') {
      $this->backend = 'memcached';
      $this->ragCache = new RagCache(true);

      if ($this->debug) {
        error_log("QueryCache: Using Memcached backend");
      }
      return;
    }

    if (defined('USE_REDIS') && USE_REDIS === 'True') {
      $this->backend = 'redis';
      $this->ragCache = new RagCache(true);

      if ($this->debug) {
        error_log("QueryCache: Using Redis backend");
      }
      return;
    }

    // Vérifier si la table existe pour le backend database
    try {
      // Utiliser le backend database par défaut
      $this->backend = 'database';
      $this->dbStorage = new CacheStorage($this->debug);

      if ($this->debug) {
        error_log("QueryCache: Using Database backend");
      }
      return;
    } catch (\Exception $e) {
      if ($this->debug) {
        error_log("QueryCache: Database backend not available: " . $e->getMessage());
      }
    }

    // Fallback sur fichier
    $this->backend = 'file';
    $this->fileStorage = new CacheFileStorage($this->debug);

    if ($this->debug) {
      error_log("QueryCache: Using File backend (fallback)");
    }
  }



  /**
   * Récupère une requête SQL depuis le cache
   * 
   * @param string $userQuery Question de l'utilisateur
   * @param array $context Contexte additionnel
   * @return array|null ['sql' => string, 'results' => array, 'timestamp' => int] ou null
   */
  public function get(string $userQuery, array $context = []): ?array
  {
    if (!$this->enabled) {
      return null;
    }

    $cacheKey = CacheKeyGenerator::generate($userQuery, $context);

    try {
      switch ($this->backend) {
        case 'memcached':
        case 'redis':
          return $this->getFromMemoryBackend($cacheKey);

        case 'database':
          $result = $this->dbStorage->get($cacheKey);
          if ($result !== null) {
            $this->dbStorage->incrementHitCount($cacheKey);
          }
          return $result;

        case 'file':
          return $this->fileStorage->get($cacheKey);

        default:
          return null;
      }
    } catch (\Exception $e) {
      error_log("QueryCache: Error getting cache: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Récupère depuis Memcached/Redis via RagCache
   */
  private function getFromMemoryBackend(string $cacheKey): ?array
  {
    // TODO: Implement Redis/Memcached backend
    // For now, fallback to database
    if ($this->debug) {
      error_log("QueryCache: Memory backend not yet implemented, using database");
    }

    return $this->dbStorage->get($cacheKey);

    /* OLD CODE - TO BE IMPLEMENTED
    if ($this->ragCache === null) {
      return null;
    }

    $cached = $this->ragCache->getCachedResponse($cacheKey);

    if ($cached !== null && is_array($cached)) {
      if ($this->debug) {
        error_log("QueryCache: HIT from {$this->backend} - {$cacheKey}");
      }

      return [
        'sql' => $cached['sql'] ?? '',
        'results' => $cached['results'] ?? [],
        'timestamp' => $cached['timestamp'] ?? time(),
        'from_cache' => true,
        'backend' => $this->backend
      ];
    }

    return null;
    */
  }

  /**
   * Stocke une requête SQL, ses résultats et son interprétation dans le cache
   * 
   * @param string $userQuery Question de l'utilisateur
   * @param string $sqlQuery Requête SQL générée
   * @param array $results Résultats de la requête
   * @param array $context Contexte additionnel (interpretation, entity_id, entity_type)
   * @param int|null $ttl Durée de vie en secondes
   * @return bool Succès
   */
  public function set(string $userQuery, string $sqlQuery, array $results, array $context = [], ?int $ttl = null): bool
  {
    if (!$this->enabled) {
      return false;
    }

    if ($this->db === null) {
      error_log("QueryCache: ERROR - Cannot set() because \$db is null");
      return false;
    }

    try {
      $cacheKey = CacheKeyGenerator::generate($userQuery, $context);
      $ttl = $ttl ?? $this->defaultTTL;

      // Extraire les données du contexte
      $interpretation = $context['interpretation'] ?? null;
      $entityId = $context['entity_id'] ?? null;
      $entityType = $context['entity_type'] ?? null;

      // Vérifier la taille du cache
      $this->cleanupIfNeeded();

      // Insérer ou mettre à jour
      $query = $this->db->prepare("
        INSERT INTO :table_rag_query_cache
        (cache_key, user_query, sql_query, query_results, interpretation, entity_id, entity_type, created_at, expires_at, hit_count)
        VALUES
        (:cache_key, :user_query, :sql_query, :query_results, :interpretation, :entity_id, :entity_type, NOW(), DATE_ADD(NOW(), INTERVAL :ttl SECOND), 0)
        ON DUPLICATE KEY UPDATE
        sql_query = VALUES(sql_query),
        query_results = VALUES(query_results),
        interpretation = VALUES(interpretation),
        entity_id = VALUES(entity_id),
        entity_type = VALUES(entity_type),
        created_at = NOW(),
        expires_at = DATE_ADD(NOW(), INTERVAL :ttl SECOND),
        hit_count = 0
      ");

      $query->bindValue(':cache_key', $cacheKey);
      $query->bindValue(':user_query', substr($userQuery, 0, 500));
      $query->bindValue(':sql_query', $sqlQuery);
      $query->bindValue(':query_results', json_encode($results, JSON_UNESCAPED_UNICODE));
      $query->bindValue(':interpretation', $interpretation);
      $query->bindValue(':entity_id', $entityId);
      $query->bindValue(':entity_type', $entityType);
      $query->bindInt(':ttl', $ttl);

      $query->execute();

      if ($this->debug) {
        error_log("QueryCache: SET for key {$cacheKey} (TTL: {$ttl}s, has_interpretation: " . ($interpretation ? 'yes' : 'no') . ")");
      }

      return true;

    } catch (\Exception $e) {
      error_log("QueryCache: Error setting cache: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Invalide une entrée de cache
   * 
   * @param string $userQuery Question de l'utilisateur
   * @param array $context Contexte additionnel
   * @return bool Succès
   */
  public function invalidate(string $userQuery, array $context = []): bool
  {
    try {
      $cacheKey = CacheKeyGenerator::generate($userQuery, $context);

      $query = $this->db->prepare("
        DELETE FROM :table_rag_query_cache
        WHERE cache_key = :cache_key
      ");

      $query->bindValue(':cache_key', $cacheKey);
      $query->execute();

      if ($this->debug) {
        error_log("QueryCache: INVALIDATE for key {$cacheKey}");
      }

      return true;

    } catch (\Exception $e) {
      error_log("QueryCache: Error invalidating cache: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Vide tout le cache
   * 
   * @return bool Succès
   */
  public function flush(): bool
  {
    try {
      $this->db->query("TRUNCATE TABLE :table_rag_query_cache");

      if ($this->debug) {
        error_log("QueryCache: FLUSH - All cache cleared");
      }

      return true;
    } catch (\Exception $e) {
      error_log("QueryCache: Error flushing cache: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Nettoie les entrées expirées et limite la taille
   * 🔧 FIX: Only clean expired entries, don't delete active cache entries
   */
  private function cleanupIfNeeded(): void
  {
    if ($this->db === null) {
      error_log("QueryCache: ERROR - \$db is null in cleanupIfNeeded()");
      return;
    }

    try {
      // Only delete expired entries (not active ones)
      $deleteExpiredQuery = $this->db->query("
        DELETE FROM :table_rag_query_cache
        WHERE expires_at < NOW()
      ");
      $deleteExpiredQuery->execute();

      if ($this->debug) {
        error_log("QueryCache: CLEANUP - Removed expired entries");
      }

      // Vérifier la taille (only count active entries)
      $countQuery = $this->db->query("
        SELECT COUNT(*) as total FROM :table_rag_query_cache
        WHERE expires_at > NOW()
      ");
      $countQuery->fetch();
      $total = $countQuery->valueInt('total');

      // Si trop d'entrées ACTIVES, supprimer les moins utilisées (mais seulement si vraiment nécessaire)
      if ($total > $this->maxCacheSize) {
        $toDelete = $total - $this->maxCacheSize;
        // Delete least used entries, but keep recently created ones
        // Use subquery to avoid "You can't specify target table for update in FROM clause" error
        $deleteQuery = $this->db->query("
          DELETE FROM :table_rag_query_cache
          WHERE expires_at > NOW()
          AND hit_count IN (
            SELECT hit_count FROM (
              SELECT hit_count FROM :table_rag_query_cache
              WHERE expires_at > NOW()
              ORDER BY hit_count ASC, created_at ASC
              LIMIT {$toDelete}
            ) AS temp
          )
        ");
        $deleteQuery->execute();

        if ($this->debug) {
          error_log("QueryCache: CLEANUP - Removed {$toDelete} least used entries (max size exceeded)");
        }
      }

    } catch (\Exception $e) {
      error_log("QueryCache: Error cleaning up: " . $e->getMessage());
    }
  }

  /**
   * Récupère les statistiques du cache
   * 
   * @return array Statistiques complètes
   */
  public function getStats(): array
  {
    try {
      $statsQuery = $this->db->query("
        SELECT 
          COUNT(*) as total_entries,
          SUM(hit_count) as total_hits,
          AVG(hit_count) as avg_hits,
          COUNT(CASE WHEN expires_at > NOW() THEN 1 END) as active_entries,
          COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired_entries
        FROM :table_rag_query_cache
      ");

      $statsQuery->fetch();

      return [
        'total_entries' => $statsQuery->valueInt('total_entries'),
        'active_entries' => $statsQuery->valueInt('active_entries'),
        'expired_entries' => $statsQuery->valueInt('expired_entries'),
        'total_hits' => $statsQuery->valueInt('total_hits'),
        'avg_hits' => round($statsQuery->valueDecimal('avg_hits'), 1),
        'enabled' => $this->enabled
      ];

    } catch (\Exception $e) {
      error_log("QueryCache: Error getting stats: " . $e->getMessage());
      return [
        'total_entries' => 0,
        'active_entries' => 0,
        'expired_entries' => 0,
        'total_hits' => 0,
        'avg_hits' => 0,
        'enabled' => $this->enabled
      ];
    }
  }

//****************************
// Not used
//****************************


  /**
   * Incrémente le compteur de hits
   *
   * @param string $cacheKey Clé de cache
   */
  private function incrementHitCount(string $cacheKey): void
  {
    try {
      $this->db->query("
        UPDATE :table_rag_query_cache
        SET hit_count = hit_count + 1
        WHERE cache_key = '{$cacheKey}'
      ");
    } catch (\Exception $e) {
      error_log("QueryCache: Error incrementing hit count: " . $e->getMessage());
    }
  }

}
