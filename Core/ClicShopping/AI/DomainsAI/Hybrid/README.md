# Hybrid Domain

## Purpose

The Hybrid Domain handles **queries that require both semantic search AND analytics processing**. It intelligently splits complex queries into semantic and analytics components, executes them in the appropriate domains, and combines the results into a coherent response.

## Use Cases

- **Combined Search & Analytics**: "Show me reviews for top-selling products"
- **Contextual Analysis**: "Find similar products and compare their prices"
- **Enriched Results**: "What are customer opinions on best-selling items?"
- **Multi-faceted Queries**: "Show products like X with sales data"
- **Semantic + Metrics**: "Find laptops and show their average ratings"

## Query Flow

```
User Query: "Show me reviews for top-selling products"
    ↓
OrchestratorAgent → Routes to Hybrid Domain
    ↓
HybridQueryProcessor.process()
    ↓
QuerySplitter.split()
    - Analyzes query structure
    - Identifies semantic component: "reviews"
    - Identifies analytics component: "top-selling products"
    - Determines execution order and dependencies
    ↓
Execute Analytics Component First
    ↓
AnalyticsAgent.execute("top-selling products")
    - Generates SQL: SELECT products WHERE ...
    - Returns: [Product IDs: 123, 456, 789, ...]
    ↓
Execute Semantic Component with Context
    ↓
SemanticAgent.processQuery("reviews", context: {product_ids: [123, 456, 789]})
    - Performs vector search for reviews
    - Filters by product IDs from analytics
    - Returns: Reviews for those products
    ↓
ResultCombiner.combine()
    - Merges analytics and semantic results
    - Maintains relationships (product → reviews)
    - Formats unified response
    ↓
HybridQueryCache.store()
    - Caches combined results
    ↓
Formatted Response with Combined Data
```

## Key Components

### Processor/

**HybridQueryProcessor.php** - Main processor for hybrid queries
- Coordinates hybrid query execution
- Manages component execution order
- Handles dependencies between components
- Combines results from multiple domains
- Implements error recovery strategies

**Key Methods**:
```php
public function process(string $query, array $context = []): array
public function canHandle(string $query): bool
public function estimateComplexity(string $query): int
public function getExecutionPlan(string $query): array
```

**QuerySplitter.php** - Splits queries into semantic and analytics components
- Analyzes query structure using LLM
- Identifies semantic keywords (reviews, similar, like, about)
- Identifies analytics keywords (top, best, average, count, total)
- Determines component dependencies
- Creates execution plan

**Key Methods**:
```php
public function split(string $query): array
public function identifyComponents(string $query): array
public function determineDependencies(array $components): array
public function createExecutionPlan(array $components): array
```

**ResultCombiner.php** - Combines results from multiple domains
- Merges semantic and analytics results
- Maintains data relationships
- Resolves conflicts
- Formats unified response

### Cache/

**HybridQueryCache.php** - Caches hybrid query results
- Stores combined results with TTL
- Caches execution plans for similar queries
- Invalidates on component data changes
- Reduces redundant domain calls

**Key Methods**:
```php
public function store(string $query, array $result, int $ttl = 3600): void
public function retrieve(string $query): ?array
public function invalidate(string $pattern): void
public function getStats(): array
```

### Agent/

**HybridAgent.php** - Entry point for hybrid queries (future)
- Implements `QueryTypeDomainInterface`
- Coordinates with HybridQueryProcessor
- Manages hybrid query lifecycle
- Provides metrics and monitoring

## Configuration

Hybrid domain configuration in `chat_system_config.json`:

```json
{
  "Hybrid": {
    "enable_parallel_execution": true,
    "max_execution_time": 60,
    "cache_ttl": 3600,
    "max_components": 5,
    "enable_result_enrichment": true,
    "fallback_to_single_domain": true,
    "max_retries": 2
  }
}
```

**Parameters**:
- `enable_parallel_execution`: Execute independent components in parallel
- `max_execution_time`: Maximum total execution time (seconds)
- `cache_ttl`: Cache lifetime (seconds)
- `max_components`: Maximum query components to handle
- `enable_result_enrichment`: Enrich results with additional context
- `fallback_to_single_domain`: Fallback if hybrid fails
- `max_retries`: Maximum retry attempts

## Examples

### Example 1: Reviews for Top Products

**Query**: "Show me reviews for top-selling products"

