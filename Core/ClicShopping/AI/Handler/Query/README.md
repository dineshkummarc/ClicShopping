# Complex Query Handler Documentation

## Overview

The `ComplexQueryHandler` class is responsible for detecting, decomposing, and orchestrating complex queries that require multiple types of analysis or processing steps. It uses a weighted scoring system to determine whether a query should be processed as a simple query or decomposed into sub-queries.

## Purpose

This handler addresses queries that:
- Contain multiple distinct questions (connected by AND, THEN, ALSO, etc.)
- Require hybrid processing (analytics + semantic + web search)
- Need competitive analysis or external data
- Have contextual dependencies on previous queries

## Key Features

### 1. Complexity Detection

The handler uses a **weighted scoring system** to evaluate query complexity:

```php
$weights = [
    'multiple_connectors' => 0.3,    // Multiple query connectors (2+)
    'strong_connector' => 0.5,        // Single strong connector
    'hybrid_pattern' => 0.4,          // Hybrid query patterns
    'web_search' => 0.2,              // Web search requirement
    'contextual_dependency' => 0.3,   // Contextual dependencies
    'minimum_threshold' => 0.4        // Minimum score for complexity
];
```

### 2. Scoring Logic

- Each detected pattern contributes to a **complexity score (0-100)**
- Scores are calculated by summing weighted factors
- A query is classified as **complex** only if `score >= minimum_threshold`
- This prevents false positives from single weak indicators

### 3. Threshold Rationale

The `minimum_threshold` of **0.4** was chosen to ensure:
- ✅ Single strong connector (0.5) → **complex**
- ✅ Multiple weak factors (0.3 + 0.2 = 0.5) → **complex**
- ✅ Single weak factor (0.3) → **simple**

This threshold prevents unnecessary decomposition of simple queries while catching genuinely complex ones.

## Usage

### Basic Detection

```php
use ClicShopping\AI\Handler\Query\ComplexQueryHandler;

$handler = new ComplexQueryHandler($debug = false);
$result = $handler->detectComplexQuery($query);

if ($result['is_complex']) {
    // Query requires decomposition
    $sub_queries = $handler->decomposeComplexQuery($query, $result);
    // Process each sub-query...
} else {
    // Process as simple query
}
```

### Detection Result Structure

```php
[
    'is_complex' => bool,              // Whether query requires complex processing
    'query_type' => string,            // 'simple', 'multiple', 'hybrid', 'multiple_hybrid'
    'complexity_score' => int,         // Calculated score (0-100)
    'detected_patterns' => array,      // List of detected complexity patterns
    'requires_web_search' => bool,     // Whether query needs external web search
    'estimated_sub_queries' => int     // Estimated number of sub-queries
]
```

## Weight Configuration

### multiple_connectors (0.3)
- **Applied**: When 2+ connectors detected
- **Examples**: "AND", "THEN", "ALSO"
- **Purpose**: Indicates multiple distinct sub-queries

### strong_connector (0.5)
- **Applied**: When exactly 1 strong connector detected
- **Purpose**: Single connector strongly suggests splitting into 2 parts

### hybrid_pattern (0.4)
- **Applied**: Per hybrid pattern detected
- **Examples**: Combining analytics + semantic, multiple data sources
- **Purpose**: Identifies queries requiring different processing modes

### web_search (0.2)
- **Applied**: When query requires external web search
- **Purpose**: Adds complexity but less significant than decomposition

### contextual_dependency (0.3)
- **Applied**: When query depends on previous context
- **Purpose**: Indicates query cannot be processed in isolation

### minimum_threshold (0.4) ⭐
- **Applied**: As cutoff for complexity classification
- **Purpose**: **Prevents false positives** from single weak indicators
- **Critical**: This threshold ensures only queries with substantial complexity are decomposed

## Important Notes

### Pure LLM Mode

This class operates in **Pure LLM mode**, meaning:
- Pattern detection methods return default values (0, false, empty arrays)
- Actual complexity detection is delegated to LLM-based analysis
- The weights array is maintained for future use but pattern matching is disabled

### Language Handling

- All queries are **pre-translated to English** before reaching this class
- Translation is handled upstream by `Semantics::translateToEnglish()`
- All patterns and keywords are in English only

### Defensive Programming

The class includes defensive enhancements:
- **Weight validation**: Checks all required keys exist
- **Null coalescing**: Uses `??` operator as fallback
- **Logging**: Warns about missing keys without breaking execution

## Bug Fix History

### 2026-01-03: Missing minimum_threshold Key

**Problem**: PHP warning "Undefined array key 'minimum_threshold'" at line 134

**Solution**: Added `'minimum_threshold' => 0.4` to weights array

**Impact**: 
- ✅ Eliminates PHP warnings
- ✅ Maintains backward compatibility
- ✅ Improves code robustness

See: `.kiro/specs/current/complex-query-handler-threshold-fix/`

## Related Classes

- `HybridQueryProcessor`: Processes hybrid queries
- `TaskPlanner`: Plans execution of sub-queries
- `Semantics`: Handles query translation
- `SecurityLogger`: Logs security and debug events

## Testing

Unit tests are located in:
- `unit_test/2026_01_03/test_weights_array_completeness.php`
- `unit_test/2026_01_03/test_defensive_code.php`

## Maintenance

When modifying the weights array:
1. Update the `REQUIRED_WEIGHT_KEYS` constant
2. Update inline comments for each weight
3. Update this README documentation
4. Run unit tests to verify no regressions
5. Consider impact on complexity threshold

## References

- Requirements: `.kiro/specs/current/complex-query-handler-threshold-fix/requirements.md`
- Design: `.kiro/specs/current/complex-query-handler-threshold-fix/design.md`
- Tasks: `.kiro/specs/current/complex-query-handler-threshold-fix/tasks.md`
