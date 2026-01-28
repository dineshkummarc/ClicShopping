# Ecommerce Analytics SQL Patterns

**Domain**: Ecommerce  
**Purpose**: Reusable SQL patterns for e-commerce analytics  
**Last Updated**: 2026-01-20

---

## Pattern 1: Top Products by Sales

**Use Case**: Find best-selling products

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

**Variations**:
- Change `LIMIT 10` to get different number of products
- Add `WHERE p.products_price > 100` to filter by price
- Add `AND o.date_purchased >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)` for last 30 days

---

## Pattern 2: Sales Trends by Date

**Use Case**: Analyze sales over time

```sql
SELECT 
    DATE(o.date_purchased) as sale_date,
    COUNT(o.orders_id) as order_count,
    SUM(o.order_total) as daily_revenue,
    AVG(o.order_total) as avg_order_value
FROM orders o
WHERE o.date_purchased >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
    AND o.orders_status >= 3
GROUP BY DATE(o.date_purchased)
ORDER BY sale_date ASC;
```

**Variations**:
- Change `INTERVAL 1 MONTH` to `INTERVAL 1 YEAR` for yearly trends
- Use `WEEK(o.date_purchased)` for weekly trends
- Use `MONTH(o.date_purchased)` for monthly trends

---

## Pattern 3: Customer Segmentation

**Use Case**: Analyze customers by group

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

**Variations**:
- Add `WHERE o.date_purchased >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)` for last 90 days
- Change `ORDER BY` to sort by different metrics
- Add `HAVING COUNT(o.orders_id) > 10` to filter groups

---

## Pattern 4: Product Category Performance

**Use Case**: Analyze sales by category

```sql
SELECT 
    c.categories_name,
    COUNT(DISTINCT p.products_id) as product_count,
    SUM(od.products_quantity) as total_sold,
    SUM(od.final_price * od.products_quantity) as total_revenue,
    AVG(od.final_price) as avg_price
FROM categories c
INNER JOIN products p ON c.categories_id = p.categories_id
INNER JOIN orders_products od ON p.products_id = od.products_id
INNER JOIN orders o ON od.orders_id = o.orders_id
WHERE o.orders_status >= 3
GROUP BY c.categories_id, c.categories_name
ORDER BY total_revenue DESC;
```

**Variations**:
- Add date filters for specific periods
- Filter by price range
- Add `HAVING total_revenue > 10000` to filter categories

---

## Pattern 5: Inventory Status

**Use Case**: Monitor stock levels

```sql
SELECT 
    p.products_id,
    p.products_name,
    p.products_quantity as current_stock,
    CASE 
        WHEN p.products_quantity < 10 THEN 'Critical'
        WHEN p.products_quantity < 50 THEN 'Low'
        WHEN p.products_quantity < 200 THEN 'Medium'
        ELSE 'Adequate'
    END as stock_level
FROM products p
WHERE p.products_quantity < 200
ORDER BY p.products_quantity ASC;
```

**Variations**:
- Adjust thresholds based on business needs
- Add `AND p.products_status = 1` to filter active products
- Join with orders_products to calculate turnover rate

---

## Pattern 6: Customer Lifetime Value

**Use Case**: Calculate customer value

```sql
SELECT 
    c.customers_id,
    c.customers_name,
    COUNT(o.orders_id) as order_count,
    SUM(o.order_total) as lifetime_value,
    AVG(o.order_total) as avg_order_value,
    MAX(o.date_purchased) as last_order_date,
    DATEDIFF(CURDATE(), MAX(o.date_purchased)) as days_since_last_order
FROM customers c
LEFT JOIN orders o ON c.customers_id = o.customers_id
    AND o.orders_status >= 3
GROUP BY c.customers_id, c.customers_name
ORDER BY lifetime_value DESC;
```

**Variations**:
- Add `HAVING COUNT(o.orders_id) > 5` to find repeat customers
- Filter by date range for specific periods
- Add `WHERE DATEDIFF(CURDATE(), MAX(o.date_purchased)) < 30` for recent customers

---

## Pattern 7: Monthly Revenue Comparison

**Use Case**: Compare revenue across months

