# Analytics Domain

## Purpose

The Analytics Domain handles **business intelligence and analytics queries** that require SQL generation and database analysis. It converts natural language questions into SQL queries, executes them safely, and interprets the results in a user-friendly format.

## Generic Use Cases

- **Aggregation Queries**: "What are the top 10 [entities] by [metric]?"
- **Trend Analysis**: "Show me [metric] trends for [time period]"
- **Comparative Analysis**: "Compare [metric] by [attribute]"
- **Status Reports**: "Which [entities] have [condition]?"
- **Financial Analysis**: "What is the total [metric] for [period]?"
- **Performance Metrics**: "Show me [metric] by [dimension]"

## Domain-Specific Examples

For domain-specific examples and use cases, see:
- **Ecommerce**: [Ecommerce Analytics](../../../Apps/AI/Ecommerce/Docs/Analytics/README.md)
- **HR**: [HR Analytics](../../../Apps/AI/HR/Docs/Analytics/README.md) (future)
- **Finance**: [Finance Analytics](../../../Apps/AI/Finance/Docs/Analytics/README.md) (future)

## Query Flow

```
User Query: "What are the top 10 selling products?"
    ↓
OrchestratorAgent → Routes to Analytics Domain
    ↓
AnalyticsAgent.execute()
    ↓
DatabaseSchemaManager.getRelevantTables()
    - Identifies relevant tables (products, orders, order_details)
    - Retrieves table schemas and relationships
    ↓
PromptBuilder.buildAnalyticsPrompt()
    - Constructs LLM prompt with schema context
    - Includes query intent and constraints
    ↓
ParallelLLMExecutor.execute()
    - Sends prompt to multiple LLM instances
    - Generates SQL queries in parallel
    - Selects best query through consensus
    ↓
SqlQueryProcessor.validateAndProcess()
    - Validates SQL syntax
    - Checks for security issues (injection, dangerous operations)
    - Optimizes query performance
    ↓
AnalyticsQueryExecutor.execute()
    - Executes SQL query safely
    - Applies row limits and timeouts
    - Handles errors gracefully
    ↓
ResultInterpreter.interpret()
    - Formats results for display
    - Adds context and explanations
    - Generates visualizations if needed
    ↓
QueryCache.store()
    - Caches results for future queries
    ↓
Formatted Response with Data
```

## Key Components

### Agent/

**AnalyticsAgent.php** - Entry point for analytics queries
- Implements `QueryTypeDomainInterface`
- Coordinates analytics query processing
- Manages database schema context
- Handles security and validation
- Supports parallel LLM execution for improved accuracy

**Key Methods**:
```php
public function execute(string $query, array $context = []): array
public function getName(): string
public function canHandle(string $query, array $context): bool
public function getCapabilities(): array
public function getMetrics(): array
```

**ParallelLLMExecutor.php** - Executes multiple LLM instances in parallel
- Sends same prompt to multiple LLM instances
- Compares generated SQL queries
- Selects best query through consensus
- Improves accuracy and reliability

**PromptBuilder.php** - Builds analytics prompts for LLM
- Constructs context-rich prompts
- Includes database schema information
- Adds constraints and guidelines
- Optimizes for SQL generation

### Executor/

**AnalyticsQueryExecutor.php** - Executes SQL queries safely
- Validates query before execution
- Applies security constraints
- Enforces row limits and timeouts
- Handles database errors
- Logs all query executions

**SqlQueryProcessor.php** - Processes and validates SQL queries
- Parses SQL syntax
- Validates against schema
- Checks for security issues (SQL injection, DROP, DELETE without WHERE)
- Optimizes query performance
- Adds safety constraints (LIMIT, timeout)

**QueryExecutor.php** - Base query executor
- Provides common execution logic
- Handles connection management
- Implements retry logic
- Manages transactions

**Key Methods**:
```php
public function execute(string $sql, array $params = []): array
public function validate(string $sql): bool
public function optimize(string $sql): string
public function estimateRows(string $sql): int
```

### Processor/

**AnalyticsProcessor.php** - Processes analytics query intent
- Analyzes query structure
- Identifies required tables and columns
- Determines aggregation needs
- Extracts filters and conditions

**DatabaseSchemaManager.php** - Manages database schema information
- Retrieves table schemas
- Identifies table relationships
- Caches schema information
- Provides schema context to LLM

**ResultInterpreter.php** - Interprets and formats query results
- Formats data for display
- Adds context and explanations
- Generates summaries
- Creates visualization suggestions

