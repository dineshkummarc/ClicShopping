# Agent Response Interface Contract

## Overview

The `AgentResponseInterface` defines a standardized contract for all agent responses in the RAG system. This ensures consistent data flow from agents through formatters to the display layer.

**Version:** 1.0.0  
**Date:** 2025-11-14  
**Status:** Active

## Purpose

1. **Standardization**: Ensure all agents return data in the same format
2. **Consistency**: Enable predictable handling in ResultFormatter
3. **Traceability**: Support source attribution tracking
4. **Debugging**: Facilitate metadata propagation for logging and analytics
5. **Maintainability**: Simplify adding new agents or modifying existing ones

## Architecture Flow

```
Query → Agent → AgentResponseInterface → ResultFormatter → Display
```

### Components

- **Agent**: Processes query and generates response (AnalyticsAgent, SemanticAgent, etc.)
- **AgentResponseInterface**: Standardized response contract
- **AgentResponse**: Concrete implementation with helper methods
- **ResultFormatter**: Formats response for display
- **Display**: Chat UI that shows formatted results to user

## Interface Methods

### Required Methods

#### `toArray(): array`
Converts the response to a standardized array format.

**Returns:**
```php
[
    'success' => bool,              // Whether the query was successful
    'type' => string,               // Query type: 'analytics', 'semantic', 'web_search', 'hybrid'
    'query' => string,              // Original query text
    'result' => array,              // Type-specific result structure
    'source_attribution' => array,  // Source information for display
    'metadata' => array,            // Additional metadata
    'error' => string|null          // Error message if success is false
]
```

#### `getType(): string`
Returns the query type.

**Valid types:**
- `'analytics'` - Database queries (SQL)
- `'semantic'` - Embedding-based searches
- `'web_search'` - External web searches
- `'hybrid'` - Combination of multiple types

#### `getResult(): array`
Returns the type-specific result structure (see Result Structures below).

#### `getSourceAttribution(): array`
Returns source attribution information for display.

**Format:**
```php
[
    'primary_source' => string,     // Main source name
    'icon' => string,               // Icon for display
    'details' => array,             // Additional details
    'confidence' => float           // Confidence score (0.0-1.0)
]
```

#### `getMetadata(): array`
Returns metadata about query execution.

**Common fields:**
```php
[
    'entity_id' => int|null,
    'entity_type' => string|null,
    'execution_time' => float,
    'timestamp' => string,
    'language_id' => int,
    'user_id' => string,
    'confidence_score' => float,
    'classification_reasoning' => array,
    'cache_hit' => bool,
    'feedback_influenced' => bool,
    'memory_context_used' => bool
]
```

#### `isSuccess(): bool`
Returns true if query was successful, false otherwise.

#### `getError(): ?string`
Returns error message if query failed, null otherwise.

#### `getQuery(): string`
Returns the original query text.

## Result Structures by Type

### Analytics Result

```php
[
    'sql_query' => string,          // Generated SQL query
    'columns' => array,             // Column names
    'rows' => array,                // Result rows (array of associative arrays)
    'row_count' => int,             // Number of rows
    'interpretation' => string      // Human-readable interpretation
]
```

**Example:**
```php
[
    'sql_query' => 'SELECT products_name, products_quantity FROM clic_products WHERE products_id = 123',
    'columns' => ['products_name', 'products_quantity'],
    'rows' => [
        ['products_name' => 'iPhone 17', 'products_quantity' => 50]
    ],
    'row_count' => 1,
    'interpretation' => 'The iPhone 17 has 50 units in stock.'
]
```

### Semantic Result

```php
[
    'documents' => array,           // Retrieved documents with content and scores
    'summary' => string,            // LLM-generated summary
    'document_count' => int,        // Number of documents retrieved
    'similarity_threshold' => float // Threshold used for retrieval
]
```

**Example:**
```php
[
    'documents' => [
        [
            'content' => 'Our return policy allows returns within 14 days...',
            'score' => 0.92,
            'metadata' => ['source' => 'policies.pdf', 'page' => 3]
        ]
    ],
    'summary' => 'Our return policy allows returns within 14 days of purchase.',
    'document_count' => 1,
    'similarity_threshold' => 0.7
]
```

### Web Search Result

```php
[
    'product_info' => array,        // Internal product information
    'external_results' => array,    // SERAPI results
    'price_comparison' => array,    // Price comparison data
    'urls' => array                 // External URLs
]
```

**Example:**
```php
[
    'product_info' => [
        'product_id' => 123,
        'name' => 'iPhone 17',
        'price' => 999.99
    ],
    'external_results' => [
        ['title' => 'iPhone 17 on Amazon', 'price' => 949.99, 'url' => 'https://amazon.com/...']
    ],
    'price_comparison' => [
        'internal_price' => 999.99,
        'cheapest_external' => 949.99,
        'difference' => -50.00,
        'percentage' => -5.0
    ],
    'urls' => ['https://amazon.com/...', 'https://bestbuy.com/...']
]
```

### Hybrid Result

```php
[
    'sub_queries' => array,         // Array of sub-query results
    'synthesis' => string,          // Combined synthesis text
    'sources_used' => array         // List of sources used
]
```

**Example:**
```php
[
    'sub_queries' => [
        [
            'type' => 'analytics',
            'query' => 'Show Q1 sales',
            'result' => [...],
            'source_attribution' => [...]
        ],
        [
            'type' => 'semantic',
            'query' => 'Summarize return policy',
            'result' => [...],
            'source_attribution' => [...]
        ]
    ],
    'synthesis' => 'Q1 sales totaled $1.2M. Our return policy allows returns within 14 days.',
    'sources_used' => ['analytics', 'semantic']
]
```