```sql
SELECT 
    MONTH(o.date_purchased) as month,
    MONTHNAME(o.date_purchased) as month_name,
    COUNT(o.orders_id) as order_count,
    SUM(o.order_total) as monthly_revenue,
    AVG(o.order_total) as avg_order_value,
    COUNT(DISTINCT o.customers_id) as unique_customers
FROM orders o
WHERE YEAR(o.date_purchased) = YEAR(CURDATE())
    AND o.orders_status >= 3
GROUP BY MONTH(o.date_purchased), MONTHNAME(o.date_purchased)
ORDER BY MONTH(o.date_purchased) ASC;
```

**Variations**:
- Change `YEAR(CURDATE())` to specific year
- Add `WHERE MONTH(o.date_purchased) IN (10, 11, 12)` for specific months
- Use `QUARTER()` for quarterly analysis

---

## Pattern 8: Product Performance by Price Range

**Use Case**: Analyze sales by price tier

```sql
SELECT 
    CASE 
        WHEN p.products_price < 50 THEN 'Budget'
        WHEN p.products_price < 200 THEN 'Mid-Range'
        WHEN p.products_price < 500 THEN 'Premium'
        ELSE 'Luxury'
    END as price_tier,
    COUNT(DISTINCT p.products_id) as product_count,
    SUM(od.products_quantity) as total_sold,
    SUM(od.final_price * od.products_quantity) as total_revenue,
    AVG(od.final_price) as avg_price
FROM products p
INNER JOIN orders_products od ON p.products_id = od.products_id
INNER JOIN orders o ON od.orders_id = o.orders_id
WHERE o.orders_status >= 3
GROUP BY price_tier
ORDER BY total_revenue DESC;
```

**Variations**:
- Adjust price thresholds based on business
- Add date filters
- Filter by category

---

## Pattern 9: Order Status Distribution

**Use Case**: Analyze order statuses

```sql
SELECT 
    os.orders_status_id,
    os.orders_status_name,
    COUNT(o.orders_id) as order_count,
    SUM(o.order_total) as total_value,
    ROUND(COUNT(o.orders_id) * 100.0 / (SELECT COUNT(*) FROM orders), 2) as percentage
FROM orders o
INNER JOIN orders_status os ON o.orders_status = os.orders_status_id
GROUP BY os.orders_status_id, os.orders_status_name
ORDER BY order_count DESC;
```

**Variations**:
- Add date filters
- Filter by specific status
- Calculate average order value per status

---

## Pattern 10: Top Customers by Revenue

**Use Case**: Identify high-value customers

```sql
SELECT 
    c.customers_id,
    c.customers_name,
    c.customers_email,
    COUNT(o.orders_id) as order_count,
    SUM(o.order_total) as total_spent,
    AVG(o.order_total) as avg_order_value,
    MAX(o.date_purchased) as last_order_date
FROM customers c
INNER JOIN orders o ON c.customers_id = o.customers_id
WHERE o.orders_status >= 3
GROUP BY c.customers_id, c.customers_name, c.customers_email
ORDER BY total_spent DESC
LIMIT 20;
```

**Variations**:
- Change `LIMIT 20` to get different number of customers
- Add date filters for specific periods
- Filter by customer group

---

## Best Practices

### 1. Always Filter by Order Status
```sql
WHERE o.orders_status >= 3  -- Only completed orders
```

### 2. Use Proper Joins
- Use `INNER JOIN` for required relationships
- Use `LEFT JOIN` for optional relationships

### 3. Group and Aggregate Correctly
```sql
GROUP BY p.products_id, p.products_name  -- Include all non-aggregated columns
```

### 4. Order Results Meaningfully
```sql
ORDER BY total_revenue DESC  -- Most important metric first
```

### 5. Limit Results for Performance
```sql
LIMIT 100  -- Prevent large result sets
```

---

## Related Documentation

- **Generic Analytics**: [../../../AI/DomainsAI/Analytics/README.md](../../../AI/DomainsAI/Analytics/README.md)
- **Examples**: [EXAMPLES.md](EXAMPLES.md)
- **README**: [README.md](README.md)

---

**Status**: Complete  
**Domain**: Ecommerce  
**Last Updated**: 2026-01-20