**Query Split**:
```json
{
  "components": [
    {
      "type": "analytics",
      "query": "top-selling products",
      "priority": 1,
      "output": "product_ids"
    },
    {
      "type": "semantic",
      "query": "reviews",
      "priority": 2,
      "depends_on": ["product_ids"],
      "filters": {"product_id": "{{product_ids}}"}
    }
  ],
  "execution_order": ["analytics", "semantic"]
}
```

**Execution**:
1. **Analytics**: Get top 10 selling products → [123, 456, 789, ...]
2. **Semantic**: Search reviews filtered by product IDs
3. **Combine**: Match reviews to products

**Response**:
```json
{
  "intent": "hybrid",
  "components": ["analytics", "semantic"],
  "results": [
    {
      "product_id": 123,
      "product_name": "Laptop XPS 15",
      "total_sold": 450,
      "reviews": [
        {
          "review_id": 789,
          "rating": 5,
          "text": "Excellent laptop, very fast...",
          "similarity": 0.95
        },
        // ... more reviews
      ]
    },
    // ... more products with reviews
  ],
  "execution_time": "1.2s",
  "cache_hit": false
}
```

### Example 2: Similar Products with Price Comparison

**Query**: "Find products similar to laptop and compare their prices"

**Query Split**:
```json
{
  "components": [
    {
      "type": "semantic",
      "query": "products similar to laptop",
      "priority": 1,
      "output": "product_ids"
    },
    {
      "type": "analytics",
      "query": "compare prices",
      "priority": 2,
      "depends_on": ["product_ids"],
      "filters": {"product_id": "{{product_ids}}"}
    }
  ],
  "execution_order": ["semantic", "analytics"]
}
```

**Execution**:
1. **Semantic**: Find similar products → [123, 456, 789, ...]
2. **Analytics**: Get price statistics for those products
3. **Combine**: Merge similarity scores with price data

**Response**:
```json
{
  "intent": "hybrid",
  "components": ["semantic", "analytics"],
  "results": [
    {
      "product_id": 123,
      "product_name": "Dell Laptop XPS 15",
      "similarity": 0.92,
      "price": 1499.99,
      "avg_market_price": 1550.00,
      "price_difference": -50.01,
      "price_position": "below_average"
    },
    // ... more products
  ],
  "summary": {
    "total_products": 10,
    "avg_price": 1425.50,
    "price_range": [999.99, 1899.99]
  },
  "execution_time": "0.95s"
}
```

### Example 3: Customer Opinions on Best Items

**Query**: "What are customer opinions on best-selling items?"

**Query Split**:
```json
{
  "components": [
    {
      "type": "analytics",
      "query": "best-selling items",
      "priority": 1,
      "output": "product_ids"
    },
    {
      "type": "semantic",
      "query": "customer opinions",
      "priority": 2,
      "depends_on": ["product_ids"],
      "filters": {"product_id": "{{product_ids}}"}
    }
  ],
  "execution_order": ["analytics", "semantic"],
  "enrichment": ["sentiment_analysis", "rating_aggregation"]
}
```

**Response**:
```json
{
  "intent": "hybrid",
  "results": [
    {
      "product_id": 123,
      "product_name": "Laptop XPS 15",
      "sales_rank": 1,
      "total_sold": 450,
      "opinions": {
        "positive": 85,
        "neutral": 10,
        "negative": 5,
        "avg_rating": 4.7,
        "common_themes": ["performance", "build quality", "battery life"],
        "sample_reviews": [
          "Excellent performance and build quality",
          "Great battery life, lasts all day"
        ]
      }
    }
  ],
  "execution_time": "1.5s"
}
```

## Query Splitting Strategy

### Identification Keywords

**Semantic Indicators**:
- similar, like, about, related, matching
- reviews, opinions, feedback, comments
- description, information, details
- find, search, show, get

**Analytics Indicators**:
- top, best, worst, highest, lowest
- average, total, sum, count, percentage
- compare, comparison, versus, vs
- trend, growth, change, increase, decrease
- statistics, metrics, numbers, data

### Dependency Resolution

**Sequential Dependencies**:
```
Analytics → Semantic
Example: "Reviews for top products"
1. Get top products (analytics)
2. Get reviews for those products (semantic)
```

**Parallel Execution**:
```
Analytics || Semantic → Combine
Example: "Show product details and sales data"
1. Get product details (semantic) || Get sales data (analytics)
2. Combine results
```

