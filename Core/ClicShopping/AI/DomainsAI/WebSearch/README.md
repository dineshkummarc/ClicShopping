# WebSearch Domain

## Purpose

The WebSearch Domain handles **queries that require external web search** when internal data is insufficient or when users explicitly request external information. It interfaces with external search APIs, caches results, and integrates external data with internal knowledge.

## Use Cases

- **Market Research**: "What are current market trends for laptops?"
- **Competitor Analysis**: "Find competitor pricing for product X"
- **External Information**: "What are the latest AI technology developments?"
- **Product Research**: "Show me reviews of product Y from external sources"
- **Trend Analysis**: "What are popular products in category Z?"
- **Fallback Search**: When internal search returns no results

## Query Flow

```
User Query: "What are current market trends for laptops?"
    ↓
OrchestratorAgent → Routes to WebSearch Domain
    ↓
WebSearchAgent.processQuery()
    ↓
SearchCacheManager.check()
    - Check if query was recently searched
    - Return cached results if available and fresh
    ↓
WebSearchTool.search()
    - Constructs search query
    - Calls external search API (Google, Bing, etc.)
    - Retrieves search results
    - Extracts relevant information
    ↓
ContentExtractor.extract()
    - Extracts content from search results
    - Filters irrelevant information
    - Summarizes key findings
    ↓
WebSearchLogger.log()
    - Logs search query and results
    - Tracks API usage
    - Records user interactions
    ↓
SearchCacheManager.store()
    - Caches results with TTL
    - Stores metadata (timestamp, source, etc.)
    ↓
WebSearchHandler.format()
    - Formats results for display
    - Adds source attribution
    - Includes confidence scores
    ↓
Formatted Response with External Data
```

## Key Components

### Tool/

**WebSearchTool.php** - Interfaces with external search APIs
- Supports multiple search providers (Google, Bing, DuckDuckGo)
- Constructs optimized search queries
- Handles API authentication and rate limiting
- Parses and normalizes search results
- Extracts structured data from results

**Key Methods**:
```php
public function search(string $query, array $options = []): array
public function searchWithProvider(string $provider, string $query): array
public function extractContent(string $url): string
public function summarizeResults(array $results): string
```

### Executor/

**WebSearchQueryExecutor.php** - Executes web search queries
- Manages search execution lifecycle
- Handles retries and fallbacks
- Implements circuit breaker pattern
- Aggregates results from multiple sources
- Validates and filters results

**Key Methods**:
```php
public function execute(string $query, array $context = []): array
public function executeMultiSource(string $query, array $sources): array
public function validateResults(array $results): array
public function rankResults(array $results): array
```

### Cache/

**SearchCacheManager.php** - Caches web search results
- Stores search results with configurable TTL
- Implements cache invalidation strategies
- Tracks cache hit rates
- Manages cache size and cleanup
- Supports cache warming for popular queries

**Key Methods**:
```php
public function store(string $query, array $results, int $ttl = 3600): void
public function retrieve(string $query): ?array
public function invalidate(string $query): void
public function warmCache(array $queries): void
public function getStats(): array
```

### Logger/

**WebSearchLogger.php** - Logs web search activities
- Logs all search queries and results
- Tracks API usage and costs
- Records response times
- Monitors error rates
- Generates usage reports

**Key Methods**:
```php
public function logSearch(string $query, array $results, float $duration): void
public function logError(string $query, string $error): void
public function getUsageStats(string $period = 'day'): array
public function generateReport(string $startDate, string $endDate): array
```

### Handler/

**WebSearchHandler.php** - Handles web search fallback logic
- Determines when to use web search
- Implements fallback strategies
- Combines internal and external results
- Manages result prioritization
- Handles search failures gracefully

**Key Methods**:
```php
public function shouldUseWebSearch(string $query, array $internalResults): bool
public function combineResults(array $internal, array $external): array
public function prioritizeResults(array $results): array
public function handleFailure(string $query, string $error): array
```

### Agent/

**WebSearchAgent.php** - Entry point for web search queries (future)
- Implements `QueryTypeDomainInterface`
- Coordinates web search operations
- Manages search providers
- Provides metrics and monitoring

## Configuration

WebSearch domain configuration in `chat_system_config.json`:

```json
{
  "WebSearch": {
    "enabled": true,
    "default_provider": "google",
    "providers": {
      "google": {
        "api_key": "YOUR_API_KEY",
        "cx": "YOUR_SEARCH_ENGINE_ID",
        "max_results": 10
      },
      "bing": {
        "api_key": "YOUR_API_KEY",
        "max_results": 10
      }
    },
    "cache_ttl": 86400,
    "max_retries": 3,
    "timeout": 10,
    "enable_content_extraction": true,
    "enable_summarization": true,
    "fallback_enabled": true,
    "rate_limit": {
      "requests_per_minute": 60,
      "requests_per_day": 1000
    }
  }
}
```