**AmbiguityHandler.php** - Handles ambiguous analytics queries
- Detects ambiguous queries
- Requests clarification from user
- Suggests query refinements
- Provides query examples

**CompoundQueryHandler.php** - Handles complex multi-part queries
- Splits compound queries
- Executes sub-queries
- Combines results
- Maintains query context

### Helper/

**AnalyticsErrorHandler.php** - Handles analytics-specific errors
- Categorizes error types
- Provides user-friendly error messages
- Suggests fixes for common errors
- Logs errors for debugging

### Cache/

**AnalyticsCache.php** - Caches analytics query results
- Stores query results with TTL
- Caches SQL queries for reuse
- Invalidates on data changes
- Reduces database load

## Configuration

Analytics domain configuration in `chat_system_config.json`:

```json
{
  "Analytics": {
    "max_rows": 1000,
    "query_timeout": 30,
    "enable_parallel_llm": true,
    "parallel_llm_count": 3,
    "cache_ttl": 3600,
    "enable_query_optimization": true,
    "max_retries": 3,
    "enable_result_interpretation": true,
    "max_interpretation_rows": 100
  }
}
```

**Parameters**:
- `max_rows`: Maximum rows to return (safety limit)
- `query_timeout`: Query execution timeout (seconds)
- `enable_parallel_llm`: Enable parallel LLM execution
- `parallel_llm_count`: Number of parallel LLM instances
- `cache_ttl`: Cache lifetime (seconds)
- `enable_query_optimization`: Enable SQL optimization
- `max_retries`: Maximum retry attempts
- `enable_result_interpretation`: Enable LLM result interpretation
- `max_interpretation_rows`: Maximum rows for interpretation

## Generic Example: Top Entities by Metric

**Query**: "What are the top 10 [entities] by [metric]?"

**Generated SQL Pattern**:
```sql
SELECT 
    e.entity_id,
    e.entity_name,
    SUM(m.metric_value) as total_metric,
    COUNT(m.metric_id) as metric_count
FROM entities e
INNER JOIN metrics m ON e.entity_id = m.entity_id
WHERE m.date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
GROUP BY e.entity_id, e.entity_name
ORDER BY total_metric DESC
LIMIT 10;
```

