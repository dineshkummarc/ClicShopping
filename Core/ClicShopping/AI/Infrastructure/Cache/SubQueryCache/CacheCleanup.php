<?php
/**
 * Cache Cleanup Manager
 * Gère le nettoyage et la maintenance du cache
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\AI\Infrastructure\Cache\SubQueryCache;

use ClicShopping\OM\Registry;

/**
 * Nettoie les entrées expirées et gère la taille du cache
 */
class CacheCleanup
{
  private mixed $db;
  private int $maxCacheSize;
  private bool $debug;
  
  public function __construct(int $maxCacheSize = 1000, bool $debug = false)
  {
    $this->db = Registry::get('Db');
    $this->maxCacheSize = $maxCacheSize;
    $this->debug = $debug;
  }
  
  /**
   * Nettoie les entrées expirées
   * 
   * @return int Nombre d'entrées supprimées
   */
  public function cleanExpired(): int
  {
    try {
      $query = $this->db->query("
        DELETE FROM :table_rag_query_cache
        WHERE expires_at < NOW()
      ");
      
      $deleted = $query->rowCount();
      
      if ($this->debug && $deleted > 0) {
        error_log("CacheCleanup: Removed {$deleted} expired entries");
      }
      
      return $deleted;
      
    } catch (\Exception $e) {
      error_log("CacheCleanup: Error cleaning expired: " . $e->getMessage());
      return 0;
    }
  }
  
  /**
   * Limite la taille du cache en supprimant les entrées les moins utilisées
   * 
   * @return int Nombre d'entrées supprimées
   */
  public function limitSize(): int
  {
    try {
      // Compter les entrées
      $countQuery = $this->db->query("
        SELECT COUNT(*) as total 
        FROM :table_rag_query_cache
      ");
      $countQuery->fetch();
      $total = $countQuery->valueInt('total');
      
      // Si trop d'entrées, supprimer les moins utilisées
      if ($total > $this->maxCacheSize) {
        $toDelete = $total - $this->maxCacheSize;
        
        $this->db->query("
          DELETE FROM :table_rag_query_cache
          ORDER BY hit_count ASC, created_at ASC
          LIMIT {$toDelete}
        ");
        
        if ($this->debug) {
          error_log("CacheCleanup: Removed {$toDelete} old entries (limit: {$this->maxCacheSize})");
        }
        
        return $toDelete;
      }
      
      return 0;
      
    } catch (\Exception $e) {
      error_log("CacheCleanup: Error limiting size: " . $e->getMessage());
      return 0;
    }
  }


  //*****************************
  // not used
  //*****************************

  /**
   * Nettoie tout (expiré + limite de taille)
   * 
   * @return array ['expired' => int, 'limited' => int]
   */
  public function cleanAll(): array
  {
    $expired = $this->cleanExpired();
    $limited = $this->limitSize();
    
    return [
      'expired' => $expired,
      'limited' => $limited,
      'total' => $expired + $limited
    ];
  }
}
