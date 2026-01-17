# Semantic Domain

## Purpose

The Semantic Domain handles **semantic search queries** using vector embeddings and RAG (Retrieval-Augmented Generation). It processes natural language queries to find semantically similar content in the database, regardless of exact keyword matches.

## Use Cases

- **Product Search**: "Find products similar to laptop"
- **Information Retrieval**: "Show me information about shipping policies"
- **Natural Language Search**: "What do you have for outdoor activities?"
- **Semantic Similarity**: "Find items like this one"
- **Multi-language Queries**: Queries in any language (translated to English for processing)

## Query Flow

```
User Query: "Find products similar to laptop"
    ↓
OrchestratorAgent → Routes to Semantic Domain
    ↓
SemanticAgent.processQuery()
    ↓
ClassificationEngine.classify()
    - Determines query type (product_search, info_retrieval, etc.)
    - Uses LLM for classification (Pure LLM Mode)
    ↓
TranslationHandler.translate()
    - Detects language
    - Translates to English if needed
    - Caches translations
    ↓
SemanticQueryExecutor.execute()
    - Generates query embedding
    - Performs vector similarity search
    - Retrieves top K similar items
    ↓
SemanticCache.store()
    - Caches results for future queries
    ↓
Response Formatter
    - Formats results for display
    - Includes similarity scores
    ↓
Formatted Response with Products
```

## Key Components

### Agent/

**SemanticAgent.php** - Entry point for semantic queries
- Implements `QueryTypeDomainInterface`
- Coordinates semantic query processing
- Manages configuration and caching
- Handles security and validation

**Key Methods**:
```php
public function processQuery(string $query, array $context = []): array
public function getName(): string
public function canHandle(string $query, array $context): bool
public function getCapabilities(): array
public function getMetrics(): array
```

### Processor/

**ClassificationEngine.php** - Classifies query intent using LLM
- Determines semantic query type (product_search, info_retrieval, etc.)
- Uses Pure LLM Mode (no pattern matching)
- Provides confidence scores
- Handles ambiguous queries

**TranslationHandler.php** - Handles multi-language queries
- Detects query language
- Translates to English for processing
- Caches translations
- Preserves original query context

**ResponseFormatter.php** - Formats semantic search results
- Structures results for display
- Includes similarity scores
- Adds metadata and context
- Handles empty results gracefully

**SemanticProcessor.php** - Processes semantic query intent
- Analyzes query structure
- Extracts entities and keywords
- Prepares query for execution

### Executor/

**SemanticQueryExecutor.php** - Executes vector similarity searches
- Generates query embeddings using OpenAI
- Performs cosine similarity search in database
- Retrieves top K most similar items
- Filters by entity type and language
- Handles vector search errors

**Key Methods**:
```php
public function execute(string $query, array $options = []): array
public function generateEmbedding(string $text): array
public function searchSimilar(array $embedding, int $limit = 10): array
```

### Cache/

**SemanticCache.php** - Caches semantic query results
- Stores query results with TTL
- Caches embeddings for reuse
- Invalidates on data changes
- Reduces API calls and latency

### Helper/

**SemanticDomainDetector.php** - Detects if query is semantic
- Analyzes query characteristics
- Determines if semantic search is appropriate
- Provides confidence scores
- Helps orchestrator route queries

## Configuration

Semantic domain configuration in `chat_system_config.json`:

```json
{
  "Semantics": {
    "classification_threshold": 3,
    "max_retries": 3,
    "translation_cache_ttl": 3600,
    "enable_fallback": true,
    "enable_competitor_detection": true,
    "similarity_threshold": 0.7,
    "max_results": 10
  }
}
```

**Parameters**:
- `classification_threshold`: Minimum confidence for classification
- `max_retries`: Maximum retry attempts for API calls
- `translation_cache_ttl`: Translation cache lifetime (seconds)
- `enable_fallback`: Enable fallback to keyword search
- `enable_competitor_detection`: Detect competitor mentions
- `similarity_threshold`: Minimum similarity score (0-1)
- `max_results`: Maximum results to return

## Examples

### Example 1: Product Search

**Query**: "Find products similar to laptop"

**Processing**:
1. Classification: `product_search`
2. Translation: Not needed (English)
3. Embedding: `[0.123, -0.456, 0.789, ...]`
4. Vector Search: Top 10 similar products
5. Results: Products with similarity scores

**Response**:
```json
{
  "intent": "semantic",
  "query_type": "product_search",
  "results": [
    {
      "id": 123,
      "name": "Dell Laptop XPS 15",
      "similarity": 0.92,
      "description": "High-performance laptop..."
    },
    {
      "id": 456,
      "name": "HP Pavilion Laptop",
      "similarity": 0.87,
      "description": "Affordable laptop..."
    }
  ],
  "total": 10,
  "execution_time": "0.45s"
}
```

### Example 2: Multi-language Query

**Query**: "Montrez-moi des produits similaires à un ordinateur portable" (French)

