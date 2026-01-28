# CoreAI Domain

## Purpose

The CoreAI Domain provides **cross-query-type functionality** used by all other domains. It contains shared components for embeddings, entity management, and common utilities that are not specific to any single query type (Semantic, Analytics, Hybrid, or WebSearch).

## Use Cases

- **Vector Embeddings**: Generate and manage embeddings for all domains
- **Entity Management**: Detect, extract, and manage entities across domains
- **Similarity Search**: Perform vector similarity searches
- **Entity Registry**: Maintain registry of all entity types
- **Common Utilities**: Shared helper functions and utilities

## Architecture

CoreAI is a **foundational domain** that other domains depend on:

```
┌─────────────────────────────────────────┐
│           Query Type Domains            │
├─────────────┬──────────────┬────────────┤
│  Semantic   │  Analytics   │   Hybrid   │
│             │              │            │
│  WebSearch  │              │            │
└─────────────┴──────────────┴────────────┘
              ↓       ↓       ↓
┌─────────────────────────────────────────┐
│            CoreAI Domain                │
├─────────────┬──────────────┬────────────┤
│  Embedding  │   Entity     │  Utilities │
└─────────────┴──────────────┴────────────┘
```

## Key Components

### Embedding/

Provides vector embedding functionality for semantic search and similarity matching.

**NewVector.php** - Generates and manages vector embeddings
- Generates embeddings using OpenAI API
- Supports multiple embedding models
- Caches embeddings for reuse
- Handles batch embedding generation
- Manages embedding dimensions and normalization

**Key Methods**:
```php
public function generateEmbedding(string $text, string $model = 'text-embedding-3-small'): array
public function generateBatchEmbeddings(array $texts, string $model = 'text-embedding-3-small'): array
public function normalizeEmbedding(array $embedding): array
public function getEmbeddingDimensions(string $model): int
```

**EmbeddingSearch.php** - Performs vector similarity searches
- Executes cosine similarity searches
- Supports multiple distance metrics (cosine, euclidean, dot product)
- Filters results by entity type and metadata
- Implements efficient vector indexing
- Provides similarity scoring and ranking

**Key Methods**:
```php
public function search(array $queryEmbedding, array $options = []): array
public function cosineSimilarity(array $vec1, array $vec2): float
public function euclideanDistance(array $vec1, array $vec2): float
public function dotProduct(array $vec1, array $vec2): float
public function rankResults(array $results, string $metric = 'cosine'): array
```

**VectorStatistics.php** - Provides statistics and analytics for embeddings
- Calculates embedding statistics (mean, variance, etc.)
- Analyzes embedding quality
- Detects outliers and anomalies
- Generates embedding reports
- Monitors embedding performance

**Key Methods**:
```php
public function calculateStatistics(array $embeddings): array
public function analyzeQuality(array $embedding): array
public function detectOutliers(array $embeddings, float $threshold = 2.0): array
public function generateReport(array $embeddings): array
```

**VectorType.php** - Defines vector types and configurations
- Defines embedding model configurations
- Manages vector dimensions
- Provides type-safe vector operations
- Validates vector formats

### Entity/

Provides entity detection, extraction, and management functionality.

**EntityHelper.php** - Helper functions for entity operations
- Detects entities in text using LLM
- Extracts entity attributes
- Validates entity data
- Formats entity information
- Provides entity type detection

**Key Methods**:
```php
public function detectEntities(string $text): array
public function extractAttributes(string $text, string $entityType): array
public function validateEntity(array $entity): bool
public function formatEntity(array $entity): array
public function getEntityType(string $text): string
```

**EntityRegistry.php** - Maintains registry of all entity types
- Registers entity types and their schemas
- Provides entity type lookup
- Manages entity relationships
- Validates entity configurations
- Supports dynamic entity registration

**Key Methods**:
```php
public function registerEntity(string $type, array $schema): void
public function getEntity(string $type): ?array
public function getAllEntities(): array
public function validateSchema(array $schema): bool
public function getEntityRelationships(string $type): array
```

**Entity Types**:
```php
[
  'product' => [
    'table' => 'products',
    'id_field' => 'products_id',
    'name_field' => 'products_name',
    'description_field' => 'products_description',
    'embedding_fields' => ['products_name', 'products_description'],
    'relationships' => ['category', 'manufacturer', 'review']
  ],
  'category' => [
    'table' => 'categories',
    'id_field' => 'categories_id',
    'name_field' => 'categories_name',
    'description_field' => 'categories_description',
    'embedding_fields' => ['categories_name', 'categories_description'],
    'relationships' => ['product', 'parent_category']
  ],
  'customer' => [
    'table' => 'customers',
    'id_field' => 'customers_id',
    'name_field' => 'customers_name',
    'embedding_fields' => ['customers_name'],
    'relationships' => ['order', 'review']
  ],
  // ... more entity types
]
```

**EntityIdExtractor.php** - Extracts entity IDs from text and context
- Extracts entity IDs from natural language
- Resolves entity references
- Handles ambiguous entity mentions
- Validates extracted IDs
- Provides confidence scores

