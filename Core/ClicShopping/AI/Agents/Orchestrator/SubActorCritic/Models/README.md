# Adaptive Weighting Data Models

This directory contains the data models for the Agent Adaptive Weighting system.

## Overview

The adaptive weighting system uses these models to represent weight calculations, consensus results, and evaluation contexts. All models follow PHP 8.4+ standards with typed properties and constructor promotion.

## Models

### WeightResult

**Purpose**: Represents the complete result of LLM weight analysis.

**Key Fields**:
- `evaluationId`: Unique identifier for the evaluation
- `weights`: Raw weights assigned by LLM (before normalization)
- `normalizedWeights`: Normalized weights that sum to 1.0
- `explanations`: LLM-generated explanations per critic
- `overallRationale`: LLM's overall reasoning for the weighting strategy
- `factorAnalysis`: Analysis of which factors were most important
- `bounds`: Optional min/max bounds applied to weights
- `isFallback`: Whether fallback weighting was used
- `fallbackReason`: Reason for fallback if applicable

**Usage**:
```php
$result = new WeightResult(
    evaluationId: 'eval_123',
    weights: ['critic1' => 0.4, 'critic2' => 0.35, 'critic3' => 0.25],
    normalizedWeights: ['critic1' => 0.4, 'critic2' => 0.35, 'critic3' => 0.25],
    explanations: [
        'critic1' => 'High expertise in security domain...',
        'critic2' => 'Strong reputation with recent improvements...',
        'critic3' => 'Competent but lower confidence...'
    ],
    overallRationale: 'Weighted heavily toward security expertise...',
    factorAnalysis: ['expertise' => 0.45, 'reputation' => 0.30, 'confidence' => 0.25]
);
```

### WeightExplanation

**Purpose**: Detailed explanation for a single critic's weight assignment.

**Key Fields**:
- `criticId`: Identifier of the critic
- `weight`: The assigned weight value
- `explanation`: Natural language explanation
- `factorInfluence`: How each factor influenced the weight
- `dominantFactor`: Which factor had the most influence
- `concerns`: Any concerns identified by LLM
- `strengths`: Strengths identified by LLM

**Usage**:
```php
$explanation = new WeightExplanation(
    criticId: 'critic1',
    weight: 0.45,
    explanation: 'This critic has expert-level security knowledge...',
    factorInfluence: [
        'reputation' => 0.85,
        'expertise' => 0.95,
        'confidence' => 0.80,
        'recency' => 0.90
    ],
    dominantFactor: 'expertise',
    concerns: ['Slightly lower confidence than usual'],
    strengths: ['Expert security knowledge', 'Consistent performance']
);
```

### ConsensusResult

**Purpose**: Result of consensus calculation comparing dynamic and static approaches.

**Key Fields**:
- `evaluationId`: Unique identifier for the evaluation
- `dynamicConsensus`: Consensus using adaptive weights
- `staticConsensus`: Consensus using reputation-only weights
- `consensusDifference`: Difference between the two approaches
- `weightedScores`: Individual weighted scores per critic
- `confidenceLevel`: Confidence in the consensus
- `consensusQuality`: LLM assessment of quality

**Usage**:
```php
$consensus = new ConsensusResult(
    evaluationId: 'eval_123',
    dynamicConsensus: 0.87,
    staticConsensus: 0.82,
    consensusDifference: 0.05,
    weightedScores: [
        'critic1' => 0.36,  // 0.9 score × 0.4 weight
        'critic2' => 0.28,  // 0.8 score × 0.35 weight
        'critic3' => 0.23   // 0.92 score × 0.25 weight
    ],
    confidenceLevel: 0.85,
    consensusQuality: 'High quality consensus with good agreement'
);
```

### EvaluationContext

**Purpose**: Describes the evaluation scenario and requirements for adaptive weighting.

