<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Infrastructure\Schema;

use ClicShopping\OM\Registry;
use ClicShopping\OM\Cache as OMCache;

/**
 * SchemaRetriever
 * 
 * Retrieves relevant database schema portions based on user query
 * Uses embedding-based similarity search with fallback mechanisms
 * 
 * Responsibilities:
 * - Find relevant tables based on query similarity
 * - Build minimal schema string with only relevant tables
 * - Handle fallbacks (keyword matching, common tables)
 * - Respect model-specific context limits
 * - Cache query→tables mappings
 * 
 * @package ClicShopping\AI\Infrastructure\Schema
 */
class SchemaRetriever
{
  private mixed $db;
  private mixed $embeddingService;
  private SchemaEmbedder $schemaEmbedder;
  private bool $debug;
  private string $useCache;
  
  /**
   * Constructor
   * 
   * @param bool $debug Debug mode flag for logging
   */
  public function __construct(bool $debug = false)
  {
    $this->db = Registry::get('Db');
    $this->embeddingService = Registry::get('EmbeddingService');
    $this->schemaEmbedder = new SchemaEmbedder($debug);
    $this->debug = $debug;
    $this->useCache = defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True' ? 'True' : 'False';
  }
  
  /**
   * Get relevant schema for a query
   * 
   * Main entry point - returns minimal schema based on query and model
   * Respects model-specific context limits
   * 
   * @param string $query User query
   * @param string $modelName Model name (e.g., "qwen/qwen3-4b")
   * @param int $maxTables Maximum number of tables to include
   * @return string Minimal schema text with only relevant tables
   */
  public function getRelevantSchema(string $query, string $modelName, int $maxTables = 5): string
  {
    $startTime = microtime(true);
    
    if ($this->debug) {
      error_log("[SchemaRetriever] Getting relevant schema for query: {$query}");
      error_log("[SchemaRetriever] Model: {$modelName}, Max tables: {$maxTables}");
    }
    
    // Get context limit for this model
    $contextLimit = $this->getContextLimit($modelName);
    
    // Get relevant tables
    $tables = $this->getRelevantTables($query, $maxTables);
    
    if (empty($tables)) {
      if ($this->debug) {
        error_log("[SchemaRetriever] No tables found, using common tables");
      }
      $tables = $this->getCommonTables();
    }
    
    // Build schema from tables
    $schema = $this->buildSchemaFromTables($tables);
    
    // If schema exceeds limit, reduce table count
    $tokenCount = $this->estimateTokenCount($schema);
    while ($tokenCount > $contextLimit && count($tables) > 1) {
      array_pop($tables);
      $schema = $this->buildSchemaFromTables($tables);
      $tokenCount = $this->estimateTokenCount($schema);
      
      if ($this->debug) {
        error_log("[SchemaRetriever] Schema too large ({$tokenCount} tokens), reduced to " . count($tables) . " tables");
      }
    }
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    if ($this->debug) {
      error_log("[SchemaRetriever] Schema built with " . count($tables) . " tables");
      error_log("[SchemaRetriever] Estimated tokens: {$tokenCount} / {$contextLimit}");
      error_log("[SchemaRetriever] Duration: {$duration}ms");
      error_log("[SchemaRetriever] Tables: " . implode(', ', $tables));
    }
    
    return $schema;
  }
  
