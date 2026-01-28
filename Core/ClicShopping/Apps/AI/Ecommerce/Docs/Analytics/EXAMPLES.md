# Ecommerce Analytics Examples

**Domain**: Ecommerce  
**Purpose**: Detailed e-commerce specific analytics examples  
**Last Updated**: 2026-01-20

---

## Example 1: Top Selling Products

### Query
"What are the top 10 selling products?"

### Generated SQL
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

### Expected Response
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
    {
      "products_id": 456,
      "products_name": "Monitor 4K 27\"",
      "total_sold": 380,
      "total_revenue": 228000.00
    },
    {
      "products_id": 789,
      "products_name": "Keyboard Mechanical",
      "total_sold": 920,
      "total_revenue": 92000.00
    }
  ],
  "total": 10,
  "interpretation": "The top-selling product is Laptop XPS 15 with 450 units sold, generating $675,000 in revenue.",
  "execution_time": "0.23s"
}
```

### Key Points
- Filters by order status >= 3 (completed orders)
- Joins products with orders_products and orders tables
- Calculates both quantity and revenue
- Limits to top 10 products

---

## Example 2: Sales Trends

### Query
"Show me sales trends for last month"

### Generated SQL
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

### Expected Response
```json
{
  "intent": "analytics",
  "query_type": "trend_analysis",
  "sql": "SELECT ...",
  "results": [
    {"sale_date": "2025-12-17", "order_count": 45, "daily_revenue": 12500.00},
    {"sale_date": "2025-12-18", "order_count": 52, "daily_revenue": 14200.00},
    {"sale_date": "2025-12-19", "order_count": 48, "daily_revenue": 13800.00},
    {"sale_date": "2025-12-20", "order_count": 61, "daily_revenue": 17500.00},
    {"sale_date": "2025-12-21", "order_count": 55, "daily_revenue": 15200.00}
  ],
  "total": 31,
  "interpretation": "Sales show an upward trend with average daily revenue of $13,450. Peak sales occurred on December 25th with $18,900 in revenue.",
  "visualization": "line_chart",
  "execution_time": "0.18s"
}
```

### Key Points
- Groups by date (daily granularity)
- Filters by last month
- Counts orders and sums revenue
- Useful for identifying sales patterns and peaks

---

## Example 3: Customer Insights

### Query
"Calculate average order value by customer group"

### Generated SQL
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

### Expected Response
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
    },
    {
      "customers_group_name": "Wholesale",
      "customer_count": 45,
      "order_count": 320,
      "avg_order_value": 2150.75,
      "total_revenue": 688240.00
    }
  ],
  "interpretation": "VIP customers have 3.6x higher average order value ($450.50) compared to regular customers ($125.30). Wholesale customers have the highest average order value at $2,150.75.",
  "execution_time": "0.31s"
}
```

### Key Points
- Groups by customer group
- Counts distinct customers and orders
- Calculates average and total revenue
- Useful for customer segmentation analysis

---

## Example 4: Inventory Reports

### Query
"Which products are low in stock?"

### Generated SQL
```sql
SELECT 
    p.products_id,
    p.products_name,
    p.products_quantity as current_stock,
    CASE 
        WHEN p.products_quantity < 10 THEN 'Critical'
        WHEN p.products_quantity < 50 THEN 'Low'
        ELSE 'Adequate'
    END as stock_level,
    AVG(od.products_quantity) as avg_daily_sales
FROM products p
LEFT JOIN orders_products od ON p.products_id = od.products_id
WHERE p.products_quantity < 100
GROUP BY p.products_id, p.products_name, p.products_quantity
ORDER BY p.products_quantity ASC;
```

### Expected Response
```json
{
  "intent": "analytics",
  "query_type": "inventory_analysis",
  "results": [
    {
      "products_id": 234,
      "products_name": "USB-C Cable",
      "current_stock": 5,
      "stock_level": "Critical",
      "avg_daily_sales": 12.5
    },
    {
      "products_id": 567,
      "products_name": "HDMI Cable",
      "current_stock": 8,
      "stock_level": "Critical",
      "avg_daily_sales": 8.2
    },
    {
      "products_id": 890,
      "products_name": "Power Adapter",
      "current_stock": 35,
      "stock_level": "Low",
      "avg_daily_sales": 5.1
    }
  ],
  "interpretation": "2 products are at critical stock levels (< 10 units). USB-C Cable is selling 12.5 units per day with only 5 in stock - reorder urgently.",
  "execution_time": "0.15s"
}
```

### Key Points
- Identifies low stock products
- Calculates average daily sales
- Categorizes stock levels
- Useful for inventory management

---

## Example 5: Financial Analysis

### Query
"What is the total revenue for Q4 2025?"

### Generated SQL
```sql
SELECT 
    MONTH(o.date_purchased) as month,
    MONTHNAME(o.date_purchased) as month_name,
    COUNT(o.orders_id) as order_count,
    SUM(o.order_total) as monthly_revenue,
    AVG(o.order_total) as avg_order_value,
    COUNT(DISTINCT o.customers_id) as unique_customers
FROM orders o
WHERE YEAR(o.date_purchased) = 2025
    AND MONTH(o.date_purchased) IN (10, 11, 12)
    AND o.orders_status >= 3
GROUP BY MONTH(o.date_purchased), MONTHNAME(o.date_purchased)
ORDER BY MONTH(o.date_purchased) ASC;
```

### Expected Response
```json
{
  "intent": "analytics",
  "query_type": "financial_analysis",
  "results": [
    {
      "month": 10,
      "month_name": "October",
      "order_count": 1250,
      "monthly_revenue": 425000.00,
      "avg_order_value": 340.00,
      "unique_customers": 890
    },
    {
      "month": 11,
      "month_name": "November",
      "order_count": 1680,
      "monthly_revenue": 598500.00,
      "avg_order_value": 356.25,
      "unique_customers": 1120
    },
    {
      "month": 12,
      "month_name": "December",
      "order_count": 2150,
      "monthly_revenue": 812000.00,
      "avg_order_value": 377.67,
      "unique_customers": 1450
    }
  ],
  "total_q4_revenue": 1835500.00,
  "interpretation": "Q4 2025 generated $1,835,500 in revenue across 5,080 orders. December was the strongest month with $812,000 (44% of Q4 revenue).",
  "execution_time": "0.42s"
}
```

### Key Points
- Analyzes revenue by month
- Filters by quarter and year
- Calculates multiple metrics
- Useful for financial reporting

---

## Related Documentation

- **Generic Analytics**: [../../../AI/DomainsAI/Analytics/README.md](../../../AI/DomainsAI/Analytics/README.md)
- **SQL Patterns**: [SQL_PATTERNS.md](SQL_PATTERNS.md)
- **README**: [README.md](README.md)

---

**Status**: Complete  
**Domain**: Ecommerce  
**Last Updated**: 2026-01-20

