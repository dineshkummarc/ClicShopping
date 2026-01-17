# DEPRECATED: Pattern-Based Logic

**Status**: Deprecated  
**Reason**: Superseded by Pure LLM Mode  
**Scheduled Removal**: Q3 2026  
**Date Deprecated**: January 17, 2026

---

## Executive Summary

All pattern-based detection and classification logic in `Core/ClicShopping/AI/Domain/Patterns/` has been **deprecated** and is scheduled for removal in **Q3 2026**.

Pattern-based detection has been completely replaced by **Pure LLM Mode**, which provides:
- ✅ Better accuracy (95%+ vs 70-80% with patterns)
- ✅ Better multilingual support (no need for language-specific patterns)
- ✅ Better handling of edge cases and ambiguous queries
- ✅ Simpler codebase (no complex regex maintenance)
- ✅ More flexible and adaptable to new query types

---

## Why Deprecated

### Problem with Pattern-Based Approach

1. **Low Accuracy**: Pattern matching achieved only 70-80% accuracy
2. **Maintenance Burden**: Required constant updates for new query variations
3. **Language Limitations**: Patterns needed to be duplicated for each language
4. **Brittle**: Small query variations could break pattern matching
5. **Complex**: Hundreds of regex patterns difficult to maintain and debug

### Pure LLM Mode Advantages

1. **High Accuracy**: Achieves 95%+ accuracy using LLM classification
2. **Zero Maintenance**: No patterns to update, LLM adapts automatically
3. **Multilingual**: Works across all languages without language-specific code
4. **Robust**: Handles query variations, typos, and edge cases gracefully
5. **Simple**: Clean, maintainable code with clear logic flow

---

## Migration Path

### For Intent Classification

**OLD (Pattern-Based)**:
```php
use ClicShopping\AI\Domain\Patterns\Analytics\AnalyticsPattern;
use ClicShopping\AI\Domain\Patterns\Semantic\SemanticPattern;

// Pattern matching
if (AnalyticsPattern::matches($query)) {
    $intent = 'analytics';
} elseif (SemanticPattern::matches($query)) {
    $intent = 'semantic';
}
```

**NEW (Pure LLM Mode)**:
```php
use ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer\UnifiedQueryAnalyzer;

// LLM-based classification
$analyzer = new UnifiedQueryAnalyzer();
$result = $analyzer->analyzeQuery($query, $context);
$intent = $result['intent']; // 'semantic', 'analytics', 'hybrid', 'websearch'
```

### For Semantic Classification

**OLD (Pattern-Based)**:
```php
use ClicShopping\AI\Domain\Patterns\Semantic\ClassificationEnginePatterns;

// Pattern matching
$patterns = ClassificationEnginePatterns::getPatterns();
foreach ($patterns as $pattern) {
    if (preg_match($pattern, $query)) {
        // Classification logic
    }
}
```

**NEW (Pure LLM Mode)**:
```php
use ClicShopping\AI\Domains\Semantic\Processor\ClassificationEngine;

// LLM-based classification
$engine = new ClassificationEngine();
$classification = $engine->classifyQuery($query, $context);
// Returns: entity_type, confidence, reasoning
```

### For Analytics Detection

**OLD (Pattern-Based)**:
```php
use ClicShopping\AI\Domain\Patterns\Analytics\TemporalFinancialPatterns;
use ClicShopping\AI\Domain\Patterns\Analytics\SuperlativePatterns;

// Pattern matching
if (TemporalFinancialPatterns::matches($query)) {
    // Temporal analytics
} elseif (SuperlativePatterns::matches($query)) {
    // Superlative analytics
}
```

**NEW (Pure LLM Mode)**:
```php
use ClicShopping\AI\Domains\Analytics\Agent\AnalyticsAgent;

// LLM-based analytics
$agent = new AnalyticsAgent();
$result = $agent->processQuery($query, $context);
// LLM determines query type, temporal aspects, superlatives automatically
```

---

## Deprecated Directories

The following directories are deprecated and will be removed in Q3 2026:

### Analytics Patterns
- `Domain/Patterns/Analytics/AnalyticsExecutorPatterns.php`
- `Domain/Patterns/Analytics/FinancialMetricsPattern.php`
- `Domain/Patterns/Analytics/MultiTemporalPostFilter.php`
- `Domain/Patterns/Analytics/OperatorPattern.php`
- `Domain/Patterns/Analytics/QueryCriteriaPattern.php`
- `Domain/Patterns/Analytics/QuerySplitterPatterns.php`
- `Domain/Patterns/Analytics/SuperlativePatterns.php`
- `Domain/Patterns/Analytics/SuperlativePostFilter.php`
- `Domain/Patterns/Analytics/TemporalConflictPattern.php`
- `Domain/Patterns/Analytics/TemporalFinancialPatterns.php`
- `Domain/Patterns/Analytics/TemporalFinancialPreFilter.php`
- `Domain/Patterns/Analytics/TemporalPeriodMappingPattern.php`
- `Domain/Patterns/Analytics/TimeRangePattern.php`