  /**
   * Get relevant tables based on query similarity
   * 
   * Uses embedding-based similarity search with fallback mechanisms
   * 
   * @param string $query User query
   * @param int $maxTables Maximum number of tables to return
   * @return array Array of table names
   */
  public function getRelevantTables(string $query, int $maxTables = 5): array
  {
    // Check cache first
    if ($this->useCache === 'True') {
      $cacheKey = 'schema_rag_query_' . md5($query . '_' . $maxTables);
      $cache = new OMCache($cacheKey);
      $cached = $cache->get();
      
      if (!empty($cached)) {
        if ($this->debug) {
          error_log("[SchemaRetriever] Using cached table list");
        }
        return $cached;
      }
    }
    
    try {
      // Try embedding-based retrieval (primary method)
      $tables = $this->getTablesBySimilarity($query, $maxTables);
      
      if (!empty($tables)) {
        // Cache the result
        if ($this->useCache === 'True') {
          $cache->save($tables, 3600); // 1 hour TTL
        }
        
        return $tables;
      }
    } catch (\Exception $e) {
      if ($this->debug) {
        error_log("[SchemaRetriever] Embedding-based retrieval failed: " . $e->getMessage());
      }
    }
    
    // Fallback to keyword matching
    if ($this->debug) {
      error_log("[SchemaRetriever] Falling back to keyword matching");
    }
    
    $tables = $this->fallbackKeywordMatching($query);
    
    if (!empty($tables)) {
      return array_slice($tables, 0, $maxTables);
    }
    
    // Final fallback to common tables
    if ($this->debug) {
      error_log("[SchemaRetriever] Falling back to common tables");
    }
    
    return $this->getCommonTables();
  }
  
  /**
   * Get tables by embedding similarity
   * 
   * @param string $query User query
   * @param int $maxTables Maximum number of tables
   * @return array Array of table names
   */
  private function getTablesBySimilarity(string $query, int $maxTables): array
  {
    // Create query embedding
    $queryEmbedding = $this->embeddingService->createEmbedding($query);
    
    if (empty($queryEmbedding)) {
      throw new \Exception("Failed to create query embedding");
    }
    
    // Load all table embeddings
    $tableEmbeddings = $this->schemaEmbedder->getAllTableEmbeddings();
    
    if (empty($tableEmbeddings)) {
      throw new \Exception("No table embeddings found in database");
    }
    
    // Calculate similarity scores
    $scores = [];
    foreach ($tableEmbeddings as $tableName => $embedding) {
      $scores[$tableName] = $this->cosineSimilarity($queryEmbedding, $embedding);
    }
    
    // Sort by similarity (descending)
    arsort($scores);
    
    if ($this->debug) {
      error_log("[SchemaRetriever] Top 5 similarity scores:");
      $count = 0;
      foreach ($scores as $table => $score) {
        if ($count++ >= 5) break;
        error_log("  - {$table}: " . round($score, 4));
      }
    }
    
    // Return top N tables
    return array_slice(array_keys($scores), 0, $maxTables);
  }
  
  /**
   * Calculate cosine similarity between two vectors
   * 
   * @param array $vec1 First vector
   * @param array $vec2 Second vector
   * @return float Similarity score (0-1)
   */
  private function cosineSimilarity(array $vec1, array $vec2): float
  {
    if (count($vec1) !== count($vec2)) {
      return 0.0;
    }
    
    $dotProduct = 0.0;
    $magnitude1 = 0.0;
    $magnitude2 = 0.0;
    
    for ($i = 0; $i < count($vec1); $i++) {
      $dotProduct += $vec1[$i] * $vec2[$i];
      $magnitude1 += $vec1[$i] * $vec1[$i];
      $magnitude2 += $vec2[$i] * $vec2[$i];
    }
    
    $magnitude1 = sqrt($magnitude1);
    $magnitude2 = sqrt($magnitude2);
    
    if ($magnitude1 == 0 || $magnitude2 == 0) {
      return 0.0;
    }
    
    return $dotProduct / ($magnitude1 * $magnitude2);
  }
  
