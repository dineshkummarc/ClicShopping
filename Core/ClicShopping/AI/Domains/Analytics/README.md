# Analytics Domain

## Purpose

The Analytics Domain handles **business intelligence and analytics queries** that require SQL generation and database analysis. It converts natural language questions into SQL queries, executes them safely, and interprets the results in a user-friendly format.

## Use Cases

- **Sales Analytics**: "What are the top 10 selling products?"
- **Trend Analysis**: "Show me sales trends for last month"
- **Customer Insights**: "Calculate average order value by customer group"
- **Inventory Reports**: "Which products are low in stock?"
- **Financial Analysis**: "What is the total revenue for Q4 2025?"
- **Performance Metrics**: "Show me conversion rates by traffic source"

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

## Examples

### Example 1: Top Selling Products

**Query**: "What are the top 10 selling products?"

**Generated SQL**:
```sql
SELECT 
    p.products_id,
    p.products_name,
    SUM(od.products_quantity) as total_sold,
    SUM(od.final_price * od.products_quantity) as total_revenue
FROM products p
INNER JOIN orders_products od ON p.products_id = od.products_id
INNER JOIN orders o ON od.orders_id = o.orders_id
WHERE o.orders_status >= 3
GROUP BY p.products_id, p.products_name
ORDER BY total_sold DESC
LIMIT 10;
```

**Response**:
```json
{
  "intent": "analytics",
  "query_type": "sales_analysis",
  "sql": "SELECT ...",
  "results": [
    {
      "products_id": 123,
      "products_name": "Laptop XPS 15",
      "total_sold": 450,
      "total_revenue": 675000.00
    },
    // ... 9 more products
  ],
  "total": 10,
  "interpretation": "The top-selling product is Laptop XPS 15 with 450 units sold, generating $675,000 in revenue.",
  "execution_time": "0.23s"
}
```

### Example 2: Sales Trends

**Query**: "Show me sales trends for last month"

**Generated SQL**:
```sql
SELECT 
    DATE(o.date_purchased) as sale_date,
    COUNT(o.orders_id) as order_count,
    SUM(o.order_total) as daily_revenue
FROM orders o
WHERE o.date_purchased >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
    AND o.orders_status >= 3
GROUP BY DATE(o.date_purchased)
ORDER BY sale_date ASC;
```

**Response**:
```json
{
  "intent": "analytics",
  "query_type": "trend_analysis",
  "sql": "SELECT ...",
  "results": [
    {"sale_date": "2025-12-17", "order_count": 45, "daily_revenue": 12500.00},
    {"sale_date": "2025-12-18", "order_count": 52, "daily_revenue": 14200.00},
    // ... more days
  ],
  "total": 31,
  "interpretation": "Sales show an upward trend with average daily revenue of $13,450. Peak sales occurred on December 25th.",
  "visualization": "line_chart",
  "execution_time": "0.18s"
}
```

### Example 3: Customer Insights

**Query**: "Calculate average order value by customer group"

**Generated SQL**:
```sql
SELECT 
    cg.customers_group_name,
    COUNT(DISTINCT o.customers_id) as customer_count,
    COUNT(o.orders_id) as order_count,
    AVG(o.order_total) as avg_order_value,
    SUM(o.order_total) as total_revenue
FROM orders o
INNER JOIN customers c ON o.customers_id = c.customers_id
INNER JOIN customers_groups cg ON c.customers_group_id = cg.customers_group_id
WHERE o.orders_status >= 3
GROUP BY cg.customers_group_id, cg.customers_group_name
ORDER BY avg_order_value DESC;
```

**Response**:
```json
{
  "intent": "analytics",
  "query_type": "customer_analysis",
  "results": [
    {
      "customers_group_name": "VIP",
      "customer_count": 150,
      "order_count": 890,
      "avg_order_value": 450.50,
      "total_revenue": 401345.00
    },
    {
      "customers_group_name": "Regular",
      "customer_count": 2500,
      "order_count": 5200,
      "avg_order_value": 125.30,
      "total_revenue": 651560.00
    }
  ],
  "interpretation": "VIP customers have 3.6x higher average order value ($450.50) compared to regular customers ($125.30).",
  "execution_time": "0.31s"
}
```

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
