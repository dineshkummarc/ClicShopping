# SubOrchestrator Components

This directory contains specialized components that were extracted from `OrchestratorAgent` to improve maintainability and follow the Single Responsibility Principle.

## Architecture Overview

```
OrchestratorAgent (Main Orchestrator)
├── IntentAnalyzer (Intent Analysis & Classification)
├── DiagnosticManager (Health Monitoring & Error Analysis)
├── HybridQueryProcessor (Hybrid Query Handling)
└── EntityExtractor (Entity Extraction from Results)
```

## Components

### 1. IntentAnalyzer
**Responsibility:** Analyze user queries to determine intent and extract metadata

**Key Methods:**
- `analyze(string $query): array` - Main analysis method
- `extractCleanTranslation()` - Clean GPT translation artifacts
- `detectHybridQuery()` - Detect queries requiring multiple agents
- `calculateConfidence()` - Calculate classification confidence
- `extractMetadata()` - Extract entities, time ranges, filters
- `requiresConversationContext()` - Check if context is needed

**Usage:**
```php
$intentAnalyzer = new IntentAnalyzer($conversationMemory, $debug);
$intent = $intentAnalyzer->analyze("Quelles sont nos marques de produit?");
// Returns: ['type' => 'analytics', 'confidence' => 0.9, 'metadata' => [...], ...]
```

### 2. DiagnosticManager ✅ COMPLETE
**Responsibility:** System health monitoring and error diagnostics

**Key Methods:**
- `storeError()` - Store errors for analysis
- `getHealthReport()` - Get system health metrics (success rate, response time, memory usage)
- `explainLastError()` - AI-powered error explanation using GPT
- `getRecentErrors()` - Get recent error list with details
- `suggestImprovements()` - Analyze error patterns and suggest fixes
- `calculateClassificationAccuracy()` - Calculate classification accuracy from errors

**Usage:**
```php
$diagnosticManager = new DiagnosticManager($executionStats, $debug);

// Store an error
$diagnosticManager->storeError($errorMessage, $query, ['intent' => $intent, 'stack_trace' => $trace]);

// Get health report
$health = $diagnosticManager->getHealthReport();
// Returns: ['status' => 'healthy', 'metrics' => [...], 'components' => [...]]

// Explain last error
$explanation = $diagnosticManager->explainLastError();

// Get improvement suggestions
$suggestions = $diagnosticManager->suggestImprovements();
```

### 3. HybridQueryProcessor ✅ COMPLETE
**Responsibility:** Handle queries requiring multiple agents (analytics + semantic + web search)

**Key Methods:**
- `detectMultipleIntents()` - Detect multi-part queries
- `splitHybridQuery()` - Split into sub-queries using GPT
- `executeSemanticQuery()` - Execute semantic search
- `executeAnalyticsQuery()` - Execute analytics query
- `executeWebSearchQuery()` - Execute web search
- `synthesizeResults()` - Combine and synthesize results

**Usage:**
```php
$hybridProcessor = new HybridQueryProcessor($debug);

// Detect if query is hybrid
$isHybrid = $hybridProcessor->detectMultipleIntents($query, $intent);

// Split into sub-queries
$subQueries = $hybridProcessor->splitHybridQuery($query, $intent);

// Execute each sub-query
$results = [];
foreach ($subQueries as $subQuery) {
  if ($subQuery['type'] === 'analytics') {
    $results[] = $hybridProcessor->executeAnalyticsQuery($subQuery['query']);
  } elseif ($subQuery['type'] === 'semantic') {
    $results[] = $hybridProcessor->executeSemanticQuery($subQuery['query']);
  } elseif ($subQuery['type'] === 'web_search') {
    $results[] = $hybridProcessor->executeWebSearchQuery($subQuery['query']);
  }
}

// Synthesize results
$finalResult = $hybridProcessor->synthesizeResults($results, $query);
```

### 4. EntityExtractor ✅ COMPLETE
**Responsibility:** Extract entity IDs and types from execution results

**Key Methods:**
- `extractEntityId()` - Extract entity ID from multiple sources
- `extractEntityType()` - Extract entity type from multiple sources
- `extractFromPlan()` - Extract from ExecutionPlan step results
- `extractFromStepResults()` - Extract from step results in executionResult
- `extractFromIntent()` - Extract from intent metadata

**Usage:**
```php
$entityExtractor = new EntityExtractor($debug);
$entityId = $entityExtractor->extractEntityId($executionResult, $intent, $plan);
$entityType = $entityExtractor->extractEntityType($executionResult, $intent, $plan);
// Returns: entity_id (int|null), entity_type (string|null)
```

## Benefits of Refactoring

✅ **Maintainability:** Each class has a single, clear responsibility
✅ **Testability:** Easier to write unit tests for isolated components
✅ **Reusability:** Components can be reused in other contexts
✅ **Readability:** Smaller, focused classes are easier to understand
✅ **Extensibility:** New features can be added without modifying core orchestrator

## Migration Status

- [x] IntentAnalyzer - COMPLETE
- [x] DiagnosticManager - COMPLETE
- [x] HybridQueryProcessor - COMPLETE ✅
- [x] EntityExtractor - COMPLETE

## Original OrchestratorAgent Size

- **Before refactoring:** ~1634 lines
- **Target after refactoring:** ~500-600 lines (orchestration only)
- **Current reduction:** 
  - ~80 lines moved to IntentAnalyzer
  - ~150 lines moved to EntityExtractor
  - ~300 lines moved to DiagnosticManager
  - ~200 lines moved to HybridQueryProcessor
  - **Total: ~730 lines extracted**
- **Current OrchestratorAgent size:** ~1640 lines (includes new delegation methods)
- **Refactorisation:** ✅ 100% COMPLETE (4/4 composants créés)

## Development Guidelines

1. **Single Responsibility:** Each class should have ONE clear purpose
2. **Dependency Injection:** Pass dependencies through constructor
3. **Interface Segregation:** Keep interfaces small and focused
4. **Logging:** Use SecurityLogger for structured logging
5. **Error Handling:** Handle errors gracefully and provide context
6. **Documentation:** Document public methods with PHPDoc

## Testing

Each SubOrchestrator component should have corresponding unit tests:

```
tests/
└── SubOrchestrator/
    ├── IntentAnalyzerTest.php
    ├── DiagnosticManagerTest.php
    ├── HybridQueryProcessorTest.php
    └── EntityExtractorTest.php
```

## Version History

- **v1.0** (2025-10-24) - Initial refactoring with IntentAnalyzer
- **v1.1** (2025-10-24) - Add EntityExtractor with multi-source extraction
- **v1.2** (2025-10-24) - Add DiagnosticManager with health monitoring and error analysis
- **v1.3** (2025-10-24) - Add HybridQueryProcessor for multi-agent queries ✅ COMPLETE