  /**
   * Fallback keyword matching
   * 
   * Uses regex patterns to match query keywords to relevant tables
   * 
   * @param string $query User query
   * @return array Array of table names
   */
  private function fallbackKeywordMatching(string $query): array
  {
    $keywords = [
      'stock|inventory|quantity|quantité' => ['clic_products', 'clic_products_description'],
      'order|commande|sale|vente' => ['clic_orders', 'clic_orders_products', 'clic_orders_total', 'clic_customers'],
      'price|prix|cost|coût' => ['clic_products', 'clic_products_specials', 'clic_products_description'],
      'customer|client|buyer|acheteur' => ['clic_customers', 'clic_orders'],
      'category|categorie|catégorie' => ['clic_categories', 'clic_categories_description', 'clic_products_to_categories'],
      'brand|manufacturer|marque|fabricant' => ['clic_manufacturers', 'clic_manufacturers_info', 'clic_products'],
      'review|avis|rating|note' => ['clic_reviews', 'clic_reviews_description', 'clic_products'],
      'supplier|fournisseur' => ['clic_suppliers', 'clic_suppliers_info', 'clic_manufacturers'],
      'return|retour' => ['clic_return_orders', 'clic_orders', 'clic_products'],
      'sentiment|opinion' => ['clic_reviews_sentiment', 'clic_reviews_sentiment_description', 'clic_reviews'],
    ];
    
    $matchedTables = [];
    $queryLower = strtolower($query);
    
    foreach ($keywords as $pattern => $tables) {
      if (preg_match("/$pattern/i", $queryLower)) {
        $matchedTables = array_merge($matchedTables, $tables);
        
        if ($this->debug) {
          error_log("[SchemaRetriever] Keyword match: '{$pattern}' → " . implode(', ', $tables));
        }
      }
    }
    
    return array_unique($matchedTables);
  }
  
  /**
   * Get common tables (final fallback)
   * 
   * Returns the most frequently used tables
   * 
   * @return array Array of table names
   */
  private function getCommonTables(): array
  {
    return [
      'clic_products',
      'clic_products_description',
      'clic_orders',
      'clic_customers',
      'clic_categories'
    ];
  }
  
  /**
   * Build schema text from table names
   * 
   * @param array $tableNames Array of table names
   * @return string Schema text
   */
  private function buildSchemaFromTables(array $tableNames): string
  {
    $schemaParts = [];
    $schemaParts[] = "IMPORTANT: Regarding the structure of the e-commerce tables, please note the following details:\n";
    
    $sectionNumber = 1;
    
    foreach ($tableNames as $tableName) {
      // Get schema text from database
      $Qschema = $this->db->prepare('
        SELECT schema_text
        FROM :table_schema_embeddings
        WHERE table_name = :table_name
      ');
      
      $Qschema->bindValue(':table_name', $tableName);
      $Qschema->execute();
      
      if ($Qschema->fetch()) {
        $schemaText = $Qschema->value('schema_text');
        
        // Remove "Table: tablename" prefix if present
        $schemaText = preg_replace('/^Table:\s+' . preg_quote($tableName, '/') . '\s*\n/', '', $schemaText);
        
        // Add numbered section
        $schemaParts[] = "{$sectionNumber}. Table {$tableName}:\n{$schemaText}";
        $sectionNumber++;
      }
    }
    
    $schemaParts[] = "\nImportant: When generating SQL queries, strictly follow these table structures and do not invent columns.\n";
    
    return implode("\n", $schemaParts);
  }
  
  /**
   * Get context limit for a model
   * 
   * @param string $modelName Model name
   * @return int Context limit in tokens
   */
  private function getContextLimit(string $modelName): int
  {
    // Model-specific limits (conservative, leaving room for response)
    $limits = [
      'qwen/qwen3-4b' => 3500,      // 4K context - 500 for response
      'qwen3-4b' => 3500,
      'microsoft/phi-4' => 15000,    // 16K context - 1K for response
      'phi-4' => 15000,
      'gpt-4o' => 120000,            // 128K context - 8K for response
      'gpt-4o-mini' => 120000,       // 128K context - 8K for response
    ];
    
    // Check for exact match
    if (isset($limits[$modelName])) {
      return $limits[$modelName];
    }
    
    // Check for partial match (e.g., "qwen/qwen3-4b-instruct" matches "qwen3-4b")
    foreach ($limits as $pattern => $limit) {
      if (stripos($modelName, $pattern) !== false) {
        return $limit;
      }
    }
    
    // Default to conservative limit for unknown models
    if ($this->debug) {
      error_log("[SchemaRetriever] Unknown model '{$modelName}', using default limit 3500");
    }
    
    return 3500;
  }
  
  /**
   * Estimate token count for text
   * 
   * Rough estimate: 4 characters per token
   * 
   * @param string $text Text to estimate
   * @return int Estimated token count
   */
  private function estimateTokenCount(string $text): int
  {
    return (int)ceil(strlen($text) / 4);
  }
}
