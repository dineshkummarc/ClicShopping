# Patterns Directory

## Overview

This directory contains pattern classes used throughout the ClicShopping AI framework for query detection, classification, and processing. Patterns are organized into domain-specific subdirectories to enable clear separation of concerns and facilitate future expansion beyond e-commerce.

## Architecture Philosophy

The patterns directory follows a **domain-driven design** approach:

- **Domain Agnostic**: Structure supports multiple business domains (e-commerce, HR, CRM, etc.)
- **Separation of Concerns**: Each subdirectory handles a specific functional domain
- **Reusability**: Common patterns are centralized to avoid duplication
- **Maintainability**: Clear organization makes it easy to locate and update patterns

## Directory Structure

```
Patterns/
├── README.md                    # This file
├── index.php                    # Security file
│
├── Common/                      # Shared patterns across domains
├── Semantic/                    # Semantic search & classification
├── Analytics/                   # Analytics & SQL patterns
├── Hybrid/                      # Hybrid query detection
├── WebSearch/                   # Web search detection
├── Ecommerce/                   # E-commerce entity patterns
└── Security/                    # Security & obfuscation
```

## Domain Categories

### Common (`Common/`)

**Purpose**: Shared patterns used across multiple domains to avoid duplication.

**Namespace**: `ClicShopping\AI\Domain\Patterns\Common`

| Pattern | Description | Used By |
|---------|-------------|---------|
| `EntityKeywordsPattern` | Centralized entity keywords (product, order, customer, etc.) | Analytics, WebSearch, Ecommerce |

**When to use**: When you need entity keywords that are referenced by multiple domains.

---

### Semantic (`Semantic/`)

**Purpose**: Patterns for semantic search, intent classification, and query analysis.

**Namespace**: `ClicShopping\AI\Domain\Patterns\Semantic`

| Pattern | Description |
|---------|-------------|
| `ClassificationEnginePatterns` | Patterns for query classification engine |
| `PatternAnalysisPattern` | Patterns for analyzing query patterns |

**When to use**: When implementing semantic search features or query classification logic.

---

### Analytics (`Analytics/`)

**Purpose**: Patterns for analytics queries, SQL generation, temporal analysis, and financial metrics.

**Namespace**: `ClicShopping\AI\Domain\Patterns\Analytics`

| Pattern | Description |
|---------|-------------|
| `AnalyticsExecutorPatterns` | Date column detection, table extraction |
| `TemporalFinancialPatterns` | Temporal and financial keyword patterns |
| `TemporalFinancialPreFilter` | Pre-filter for temporal/financial queries |
| `MultiTemporalPostFilter` | Post-filter for multi-temporal queries |
| `SuperlativePatterns` | MIN/MAX/BEST/WORST keyword patterns |
| `SuperlativePostFilter` | Post-filter for superlative queries |
| `OperatorPattern` | SQL operator patterns (>, <, =, BETWEEN, etc.) |
| `QuerySplitterPatterns` | Patterns for splitting complex queries |
| `FinancialMetricsPattern` | Financial metrics detection (revenue, profit, sales, etc.) |
| `TimeRangePattern` | Time range detection (last month, this year, Q1, etc.) |
| `TemporalPeriodMappingPattern` | Temporal period mappings (biweekly, quarterly, fiscal year, YTD, etc.) |

**When to use**: When building analytics features, SQL query generation, or temporal/financial analysis.

---

### Hybrid (`Hybrid/`)

**Purpose**: Patterns for detecting hybrid queries (queries with multiple intents).

**Namespace**: `ClicShopping\AI\Domain\Patterns\Hybrid`

| Pattern | Description |
|---------|-------------|
| `HybridPreFilter` | Pre-filter for hybrid query detection |
| `AmbiguityPreFilter` | Pre-filter for ambiguous queries |

**When to use**: When handling complex queries that combine multiple intents (e.g., "Show me sales trends AND top products").

---

### WebSearch (`WebSearch/`)

**Purpose**: Patterns for detecting web search intent (trends, news, competitors).

**Namespace**: `ClicShopping\AI\Domain\Patterns\WebSearch`

| Pattern | Description |
|---------|-------------|
| `WebSearchPatterns` | Trends, news, competitor keywords |
| `WebSearchPostFilter` | Post-filter for web search queries |

**When to use**: When implementing web search detection or external data retrieval features.

---

### Ecommerce (`Ecommerce/`)

**Purpose**: Patterns specific to e-commerce entities and operations.

**Namespace**: `ClicShopping\AI\Domain\Patterns\Ecommerce`