**Processing**:
1. Language Detection: French
2. Translation: "Show me products similar to a laptop"
3. Classification: `product_search`
4. Embedding: Generated from English translation
5. Vector Search: Top 10 similar products
6. Results: Products (can be displayed in French)

**Response**: Same as Example 1, but with French labels if needed

### Example 3: Information Retrieval

**Query**: "What are your shipping policies?"

**Processing**:
1. Classification: `info_retrieval`
2. Embedding: Generated for query
3. Vector Search: Search in documents/pages
4. Results: Relevant policy documents

**Response**:
```json
{
  "intent": "semantic",
  "query_type": "info_retrieval",
  "results": [
    {
      "type": "document",
      "title": "Shipping Policy",
      "similarity": 0.95,
      "excerpt": "We offer free shipping on orders over $50..."
    }
  ],
  "total": 3,
  "execution_time": "0.32s"
}
```

## Integration with Other Domains

### With Analytics Domain (Hybrid Queries)
When a query requires both semantic search AND analytics:

**Query**: "Show me reviews for top-selling products"

**Flow**:
1. HybridQueryProcessor splits query
2. Analytics: "top-selling products" → SQL query
3. Semantic: "reviews" → Vector search for reviews
4. Results combined and returned

### With WebSearch Domain (Fallback)
When semantic search finds no results:

**Query**: "Latest trends in AI technology"

**Flow**:
1. Semantic search in internal database → No results
2. Fallback to WebSearch domain
3. External search for AI trends
4. Results returned with source attribution

### With CoreAI Domain (Shared Components)
Semantic domain uses CoreAI components:
- **Embedding**: Vector generation and search
- **Entity**: Entity detection and extraction

## Performance Considerations

### Caching Strategy
- **Query Cache**: Cache query results (TTL: 1 hour)
- **Embedding Cache**: Cache embeddings (TTL: 24 hours)
- **Translation Cache**: Cache translations (TTL: 1 hour)

### Optimization Tips
1. **Batch Embeddings**: Generate embeddings in batches when possible
2. **Index Optimization**: Ensure vector indexes are optimized
3. **Limit Results**: Use reasonable result limits (default: 10)
4. **Similarity Threshold**: Filter low-similarity results early

### Monitoring
- Track embedding generation time
- Monitor vector search latency
- Log cache hit rates
- Alert on high error rates

## Security

### Input Validation
- Validate query length (max: 500 characters)
- Sanitize special characters
- Check for injection attempts
- Rate limit per user

### Data Protection
- Filter results by user permissions
- Mask sensitive data in results
- Log all queries for audit
- Encrypt embeddings at rest

### Competitor Detection
- Detect competitor brand mentions
- Log competitor queries
- Optional blocking or warnings

## Testing

### Unit Tests
```bash
# Test semantic classification
php unit_test/2026_01_17/test_semantic_classification.php

# Test translation handler
php unit_test/2026_01_17/test_translation_handler.php

# Test vector search
php unit_test/2026_01_17/test_vector_search.php
```

### Integration Tests
```bash
# Test full semantic flow
php unit_test/2026_01_17/test_semantic_domain.php

# Test with real queries
php unit_test/2026_01_17/test_semantic_queries.php
```

### Test Cases
1. **Basic Search**: Simple product search
2. **Multi-language**: Query in French, German, Spanish
3. **Empty Results**: Query with no matches
4. **Ambiguous Query**: Query that could be semantic or analytics
5. **Long Query**: Query near character limit
6. **Special Characters**: Query with emojis, symbols

## Troubleshooting

### Common Issues

**Issue**: No results returned
- **Cause**: Similarity threshold too high
- **Solution**: Lower `similarity_threshold` in config

**Issue**: Slow query performance
- **Cause**: Vector index not optimized
- **Solution**: Rebuild vector indexes, check database performance

**Issue**: Translation errors
- **Cause**: Unsupported language or API issues
- **Solution**: Check translation API status, verify language support

**Issue**: Classification errors
- **Cause**: Ambiguous query or LLM issues
- **Solution**: Improve query clarity, check LLM API status

### Debug Mode

Enable debug logging:
```php
define('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER', 'True');
```

Check logs:
```bash
tail -f Core/ClicShopping/Work/Log/semantic_*.log
```

## Related Documentation

- **Main Domains README**: [../README.md](../README.md)
- **CoreAI Domain**: [../CoreAI/README.md](../CoreAI/README.md)
- **Analytics Domain**: [../Analytics/README.md](../Analytics/README.md)
- **Hybrid Domain**: [../Hybrid/README.md](../Hybrid/README.md)
- **QueryTypeDomainInterface**: `../../Interfaces/QueryTypeDomainInterface.php`

## Future Enhancements

- [ ] Support for image embeddings (visual search)
- [ ] Multi-modal search (text + image)
- [ ] Personalized semantic search (user preferences)
- [ ] Real-time embedding updates
- [ ] Advanced query expansion
- [ ] Semantic query suggestions

---

**Status**: Active  
**Created**: January 17, 2026  
**Last Updated**: January 17, 2026  
**Owner**: Development Team