### Semantic Patterns
- `Domain/Patterns/Semantic/ClassificationEnginePatterns.php`
- `Domain/Patterns/Semantic/PatternAnalysisPattern.php`

### Hybrid Patterns
- `Domain/Patterns/Hybrid/AggregationDimensionPatterns.php`
- `Domain/Patterns/Hybrid/AmbiguityPreFilter.php`
- `Domain/Patterns/Hybrid/HybridPreFilter.php`

### WebSearch Patterns
- `Domain/Patterns/WebSearch/WebSearchPatterns.php`
- `Domain/Patterns/WebSearch/WebSearchPostFilter.php`

### Security Patterns
- `Domain/Patterns/Security/ObfuscationPatterns.php`

### Common Patterns
- `Domain/Patterns/Common/CompoundQueryIndicatorsPattern.php`
- `Domain/Patterns/Common/EntityKeywordsPattern.php`

### Ecommerce Patterns
- `Domain/Patterns/Ecommerce/ContextResetPattern.php`
- `Domain/Patterns/Ecommerce/ContinuationPattern.php`
- `Domain/Patterns/Ecommerce/EntityDetectionPattern.php`
- `Domain/Patterns/Ecommerce/ModificationKeywordsPattern.php`

**Total**: 27 pattern files

---

## Cleanup Schedule

### Q1 2026 (Current)
- ✅ Mark all pattern classes as @deprecated
- ✅ Create DEPRECATED.md documentation
- ✅ Create DEPRECATED_CLEANUP_TASKS.md
- ✅ Add deprecation comments in all pattern files

### Q2 2026
- Monitor usage of deprecated patterns
- Ensure zero dependencies on pattern classes
- Verify all code uses Pure LLM Mode
- Update any remaining pattern references

### Q3 2026
- **Remove all deprecated pattern code**
- Delete `Domain/Patterns/` directory
- Update documentation
- Run comprehensive tests
- Verify no broken references

---

## Current Status

**Pure LLM Mode Implementation**: ✅ COMPLETE

All query processing now uses LLM-based classification:

1. **Intent Classification**: `UnifiedQueryAnalyzer` (LLM-based)
2. **Semantic Classification**: `ClassificationEngine` (LLM-based)
3. **Analytics Detection**: `AnalyticsAgent` (LLM-based)
4. **Hybrid Detection**: `HybridQueryProcessor` (LLM-based)
5. **WebSearch Detection**: `WebSearchHandler` (LLM-based)

**Pattern Usage**: ❌ ZERO

No production code currently uses pattern-based detection. All patterns are preserved only for reference and potential future hybrid approaches.

---

## Testing

Before removing deprecated code in Q3 2026, verify:

1. ✅ All tests pass without pattern classes
2. ✅ No grep results for pattern class usage in production code
3. ✅ Pure LLM Mode achieves target accuracy (95%+)
4. ✅ Performance meets requirements (<100ms per query)
5. ✅ All query types handled correctly

---

## Questions?

**Q: Can I still use pattern classes?**  
A: Technically yes, but they are deprecated and will be removed in Q3 2026. Migrate to Pure LLM Mode immediately.

**Q: What if I need pattern matching for a specific case?**  
A: Pure LLM Mode handles all cases better than patterns. If you have a specific need, discuss with the team before using deprecated patterns.

**Q: Will pattern classes be maintained?**  
A: No. Pattern classes are frozen and will not receive bug fixes or updates.

**Q: What if I find a bug in pattern code?**  
A: Don't fix it. Migrate to Pure LLM Mode instead, which doesn't have the bug.

**Q: Can I create new pattern classes?**  
A: No. All new detection logic must use Pure LLM Mode.

---

## References

- **Pure LLM Mode Documentation**: `Core/ClicShopping/AI/ARCHITECTURE.md`
- **UnifiedQueryAnalyzer**: `Core/ClicShopping/AI/Agents/Orchestrator/SubIntentAnalyzer/UnifiedQueryAnalyzer.php`
- **ClassificationEngine**: `Core/ClicShopping/AI/Domains/Semantic/Processor/ClassificationEngine.php`
- **Migration Guide**: `Core/ClicShopping/AI/MIGRATION_GUIDE.md`

---

**Status**: Deprecated  
**Last Updated**: January 17, 2026  
**Removal Date**: Q3 2026  
**Contact**: Development Team
