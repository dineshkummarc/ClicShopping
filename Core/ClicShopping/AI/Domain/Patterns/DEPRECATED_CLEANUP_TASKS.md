# Deprecated Code Cleanup Tasks

**Scheduled Cleanup Date**: Q3 2026  
**Created**: January 17, 2026  
**Status**: Pending

---

## Overview

This document outlines the tasks required to remove all deprecated pattern-based logic from the ClicShopping AI system in Q3 2026.

**Estimated Effort**: 1.5 days
- 1 day for removal
- 0.5 day for verification and testing

---

## Q3 2026 Cleanup Tasks

### Phase 1: Pre-Removal Verification (0.5 day)

#### Task 1.1: Verify Zero Pattern Usage
- [ ] Grep for pattern class usage in production code
- [ ] Verify no `use ClicShopping\AI\Domain\Patterns\` statements
- [ ] Check for any dynamic pattern loading
- [ ] Review recent commits for pattern additions

**Command**:
```bash
# Search for pattern usage
grep -r "use ClicShopping\\\\AI\\\\Domain\\\\Patterns" Core/ClicShopping/AI --exclude-dir=Domain/Patterns

# Should return: No matches found
```

#### Task 1.2: Verify Pure LLM Mode Performance
- [ ] Run performance benchmarks
- [ ] Verify accuracy >= 95%
- [ ] Verify response time < 100ms per query
- [ ] Check error rates < 1%

**Test Command**:
```bash
php unit_test/run_all_tests.php
php unit_test/2026_q3/test_pure_llm_performance.php
```

#### Task 1.3: Backup Pattern Code
- [ ] Create backup of `Domain/Patterns/` directory
- [ ] Store in `backups/patterns_deprecated_2026_q3/`
- [ ] Document backup location in CHANGELOG

**Command**:
```bash
mkdir -p backups/patterns_deprecated_2026_q3
cp -r Core/ClicShopping/AI/Domain/Patterns backups/patterns_deprecated_2026_q3/
```

---

### Phase 2: Pattern Code Removal (0.5 day)

#### Task 2.1: Remove Analytics Patterns
- [ ] Remove `Domain/Patterns/Analytics/AnalyticsExecutorPatterns.php`
- [ ] Remove `Domain/Patterns/Analytics/FinancialMetricsPattern.php`
- [ ] Remove `Domain/Patterns/Analytics/MultiTemporalPostFilter.php`
- [ ] Remove `Domain/Patterns/Analytics/OperatorPattern.php`
- [ ] Remove `Domain/Patterns/Analytics/QueryCriteriaPattern.php`
- [ ] Remove `Domain/Patterns/Analytics/QuerySplitterPatterns.php`
- [ ] Remove `Domain/Patterns/Analytics/SuperlativePatterns.php`
- [ ] Remove `Domain/Patterns/Analytics/SuperlativePostFilter.php`
- [ ] Remove `Domain/Patterns/Analytics/TemporalConflictPattern.php`
- [ ] Remove `Domain/Patterns/Analytics/TemporalFinancialPatterns.php`
- [ ] Remove `Domain/Patterns/Analytics/TemporalFinancialPreFilter.php`
- [ ] Remove `Domain/Patterns/Analytics/TemporalPeriodMappingPattern.php`
- [ ] Remove `Domain/Patterns/Analytics/TimeRangePattern.php`
- [ ] Remove `Domain/Patterns/Analytics/` directory

**Command**:
```bash
rm -rf Core/ClicShopping/AI/Domain/Patterns/Analytics
```

#### Task 2.2: Remove Semantic Patterns
- [ ] Remove `Domain/Patterns/Semantic/ClassificationEnginePatterns.php`
- [ ] Remove `Domain/Patterns/Semantic/PatternAnalysisPattern.php`
- [ ] Remove `Domain/Patterns/Semantic/` directory

**Command**:
```bash
rm -rf Core/ClicShopping/AI/Domain/Patterns/Semantic
```

#### Task 2.3: Remove Hybrid Patterns
- [ ] Remove `Domain/Patterns/Hybrid/AggregationDimensionPatterns.php`
- [ ] Remove `Domain/Patterns/Hybrid/AmbiguityPreFilter.php`
- [ ] Remove `Domain/Patterns/Hybrid/HybridPreFilter.php`
- [ ] Remove `Domain/Patterns/Hybrid/` directory

**Command**:
```bash
rm -rf Core/ClicShopping/AI/Domain/Patterns/Hybrid
```

#### Task 2.4: Remove WebSearch Patterns
- [ ] Remove `Domain/Patterns/WebSearch/WebSearchPatterns.php`
- [ ] Remove `Domain/Patterns/WebSearch/WebSearchPostFilter.php`
- [ ] Remove `Domain/Patterns/WebSearch/` directory

**Command**:
```bash
rm -rf Core/ClicShopping/AI/Domain/Patterns/WebSearch
```

#### Task 2.5: Remove Security Patterns
- [ ] Remove `Domain/Patterns/Security/ObfuscationPatterns.php`
- [ ] Remove `Domain/Patterns/Security/` directory

**Command**:
```bash
rm -rf Core/ClicShopping/AI/Domain/Patterns/Security
```

#### Task 2.6: Remove Common Patterns
- [ ] Remove `Domain/Patterns/Common/CompoundQueryIndicatorsPattern.php`
- [ ] Remove `Domain/Patterns/Common/EntityKeywordsPattern.php`
- [ ] Remove `Domain/Patterns/Common/` directory

**Command**:
```bash
rm -rf Core/ClicShopping/AI/Domain/Patterns/Common
```

#### Task 2.7: Remove Ecommerce Patterns
- [ ] Remove `Domain/Patterns/Ecommerce/ContextResetPattern.php`
- [ ] Remove `Domain/Patterns/Ecommerce/ContinuationPattern.php`
- [ ] Remove `Domain/Patterns/Ecommerce/EntityDetectionPattern.php`
- [ ] Remove `Domain/Patterns/Ecommerce/ModificationKeywordsPattern.php`
- [ ] Remove `Domain/Patterns/Ecommerce/` directory

**Command**:
```bash
rm -rf Core/ClicShopping/AI/Domain/Patterns/Ecommerce
```

#### Task 2.8: Remove Patterns Root Directory
- [ ] Remove `Domain/Patterns/README.md`
- [ ] Remove `Domain/Patterns/DEPRECATED.md`
- [ ] Remove `Domain/Patterns/DEPRECATED_CLEANUP_TASKS.md`
- [ ] Remove `Domain/Patterns/` directory

**Command**:
```bash
rm -rf Core/ClicShopping/AI/Domain/Patterns
```

---

### Phase 3: Verification (0.5 day)

#### Task 3.1: Verify No Broken References
- [ ] Search for any remaining pattern references
- [ ] Check for broken imports
- [ ] Verify no syntax errors

**Command**:
```bash
# Search for pattern references
grep -r "Domain\\\\Patterns" Core/ClicShopping/AI