| Pattern | Description |
|---------|-------------|
| `EntityDetectionPattern` | Product, category, customer detection |
| `ModificationKeywordsPattern` | CRUD operation keywords (add, update, delete, etc.) |
| `ContinuationPattern` | Conversation continuation patterns |
| `ContextResetPattern` | Context reset detection |

**When to use**: When building e-commerce specific features like product search, order management, or customer interactions.

---

### Security (`Security/`)

**Purpose**: Patterns for security and obfuscation detection.

**Namespace**: `ClicShopping\AI\Domain\Patterns\Security`

| Pattern | Description |
|---------|-------------|
| `ObfuscationPatterns` | Encoding, leetspeak, homoglyph detection |

**When to use**: When implementing security features or detecting malicious input patterns.

---

## Guidelines for Adding New Patterns

### 1. Choose the Correct Domain

Before creating a new pattern, determine which domain it belongs to:

- **Common**: Used by 2+ domains? → Place in `Common/`
- **Semantic**: Related to classification or semantic search? → Place in `Semantic/`
- **Analytics**: Related to SQL, temporal, or financial analysis? → Place in `Analytics/`
- **Hybrid**: Related to multi-intent queries? → Place in `Hybrid/`
- **WebSearch**: Related to external web search? → Place in `WebSearch/`
- **Ecommerce**: Specific to e-commerce entities? → Place in `Ecommerce/`
- **Security**: Related to security or obfuscation? → Place in `Security/`

### 2. Naming Conventions

Follow these naming conventions:

- **Pattern classes**: End with `Pattern` or `Patterns` (e.g., `EntityKeywordsPattern`)
- **PreFilter classes**: End with `PreFilter` (e.g., `TemporalFinancialPreFilter`)
- **PostFilter classes**: End with `PostFilter` (e.g., `SuperlativePostFilter`)
- **Use descriptive names**: Name should clearly indicate the pattern's purpose

### 3. File Structure Template

```php
<?php
/**
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

namespace ClicShopping\AI\Domain\Patterns\{Domain};

/**
 * Brief description of what this pattern does
 */
class YourPatternName
{
  /**
   * Returns the pattern definitions
   * 
   * @return array Pattern definitions
   */
  public static function getPatterns(): array
  {
    return [
      // Your pattern definitions here
    ];
  }

  /**
   * Additional helper methods as needed
   */
}
```

### 4. Documentation Requirements

When adding a new pattern:

1. **Add to this README**: Update the appropriate domain section with pattern name and description
2. **Add PHPDoc comments**: Document all public methods with clear descriptions
3. **Add inline comments**: Explain complex logic or non-obvious patterns
4. **Update dependent files**: If other files need to use your pattern, update their imports

### 5. Testing Requirements

All new patterns should be tested:

1. **Unit tests**: Test specific pattern matching examples
2. **Integration tests**: Test pattern usage in dependent classes
3. **Property tests**: Test universal properties (if applicable)

### 6. Avoid Duplication

Before creating a new pattern:

1. **Check existing patterns**: Search for similar functionality
2. **Check Common/**: See if a shared pattern already exists
3. **Consider refactoring**: If similar patterns exist, consider extracting common logic to `Common/`

### 7. Pure LLM Mode Consideration

**Important**: ClicShopping AI operates in **Pure LLM Mode**, which means:

- All detection, classification, and analysis MUST use LLM (not pattern matching)
- Pattern classes can be updated for future use, but MUST NOT be used in current implementation
- Patterns serve as reference data or fallback mechanisms only

When adding patterns, consider:
- Is this pattern for LLM reference data? → OK to add
- Is this pattern for deterministic detection? → Reconsider, use LLM instead
- Is this pattern for fallback scenarios? → Document clearly

## Migration from Flat Structure

This directory was restructured from a flat structure to the current domain-organized hierarchy. The migration involved:

1. Creating domain subdirectories
2. Moving pattern files to appropriate domains
3. Updating namespaces to match new locations
4. Updating all import statements in dependent files
5. Creating `Common/` directory to centralize shared patterns

### Backward Compatibility

The restructuration maintains full backward compatibility:
- All public interfaces remain unchanged
- All method signatures are preserved
- Only namespaces and file locations changed

## Related Documentation

- **Design Document**: `.kiro/specs/patterns-restructuration/design.md`
- **Requirements Document**: `.kiro/specs/patterns-restructuration/requirements.md`
- **Implementation Tasks**: `.kiro/specs/patterns-restructuration/tasks.md`

## Questions or Issues?

If you have questions about:
- Which domain a pattern belongs to
- How to structure a new pattern
- Migration issues or backward compatibility

Please refer to the design document or contact the development team.

---

**Last Updated**: January 2026  
**Version**: 1.0  
**Maintainer**: ClicShopping AI Team