**Parameters**:
- `enabled`: Enable/disable web search functionality
- `default_provider`: Default search provider
- `providers`: Configuration for each search provider
- `cache_ttl`: Cache lifetime (seconds, default: 24 hours)
- `max_retries`: Maximum retry attempts
- `timeout`: Request timeout (seconds)
- `enable_content_extraction`: Extract content from result URLs
- `enable_summarization`: Summarize search results using LLM
- `fallback_enabled`: Enable fallback to web search
- `rate_limit`: API rate limiting configuration

## Examples

### Example 1: Market Trends

**Query**: "What are current market trends for laptops?"

**Search Execution**:
1. Check cache → Miss
2. Search Google: "laptop market trends 2026"
3. Extract top 10 results
4. Summarize findings using LLM
5. Cache results (TTL: 24 hours)

**Response**:
```json
{
  "intent": "websearch",
  "query": "What are current market trends for laptops?",
  "results": [
    {
      "title": "2026 Laptop Market Trends Report",
      "url": "https://example.com/laptop-trends-2026",
      "snippet": "AI-powered laptops dominate the market with 45% growth...",
      "source": "TechAnalysis",
      "published_date": "2026-01-15",
      "relevance_score": 0.95
    },
    // ... more results
  ],
  "summary": "Current laptop market trends show strong growth in AI-powered devices, with emphasis on battery life and portability. Gaming laptops continue to grow at 20% annually.",
  "sources_count": 10,
  "cache_hit": false,
  "execution_time": "2.3s"
}
```

### Example 2: Competitor Pricing

**Query**: "Find competitor pricing for Dell XPS 15"

**Search Execution**:
1. Check cache → Miss
2. Multi-source search:
   - Google Shopping
   - Price comparison sites
   - Competitor websites
3. Extract pricing data
4. Aggregate and compare
5. Cache results

**Response**:
```json
{
  "intent": "websearch",
  "query": "Find competitor pricing for Dell XPS 15",
  "results": [
    {
      "retailer": "Amazon",
      "price": 1499.99,
      "url": "https://amazon.com/...",
      "availability": "In Stock",
      "shipping": "Free"
    },
    {
      "retailer": "Best Buy",
      "price": 1549.99,
      "url": "https://bestbuy.com/...",
      "availability": "In Stock",
      "shipping": "Free"
    },
    // ... more retailers
  ],
  "price_analysis": {
    "min_price": 1449.99,
    "max_price": 1649.99,
    "avg_price": 1532.50,
    "our_price": 1499.99,
    "position": "competitive"
  },
  "execution_time": "3.1s"
}
```

### Example 3: External Product Reviews

**Query**: "Show me external reviews of iPhone 15"

**Search Execution**:
1. Search for "iPhone 15 reviews"
2. Filter for review sites (CNET, TechRadar, etc.)
3. Extract review content
4. Analyze sentiment
5. Summarize findings

**Response**:
```json
{
  "intent": "websearch",
  "query": "Show me external reviews of iPhone 15",
  "results": [
    {
      "source": "CNET",
      "rating": 4.5,
      "url": "https://cnet.com/...",
      "summary": "Excellent camera and performance, but battery life could be better",
      "pros": ["Camera quality", "Performance", "Design"],
      "cons": ["Battery life", "Price"],
      "published_date": "2025-09-20"
    },
    // ... more reviews
  ],
  "aggregate_rating": 4.3,
  "total_reviews": 15,
  "sentiment": {
    "positive": 75,
    "neutral": 15,
    "negative": 10
  },
  "execution_time": "4.2s"
}
```

## Search Providers

### Google Custom Search
- **Pros**: High-quality results, extensive coverage
- **Cons**: Requires API key, limited free tier
- **Use Case**: General web search, market research

### Bing Search API
- **Pros**: Good results, competitive pricing
- **Cons**: Requires API key
- **Use Case**: Alternative to Google, specific markets

### DuckDuckGo
- **Pros**: Privacy-focused, no API key required
- **Cons**: Limited features, no official API
- **Use Case**: Privacy-conscious searches, fallback

### Custom Scrapers
- **Pros**: Targeted data extraction
- **Cons**: Maintenance overhead, legal considerations
- **Use Case**: Specific websites, structured data

## Fallback Strategy

### When to Use Web Search

1. **No Internal Results**: Internal search returns empty
2. **Explicit Request**: User explicitly asks for external info
3. **Outdated Data**: Internal data is stale
4. **External Context**: Query requires external knowledge
5. **Competitor Info**: Query about competitors

### Fallback Flow

