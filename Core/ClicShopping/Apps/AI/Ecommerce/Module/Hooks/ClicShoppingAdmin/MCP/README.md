# MCP Hooks - Ecommerce Domain Integration

## Overview

This directory contains MCP (Model Context Protocol) integration hooks specific to the Ecommerce domain. These hooks provide domain-specific logic for communicating with the MCP server.

## Architecture

### Separation of Concerns

**Apps/Tools/MCP** (Infrastructure Layer):
- Generic MCP protocol implementation
- Connection management (MCPConnector, McpJsonRpcClient)
- Health monitoring (McpHealth, McpMonitor)
- Performance analysis (McpPerformanceAnalyzer)
- Transport layer (HTTP, WebSocket)

**Apps/AI/Ecommerce/Module/Hooks/.../MCP/** (Domain Layer):
- Ecommerce-specific MCP operations
- Domain entity integration (products, orders, customers)
- Domain-specific prompts and context
- Business logic orchestration

## Planned Hooks

### ClicShoppingAdmin/MCP/

#### DomainContextProvider.php
**Purpose**: Provides domain-specific context to MCP server
- Sends entity configuration to MCP
- Provides domain guardrails
- Sends LLM prompts for domain operations
- Updates domain state in MCP

**Usage**:
```php
// Called when domain is activated
Hooks::call('AI/Ecommerce/MCP', 'DomainContextProvider', [
    'action' => 'sync_domain_config',
    'domain_id' => 'ecommerce'
]);
```

#### EntityOperations.php
**Purpose**: Domain entity operations via MCP
- CRUD operations on domain entities
- Batch operations
- Entity validation
- Entity transformation

**Usage**:
```php
// Create product via MCP
Hooks::call('AI/Ecommerce/MCP', 'EntityOperations', [
    'action' => 'create',
    'entity_type' => 'product',
    'data' => $product_data
]);
```

#### QueryProcessor.php
**Purpose**: Process RAG queries via MCP
- Send natural language queries to MCP
- Receive structured responses
- Handle query classification
- Manage query cache

**Usage**:
```php
// Process RAG query via MCP
$result = Hooks::call('AI/Ecommerce/MCP', 'QueryProcessor', [
    'query' => 'Show me top selling products',
    'language' => 'fr',
    'user_id' => $user_id
]);
```

#### AnalyticsAgent.php
**Purpose**: Analytics operations via MCP
- Request analytics reports
- Stream real-time analytics
- Generate insights
- Export analytics data

**Usage**:
```php
// Request analytics via MCP
Hooks::call('AI/Ecommerce/MCP', 'AnalyticsAgent', [
    'action' => 'generate_report',
    'report_type' => 'sales_trends',
    'period' => 'last_30_days'
]);
```

#### HealthMonitor.php
**Purpose**: Monitor MCP health from domain perspective
- Check domain-specific MCP endpoints
- Validate domain configuration sync
- Monitor query performance
- Alert on domain-specific issues

**Usage**:
```php
// Check domain MCP health
$health = Hooks::call('AI/Ecommerce/MCP', 'HealthMonitor', [
    'action' => 'check_health'
]);
```

### Shop/MCP/

#### ProductRecommendations.php (Future)
**Purpose**: Real-time product recommendations via MCP
- Request personalized recommendations
- Stream recommendation updates
- Track recommendation performance

#### DynamicPricing.php (Future)
**Purpose**: Dynamic pricing via MCP
- Request price optimization
- Get competitor pricing
- Calculate dynamic prices

#### PersonalizationEngine.php (Future)
**Purpose**: Customer personalization via MCP
- Request personalized content
- Get customer segments
- Track personalization effectiveness

## Communication Flow

### 1. Domain → MCP (Outbound)

```
Ecommerce Domain
    ↓ Hooks::call('AI/Ecommerce/MCP', 'QueryProcessor', ...)
Ecommerce MCP Hook (QueryProcessor.php)
    ↓ Prepare domain-specific context
    ↓ Hooks::call('Tools/MCP', 'ExecuteRequest', ...)
MCP Infrastructure (Apps/Tools/MCP)
    ↓ MCPConnector → McpJsonRpcClient
MCP Server (Node.js)
```

### 2. MCP → Domain (Inbound)

```
MCP Server (Node.js)
    ↓ Webhook/Callback
MCP Infrastructure (Apps/Tools/MCP)
    ↓ Hooks::call('AI/Ecommerce/MCP', 'HandleResponse', ...)
Ecommerce MCP Hook (HandleResponse.php)
    ↓ Process domain-specific response
Ecommerce Domain
```

## Configuration

### clicshopping.json Declaration

```json
{
  "modules": {
    "Hooks": {
      "ClicShoppingAdmin/MCP": {
        "DomainContextProvider": "Module\\Hooks\\ClicShoppingAdmin\\MCP\\DomainContextProvider",
        "EntityOperations": "Module\\Hooks\\ClicShoppingAdmin\\MCP\\EntityOperations",
        "QueryProcessor": "Module\\Hooks\\ClicShoppingAdmin\\MCP\\QueryProcessor",
        "AnalyticsAgent": "Module\\Hooks\\ClicShoppingAdmin\\MCP\\AnalyticsAgent",
        "HealthMonitor": "Module\\Hooks\\ClicShoppingAdmin\\MCP\\HealthMonitor"
      },
      "Shop/MCP": {
        "ProductRecommendations": "Module\\Hooks\\Shop\\MCP\\ProductRecommendations",
        "DynamicPricing": "Module\\Hooks\\Shop\\MCP\\DynamicPricing",
        "PersonalizationEngine": "Module\\Hooks\\Shop\\MCP\\PersonalizationEngine"
      }
    }
  }
}
```

## Benefits

### 1. Domain Isolation
- Each domain has its own MCP logic
- No cross-domain contamination
- Easier testing and debugging

### 2. Reusability
- MCP infrastructure is shared
- Domain logic is specific
- Easy to add new domains

### 3. Maintainability
- Clear separation of concerns
- Domain experts can modify domain hooks
- Infrastructure team manages MCP core

### 4. Scalability
- Multiple domains can use MCP simultaneously
- Domain-specific caching
- Domain-specific rate limiting

## Integration with Existing MCP

### Using MCP Infrastructure

```php
namespace ClicShopping\Apps\AI\Ecommerce\Module\Hooks\ClicShoppingAdmin\MCP;

use ClicShopping\OM\Registry;
use ClicShopping\OM\Hooks;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\MCPConnector;

class QueryProcessor implements \ClicShopping\OM\Modules\HooksInterface
{
    public function execute()
    {
        $params = func_get_args()[0] ?? [];
        
        // Get domain configuration
        $domain = Registry::get('DomainRegistry')->getActiveApp();
        $prompts = $domain->getLlmPrompts();
        
        // Prepare MCP request with domain context
        $mcpRequest = [
            'method' => 'query/process',
            'params' => [
                'query' => $params['query'],
                'domain' => 'ecommerce',
                'prompts' => $prompts,
                'entities' => $domain->getEntityConfig()
            ]
        ];
        
        // Call MCP infrastructure
        $result = Hooks::call('Tools/MCP', 'ExecuteRequest', $mcpRequest);
        
        // Process domain-specific response
        return $this->processResponse($result);
    }
    
    private function processResponse($result)
    {
        // Domain-specific response processing
        // ...
    }
}
```

## Development Timeline

**Phase 3** (Week 6-8): Basic MCP hooks
- DomainContextProvider
- QueryProcessor
- HealthMonitor

**Phase 4** (Week 9-10): Advanced MCP hooks
- EntityOperations
- AnalyticsAgent

**Phase 8** (Future): Shop MCP hooks
- ProductRecommendations
- DynamicPricing
- PersonalizationEngine

## Testing

### Unit Tests
- Test hook registration
- Test parameter validation
- Test error handling

### Integration Tests
- Test MCP communication
- Test domain context sync
- Test query processing

### End-to-End Tests
- Test complete query workflow
- Test analytics generation
- Test real-time updates

## References

- MCP Infrastructure: `Core/ClicShopping/Apps/Tools/MCP/`
- MCP Server: `mcp/src/`
- Domain Registry: `Core/ClicShopping/AI/DomainRegistry.php`
- Spec: `.kiro/specs/active/rag-multi-domain-evolution/`
