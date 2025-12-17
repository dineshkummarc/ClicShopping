<?php
/**
 * StatisticsHelper
 * 
 * Helper class to save query statistics to rag_statistics table
 * with query_type and metadata including source information.
 * 
 * Related: .kiro/specs/old/semantic-search-critical-fixes/manual_test_guide.md Test 1.1
 */

namespace ClicShopping\AI\Helper;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

class StatisticsHelper
{
  private $db;
  private string $prefix;
  
  public function __construct()
  {
    $this->db = Registry::get('Db');
    $this->prefix = CLICSHOPPING::getConfig('db_table_prefix');
  }
  
  /**
   * Save query statistics to rag_statistics table
   * 
   * @param array $data Statistics data with keys:
   *   - query_type: string (semantic, analytics, hybrid, web_search)
   *   - success: bool (whether the query was successful)
   *   - response_time: int (response time in milliseconds)
   *   - metadata: array (additional metadata including source)
   *   - user_id: int (optional, user ID)
   *   - session_id: string (optional, session ID)
   *   - language_id: int (optional, language ID)
   * @return bool True if saved successfully, false otherwise
   */
  public function saveQueryStatistics(array $data): bool
  {
    try {
      // Extract required fields
      $queryType = $data['query_type'] ?? 'unknown';
      $success = $data['success'] ?? true;
      $responseTime = $data['response_time'] ?? 0;
      $metadata = $data['metadata'] ?? [];
      
      // Extract optional fields
      $userId = $data['user_id'] ?? 1;
      $sessionId = $data['session_id'] ?? session_id();
      $languageId = $data['language_id'] ?? Registry::get('Language')->getId();
      
      // Ensure metadata is JSON-encoded
      $metadataJson = is_string($metadata) ? $metadata : json_encode($metadata);
      
      // Insert into rag_statistics
      $sql = "INSERT INTO {$this->prefix}rag_statistics 
              (query_type, success, response_time, response_time_ms, metadata, 
               user_id, session_id, language_id, date_added, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
      
      $stmt = $this->db->prepare($sql);
      $stmt->execute([
        $queryType,
        $success ? 1 : 0,
        $responseTime,
        $responseTime,
        $metadataJson,
        $userId,
        $sessionId,
        $languageId
      ]);
      
      return true;
      
    } catch (\Exception $e) {
      error_log("StatisticsHelper: Error saving statistics: " . $e->getMessage());
      return false;
    }
  }
  
  /**
   * Extract source from result
   * 
   * @param array $result Query result
   * @return string Source (documents, embeddings, semantic, llm, web_search, etc.)
   */
  public static function extractSource(array $result): string
  {
    // Check direct source field
    if (isset($result['source'])) {
      return $result['source'];
    }
    
    // Check metadata
    if (isset($result['metadata']['source'])) {
      return $result['metadata']['source'];
    }
    
    // Check audit_metadata
    if (isset($result['audit_metadata']['source'])) {
      return $result['audit_metadata']['source'];
    }
    
    // Check type field
    if (isset($result['type'])) {
      return $result['type'];
    }
    
    // Default
    return 'unknown';
  }
  
  /**
   * Extract query type from result
   * 
   * @param array $result Query result
   * @return string Query type (semantic, analytics, hybrid, web_search)
   */
  public static function extractQueryType(array $result): string
  {
    // Check direct type field
    if (isset($result['type'])) {
      return $result['type'];
    }
    
    // Check metadata
    if (isset($result['metadata']['query_type'])) {
      return $result['metadata']['query_type'];
    }
    
    // Check audit_metadata
    if (isset($result['audit_metadata']['query_type'])) {
      return $result['audit_metadata']['query_type'];
    }
    
    // Infer from source
    $source = self::extractSource($result);
    if (in_array($source, ['documents', 'embeddings', 'llm', 'conversation_memory'])) {
      return 'semantic';
    } elseif ($source === 'web_search') {
      return 'web_search';
    } elseif ($source === 'analytics') {
      return 'analytics';
    } elseif ($source === 'hybrid') {
      return 'hybrid';
    }
    
    // Default
    return 'semantic';
  }
  
  /**
   * Build metadata array from result
   * 
   * @param array $result Query result
   * @return array Metadata array
   */
  public static function buildMetadata(array $result): array
  {
    $metadata = [];
    
    // Add source
    $metadata['source'] = self::extractSource($result);
    
    // 🆕 Add cache information (CRITICAL for Test 7.5)
    // Check multiple possible locations for cache status
    if (isset($result['cached']) && $result['cached'] === true) {
      $metadata['from_cache'] = true;
      $metadata['cache_hit'] = true;
      $metadata['cache_source'] = $result['cache_source'] ?? 'query_cache';
      $metadata['cache_age'] = $result['cache_age'] ?? null;
    } elseif (isset($result['metadata']['from_cache'])) {
      $metadata['from_cache'] = $result['metadata']['from_cache'];
      $metadata['cache_hit'] = $result['metadata']['from_cache'];
    } elseif (isset($result['metadata']['cache_hit'])) {
      $metadata['from_cache'] = $result['metadata']['cache_hit'];
      $metadata['cache_hit'] = $result['metadata']['cache_hit'];
    } else {
      // Explicitly mark as not from cache
      $metadata['from_cache'] = false;
      $metadata['cache_hit'] = false;
    }
    
    // Add cache status if available
    if (isset($result['metadata']['cache_status'])) {
      $metadata['cache_status'] = $result['metadata']['cache_status'];
    }
    
    // Add execution time if available
    if (isset($result['metadata']['execution_time'])) {
      $metadata['execution_time'] = $result['metadata']['execution_time'];
    }
    
    // Add fallback chain if available
    if (isset($result['metadata']['fallback_chain'])) {
      $metadata['fallback_chain'] = $result['metadata']['fallback_chain'];
    }
    
    // Add vector stores searched if available
    if (isset($result['metadata']['vector_stores_searched'])) {
      $metadata['vector_stores_searched'] = $result['metadata']['vector_stores_searched'];
    }
    
    // Add any audit metadata
    if (isset($result['audit_metadata'])) {
      $metadata['audit_metadata'] = $result['audit_metadata'];
    }
    
    return $metadata;
  }
}