```
Internal Search
    ↓
No Results or Low Confidence
    ↓
Check if Web Search Appropriate
    ↓
Execute Web Search
    ↓
Combine Internal + External Results
    ↓
Return Unified Response
```

### Result Prioritization

1. **Internal Results**: Prioritize internal data (authoritative)
2. **External Results**: Add external context
3. **Source Attribution**: Clearly mark external sources
4. **Confidence Scores**: Indicate result reliability

## Performance Optimization

### Caching Strategy
- **Long TTL**: Cache stable data (24 hours)
- **Short TTL**: Cache volatile data (1 hour)
- **Cache Warming**: Pre-cache popular queries
- **Selective Caching**: Cache only successful results

### Rate Limiting
- **Per-User Limits**: Prevent abuse
- **Global Limits**: Stay within API quotas
- **Adaptive Throttling**: Adjust based on usage
- **Queue Management**: Queue requests during high load

### Content Extraction
- **Lazy Loading**: Extract content only when needed
- **Parallel Extraction**: Extract from multiple URLs simultaneously
- **Timeout Handling**: Skip slow-loading pages
- **Content Caching**: Cache extracted content

## Security & Privacy

### API Key Management
- Store API keys securely (environment variables)
- Rotate keys regularly
- Monitor API usage for anomalies
- Implement key-specific rate limits

### User Privacy
- Don't log personally identifiable information
- Anonymize search queries in logs
- Comply with GDPR and privacy regulations
- Provide opt-out mechanisms

### Content Filtering
- Filter inappropriate content
- Validate external URLs
- Sanitize extracted content
- Prevent XSS and injection attacks

### Source Verification
- Verify source authenticity
- Check SSL certificates
- Validate content integrity
- Flag suspicious sources

## Integration with Other Domains

### As Fallback for Semantic Domain
When semantic search finds no results:
1. Semantic search → No results
2. Fallback to WebSearch
3. Return external results with attribution

### Enriching Analytics Results
Combine internal analytics with external market data:
1. Analytics: Internal sales data
2. WebSearch: External market trends
3. Compare and provide insights

### Supporting Hybrid Queries
Provide external context for hybrid queries:
1. Hybrid: Internal product + reviews
2. WebSearch: External reviews and comparisons
3. Combine for comprehensive view

## Testing

### Unit Tests
```bash
# Test search providers
php unit_test/2026_01_17/test_search_providers.php

# Test cache manager
php unit_test/2026_01_17/test_search_cache.php

# Test content extraction
php unit_test/2026_01_17/test_content_extraction.php
```

### Integration Tests
```bash
# Test full websearch flow
php unit_test/2026_01_17/test_websearch_domain.php

# Test fallback logic
php unit_test/2026_01_17/test_websearch_fallback.php
```

### Test Cases
1. **Basic Search**: Simple web search query
2. **Multi-Provider**: Search across multiple providers
3. **Cache Hit/Miss**: Test caching effectiveness
4. **Fallback**: Test fallback from internal search
5. **Rate Limiting**: Test rate limit enforcement
6. **Error Handling**: API failures, timeouts
7. **Content Extraction**: Extract and summarize content

## Troubleshooting

### Common Issues

**Issue**: No search results
- **Cause**: API key invalid or quota exceeded
- **Solution**: Check API key, verify quota, try alternative provider

**Issue**: Slow search performance
- **Cause**: Content extraction taking too long
- **Solution**: Disable content extraction, increase timeout, use cache

**Issue**: Irrelevant results
- **Cause**: Poor query construction
- **Solution**: Improve query formulation, add filters, refine keywords

**Issue**: Rate limit exceeded
- **Cause**: Too many requests
- **Solution**: Implement request queuing, increase cache TTL, upgrade API plan

### Debug Mode

Enable debug logging:
```php
define('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER', 'True');
```

Check logs:
```bash
tail -f Core/ClicShopping/Work/Log/websearch_*.log
```

## Related Documentation

- **Main Domains README**: [../README.md](../README.md)
- **Semantic Domain**: [../Semantic/README.md](../Semantic/README.md)
- **Analytics Domain**: [../Analytics/README.md](../Analytics/README.md)
- **Hybrid Domain**: [../Hybrid/README.md](../Hybrid/README.md)
- **QueryTypeDomainInterface**: `../../Interfaces/QueryTypeDomainInterface.php`

## Future Enhancements

- [ ] Support for more search providers (Brave, Yandex)
- [ ] Advanced content extraction (images, videos)
- [ ] Real-time search result streaming
- [ ] Machine learning for result ranking
- [ ] Automatic query refinement
- [ ] Multi-language search support
- [ ] Visual search capabilities

---

**Status**: Active  
**Created**: January 17, 2026  
**Last Updated**: January 17, 2026  
**Owner**: Development Team