**Key Fields**:
- `evaluationId`: Unique identifier for the evaluation
- `outputType`: Type of output being evaluated (e.g., 'search_results', 'analytics_query')
- `requiredExpertise`: Array of required expertise domains
- `priorityLevel`: Priority level (low, medium, high, critical)
- `specialRequirements`: Array of special requirements (e.g., 'security-sensitive')
- `domain`: Optional business domain
- `metadata`: Additional context data

**Usage**:
```php
$context = new EvaluationContext(
    evaluationId: 'eval_123',
    outputType: 'security_validation',
    requiredExpertise: ['security', 'performance'],
    priorityLevel: 'critical',
    specialRequirements: ['security-sensitive', 'compliance-required'],
    domain: 'ecommerce',
    metadata: ['user_role' => 'admin', 'data_sensitivity' => 'high']
);
```

## Existing Models (Reused)

The adaptive weighting system leverages these existing models from the Actor-Critic system:

### Context
- **Location**: `Core/ClicShopping/AI/Agents/Orchestrator/SubActorCritic/Context.php`
- **Purpose**: Execution context for actions and evaluations
- **Key Fields**: userId, languageId, systemState, userPreferences, environmentalData

### Evaluation
- **Location**: `Core/ClicShopping/AI/Agents/Orchestrator/SubActorCritic/Evaluation.php`
- **Purpose**: Critic's evaluation of an action result
- **Key Fields**: evaluatorAgentId, scores (accuracy, completeness, efficiency, clarity), feedback

### EvaluationCriteria
- **Location**: `Core/ClicShopping/AI/Agents/Orchestrator/SubActorCritic/EvaluationCriteria.php`
- **Purpose**: Critic's evaluation criteria for specific output types
- **Key Fields**: outputType, expertiseLevel, domain, evaluationWeights
- **Note**: Already contains `domain` and `expertiseLevel` fields used by adaptive weighting

## Database Schema

The models are persisted to these database tables:

### rag_agent_adaptive_weights
- Stores all weight calculations with LLM explanations
- Maps to: WeightResult data

### rag_agent_weight_consensus
- Stores consensus comparisons
- Maps to: ConsensusResult data

### rag_agent_weight_anomalies
- Stores detected anomalies
- Used by: Anomaly detection system

### rag_agent_critic_weight_history
- Tracks weight history per critic
- Used by: Trend analysis and visualization

## Design Patterns

### Immutability
All models are designed to be immutable after construction. They provide getters but no setters (except for EvaluationContext.addMetadata for convenience).

### Type Safety
All models use PHP 8.4+ typed properties and return types for maximum type safety.

### Array Conversion
All models implement `toArray()` for easy serialization to JSON or database storage.

### Null Safety
Optional fields use nullable types (`?string`, `?array`) and provide null-safe accessors.

## Integration Points

### With LLMWeightingEngine
- **Input**: EvaluationContext, critic profiles
- **Output**: WeightResult

### With WeightedConsensusBuilder
- **Input**: WeightResult, Evaluation array
- **Output**: ConsensusResult

### With WeightAuditLogger
- **Input**: WeightResult, ConsensusResult
- **Output**: Database records

## Testing

Property-based tests verify:
- Weight normalization invariants (sum to 1.0)
- Data completeness (all required fields present)
- Explanation quality (mentions all factors)
- Consensus calculation correctness

Unit tests verify:
- Model construction and getters
- Array conversion
- Helper methods (hasRequiredExpertise, isCritical, etc.)
- Edge cases (empty arrays, null values)

## Future Enhancements

Potential additions to the models:

1. **WeightTrend**: Track weight trends over time
2. **CriticProfile**: Complete critic profile with reputation history
3. **WeightBounds**: Separate model for weight bounds configuration
4. **AnomalyReport**: Structured anomaly detection results

## References

- Requirements: `.kiro/specs/active/agent-adaptive-weighting/requirements.md`
- Design: `.kiro/specs/active/agent-adaptive-weighting/design.md`
- Tasks: `.kiro/specs/active/agent-adaptive-weighting/tasks.md`