**Key Methods**:
```php
public function extractIds(string $text, string $entityType): array
public function resolveReference(string $reference, string $entityType): ?int
public function validateId(int $id, string $entityType): bool
public function getConfidenceScore(string $text, int $id): float
```

## Usage Examples

### Example 1: Generate Embeddings (Semantic Domain)

```php
use ClicShopping\AI\DomainsAI\CoreAI\Embedding\NewVector;

$vector = new NewVector();

// Generate single embedding
$text = "High-performance laptop with 16GB RAM";
$embedding = $vector->generateEmbedding($text);
// Returns: [0.123, -0.456, 0.789, ...] (1536 dimensions)

// Generate batch embeddings
$texts = [
  "Laptop with 16GB RAM",
  "Gaming desktop computer",
  "Wireless mouse"
];
$embeddings = $vector->generateBatchEmbeddings($texts);
// Returns: [[...], [...], [...]]
```

### Example 2: Search Similar Vectors (Semantic Domain)

```php
use ClicShopping\AI\DomainsAI\CoreAI\Embedding\EmbeddingSearch;

$search = new EmbeddingSearch();

// Search for similar products
$queryEmbedding = [0.123, -0.456, 0.789, ...];
$results = $search->search($queryEmbedding, [
  'entity_type' => 'product',
  'limit' => 10,
  'threshold' => 0.7,
  'metric' => 'cosine'
]);

// Returns:
[
  [
    'id' => 123,
    'name' => 'Dell Laptop XPS 15',
    'similarity' => 0.92,
    'embedding' => [...]
  ],
  // ... more results
]
```

### Example 3: Detect Entities (All Domains)

```php
use ClicShopping\AI\DomainsAI\CoreAI\Entity\EntityHelper;

$helper = new EntityHelper();

// Detect entities in query
$text = "Show me laptops from Dell under $1500";
$entities = $helper->detectEntities($text);

// Returns:
[
  [
    'type' => 'product',
    'value' => 'laptops',
    'confidence' => 0.95
  ],
  [
    'type' => 'manufacturer',
    'value' => 'Dell',
    'confidence' => 0.98
  ],
  [
    'type' => 'price',
    'value' => 1500,
    'confidence' => 0.99
  ]
]
```

### Example 4: Entity Registry (All Domains)

```php
use ClicShopping\AI\DomainsAI\CoreAI\Entity\EntityRegistry;

$registry = EntityRegistry::getInstance();

// Get entity configuration
$productEntity = $registry->getEntity('product');

// Returns:
[
  'table' => 'products',
  'id_field' => 'products_id',
  'name_field' => 'products_name',
  'description_field' => 'products_description',
  'embedding_fields' => ['products_name', 'products_description'],
  'relationships' => ['category', 'manufacturer', 'review']
]

// Get all registered entities
$allEntities = $registry->getAllEntities();
// Returns: ['product', 'category', 'customer', 'order', ...]
```

### Example 5: Extract Entity IDs (Analytics Domain)

```php
use ClicShopping\AI\DomainsAI\CoreAI\Entity\EntityIdExtractor;

$extractor = new EntityIdExtractor();

// Extract product IDs from text
$text = "Show me sales for iPhone 15 and Samsung Galaxy S24";
$productIds = $extractor->extractIds($text, 'product');

// Returns:
[
  [
    'id' => 123,
    'name' => 'iPhone 15',
    'confidence' => 0.95
  ],
  [
    'id' => 456,
    'name' => 'Samsung Galaxy S24',
    'confidence' => 0.92
  ]
]
```

## Integration with Query Type Domains

### Semantic Domain
**Uses**:
- `NewVector`: Generate query embeddings
- `EmbeddingSearch`: Search similar products/content
- `EntityHelper`: Detect entities in queries
- `EntityRegistry`: Get entity configurations

**Example Flow**:
```
User Query → SemanticAgent
    ↓
NewVector.generateEmbedding(query)
    ↓
EmbeddingSearch.search(embedding)
    ↓
Results with similarity scores
```

### Analytics Domain
**Uses**:
- `EntityHelper`: Detect entities for SQL generation
- `EntityRegistry`: Get table/column mappings
- `EntityIdExtractor`: Extract entity IDs from queries

**Example Flow**:
```
User Query → AnalyticsAgent
    ↓
EntityHelper.detectEntities(query)
    ↓
EntityRegistry.getEntity(type)
    ↓
Generate SQL with correct tables/columns
```

### Hybrid Domain
**Uses**:
- All CoreAI components for both semantic and analytics parts
- `EntityHelper`: Coordinate entity detection across components
- `EntityRegistry`: Maintain consistent entity definitions

### WebSearch Domain
**Uses**:
- `EntityHelper`: Extract entities for search query construction
- `NewVector`: Generate embeddings for external content (optional)

## Configuration

CoreAI domain configuration in `chat_system_config.json`:

