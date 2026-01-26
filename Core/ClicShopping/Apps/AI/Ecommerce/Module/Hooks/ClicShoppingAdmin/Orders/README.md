# Orders Hooks

This directory contains Hook implementations for Orders-related AI agents.

## InsightsAgent - Two Implementations

The InsightsAgent is available in two versions for different use cases:

### 1. InsightsAgentDisplay.php - For HTML Display

**Purpose**: Displays order insights directly in the admin interface as HTML.

**Usage in templates**:
```php
<?php 
// In Orders edit.php template
$CLICSHOPPING_Hooks = Registry::get('Hooks');

// Set parameters
$CLICSHOPPING_Hooks->setParameter('order_id', $order_id);
$CLICSHOPPING_Hooks->setParameter('insight_type', 'summary');

// Display insights
echo $CLICSHOPPING_Hooks->output('Orders', 'InsightsAgentDisplay', null, 'display');
?>
```

**Returns**: HTML string ready for display

**Example output**:
```html
<div class="card mt-3">
  <div class="card-header bg-info text-white">
    <h5>Order Insights - Summary</h5>
  </div>
  <div class="card-body">
    <div class="alert alert-light">
      <strong>Summary:</strong> High-value order with premium products...
    </div>
    <span class="badge bg-success">Confidence: 95%</span>
    <h6>Key Insights:</h6>
    <ul class="list-group">
      <li class="list-group-item">Customer is a repeat buyer</li>
      <li class="list-group-item">Order value above average</li>
    </ul>
  </div>
</div>
```

### 2. InsightsAgentExecute.php - For Programmatic Use

**Purpose**: Generates insights silently and returns data array for programmatic use.

**Usage in code**:
```php
<?php
use ClicShopping\OM\Hooks;
use ClicShopping\OM\Registry;

// Method 1: Direct call with parameters
$insights = Hooks::call('Orders', 'InsightsAgentExecute', [
    'order_id' => 12345,
    'insight_type' => 'summary'
]);

// Method 2: Set parameters then call
Registry::get('Hooks')->setParameter('order_id', 12345);
Registry::get('Hooks')->setParameter('insight_type', 'anomalies');
Hooks::call('Orders', 'InsightsAgentExecute');
$insights = Registry::get('Hooks')->getReturnValue();

// Use the data
if ($insights['success']) {
    foreach ($insights['insights'] as $insight) {
        // Process insight
        echo "- {$insight}\n";
    }
    
    // Check confidence
    if ($insights['confidence'] > 0.8) {
        // High confidence - take action
    }
}
?>
```

**Returns**: Array structure
```php
[
    'success' => true,
    'insight_type' => 'summary',
    'insights' => ['insight1', 'insight2', ...],
    'confidence' => 0.95,
    'recommendations' => ['recommendation1', 'recommendation2', ...],
    'summary' => 'Brief summary',
    'execution_time_ms' => 450.23,
    'raw_response' => 'Full LLM response'
]
```

## Parameters

Both hooks accept the same parameters:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `order_id` | int | Yes* | - | Order ID to analyze |
| `order_data` | array | No | - | Order data (fetched if not provided) |
| `insight_type` | string | No | 'summary' | Type of insight to generate |

*Required for Display hook, optional for Execute hook if `order_data` is provided

## Insight Types

| Type | Description | Use Case |
|------|-------------|----------|
| `summary` | Concise order overview | Quick order review |
| `trends` | Pattern and trend analysis | Identify buying patterns |
| `anomalies` | Unusual aspects detection | Fraud detection, quality control |
| `recommendations` | Actionable suggestions | Upsell, cross-sell opportunities |

## When to Use Which Hook?

### Use InsightsAgentDisplay when:
- ✅ Displaying insights in admin interface
- ✅ Need formatted HTML output
- ✅ Want ready-to-use UI components
- ✅ Integrating into existing templates

### Use InsightsAgentExecute when:
- ✅ Processing insights programmatically
- ✅ Need raw data for further processing
- ✅ Building custom UI
- ✅ Integrating with APIs or workflows
- ✅ Batch processing multiple orders

## Integration Examples

### Example 1: Display in Order Edit Page

```php
// In Core/ClicShopping/Apps/Orders/Orders/Sites/ClicShoppingAdmin/Pages/Home/templates/edit.php

<div class="tab-pane" id="tab4">
  <div class="mainTitle">AI Insights</div>
  <div class="adminformTitle">
    <?php
    $CLICSHOPPING_Hooks->setParameter('order_id', $order_id);
    $CLICSHOPPING_Hooks->setParameter('insight_type', 'summary');
    echo $CLICSHOPPING_Hooks->output('Orders', 'InsightsAgentDisplay', null, 'display');
    ?>
  </div>
</div>
```

### Example 2: Programmatic Anomaly Detection

```php
// In order processing workflow

$insights = Hooks::call('Orders', 'InsightsAgentExecute', [
    'order_id' => $orderId,
    'insight_type' => 'anomalies'
]);

if ($insights['success'] && !empty($insights['insights'])) {
    // Anomalies detected - flag for review
    $this->flagOrderForReview($orderId, $insights['insights']);
    
    // Send notification
    $this->notifyAdmin("Order #{$orderId} has anomalies", $insights['summary']);
}
```

### Example 3: Batch Recommendations

```php
// Generate recommendations for recent orders

$recentOrders = $this->getRecentOrders(10);

foreach ($recentOrders as $order) {
    $insights = Hooks::call('Orders', 'InsightsAgentExecute', [
        'order_id' => $order['orders_id'],
        'insight_type' => 'recommendations'
    ]);
    
    if ($insights['success'] && !empty($insights['recommendations'])) {
        // Store recommendations for follow-up
        $this->storeRecommendations($order['orders_id'], $insights['recommendations']);
    }
}
```

## Implementation Notes

1. **Pure LLM Mode**: All insights are generated using LLM, not pattern matching
2. **Language Files**: Prompts are loaded from language files for easy customization
3. **Fallback Prompts**: Default prompts are used if language file is not found
4. **Error Handling**: Graceful error handling with detailed error messages
5. **Performance**: Execution time is tracked and logged in debug mode
6. **Caching**: Consider implementing caching for frequently requested insights

## Testing

Run the verification script:
```bash
php unit_test/2026_01_19/verify_insights_agent_simple.php
```

## Future Enhancements

- Add caching for insights to reduce LLM API calls
- Support batch insight generation for multiple orders
- Add more insight types (e.g., 'fraud_detection', 'fulfillment_optimization')
- Implement insight history tracking
- Add confidence threshold filtering