**Bidirectional Dependencies**:
```
Semantic ↔ Analytics
Example: "Find similar high-revenue products"
1. Get high-revenue products (analytics)
2. Find similar products (semantic)
3. Filter by revenue threshold (analytics)
```

## Performance Optimization

### Parallel Execution
When components are independent:
- Execute semantic and analytics queries simultaneously
- Reduce total execution time by ~40%
- Combine results when both complete

### Caching Strategy
- **Query Cache**: Cache entire hybrid results (TTL: 1 hour)
- **Component Cache**: Cache individual component results
- **Plan Cache**: Cache execution plans for similar queries

### Execution Plan Optimization
- Identify independent components
- Execute in optimal order (dependencies first)
- Minimize data transfer between domains
- Reuse component results when possible

## Error Handling

### Component Failure
If one component fails:
1. **Retry**: Attempt component execution again (max 2 retries)
2. **Partial Results**: Return results from successful component
3. **Fallback**: Fall back to single-domain query
4. **User Notification**: Inform user of partial results

### Timeout Handling
If execution exceeds timeout:
1. **Cancel**: Cancel pending components
2. **Return Partial**: Return completed components
3. **Cache Partial**: Cache partial results for retry
4. **Suggest Simplification**: Suggest simpler query

### Conflict Resolution
If components return conflicting data:
1. **Priority**: Use component with higher priority
2. **Recency**: Prefer more recent data
3. **Confidence**: Use result with higher confidence score
4. **User Choice**: Ask user to resolve conflict

## Integration with Other Domains

### Semantic Domain
- Receives product IDs from analytics
- Performs filtered vector searches
- Returns semantically relevant content

### Analytics Domain
- Provides structured data (IDs, metrics)
- Executes SQL queries
- Returns quantitative results

### CoreAI Domain
- Uses shared embedding functionality
- Leverages entity extraction
- Accesses common utilities

## Testing

### Unit Tests
```bash
# Test query splitting
php unit_test/2026_01_17/test_query_splitter.php

# Test result combining
php unit_test/2026_01_17/test_result_combiner.php

# Test dependency resolution
php unit_test/2026_01_17/test_dependency_resolution.php
```

### Integration Tests
```bash
# Test full hybrid flow
php unit_test/2026_01_17/test_hybrid_domain.php

# Test with real hybrid queries
php unit_test/2026_01_17/test_hybrid_queries.php
```

### Test Cases
1. **Sequential Dependencies**: Analytics → Semantic
2. **Parallel Execution**: Independent components
3. **Complex Dependencies**: Multi-level dependencies
4. **Component Failure**: One component fails
5. **Timeout Scenarios**: Execution exceeds limit
6. **Conflict Resolution**: Conflicting results
7. **Cache Effectiveness**: Cache hit/miss scenarios

## Troubleshooting

### Common Issues

**Issue**: Query not recognized as hybrid
- **Cause**: Unclear query structure
- **Solution**: Make query more explicit, use clear semantic and analytics keywords

**Issue**: Incorrect component split
- **Cause**: Ambiguous query or LLM error
- **Solution**: Rephrase query, check query splitter logic

**Issue**: Slow execution
- **Cause**: Sequential execution of independent components
- **Solution**: Enable parallel execution, optimize component queries

**Issue**: Incomplete results
- **Cause**: Component failure or timeout
- **Solution**: Check component logs, increase timeout, simplify query

### Debug Mode

Enable debug logging:
```php
define('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER', 'True');
```

Check logs:
```bash
tail -f Core/ClicShopping/Work/Log/hybrid_*.log
```

## Related Documentation

- **Main Domains README**: [../README.md](../README.md)
- **Semantic Domain**: [../Semantic/README.md](../Semantic/README.md)
- **Analytics Domain**: [../Analytics/README.md](../Analytics/README.md)
- **CoreAI Domain**: [../CoreAI/README.md](../CoreAI/README.md)
- **QueryTypeDomainInterface**: `../../Interfaces/QueryTypeDomainInterface.php`

## Future Enhancements

- [ ] Support for 3+ component queries
- [ ] Automatic query optimization suggestions
- [ ] Machine learning for better query splitting
- [ ] Real-time result streaming
- [ ] Advanced dependency graph visualization
- [ ] Query complexity estimation and warnings

---

**Status**: Active  
**Created**: January 17, 2026  
**Last Updated**: January 17, 2026  
**Owner**: Development Team
