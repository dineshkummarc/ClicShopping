<?php
/**
 * File-based Cache Storage
 * Gère le cache sur disque dans Work/Cache/Rag
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\AI\Infrastructure\Cache\SubQueryCache;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\FileSystem;

/**
 * Stockage de cache basé sur fichiers dans Work/Cache/Rag
 * Utilisé comme fallback ou complément au cache base de données
 */
class CacheFileStorage
{
  private string $cachePath;
  private bool $debug;
  private bool $enabled;
  
  public function __construct(bool $debug = false)
  {
    // Check if cache is globally enabled
    $this->enabled = defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER')   && CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True';
    $this->cachePath = CLICSHOPPING::getConfig('dir_root', 'Shop') . 'Work/Cache/Rag/';
    $this->debug = $debug;
    
    // Only create cache directory if cache is enabled
    if ($this->enabled) {
      $this->ensureCacheDirectory();
    }
    
    if ($this->debug && !$this->enabled) {
      error_log("CacheFileStorage: Cache disabled (CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER = False)");
    }
  }
  
  /**
   * S'assure que le répertoire de cache existe
   */
  private function ensureCacheDirectory(): void
  {
    if (!is_dir($this->cachePath)) {
      try {
        mkdir($this->cachePath, 0755, true);
        
        if ($this->debug) {
          error_log("CacheFileStorage: Created cache directory: {$this->cachePath}");
        }
      } catch (\Exception $e) {
        error_log("CacheFileStorage: Error creating cache directory: " . $e->getMessage());
      }
    }
  }
  
  /**
   * Génère le chemin du fichier de cache
   * 
   * @param string $cacheKey Clé de cache
   * @return string Chemin complet du fichier
   */
  private function getFilePath(string $cacheKey): string
  {
    // Utiliser les 2 premiers caractères pour créer des sous-répertoires
    $subDir = substr($cacheKey, 0, 2);
    $dirPath = $this->cachePath . $subDir . '/';
    
    if (!is_dir($dirPath)) {
      @mkdir($dirPath, 0755, true);
    }
    
    return $dirPath . $cacheKey . '.cache';
  }
  
  /**
   * Récupère une entrée de cache depuis le fichier
   * 
   * @param string $cacheKey Clé de cache
   * @return array|null Données ou null si non trouvé/expiré
   */
  public function get(string $cacheKey): ?array
  {
    // Early return if cache is disabled
    if (!$this->enabled) {
      if ($this->debug) {
        error_log("CacheFileStorage: Cache disabled, returning null");
      }
      return null;
    }
    
    $filePath = $this->getFilePath($cacheKey);
    
    if (!file_exists($filePath)) {
      if ($this->debug) {
        error_log("CacheFileStorage: MISS - File not found: {$cacheKey}");
      }
      return null;
    }
    
    try {
      $content = file_get_contents($filePath);
      if ($content === false) {
        return null;
      }
      
      $data = json_decode($content, true);
      
      // Vérifier l'expiration
      if (isset($data['expires_at']) && time() > $data['expires_at']) {
        // Supprimer le fichier expiré
        @unlink($filePath);
        
        if ($this->debug) {
          error_log("CacheFileStorage: EXPIRED - Removed: {$cacheKey}");
        }
        
        return null;
      }
      
      // Incrémenter le compteur de hits
      if (isset($data['hit_count'])) {
        $data['hit_count']++;
        file_put_contents($filePath, json_encode($data, JSON_UNESCAPED_UNICODE));
      }
      
      if ($this->debug) {
        error_log("CacheFileStorage: HIT - {$cacheKey}");
      }
      
      return [
        'sql' => $data['sql_query'] ?? '',
        'results' => $data['query_results'] ?? [],
        'timestamp' => $data['created_at'] ?? time(),
        'hit_count' => $data['hit_count'] ?? 0,
        'from_file' => true
      ];
      
    } catch (\Exception $e) {
      error_log("CacheFileStorage: Error reading cache: " . $e->getMessage());
      return null;
    }
  }
  
  /**
   * Stocke une entrée de cache dans un fichier
   * 
   * @param string $cacheKey Clé de cache
   * @param string $userQuery Question utilisateur
   * @param string $sqlQuery Requête SQL
   * @param array $results Résultats
   * @param int $ttl Durée de vie en secondes
   * @return bool Succès
   */
  public function set(string $cacheKey, string $userQuery, string $sqlQuery, array $results, int $ttl): bool
  {
    // Early return if cache is disabled - DO NOT create JSON files
    if (!$this->enabled) {
      if ($this->debug) {
        error_log("CacheFileStorage: Cache disabled, skipping file creation for key {$cacheKey}");
      }
      return false;
    }
    
    $filePath = $this->getFilePath($cacheKey);
    
    try {
      $data = [
        'cache_key' => $cacheKey,
        'user_query' => substr($userQuery, 0, 500),
        'sql_query' => $sqlQuery,
        'query_results' => $results,
        'created_at' => time(),
        'expires_at' => time() + $ttl,
        'hit_count' => 0
      ];
      
      $result = file_put_contents(
        $filePath,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
      );
      
      if ($result !== false) {
        if ($this->debug) {
          error_log("CacheFileStorage: SET - {$cacheKey} (TTL: {$ttl}s)");
        }
        return true;
      }
      
      return false;
      
    } catch (\Exception $e) {
      error_log("CacheFileStorage: Error writing cache: " . $e->getMessage());
      return false;
    }
  }
  
