# AgentResponse Usage Examples

This document provides practical examples of using the `AgentResponse` class in different scenarios.

## Table of Contents

1. [Analytics Query Examples](#analytics-query-examples)
2. [Semantic Query Examples](#semantic-query-examples)
3. [Web Search Query Examples](#web-search-query-examples)
4. [Hybrid Query Examples](#hybrid-query-examples)
5. [Error Handling Examples](#error-handling-examples)
6. [Migration Examples](#migration-examples)

---

## Analytics Query Examples

### Example 1: Simple Stock Query

```php
use ClicShopping\AI\InterfacesAI\AgentResponse;

public function executeAnalyticsQuery(string $query, array $context = []): array
{
    $startTime = microtime(true);
    
    try {
        // Execute SQL query
        $sql = "SELECT products_name, products_quantity FROM clic_products WHERE products_id = 123";
        $result = $this->db->query($sql);
        $rows = $result->fetchAll();
        
        // Extract entity information
        $entityId = 123;
        $entityType = 'product';
        
        // Create interpretation
        $interpretation = "The iPhone 17 has 50 units in stock.";
        
        // Build response
        $response = AgentResponse::success('analytics', $query)
            ->setResult([
                'sql_query' => $sql,
                'columns' => ['products_name', 'products_quantity'],
                'rows' => $rows,
                'row_count' => count($rows),
                'interpretation' => $interpretation
            ])
            ->setSourceAttribution(
                AgentResponse::createAnalyticsSourceAttribution('clic_products', 0.9)
            )
            ->setMetadata([
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'execution_time' => microtime(true) - $startTime,
                'language_id' => $context['language_id'] ?? 1,
                'user_id' => $context['user_id'] ?? 'system'
            ]);
        
        return $response->toArray();
        
    } catch (\Exception $e) {
        return AgentResponse::error('analytics', $query, 'SQL execution failed: ' . $e->getMessage())
            ->setMetadata([
                'error_code' => 'SQL_EXECUTION_ERROR',
                'execution_time' => microtime(true) - $startTime
            ])
            ->toArray();
    }
}
```

### Example 2: Multi-Row Analytics Query

```php
use ClicShopping\AI\InterfacesAI\AgentResponse;

public function getTopProducts(string $query): array
{
    $startTime = microtime(true);
    
    $sql = "SELECT products_id, products_name, products_quantity 
            FROM clic_products 
            ORDER BY products_quantity DESC 
            LIMIT 10";
    
    $result = $this->db->query($sql);
    $rows = $result->fetchAll();
    
    $interpretation = sprintf(
        "Found %d products. Top product: %s with %d units.",
        count($rows),
        $rows[0]['products_name'] ?? 'N/A',
        $rows[0]['products_quantity'] ?? 0
    );
    
    return AgentResponse::success('analytics', $query)
        ->setResult([
            'sql_query' => $sql,
            'columns' => ['products_id', 'products_name', 'products_quantity'],
            'rows' => $rows,
            'row_count' => count($rows),
            'interpretation' => $interpretation
        ])
        ->setSourceAttribution(
            AgentResponse::createAnalyticsSourceAttribution('clic_products', 0.95)
        )
        ->setMetadata([
            'execution_time' => microtime(true) - $startTime,
            'query_type' => 'top_products'
        ])
        ->toArray();
}
```

---

## Semantic Query Examples

### Example 1: Policy Retrieval

```php
use ClicShopping\AI\InterfacesAI\AgentResponse;

public function executeSemanticQuery(string $query, array $context = []): array
{
    $startTime = microtime(true);
    
    try {
        // Search embeddings
        $semantics = new Semantics();
        $documents = $semantics->search($query, $context['language_id'] ?? 1, 0.7);
        
        // Generate summary with LLM
        $summary = $this->generateSummary($documents, $query);
        
        return AgentResponse::success('semantic', $query)
            ->setResult([
                'documents' => $documents,
                'summary' => $summary,
                'document_count' => count($documents),
                'similarity_threshold' => 0.7
            ])
            ->setSourceAttribution(
                AgentResponse::createSemanticSourceAttribution(count($documents), 0.85)
            )
            ->setMetadata([
                'execution_time' => microtime(true) - $startTime,
                'language_id' => $context['language_id'] ?? 1,
                'embedding_model' => 'text-embedding-ada-002'
            ])
            ->toArray();
            
    } catch (\Exception $e) {
        return AgentResponse::error('semantic', $query, 'Embedding search failed: ' . $e->getMessage())
            ->setMetadata([
                'error_code' => 'EMBEDDING_SEARCH_ERROR',
                'execution_time' => microtime(true) - $startTime
            ])
            ->toArray();
    }
}
```

### Example 2: Semantic Search with Fallback to LLM

```php
use ClicShopping\AI\InterfacesAI\AgentResponse;

public function executeSemanticQueryWithFallback(string $query): array
{
    $startTime = microtime(true);
    
    // Try embedding search first
    $documents = $this->searchEmbeddings($query);
    
    if (empty($documents)) {
        // Fallback to LLM
        $llmResponse = $this->generateLLMResponse($query);
        
        return AgentResponse::success('semantic', $query)
            ->setResult([
                'documents' => [],
                'summary' => $llmResponse,
                'document_count' => 0,
                'similarity_threshold' => 0.7,
                'fallback_used' => true
            ])
            ->setSourceAttribution(
                AgentResponse::createLLMSourceAttribution(0.5)
            )
            ->setMetadata([
                'execution_time' => microtime(true) - $startTime,
                'fallback_reason' => 'No documents found above threshold'
            ])
            ->toArray();
    }
    
    // Use embedding results
    $summary = $this->generateSummary($documents, $query);
    
    return AgentResponse::success('semantic', $query)
        ->setResult([
            'documents' => $documents,
            'summary' => $summary,
            'document_count' => count($documents),
            'similarity_threshold' => 0.7
        ])
        ->setSourceAttribution(
            AgentResponse::createSemanticSourceAttribution(count($documents), 0.85)
        )
        ->setMetadata([
            'execution_time' => microtime(true) - $startTime
        ])
        ->toArray();
}
```

---

## Web Search Query Examples

### Example 1: Product Price Comparison

```php
use ClicShopping\AI\InterfacesAI\AgentResponse;

public function executeWebSearchQuery(string $query, array $context = []): array
{
    $startTime = microtime(true);
    
    try {
        // Find product in database
        $productInfo = $this->findProductInDatabase($query);
        
        if (!$productInfo) {
            return AgentResponse::error('web_search', $query, 'Product not found in database')
                ->setMetadata(['execution_time' => microtime(true) - $startTime])
                ->toArray();
        }
        
        // Search web with SERAPI
        $externalResults = $this->searchWeb($productInfo['name']);
        
        // Compare prices
        $priceComparison = $this->comparePrice($productInfo['price'], $externalResults);
        
        // Extract URLs
        $urls = array_column($externalResults, 'url');
        
        return AgentResponse::success('web_search', $query)
            ->setResult([
                'product_info' => $productInfo,
                'external_results' => $externalResults,
                'price_comparison' => $priceComparison,
                'urls' => $urls
            ])
            ->setSourceAttribution(
                AgentResponse::createWebSearchSourceAttribution(count($urls), 0.7)
            )
            ->setMetadata([
                'entity_id' => $productInfo['product_id'],
                'entity_type' => 'product',
                'execution_time' => microtime(true) - $startTime,
                'serapi_calls' => 1
            ])
            ->toArray();
            
    } catch (\Exception $e) {
        return AgentResponse::error('web_search', $query, 'Web search failed: ' . $e->getMessage())
            ->setMetadata([
                'error_code' => 'WEB_SEARCH_ERROR',
                'execution_time' => microtime(true) - $startTime
            ])
            ->toArray();
    }
}
```

---

## Hybrid Query Examples

### Example 1: Analytics + Semantic Hybrid

```php
use ClicShopping\AI\InterfacesAI\AgentResponse;

public function synthesizeResults(array $subQueryResults, string $originalQuery): array
{
    $startTime = microtime(true);
    
    // Extract sources used
    $sourcesUsed = array_unique(array_column($subQueryResults, 'type'));
    
    // Generate synthesis text
    $synthesisText = $this->generateSynthesis($subQueryResults, $originalQuery);
    
    return AgentResponse::success('hybrid', $originalQuery)
        ->setResult([
            'sub_queries' => $subQueryResults,
            'synthesis' => $synthesisText,
            'sources_used' => $sourcesUsed
        ])
        ->setSourceAttribution(
            AgentResponse::createHybridSourceAttribution($sourcesUsed, 0.8)
        )
        ->setMetadata([
            'execution_time' => microtime(true) - $startTime,
            'sub_query_count' => count($subQueryResults)
        ])
        ->toArray();
}
```

---

## Error Handling Examples

### Example 1: SQL Execution Error

```php
use ClicShopping\AI\InterfacesAI\AgentResponse;

try {
    $result = $this->db->query($sql);
} catch (\PDOException $e) {
    return AgentResponse::error('analytics', $query, 'Database error: ' . $e->getMessage())
        ->setMetadata([
            'error_code' => 'DB_ERROR',
            'sql_query' => $sql,
            'error_type' => get_class($e)
        ])
        ->toArray();
}
```

### Example 2: Timeout Error

```php
use ClicShopping\AI\InterfacesAI\AgentResponse;

$timeout = 10; // seconds
$startTime = time();

while (time() - $startTime < $timeout) {
    // Try to get results
    $results = $this->fetchResults();
    
    if ($results) {
        return AgentResponse::success('web_search', $query)
            ->setResult($results)
            ->toArray();
    }
}

// Timeout reached
return AgentResponse::error('web_search', $query, 'Request timed out after ' . $timeout . ' seconds')
    ->setMetadata([
        'error_code' => 'TIMEOUT',
        'timeout_seconds' => $timeout
    ])
    ->toArray();
```

---

## Migration Examples

### Before: Old Format

```php
// Old way (inconsistent format)
return [
    'type' => 'analytics',
    'query' => $query,
    'result' => $results,
    'success' => true,
    'sql_query' => $sql,
    'entity_id' => $entityId
];
```

### After: New Format

```php
// New way (standardized format)
return AgentResponse::success('analytics', $query)
    ->setResult([
        'sql_query' => $sql,
        'columns' => $columns,
        'rows' => $results,
        'row_count' => count($results),
        'interpretation' => $interpretation
    ])
    ->setSourceAttribution(
        AgentResponse::createAnalyticsSourceAttribution('clic_products', 0.9)
    )
    ->setMetadata([
        'entity_id' => $entityId,
        'entity_type' => 'product',
        'execution_time' => $executionTime
    ])
    ->toArray();
```

---

## Best Practices

1. **Always include execution_time** in metadata for performance tracking
2. **Always include source_attribution** to show users where data came from
3. **Use helper methods** (`createAnalyticsSourceAttribution`, etc.) for consistency
4. **Include entity_id and entity_type** when applicable for memory integration
5. **Provide meaningful error messages** that help users understand what went wrong
6. **Log errors** before returning error responses
7. **Use try-catch blocks** to handle exceptions gracefully
8. **Include confidence scores** in source attribution
9. **Add interpretation text** for analytics results to make them user-friendly
10. **Test responses** with unit tests to ensure format compliance

---

## Testing Examples

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use ClicShopping\AI\InterfacesAI\AgentResponse;

class AgentResponseTest extends TestCase
{
    public function testSuccessfulAnalyticsResponse()
    {
        $response = AgentResponse::success('analytics', 'Show stock of iPhone 17')
            ->setResult([
                'sql_query' => 'SELECT * FROM clic_products WHERE products_id = 123',
                'columns' => ['products_name', 'products_quantity'],
                'rows' => [['products_name' => 'iPhone 17', 'products_quantity' => 50]],
                'row_count' => 1,
                'interpretation' => 'The iPhone 17 has 50 units in stock.'
            ])
            ->setSourceAttribution(
                AgentResponse::createAnalyticsSourceAttribution('clic_products', 0.9)
            )
            ->setMetadata([
                'entity_id' => 123,
                'entity_type' => 'product',
                'execution_time' => 0.5
            ]);
        
        $array = $response->toArray();
        
        $this->assertTrue($array['success']);
        $this->assertEquals('analytics', $array['type']);
        $this->assertEquals('Show stock of iPhone 17', $array['query']);
        $this->assertArrayHasKey('result', $array);
        $this->assertArrayHasKey('source_attribution', $array);
        $this->assertArrayHasKey('metadata', $array);
        $this->assertNull($array['error']);
    }
    
    public function testErrorResponse()
    {
        $response = AgentResponse::error('analytics', 'Invalid query', 'SQL syntax error');
        
        $array = $response->toArray();
        
        $this->assertFalse($array['success']);
        $this->assertEquals('SQL syntax error', $array['error']);
    }
}
```

---

## Related Documentation

- [AGENT_RESPONSE_CONTRACT.md](AGENT_RESPONSE_CONTRACT.md) - Full interface contract
- [AgentResponseInterface.php](AgentResponseInterface.php) - Interface definition
- [AgentResponse.php](AgentResponse.php) - Concrete implementation