## Source Attribution Examples

### Analytics Database
```php
[
    'primary_source' => 'Analytics Database',
    'icon' => '📊',
    'details' => ['table' => 'clic_products'],
    'confidence' => 0.9
]
```

### RAG Knowledge Base
```php
[
    'primary_source' => 'RAG Knowledge Base',
    'icon' => '📚',
    'details' => ['document_count' => 3],
    'confidence' => 0.85
]
```

### Web Search
```php
[
    'primary_source' => 'Web Search',
    'icon' => '🌐',
    'details' => ['url_count' => 5],
    'confidence' => 0.7
]
```

### LLM Fallback
```php
[
    'primary_source' => 'LLM',
    'icon' => '🤖',
    'details' => ['fallback' => true],
    'confidence' => 0.5
]
```

### Mixed Sources
```php
[
    'primary_source' => 'Mixed',
    'icon' => '🔀',
    'details' => ['sources' => ['analytics', 'semantic']],
    'confidence' => 0.8
]
```

## Usage Examples

### Creating a Successful Analytics Response

```php
use ClicShopping\AI\Agents\Orchestrator\AgentResponse;

$response = AgentResponse::success('analytics', $query)
    ->setResult([
        'sql_query' => $sql,
        'columns' => $columns,
        'rows' => $rows,
        'row_count' => count($rows),
        'interpretation' => $interpretation
    ])
    ->setSourceAttribution(
        AgentResponse::createAnalyticsSourceAttribution('clic_products', 0.9)
    )
    ->setMetadata([
        'entity_id' => $productId,
        'entity_type' => 'product',
        'execution_time' => $executionTime,
        'language_id' => $languageId,
        'user_id' => $userId
    ]);

return $response->toArray();
```

### Creating an Error Response

```php
use ClicShopping\AI\Agents\Orchestrator\AgentResponse;

$response = AgentResponse::error('analytics', $query, 'SQL execution failed: ' . $e->getMessage())
    ->setMetadata([
        'error_code' => 'SQL_EXECUTION_ERROR',
        'execution_time' => $executionTime
    ]);

return $response->toArray();
```

### Creating a Semantic Response

```php
use ClicShopping\AI\Agents\Orchestrator\AgentResponse;

$response = AgentResponse::success('semantic', $query)
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
        'execution_time' => $executionTime,
        'language_id' => $languageId
    ]);

return $response->toArray();
```

### Creating a Hybrid Response

```php
use ClicShopping\AI\Agents\Orchestrator\AgentResponse;

$response = AgentResponse::success('hybrid', $query)
    ->setResult([
        'sub_queries' => $subQueryResults,
        'synthesis' => $synthesisText,
        'sources_used' => ['analytics', 'semantic']
    ])
    ->setSourceAttribution(
        AgentResponse::createHybridSourceAttribution(['analytics', 'semantic'], 0.8)
    )
    ->setMetadata([
        'execution_time' => $executionTime,
        'sub_query_count' => count($subQueryResults)
    ]);

return $response->toArray();
```

## Migration Guide

### For Existing Agents

To migrate an existing agent to use the new interface:

1. **Import the classes:**
   ```php
   use ClicShopping\AI\Agents\Orchestrator\AgentResponse;
   ```

2. **Replace existing return statements:**
   
   **Before:**
   ```php
   return [
       'type' => 'analytics',
       'query' => $query,
       'result' => $results,
       'success' => true
   ];
   ```
   
   **After:**
   ```php
   return AgentResponse::success('analytics', $query)
       ->setResult($results)
       ->setSourceAttribution(
           AgentResponse::createAnalyticsSourceAttribution('clic_products')
       )
       ->setMetadata([
           'entity_id' => $entityId,
           'execution_time' => $executionTime
       ])
       ->toArray();
   ```

3. **Add source attribution** (if not present)
4. **Add metadata** (entity_id, execution_time, etc.)
5. **Test with existing unit tests**

## Validation Rules

### Required Fields
- `success` (bool)
- `type` (string, one of: 'analytics', 'semantic', 'web_search', 'hybrid')
- `query` (string, non-empty)
- `result` (array)
- `source_attribution` (array)
- `metadata` (array)
- `error` (string|null)

### Type-Specific Validation

**Analytics:**
- `result.sql_query` must be present
- `result.rows` must be an array
- `result.row_count` must match `count(result.rows)`

**Semantic:**
- `result.documents` must be an array
- `result.document_count` must match `count(result.documents)`

**Web Search:**
- `result.urls` must be an array of valid URLs

**Hybrid:**
- `result.sub_queries` must be an array
- Each sub-query must have `type`, `query`, and `result`

## Benefits

1. **Consistency**: All agents return data in the same format
2. **Maintainability**: Easy to add new agents or modify existing ones
3. **Debugging**: Metadata provides rich context for troubleshooting
4. **Testing**: Standardized format simplifies unit testing
5. **Display**: ResultFormatter can handle all response types uniformly
6. **Traceability**: Source attribution shows users where data came from
7. **Analytics**: Metadata enables performance tracking and optimization

## Related Documentation

- `AgentResponseInterface.php` - Interface definition
- `AgentResponse.php` - Concrete implementation
- `ResultFormatter.php` - Formatter that consumes agent responses
- `HybridQueryProcessor.php` - Example agent implementation
- `kiro_documentation/2025_11_14_display_architecture_analysis.md` - Architecture analysis

## Version History

- **1.0.0** (2025-11-14): Initial release
  - Defined standardized interface
  - Created concrete implementation
  - Added helper methods for common source attributions
  - Documented contract and usage examples