  /**
   * Supprime une entrée de cache
   * 
   * @param string $cacheKey Clé de cache
   * @return bool Succès
   */
  public function delete(string $cacheKey): bool
  {
    // If cache is disabled, nothing to delete
    if (!$this->enabled) {
      return true;
    }
    
    $filePath = $this->getFilePath($cacheKey);
    
    if (file_exists($filePath)) {
      $result = @unlink($filePath);
      
      if ($this->debug && $result) {
        error_log("CacheFileStorage: DELETE - {$cacheKey}");
      }
      
      return $result;
    }
    
    return true;
  }
  
  /**
   * Vide tout le cache fichier
   * 
   * @return bool Succès
   */
  public function flush(): bool
  {
    // If cache is disabled, nothing to flush
    if (!$this->enabled) {
      if ($this->debug) {
        error_log("CacheFileStorage: Cache disabled, nothing to flush");
      }
      return true;
    }
    
    try {
      $deleted = 0;
      
      // Parcourir tous les sous-répertoires
      $dirs = glob($this->cachePath . '*', GLOB_ONLYDIR);
      
      foreach ($dirs as $dir) {
        $files = glob($dir . '/*.cache');
        foreach ($files as $file) {
          if (@unlink($file)) {
            $deleted++;
          }
        }
        // Supprimer le répertoire vide
        @rmdir($dir);
      }
      
      if ($this->debug) {
        error_log("CacheFileStorage: FLUSH - Removed {$deleted} files");
      }
      
      return true;
      
    } catch (\Exception $e) {
      error_log("CacheFileStorage: Error flushing cache: " . $e->getMessage());
      return false;
    }
  }
  
  /**
   * Nettoie les fichiers expirés
   * 
   * @return int Nombre de fichiers supprimés
   */
  public function cleanExpired(): int
  {
    // If cache is disabled, nothing to clean
    if (!$this->enabled) {
      return 0;
    }
    
    $deleted = 0;
    
    try {
      $dirs = glob($this->cachePath . '*', GLOB_ONLYDIR);
      
      foreach ($dirs as $dir) {
        $files = glob($dir . '/*.cache');
        
        foreach ($files as $file) {
          $content = @file_get_contents($file);
          if ($content === false) {
            continue;
          }
          
          $data = json_decode($content, true);
          
          if (isset($data['expires_at']) && time() > $data['expires_at']) {
            if (@unlink($file)) {
              $deleted++;
            }
          }
        }
      }
      
      if ($this->debug && $deleted > 0) {
        error_log("CacheFileStorage: Cleaned {$deleted} expired files");
      }
      
    } catch (\Exception $e) {
      error_log("CacheFileStorage: Error cleaning expired: " . $e->getMessage());
    }
    
    return $deleted;
  }
  
  /**
   * Récupère les statistiques du cache fichier
   * 
   * @return array Statistiques
   */
  public function getStats(): array
  {
    // If cache is disabled, return empty stats
    if (!$this->enabled) {
      return [
        'enabled' => false,
        'total_files' => 0,
        'active_files' => 0,
        'expired_files' => 0,
        'total_size_bytes' => 0,
        'total_size_mb' => 0,
        'total_hits' => 0,
        'avg_hits' => 0
      ];
    }
    
    $totalFiles = 0;
    $totalSize = 0;
    $expiredFiles = 0;
    $totalHits = 0;
    
    try {
      $dirs = glob($this->cachePath . '*', GLOB_ONLYDIR);
      
      foreach ($dirs as $dir) {
        $files = glob($dir . '/*.cache');
        
        foreach ($files as $file) {
          $totalFiles++;
          $totalSize += filesize($file);
          
          $content = @file_get_contents($file);
          if ($content !== false) {
            $data = json_decode($content, true);
            
            if (isset($data['expires_at']) && time() > $data['expires_at']) {
              $expiredFiles++;
            }
            
            if (isset($data['hit_count'])) {
              $totalHits += $data['hit_count'];
            }
          }
        }
      }
      
    } catch (\Exception $e) {
      error_log("CacheFileStorage: Error getting stats: " . $e->getMessage());
    }
    
    return [
      'enabled' => true,
      'total_files' => $totalFiles,
      'active_files' => $totalFiles - $expiredFiles,
      'expired_files' => $expiredFiles,
      'total_size_bytes' => $totalSize,
      'total_size_mb' => round($totalSize / 1024 / 1024, 2),
      'total_hits' => $totalHits,
      'avg_hits' => $totalFiles > 0 ? round($totalHits / $totalFiles, 1) : 0
    ];
  }
}
