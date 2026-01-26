# Ecommerce Analytics

## Purpose

This documentation describes **e-commerce specific analytics** use cases, examples, and SQL patterns. For generic Analytics infrastructure documentation, see [Generic Analytics](../../../AI/DomainsAI/Analytics/README.md).

## E-Commerce Use Cases

- **Sales Analytics**: "What are the top 10 selling products?"
- **Trend Analysis**: "Show me sales trends for last month"
- **Customer Insights**: "Calculate average order value by customer group"
- **Inventory Reports**: "Which products are low in stock?"
- **Financial Analysis**: "What is the total revenue for Q4 2025?"
- **Performance Metrics**: "Show me conversion rates by traffic source"

## E-Commerce Examples

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
    }
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
    {"sale_date": "2025-12-18", "order_count": 52, "daily_revenue": 14200.00}
  ],
  "total": 31,
  "interpretation": "Sales show an upward trend with average daily revenue of $13,450.",
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

## E-Commerce SQL Patterns

For detailed SQL patterns, see [SQL Patterns](SQL_PATTERNS.md).

## E-Commerce Configuration

Analytics domain configuration for e-commerce:

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

## Related Documentation

- **Generic Analytics**: [../../../AI/DomainsAI/Analytics/README.md](../../../AI/DomainsAI/Analytics/README.md)
- **SQL Patterns**: [SQL_PATTERNS.md](SQL_PATTERNS.md)
- **Examples**: [EXAMPLES.md](EXAMPLES.md)

---

**Status**: Active  
**Domain**: Ecommerce  
**Last Updated**: 2026-01-20