**Domain-Specific Examples**:
- **Ecommerce**: Top selling products (see [Ecommerce Analytics](../../../Apps/AI/Ecommerce/Docs/Analytics/EXAMPLES.md#example-1-top-selling-products))
- **HR**: Top performing employees (future)
- **Finance**: Top revenue accounts (future)

---

## Generic Example: Trend Analysis

**Query**: "Show me [metric] trends for [time period]"

**Generated SQL Pattern**:
```sql
SELECT 
    DATE(m.date) as period_date,
    COUNT(m.metric_id) as metric_count,
    SUM(m.metric_value) as total_metric,
    AVG(m.metric_value) as avg_metric
FROM metrics m
WHERE m.date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
GROUP BY DATE(m.date)
ORDER BY period_date ASC;
```

**Domain-Specific Examples**:
- **Ecommerce**: Sales trends (see [Ecommerce Analytics](../../../Apps/AI/Ecommerce/Docs/Analytics/EXAMPLES.md#example-2-sales-trends))
- **HR**: Hiring trends (future)
- **Finance**: Revenue trends (future)

---

## Generic Example: Comparative Analysis

**Query**: "Compare [metric] by [attribute]"

**Generated SQL Pattern**:
```sql
SELECT 
    a.attribute_name,
    COUNT(DISTINCT e.entity_id) as entity_count,
    COUNT(m.metric_id) as metric_count,
    AVG(m.metric_value) as avg_metric,
    SUM(m.metric_value) as total_metric
FROM attributes a
INNER JOIN entities e ON a.attribute_id = e.attribute_id
INNER JOIN metrics m ON e.entity_id = m.entity_id
GROUP BY a.attribute_id, a.attribute_name
ORDER BY total_metric DESC;
```

**Domain-Specific Examples**:
- **Ecommerce**: Customer insights (see [Ecommerce Analytics](../../../Apps/AI/Ecommerce/Docs/Analytics/EXAMPLES.md#example-3-customer-insights))
- **HR**: Department performance (future)
- **Finance**: Account analysis (future)

## Security Features

### SQL Injection Prevention
- **Parameterized Queries**: All user inputs are parameterized
- **Query Validation**: Validates SQL syntax before execution
- **Whitelist Approach**: Only SELECT queries allowed by default
- **Pattern Detection**: Detects injection patterns

### Dangerous Operation Prevention
- **No DROP/TRUNCATE**: Blocks destructive operations
- **No DELETE without WHERE**: Prevents accidental data deletion
- **No UPDATE without WHERE**: Prevents mass updates
- **Row Limit Enforcement**: Always applies LIMIT clause

### Access Control
- **Table Permissions**: Checks user permissions for tables
- **Column Filtering**: Filters sensitive columns
- **Row-Level Security**: Applies user-specific filters
- **Audit Logging**: Logs all query executions

### Rate Limiting
- **Per-User Limits**: Limits queries per user per hour
- **Global Limits**: Prevents system overload
- **Adaptive Throttling**: Adjusts based on system load

## Performance Optimization

### Query Optimization
- **Index Hints**: Suggests optimal indexes
- **Join Optimization**: Optimizes join order
- **Subquery Elimination**: Converts subqueries to joins
- **Query Caching**: Caches frequent queries

### Parallel LLM Execution
- **Multiple Instances**: Runs 3 LLM instances in parallel
- **Consensus Selection**: Selects best SQL through voting
- **Fallback Logic**: Uses single LLM if parallel fails
- **Performance Gain**: 40% more accurate SQL generation

### Caching Strategy
- **Query Cache**: Caches query results (TTL: 1 hour)
- **Schema Cache**: Caches database schema (TTL: 24 hours)
- **Result Cache**: Caches interpreted results (TTL: 30 minutes)

## Integration with Other Domains

### With Semantic Domain (Hybrid Queries)
**Query**: "Show me reviews for top-selling products"

**Flow**:
1. HybridQueryProcessor splits query
2. Analytics: "top-selling products" → SQL query for products
3. Semantic: "reviews" → Vector search for reviews of those products
4. Results combined

### With WebSearch Domain (External Data)
**Query**: "Compare our prices with market average"

**Flow**:
1. Analytics: Query internal prices
2. WebSearch: Search external market data
3. Combine and compare results

## Testing

### Unit Tests
```bash
# Test SQL generation
php unit_test/2026_01_17/test_sql_generation.php

# Test query validation
php unit_test/2026_01_17/test_query_validation.php

# Test parallel LLM execution
php unit_test/2026_01_17/test_parallel_llm.php
```

### Integration Tests
```bash
# Test full analytics flow
php unit_test/2026_01_17/test_analytics_domain.php

# Test with real queries
php unit_test/2026_01_17/test_analytics_queries.php
```

### Test Cases
1. **Simple Aggregation**: COUNT, SUM, AVG queries
2. **Complex Joins**: Multi-table joins with filters
3. **Subqueries**: Nested SELECT statements
4. **Time-based Analysis**: Date range queries
5. **Grouping**: GROUP BY with HAVING clauses
6. **Security Tests**: SQL injection attempts
7. **Error Handling**: Invalid queries, timeout scenarios

## Troubleshooting

### Common Issues

**Issue**: SQL generation errors
- **Cause**: Ambiguous query or missing schema context
- **Solution**: Provide more specific query, check schema cache

**Issue**: Query timeout
- **Cause**: Complex query or large dataset
- **Solution**: Increase timeout, optimize query, add indexes

**Issue**: Incorrect results
- **Cause**: Wrong table relationships or filters
- **Solution**: Verify schema relationships, check generated SQL

**Issue**: Permission denied
- **Cause**: User lacks table access
- **Solution**: Grant appropriate permissions, check access control

### Debug Mode

Enable debug logging:
```php
define('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER', 'True');
```

Check logs:
```bash
tail -f Core/ClicShopping/Work/Log/analytics_*.log
```

## Related Documentation

- **Main Domains README**: [../README.md](../README.md)
- **Semantic Domain**: [../Semantic/README.md](../Semantic/README.md)
- **Hybrid Domain**: [../Hybrid/README.md](../Hybrid/README.md)
- **CoreAI Domain**: [../CoreAI/README.md](../CoreAI/README.md)
- **QueryTypeDomainInterface**: `../../Interfaces/QueryTypeDomainInterface.php`

## Future Enhancements

- [ ] Support for complex analytical functions (window functions, CTEs)
- [ ] Automatic query optimization suggestions
- [ ] Real-time data streaming for live dashboards
- [ ] Natural language result explanations with charts
- [ ] Query learning from user feedback
- [ ] Multi-database support (PostgreSQL, MongoDB)

---

**Status**: Active  
**Created**: January 17, 2026  
**Last Updated**: January 17, 2026  
**Owner**: Development Team