```json
{
  "CoreAI": {
    "embedding": {
      "default_model": "text-embedding-3-small",
      "dimensions": 1536,
      "batch_size": 100,
      "cache_ttl": 86400,
      "normalize": true
    },
    "entity": {
      "detection_threshold": 0.7,
      "max_entities_per_query": 10,
      "enable_caching": true,
      "cache_ttl": 3600
    },
    "vector_search": {
      "default_metric": "cosine",
      "default_limit": 10,
      "similarity_threshold": 0.7,
      "enable_filtering": true
    }
  }
}
```

## Performance Considerations

### Embedding Generation
- **Batch Processing**: Generate embeddings in batches (100 texts)
- **Caching**: Cache embeddings for 24 hours
- **Model Selection**: Use smaller models for faster generation
- **Async Generation**: Generate embeddings asynchronously when possible

### Vector Search
- **Indexing**: Use vector indexes (HNSW, IVF) for fast search
- **Filtering**: Apply filters before similarity calculation
- **Limit Results**: Use reasonable result limits (default: 10)
- **Threshold**: Filter low-similarity results early

### Entity Detection
- **Caching**: Cache entity detection results
- **Batch Processing**: Detect entities in batches
- **Lazy Loading**: Load entity details only when needed
- **Registry Caching**: Cache entity registry in memory

## Testing

### Unit Tests
```bash
# Test embedding generation
php unit_test/2026_01_17/test_embedding_generation.php

# Test vector search
php unit_test/2026_01_17/test_vector_search.php

# Test entity detection
php unit_test/2026_01_17/test_entity_detection.php

# Test entity registry
php unit_test/2026_01_17/test_entity_registry.php
```

### Integration Tests
```bash
# Test CoreAI with Semantic domain
php unit_test/2026_01_17/test_coreai_semantic.php

# Test CoreAI with Analytics domain
php unit_test/2026_01_17/test_coreai_analytics.php
```

### Test Cases
1. **Embedding Generation**: Single and batch generation
2. **Vector Search**: Cosine, euclidean, dot product
3. **Entity Detection**: Various entity types
4. **Entity Registry**: Registration and lookup
5. **Entity ID Extraction**: From natural language
6. **Performance**: Batch processing, caching
7. **Error Handling**: API failures, invalid inputs

## Extending CoreAI

### Adding New Entity Types

1. **Define Entity Schema**:
```php
$schema = [
  'table' => 'new_entity_table',
  'id_field' => 'entity_id',
  'name_field' => 'entity_name',
  'description_field' => 'entity_description',
  'embedding_fields' => ['entity_name', 'entity_description'],
  'relationships' => ['related_entity1', 'related_entity2']
];
```

2. **Register Entity**:
```php
$registry = EntityRegistry::getInstance();
$registry->registerEntity('new_entity', $schema);
```

3. **Update Entity Helper**:
Add detection logic for new entity type in `EntityHelper.php`

4. **Test**:
Create tests for new entity type

### Adding New Embedding Models

1. **Update VectorType.php**:
```php
public const MODELS = [
  'text-embedding-3-small' => ['dimensions' => 1536],
  'text-embedding-3-large' => ['dimensions' => 3072],
  'new-model' => ['dimensions' => 2048]  // Add new model
];
```

2. **Update NewVector.php**:
Add model-specific logic if needed

3. **Update Configuration**:
Add model to config file

4. **Test**:
Test embedding generation with new model

## Troubleshooting

### Common Issues

**Issue**: Embedding generation fails
- **Cause**: API key invalid or quota exceeded
- **Solution**: Check API key, verify quota, use cache

**Issue**: Vector search returns no results
- **Cause**: Similarity threshold too high
- **Solution**: Lower threshold, check embedding quality

**Issue**: Entity detection inaccurate
- **Cause**: Ambiguous text or low confidence
- **Solution**: Improve query clarity, adjust threshold

**Issue**: Entity registry empty
- **Cause**: Entities not registered
- **Solution**: Register entities on initialization

### Debug Mode

Enable debug logging:
```php
define('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER', 'True');
```

Check logs:
```bash
tail -f Core/ClicShopping/Work/Log/coreai_*.log
```

## Related Documentation

- **Main Domains README**: [../README.md](../README.md)
- **Semantic Domain**: [../Semantic/README.md](../Semantic/README.md)
- **Analytics Domain**: [../Analytics/README.md](../Analytics/README.md)
- **Hybrid Domain**: [../Hybrid/README.md](../Hybrid/README.md)
- **WebSearch Domain**: [../WebSearch/README.md](../WebSearch/README.md)

## Future Enhancements

- [ ] Support for more embedding models (Cohere, Anthropic)
- [ ] Multi-modal embeddings (text + image)
- [ ] Advanced entity relationship mapping
- [ ] Real-time entity registry updates
- [ ] Distributed vector search
- [ ] Entity disambiguation using knowledge graphs
- [ ] Automatic entity schema inference

---

**Status**: Active  
**Created**: January 17, 2026  
**Last Updated**: January 17, 2026  
**Owner**: Development Team
