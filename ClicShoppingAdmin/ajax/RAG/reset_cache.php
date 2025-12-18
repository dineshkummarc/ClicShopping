<?php
/**
 * AJAX Endpoint: Reset Cache
 * Handles cache reset requests from the Dashboard
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @date 2025-11-17
 */

use ClicShopping\AI\Insfrastructure\Cache\Cache;
use ClicShopping\AI\Insfrastructure\Cache\QueryCache;
use ClicShopping\AI\Insfrastructure\Cache\SubQueryCache\CacheFileStorage;
use ClicShopping\AI\Insfrastructure\Cache\TranslationCache;
use ClicShopping\OM\CLICSHOPPING;

// Bootstrap
define('PAGE_PARSE_START_TIME', microtime());
define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../../Core/ClicShopping/') . '/');

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');

spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');
AdministratorAdmin::hasUserAccess();
// Set JSON header
header('Content-Type: application/json');

// Security check - ensure user is authenticated
// TODO: Add proper authentication check
// if (!isset($_SESSION['admin_id'])) {
//   echo json_encode(['success' => false, 'message' => 'Unauthorized']);
//   exit;
// }

try {
  // Get request data
  $input = file_get_contents('php://input');
  $data = json_decode($input, true);
  
  if (!$data || !isset($data['cache_types']) || !is_array($data['cache_types'])) {
    echo json_encode([
      'success' => false,
      'message' => 'Invalid request data'
    ]);
    exit;
  }
  
  $cacheTypes = $data['cache_types'];
  $results = [];
  $errors = [];
  
  // ============================================================================
  // 1. Reset File Cache (RAG)
  // ============================================================================
  if (in_array('files', $cacheTypes)) {
    try {
      $fileStorage = new CacheFileStorage(true);
      
      // Get stats before flush
      $statsBefore = $fileStorage->getStats();
      $filesBefore = $statsBefore['total_files'] ?? 0;
      
      // Flush file cache
      $fileStorage->flush();
      
      // Get stats after flush
      $statsAfter = $fileStorage->getStats();
      $filesAfter = $statsAfter['total_files'] ?? 0;
      
      $results['files'] = $filesBefore - $filesAfter;
      
      error_log("Cache Reset: File cache flushed - {$results['files']} files deleted");
      
    } catch (Exception $e) {
      $errors[] = "File cache: " . $e->getMessage();
      error_log("Cache Reset Error (files): " . $e->getMessage());
    }
  }
  
  // ============================================================================
  // 2. Reset Translation Cache
  // ============================================================================
  if (in_array('translations', $cacheTypes)) {
    try {
      $translationCache = new TranslationCache();
      
      // Count files before
      $cacheDir = CLICSHOPPING::getConfig('dir_root', 'Shop') . 'Work/Cache/Translations/';
      $filesBefore = 0;
      
      if (is_dir($cacheDir)) {
        $iterator = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
          if ($file->isFile()) {
            $filesBefore++;
          }
        }
      }
      
      // Flush translation cache
      $translationCache->clearCache();
      
      // Count files after
      $filesAfter = 0;
      if (is_dir($cacheDir)) {
        $iterator = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
          if ($file->isFile()) {
            $filesAfter++;
          }
        }
      }
      
      $results['translations'] = $filesBefore - $filesAfter;
      
      error_log("Cache Reset: Translation cache flushed - {$results['translations']} files deleted");
      
    } catch (Exception $e) {
      $errors[] = "Translation cache: " . $e->getMessage();
      error_log("Cache Reset Error (translations): " . $e->getMessage());
    }
  }
  
  // ============================================================================
  // 3. Reset Database Cache
  // ============================================================================
  if (in_array('database', $cacheTypes)) {
    try {
      $queryCache = new QueryCache();
      
      // Get stats before flush
      $statsBefore = $queryCache->getStats();
      $entriesBefore = $statsBefore['total_entries'] ?? 0;
      
      // Flush database cache
      $queryCache->flush();
      
      // Get stats after flush
      $statsAfter = $queryCache->getStats();
      $entriesAfter = $statsAfter['total_entries'] ?? 0;
      
      $results['database'] = $entriesBefore - $entriesAfter;
      
      error_log("Cache Reset: Database cache flushed - {$results['database']} entries deleted");
      
    } catch (Exception $e) {
      $errors[] = "Database cache: " . $e->getMessage();
      error_log("Cache Reset Error (database): " . $e->getMessage());
    }
  }
  
  // ============================================================================
  // 4. Reset Prompt Cache (includes semantic query cache - TASK 4.3.2)
  // ============================================================================
  if (in_array('prompts', $cacheTypes)) {
    try {
      $cache = new Cache(true);
      
      // Get stats before
      $statsBefore = $cache->getPromptCacheStats();
      $entriesBefore = $statsBefore['entries'] ?? 0;
      
      // Delete the cache file (contains both prompts and semantic queries)
      $cacheFile = CLICSHOPPING::getConfig('dir_root', 'Shop') . 'Work/Cache/Rag/rag_cache.cache';
      $deleted = 0;
      
      if (file_exists($cacheFile)) {
        if (unlink($cacheFile)) {
          $deleted = $entriesBefore;
        }
      }
      
      $results['prompts'] = $deleted;
      
      error_log("Cache Reset: Prompt cache flushed (includes semantic queries) - {$results['prompts']} entries deleted");
      
    } catch (Exception $e) {
      $errors[] = "Prompt cache: " . $e->getMessage();
      error_log("Cache Reset Error (prompts): " . $e->getMessage());
    }
  }
  
  // ============================================================================
  // 5. Reset Semantic Query Cache (TASK 4.3.2 - separate option)
  // ============================================================================
  if (in_array('semantic', $cacheTypes)) {
    try {
      $cache = new Cache(true);
      
      // Get stats before
      $statsBefore = $cache->getPromptCacheStats();
      $entriesBefore = $statsBefore['entries'] ?? 0;
      
      // Read cache file and filter out semantic queries
      $cacheFile = CLICSHOPPING::getConfig('dir_root', 'Shop') . 'Work/Cache/Rag/rag_cache.cache';
      $deleted = 0;
      
      if (file_exists($cacheFile)) {
        $cacheContent = file_get_contents($cacheFile);
        $cacheData = json_decode($cacheContent, true);
        
        if ($cacheData !== null && is_array($cacheData)) {
          $originalCount = count($cacheData);
          
          // Filter out semantic queries (those with JSON response containing "type":"semantic")
          $filteredCache = [];
          foreach ($cacheData as $key => $entry) {
            $response = $entry['response'] ?? '';
            // Check if response is a JSON string containing semantic query result
            $decoded = json_decode($response, true);
            if ($decoded === null || !isset($decoded['type']) || $decoded['type'] !== 'semantic') {
              // Keep non-semantic entries
              $filteredCache[$key] = $entry;
            }
          }
          
          $deleted = $originalCount - count($filteredCache);
          
          // Save filtered cache back to file
          file_put_contents($cacheFile, json_encode($filteredCache));
        }
      }
      
      $results['semantic'] = $deleted;
      
      error_log("Cache Reset: Semantic query cache flushed - {$results['semantic']} entries deleted");
      
    } catch (Exception $e) {
      $errors[] = "Semantic cache: " . $e->getMessage();
      error_log("Cache Reset Error (semantic): " . $e->getMessage());
    }
  }
  
  // ============================================================================
  // Return Response
  // ============================================================================
  if (empty($errors)) {
    echo json_encode([
      'success' => true,
      'message' => 'Cache reset successfully',
      'details' => $results
    ]);
  } else {
    echo json_encode([
      'success' => false,
      'message' => 'Some caches could not be reset',
      'details' => $results,
      'errors' => $errors
    ]);
  }
  
} catch (Exception $e) {
  error_log("Cache Reset Fatal Error: " . $e->getMessage());
  echo json_encode([
    'success' => false,
    'message' => 'Fatal error: ' . $e->getMessage()
  ]);
}
