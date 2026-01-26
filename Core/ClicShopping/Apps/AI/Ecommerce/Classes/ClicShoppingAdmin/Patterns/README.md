# Patterns Directory - ClicShoppingAdmin Context

## Purpose

This directory contains **pattern classes** used for **table filtering** in the GuardrailsConfig dynamic table discovery.

## Important Distinction

⚠️ **CRITICAL**: These patterns are used for **TABLE FILTERING ONLY**, NOT for entity detection or query classification.

### What Patterns ARE Used For:
✅ **Table Discovery**: Filtering database tables to identify domain-relevant tables  
✅ **Dynamic getAllowedTables()**: Determining which tables are relevant for the domain  
✅ **Security Filtering**: Helping GuardrailsConfig identify valid domain tables  

### What Patterns are NOT Used For:
❌ **Entity Detection**: Uses Pure LLM Mode (not patterns)  
❌ **Query Classification**: Uses Pure LLM Mode (not patterns)  
❌ **Intent Analysis**: Uses Pure LLM Mode (not patterns)  

## Current Pattern Classes

### GuardrailsPattern.php
**Purpose**: Generic pattern matcher for table filtering (reusable across Apps)

**Usage**:
```php
// In GuardrailsConfig::getAllowedTables()
if (GuardrailsPattern::matches($tableName)) {
    $allowedTables[] = $tableName;
}
```

**Pattern Categories (Ecommerce)**:
1. **Core Entities**: products, orders, customers, categories, manufacturers, reviews
2. **Attributes**: *_attributes, *_options, *_values
3. **Descriptions**: *_description
4. **Analytics**: rag_*, web_search*
5. **Relationships**: *_to_*, notifications

**Methods**:
- `getPatterns()`: Returns array of regex patterns
- `matches($tableName)`: Tests if table matches domain patterns
- `getPatternCategories()`: Returns structured pattern documentation

**Reusability**: This class can be copied/adapted for other domain apps:
- **HR App**: Employee, department, payroll patterns
- **Finance App**: Transaction, account, invoice patterns
- **Trading App**: Order, position, portfolio patterns

## Architecture Context

### Pure LLM Mode (Current Implementation)
The RAG BI Multi-Domain Evolution project uses **Pure LLM Mode** for:
- Entity detection from user queries
- Query classification (analytics/semantic/web)
- Intent analysis and understanding

### Pattern Usage (Table Filtering)
Patterns are used ONLY for:
- Filtering database tables during dynamic discovery
- Identifying which tables are domain-related
- Supporting GuardrailsConfig security rules

## Why Separate Patterns from GuardrailsConfig?

1. **Separation of Concerns**: GuardrailsConfig handles security, Patterns handle matching logic
2. **Reusability**: Pattern classes can be reused by other Apps (HR, Finance, etc.)
3. **Maintainability**: Easier to update patterns without touching security logic
4. **Testability**: Patterns can be tested independently
5. **Clarity**: Clear distinction between security rules and pattern matching
6. **Portability**: Easy to copy GuardrailsPattern to other domain apps

## Adding New Patterns

If you need to add new table patterns:

1. Update `GuardrailsPattern::getPatterns()` with new regex
2. Document the pattern in `getPatternCategories()`
3. Test the pattern with `matches()` method
4. Update this README with the new pattern category

Example:
```php
// In GuardrailsPattern.php
public static function getPatterns(): array
{
    return [
        // ... existing patterns ...
        '/^suppliers/',  // NEW: Supplier tables
    ];
}
```

## Reusing for Other Domain Apps

To create patterns for a new domain app (e.g., HR):

1. Copy `GuardrailsPattern.php` to the new app:
   ```
   Core/ClicShopping/Apps/AI/HR/Classes/ClicShoppingAdmin/Patterns/GuardrailsPattern.php
   ```

2. Update namespace:
   ```php
   namespace ClicShopping\Apps\AI\HR\Classes\ClicShoppingAdmin\Patterns;
   ```

3. Update patterns in `getPatterns()`:
   ```php
   return [
       '/^employees/',      // HR: Employee tables
       '/^departments/',    // HR: Department tables
       '/^payroll/',        // HR: Payroll tables
       '/^attendance/',     // HR: Attendance tables
       // ... etc
   ];
   ```

4. Use in HR GuardrailsConfig:
   ```php
   use ClicShopping\Apps\AI\HR\Classes\ClicShoppingAdmin\Patterns\GuardrailsPattern;
   
   if (GuardrailsPattern::matches($tableName)) {
       $allowedTables[] = $tableName;
   }
   ```

## Future Pattern Classes

When Shop context is implemented (Task 5.3), create:
- `Core/ClicShopping/Apps/AI/Ecommerce/Classes/Shop/Patterns/GuardrailsPattern.php`

Shop patterns may be more restrictive than Admin patterns for security.

## See Also

- `GuardrailsConfig.php` - Uses GuardrailsPattern for table filtering
- `Core/ClicShopping/AI/Infrastructure/Orm/DoctrineOrm.php` - Provides getRelevantTables()
- `.kiro/specs/active/rag-multi-domain-evolution/design.md` - Pure LLM Mode specification