# Should return: No matches found

# Check for PHP syntax errors
find Core/ClicShopping/AI -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"
```

#### Task 3.2: Run All Tests
- [ ] Run unit tests
- [ ] Run integration tests
- [ ] Run end-to-end tests
- [ ] Verify 100% pass rate

**Command**:
```bash
php unit_test/run_all_tests.php
php unit_test/2026_q3/test_pattern_removal_verification.php
```

#### Task 3.3: Performance Testing
- [ ] Run performance benchmarks
- [ ] Compare with pre-removal performance
- [ ] Verify no performance regression
- [ ] Document results

**Command**:
```bash
php unit_test/2026_q3/test_performance_after_cleanup.php
```

#### Task 3.4: Update Documentation
- [ ] Update ARCHITECTURE.md (remove pattern references)
- [ ] Update MIGRATION_GUIDE.md (mark cleanup complete)
- [ ] Update CHANGELOG.md (document pattern removal)
- [ ] Update README.md (remove pattern mentions)

---

## Rollback Plan

If issues are discovered after removal:

1. **Restore from Backup**:
   ```bash
   cp -r backups/patterns_deprecated_2026_q3/Patterns Core/ClicShopping/AI/Domain/
   ```

2. **Verify Restoration**:
   ```bash
   php unit_test/run_all_tests.php
   ```

3. **Investigate Issue**:
   - Identify what broke
   - Determine if pattern code was actually needed
   - Create fix using Pure LLM Mode instead

4. **Re-attempt Removal**:
   - Fix the issue
   - Re-run verification
   - Remove patterns again

---

## Success Criteria

Cleanup is considered successful when:

- ✅ All pattern directories removed
- ✅ No grep results for pattern class usage
- ✅ All tests pass (100% pass rate)
- ✅ No syntax errors
- ✅ No broken references
- ✅ Performance maintained or improved
- ✅ Documentation updated
- ✅ Backup created and documented

---

## Estimated File Counts

| Directory | Files | Lines of Code (est.) |
|-----------|-------|---------------------|
| Analytics/ | 13 | ~2,000 |
| Semantic/ | 2 | ~300 |
| Hybrid/ | 3 | ~400 |
| WebSearch/ | 2 | ~300 |
| Security/ | 1 | ~150 |
| Common/ | 2 | ~250 |
| Ecommerce/ | 4 | ~500 |
| **Total** | **27** | **~3,900** |

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Broken references | LOW | HIGH | Comprehensive grep search before removal |
| Test failures | LOW | MEDIUM | Run all tests before and after removal |
| Performance regression | VERY LOW | MEDIUM | Performance benchmarks before/after |
| Rollback needed | VERY LOW | LOW | Backup created before removal |

---

## Timeline

**Q3 2026 Schedule**:

- **Week 1**: Pre-removal verification (Tasks 1.1-1.3)
- **Week 2**: Pattern code removal (Tasks 2.1-2.8)
- **Week 3**: Verification and testing (Tasks 3.1-3.4)
- **Week 4**: Documentation and final review

**Total Duration**: 4 weeks (with buffer)

---

## Contacts

**Primary**: Development Team  
**Backup**: AI Architecture Team  
**Questions**: See DEPRECATED.md or contact team lead

---

**Status**: Pending  
**Created**: January 17, 2026  
**Scheduled**: Q3 2026  
**Last Updated**: January 17, 2026
