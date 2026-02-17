<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Critics;

use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;

use ClicShopping\AI\InterfacesAI\CriticAgentInterface;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Action;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\ActionResult;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Evaluation;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Prediction;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Feedback;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\EvaluationCriteria;

use ClicShopping\AI\DomainsAI\Analytics\Agent\DatabaseSchemaManager;
use ClicShopping\AI\DomainsAI\Analytics\Helper\Detection\AmbiguousQueryDetector;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Security\DbSecurity;
use ClicShopping\AI\RegistryAI\CriticRegistry;

/**
 * AnalyticsCritic - Critic agent specialized in SQL quality evaluation
 * 
 * This critic evaluates SQL queries and analytics results by:
 * - Analyzing SQL query accuracy and correctness
 * - Evaluating query completeness against requirements
 * - Assessing query efficiency and performance
 * - Checking query clarity and maintainability
 * - Predicting potential issues and risks
 * 
 * Evaluation Dimensions:
 * - Accuracy: Correctness of SQL syntax and logic
 * - Completeness: Whether query addresses all requirements
 * - Efficiency: Query performance and optimization
 * - Clarity: Readability and maintainability
 * 
 * @package ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Critics
 * @version 1.0.0
 * @since 2026-01-30
 */
class AnalyticsCritic implements CriticAgentInterface
{
    private string $criticId;
    private DatabaseSchemaManager $schemaManager;
    private AmbiguousQueryDetector $ambiguityDetector;
    private SecurityLogger $securityLogger;
    private DbSecurity $dbSecurity;
    private mixed $db;
    private int $languageId;
    private bool $debug;
    private array $evaluationHistory = [];
    
    /**
     * Constructor
     * 
     * @param int|null $languageId Language ID for filtering results
     * @param bool $debug Enable debug mode
     */
    public function __construct(?int $languageId = null, bool $debug = false)
    {
        $this->criticId = 'analytics_critic_' . uniqid();
        $this->db = Registry::get('Db');
        $this->languageId = $languageId ?? Registry::get('Language')->getId();
        $this->debug = $debug;
        
        // Initialize security components
        $this->securityLogger = new SecurityLogger();
        $this->dbSecurity = new DbSecurity();
        
        // Initialize schema manager
        $this->schemaManager = new DatabaseSchemaManager(
            $this->db,
            $this->securityLogger,
            $this->debug
        );
        
        // Initialize ambiguity detector (without chat for now - will use basic detection)
        $this->ambiguityDetector = new AmbiguousQueryDetector(
            null, // No chat instance needed for basic detection
            $this->securityLogger,
            $this->debug
        );
        
        // Register this critic in the CriticRegistry
        $this->registerInRegistry();
        
        $this->securityLogger->logSecurityEvent(
            "AnalyticsCritic initialized: {$this->criticId}",
            'info'
        );
    }
    
    /**
     * Evaluate an action result
     * 
     * @param ActionResult $result Result to evaluate
     * @return Evaluation Complete evaluation with scores and feedback
     * @throws \Exception If evaluation fails
     */
    public function evaluateAction(ActionResult $result): Evaluation
    {
        $startTime = microtime(true);
        
        try {
            $outputType = $result->getOutputType();
            $output = $result->getOutput();
            
            $this->securityLogger->logSecurityEvent(
                "AnalyticsCritic evaluating action result: {$outputType}",
                'info',
                ['critic_id' => $this->criticId, 'result_id' => $result->getResultId()]
            );
            
            // Route to appropriate evaluation method based on output type
            $scores = match($outputType) {
                'sql_query' => $this->evaluateSqlQuery($output, $result),
                'query_results' => $this->evaluateQueryResults($output, $result),
                'schema_info' => $this->evaluateSchemaInfo($output, $result),
                default => $this->evaluateGenericOutput($output, $result)
            };
            
            // Generate structured feedback
            $feedback = $this->generateFeedback($scores, $output, $outputType);
            $strengths = $this->identifyStrengths($scores, $output, $outputType);
            $improvements = $this->identifyImprovements($scores, $output, $outputType);
            
            $evaluation = new Evaluation(
                $this->criticId,
                $result->getResultId(),
                $scores,
                $feedback,
                $strengths,
                $improvements
            );
            
            // Store evaluation history
            $this->evaluationHistory[] = [
                'evaluation_id' => $evaluation->getEvaluationId(),
                'output_type' => $outputType,
                'scores' => $scores,
                'overall_score' => $evaluation->getOverallScore(),
                'evaluated_at' => date('Y-m-d H:i:s')
            ];
            
            $evaluationTime = microtime(true) - $startTime;
            
            $this->securityLogger->logSecurityEvent(
                "AnalyticsCritic completed evaluation",
                'info',
                [
                    'critic_id' => $this->criticId,
                    'overall_score' => $evaluation->getOverallScore(),
                    'evaluation_time' => $evaluationTime
                ]
            );
            
            return $evaluation;
            
        } catch (\Exception $e) {
            $this->securityLogger->logSecurityEvent(
                "AnalyticsCritic evaluation failed: " . $e->getMessage(),
                'error',
                ['critic_id' => $this->criticId, 'result_id' => $result->getResultId()]
            );
            
            throw new \Exception("Evaluation failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Evaluate SQL query output
     * 
     * @param array $output SQL query output
     * @param ActionResult $result Full action result
     * @return array Dimension scores
     */
    private function evaluateSqlQuery(array $output, ActionResult $result): array
    {
        $sql = $output['sql'] ?? '';
        $explanation = $output['explanation'] ?? '';
        $tablesUsed = $output['tables_used'] ?? [];
        $queryType = $output['query_type'] ?? 'unknown';
        
        // Accuracy: SQL syntax and logical correctness
        $accuracyScore = $this->evaluateSqlAccuracy($sql, $tablesUsed);
        
        // Completeness: Whether query addresses requirements
        $completenessScore = $this->evaluateSqlCompleteness($sql, $explanation, $result);
        
        // Efficiency: Query performance and optimization
        $efficiencyScore = $this->evaluateSqlEfficiency($sql, $tablesUsed, $queryType);
        
        // Clarity: Readability and maintainability
        $clarityScore = $this->evaluateSqlClarity($sql, $explanation);
        
        return [
            'accuracy' => $accuracyScore,
            'completeness' => $completenessScore,
            'efficiency' => $efficiencyScore,
            'clarity' => $clarityScore
        ];
    }
    
    /**
     * Evaluate query results output
     * 
     * @param array $output Query results output
     * @param ActionResult $result Full action result
     * @return array Dimension scores
     */
    private function evaluateQueryResults(array $output, ActionResult $result): array
    {
        $results = $output['results'] ?? [];
        $rowCount = $output['row_count'] ?? 0;
        $executionTime = $output['execution_time'] ?? 0;
        
        // Accuracy: Data correctness and consistency
        $accuracyScore = $this->evaluateResultsAccuracy($results, $rowCount);
        
        // Completeness: Whether results are complete
        $completenessScore = $this->evaluateResultsCompleteness($results, $result);
        
        // Efficiency: Query execution performance
        $efficiencyScore = $this->evaluateResultsEfficiency($executionTime, $rowCount);
        
        // Clarity: Result structure and presentation
        $clarityScore = $this->evaluateResultsClarity($results);
        
        return [
            'accuracy' => $accuracyScore,
            'completeness' => $completenessScore,
            'efficiency' => $efficiencyScore,
            'clarity' => $clarityScore
        ];
    }
    
    /**
     * Evaluate schema information output
     * 
     * @param array $output Schema info output
     * @param ActionResult $result Full action result
     * @return array Dimension scores
     */
    private function evaluateSchemaInfo(array $output, ActionResult $result): array
    {
        $tables = $output['tables'] ?? [];
        $columns = $output['columns'] ?? [];
        $relationships = $output['relationships'] ?? [];
        
        // Accuracy: Schema information correctness
        $accuracyScore = $this->evaluateSchemaAccuracy($tables, $columns);
        
        // Completeness: Whether schema info is complete
        $completenessScore = $this->evaluateSchemaCompleteness($tables, $columns, $relationships);
        
        // Efficiency: Information retrieval efficiency
        $efficiencyScore = 0.9; // Schema queries are typically efficient
        
        // Clarity: Information structure and organization
        $clarityScore = $this->evaluateSchemaClarity($tables, $columns, $relationships);
        
        return [
            'accuracy' => $accuracyScore,
            'completeness' => $completenessScore,
            'efficiency' => $efficiencyScore,
            'clarity' => $clarityScore
        ];
    }
    
    /**
     * Evaluate generic output (fallback)
     * 
     * @param mixed $output Generic output
     * @param ActionResult $result Full action result
     * @return array Dimension scores
     */
    private function evaluateGenericOutput($output, ActionResult $result): array
    {
        // Basic evaluation for unknown output types
        $hasOutput = !empty($output);
        $isStructured = is_array($output);
        
        return [
            'accuracy' => $hasOutput ? 0.7 : 0.3,
            'completeness' => $hasOutput ? 0.7 : 0.3,
            'efficiency' => 0.6, // Neutral
            'clarity' => $isStructured ? 0.7 : 0.5
        ];
    }
    
    /**
     * Evaluate SQL accuracy (syntax and logic)
     * 
     * @param string $sql SQL query
     * @param array $tablesUsed Tables used in query
     * @return float Accuracy score (0.0-1.0)
     */
    private function evaluateSqlAccuracy(string $sql, array $tablesUsed): float
    {
        $score = 0.5; // Base score
        
        // Check if SQL is not empty
        if (empty($sql)) {
            return 0.0;
        }
        
        // Basic syntax validation
        if ($this->isValidSqlSyntax($sql)) {
            $score += 0.2;
        }
        
        // Check if tables exist in schema
        if ($this->validateTablesExist($tablesUsed)) {
            $score += 0.1;
        }
        
        // Check for dangerous SQL patterns (security)
        if ($this->hasDangerousPatterns($sql)) {
            $score -= 0.5; // Heavy penalty for dangerous patterns
            $this->securityLogger->logSecurityEvent(
                "AnalyticsCritic detected dangerous SQL patterns",
                'warning',
                ['sql' => substr($sql, 0, 100)]
            );
        } else {
            $score += 0.1; // Bonus for safe SQL
        }
        
        // Check for SQL injection patterns (security)
        if ($this->isSecureSql($sql)) {
            $score += 0.1;
        } else {
            $score -= 0.3; // Penalize security issues
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate SQL completeness
     * 
     * @param string $sql SQL query
     * @param string $explanation Query explanation
     * @param ActionResult $result Full action result
     * @return float Completeness score (0.0-1.0)
     */
    private function evaluateSqlCompleteness(string $sql, string $explanation, ActionResult $result): float
    {
        $score = 0.5; // Base score
        
        // Check if query has explanation
        if (!empty($explanation)) {
            $score += 0.2;
        }
        
        // Check if query addresses the action context
        $context = $result->getExecutionContext();
        if ($context && $this->queryAddressesContext($sql, $context)) {
            $score += 0.3;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate SQL efficiency
     * 
     * @param string $sql SQL query
     * @param array $tablesUsed Tables used
     * @param string $queryType Query type
     * @return float Efficiency score (0.0-1.0)
     */
    private function evaluateSqlEfficiency(string $sql, array $tablesUsed, string $queryType): float
    {
        $score = 0.7; // Base score
        
        // Check for SELECT * (inefficient)
        if (strpos($sql, 'SELECT *') !== false) {
            $score -= 0.2;
        }
        
        // Check for proper indexing hints
        if ($this->hasProperIndexing($sql, $tablesUsed)) {
            $score += 0.2;
        }
        
        // Check for unnecessary JOINs
        if ($this->hasUnnecessaryJoins($sql)) {
            $score -= 0.1;
        }
        
        // Check for LIMIT clause on large result sets
        if ($queryType === 'SELECT' && !$this->hasLimitClause($sql)) {
            $score -= 0.1;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate SQL clarity
     * 
     * @param string $sql SQL query
     * @param string $explanation Query explanation
     * @return float Clarity score (0.0-1.0)
     */
    private function evaluateSqlClarity(string $sql, string $explanation): float
    {
        $score = 0.5; // Base score
        
        // Check for proper formatting
        if ($this->isWellFormatted($sql)) {
            $score += 0.2;
        }
        
        // Check for meaningful aliases
        if ($this->hasMeaningfulAliases($sql)) {
            $score += 0.1;
        }
        
        // Check for comments or explanation
        if (!empty($explanation) || $this->hasComments($sql)) {
            $score += 0.2;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate results accuracy
     * 
     * @param array $results Query results
     * @param int $rowCount Row count
     * @return float Accuracy score (0.0-1.0)
     */
    private function evaluateResultsAccuracy(array $results, int $rowCount): float
    {
        $score = 0.5; // Base score
        
        // Check if row count matches results
        if (count($results) === $rowCount) {
            $score += 0.3;
        }
        
        // Check for data consistency
        if ($this->hasConsistentData($results)) {
            $score += 0.2;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate results completeness
     * 
     * @param array $results Query results
     * @param ActionResult $result Full action result
     * @return float Completeness score (0.0-1.0)
     */
    private function evaluateResultsCompleteness(array $results, ActionResult $result): float
    {
        $score = 0.5; // Base score
        
        // Check if results are not empty (when expected)
        if (!empty($results)) {
            $score += 0.3;
        }
        
        // Check if all expected columns are present
        if ($this->hasExpectedColumns($results)) {
            $score += 0.2;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate results efficiency
     * 
     * @param float $executionTime Execution time
     * @param int $rowCount Row count
     * @return float Efficiency score (0.0-1.0)
     */
    private function evaluateResultsEfficiency(float $executionTime, int $rowCount): float
    {
        $score = 0.8; // Base score
        
        // Penalize slow queries
        if ($executionTime > 5.0) {
            $score -= 0.3;
        } elseif ($executionTime > 2.0) {
            $score -= 0.1;
        }
        
        // Consider row count vs execution time ratio
        if ($rowCount > 0 && $executionTime > 0) {
            $rowsPerSecond = $rowCount / $executionTime;
            if ($rowsPerSecond > 1000) {
                $score += 0.1;
            }
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate results clarity
     * 
     * @param array $results Query results
     * @return float Clarity score (0.0-1.0)
     */
    private function evaluateResultsClarity(array $results): float
    {
        $score = 0.5; // Base score
        
        // Check for consistent structure
        if ($this->hasConsistentStructure($results)) {
            $score += 0.3;
        }
        
        // Check for meaningful column names
        if ($this->hasMeaningfulColumnNames($results)) {
            $score += 0.2;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate schema accuracy
     * 
     * @param array $tables Tables list
     * @param array $columns Columns list
     * @return float Accuracy score (0.0-1.0)
     */
    private function evaluateSchemaAccuracy(array $tables, array $columns): float
    {
        $score = 0.5; // Base score
        
        // Verify tables exist in actual schema
        if ($this->validateTablesExist($tables)) {
            $score += 0.3;
        }
        
        // Verify columns exist for specified table
        if (!empty($columns) && $this->validateColumnsExist($columns)) {
            $score += 0.2;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate schema completeness
     * 
     * @param array $tables Tables list
     * @param array $columns Columns list
     * @param array $relationships Relationships list
     * @return float Completeness score (0.0-1.0)
     */
    private function evaluateSchemaCompleteness(array $tables, array $columns, array $relationships): float
    {
        $score = 0.5; // Base score
        
        // Check if tables are provided
        if (!empty($tables)) {
            $score += 0.2;
        }
        
        // Check if columns are provided when expected
        if (!empty($columns)) {
            $score += 0.2;
        }
        
        // Check if relationships are provided
        if (!empty($relationships)) {
            $score += 0.1;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Evaluate schema clarity
     * 
     * @param array $tables Tables list
     * @param array $columns Columns list
     * @param array $relationships Relationships list
     * @return float Clarity score (0.0-1.0)
     */
    private function evaluateSchemaClarity(array $tables, array $columns, array $relationships): float
    {
        $score = 0.7; // Base score (schema info is typically clear)
        
        // Check for organized structure
        if (is_array($tables) && is_array($columns)) {
            $score += 0.2;
        }
        
        // Check for relationship information
        if (!empty($relationships)) {
            $score += 0.1;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * Generate structured feedback
     * 
     * @param array $scores Dimension scores
     * @param mixed $output Action output
     * @param string $outputType Output type
     * @return string Feedback text
     */
    private function generateFeedback(array $scores, $output, string $outputType): string
    {
        $feedback = [];
        
        // Overall assessment
        $overallScore = ($scores['accuracy'] * 0.35) + ($scores['completeness'] * 0.25) + 
                       ($scores['efficiency'] * 0.25) + ($scores['clarity'] * 0.15);
        
        if ($overallScore >= 0.8) {
            $feedback[] = "Excellent {$outputType} with high quality across all dimensions.";
        } elseif ($overallScore >= 0.6) {
            $feedback[] = "Good {$outputType} with room for improvement in some areas.";
        } else {
            $feedback[] = "The {$outputType} needs significant improvement.";
        }
        
        // Dimension-specific feedback
        if ($scores['accuracy'] < 0.6) {
            $feedback[] = "Accuracy concerns: Check syntax, logic, and data correctness.";
        }
        
        if ($scores['completeness'] < 0.6) {
            $feedback[] = "Completeness issues: Ensure all requirements are addressed.";
        }
        
        if ($scores['efficiency'] < 0.6) {
            $feedback[] = "Efficiency problems: Consider optimization and performance improvements.";
        }
        
        if ($scores['clarity'] < 0.6) {
            $feedback[] = "Clarity issues: Improve formatting, naming, and documentation.";
        }
        
        return implode(' ', $feedback);
    }
    
    /**
     * Identify strengths
     * 
     * @param array $scores Dimension scores
     * @param mixed $output Action output
     * @param string $outputType Output type
     * @return array Strengths list
     */
    private function identifyStrengths(array $scores, $output, string $outputType): array
    {
        $strengths = [];
        
        if ($scores['accuracy'] >= 0.8) {
            $strengths[] = "High accuracy and correctness";
        }
        
        if ($scores['completeness'] >= 0.8) {
            $strengths[] = "Complete and comprehensive solution";
        }
        
        if ($scores['efficiency'] >= 0.8) {
            $strengths[] = "Efficient and optimized approach";
        }
        
        if ($scores['clarity'] >= 0.8) {
            $strengths[] = "Clear and well-structured output";
        }
        
        return $strengths;
    }
    
    /**
     * Identify improvements
     * 
     * @param array $scores Dimension scores
     * @param mixed $output Action output
     * @param string $outputType Output type
     * @return array Improvements list
     */
    private function identifyImprovements(array $scores, $output, string $outputType): array
    {
        $improvements = [];
        
        if ($scores['accuracy'] < 0.7) {
            $improvements[] = "Improve accuracy by validating syntax and logic";
        }
        
        if ($scores['completeness'] < 0.7) {
            $improvements[] = "Ensure all requirements are fully addressed";
        }
        
        if ($scores['efficiency'] < 0.7) {
            $improvements[] = "Optimize for better performance and efficiency";
        }
        
        if ($scores['clarity'] < 0.7) {
            $improvements[] = "Enhance clarity through better formatting and documentation";
        }
        
        return $improvements;
    }
    
    /**
     * Predict outcome of an action before execution
     * 
     * @param Action $action Action to predict
     * @return Prediction Predicted outcome with confidence and risks
     */
    public function predictOutcome(Action $action): Prediction
    {
        $actionType = $action->getType();
        $parameters = $action->getParameters();
        
        // Predict based on action type
        $prediction = match($actionType) {
            'sql_generation' => $this->predictSqlGeneration($parameters),
            'data_query' => $this->predictDataQuery($parameters),
            'schema_query' => $this->predictSchemaQuery($parameters),
            default => $this->predictGenericAction($parameters)
        };
        
        return new Prediction(
            $action->getActionId(),
            $this->criticId,
            $prediction['outcome'],
            $prediction['confidence'],
            $prediction['risks'],
            $prediction['success_probabilities'],
            $prediction['mitigations']
        );
    }
    
    /**
     * Predict SQL generation outcome
     * 
     * @param array $parameters Action parameters
     * @return array Prediction data
     */
    private function predictSqlGeneration(array $parameters): array
    {
        $query = $parameters['query'] ?? '';
        $confidence = 0.7; // Base confidence
        $risks = [];
        
        // Analyze query complexity
        if (strlen($query) > 500) {
            $confidence -= 0.2;
            $risks[] = [
                'type' => 'complexity',
                'description' => 'Very long query may be complex to generate accurately',
                'probability' => 0.6
            ];
        }
        
        // Check for ambiguous terms
        if ($this->hasAmbiguousTerms($query)) {
            $confidence -= 0.1;
            $risks[] = [
                'type' => 'ambiguity',
                'description' => 'Query contains ambiguous terms that may lead to incorrect SQL',
                'probability' => 0.4
            ];
        }
        
        return [
            'outcome' => 'SQL query will be generated with potential accuracy issues',
            'confidence' => max(0.1, min(1.0, $confidence)),
            'risks' => $risks,
            'success_probabilities' => ['high_quality' => $confidence, 'acceptable' => 0.8, 'poor' => 0.2],
            'mitigations' => ['Validate generated SQL', 'Test with sample data', 'Review for optimization']
        ];
    }
    
    /**
     * Predict data query outcome
     * 
     * @param array $parameters Action parameters
     * @return array Prediction data
     */
    private function predictDataQuery(array $parameters): array
    {
        $sql = $parameters['sql'] ?? '';
        $confidence = 0.8; // Base confidence
        $risks = [];
        
        // Check for potentially slow queries
        if ($this->isPotentiallySlowQuery($sql)) {
            $confidence -= 0.2;
            $risks[] = [
                'type' => 'performance',
                'description' => 'Query may execute slowly due to missing indexes or complex joins',
                'probability' => 0.7
            ];
        }
        
        return [
            'outcome' => 'Query will execute and return results',
            'confidence' => max(0.1, min(1.0, $confidence)),
            'risks' => $risks,
            'success_probabilities' => ['fast' => $confidence, 'slow' => 0.3, 'timeout' => 0.1],
            'mitigations' => ['Add appropriate indexes', 'Limit result set size', 'Optimize joins']
        ];
    }
    
    /**
     * Predict schema query outcome
     * 
     * @param array $parameters Action parameters
     * @return array Prediction data
     */
    private function predictSchemaQuery(array $parameters): array
    {
        return [
            'outcome' => 'Schema information will be retrieved successfully',
            'confidence' => 0.9,
            'risks' => [],
            'success_probabilities' => ['complete' => 0.9, 'partial' => 0.1, 'failed' => 0.0],
            'mitigations' => []
        ];
    }
    
    /**
     * Predict generic action outcome
     * 
     * @param array $parameters Action parameters
     * @return array Prediction data
     */
    private function predictGenericAction(array $parameters): array
    {
        return [
            'outcome' => 'Action will be executed with unknown quality',
            'confidence' => 0.5,
            'risks' => [
                [
                    'type' => 'unknown',
                    'description' => 'Unknown action type may have unpredictable results',
                    'probability' => 0.5
                ]
            ],
            'success_probabilities' => ['success' => 0.5, 'failure' => 0.5],
            'mitigations' => ['Monitor execution closely', 'Validate results manually']
        ];
    }
    
    /**
     * Get evaluation criteria and capabilities
     * 
     * @return array<string, EvaluationCriteria> Map of output types to criteria
     */
    public function getEvaluationCriteria(): array
    {
        return [
            'sql_query' => new EvaluationCriteria(
                'sql_query',
                0.9, // High expertise in SQL evaluation
                'analytics',
                ['accuracy' => 0.4, 'completeness' => 0.25, 'efficiency' => 0.25, 'clarity' => 0.1],
                ['sql_syntax_validation' => true, 'performance_analysis' => true],
                ['accuracy' => 0.7, 'completeness' => 0.6, 'efficiency' => 0.6, 'clarity' => 0.5]
            ),
            'query_results' => new EvaluationCriteria(
                'query_results',
                0.85, // High expertise in results evaluation
                'analytics',
                ['accuracy' => 0.35, 'completeness' => 0.3, 'efficiency' => 0.2, 'clarity' => 0.15],
                ['data_consistency_check' => true, 'performance_metrics' => true],
                ['accuracy' => 0.8, 'completeness' => 0.7, 'efficiency' => 0.6, 'clarity' => 0.5]
            ),
            'schema_info' => new EvaluationCriteria(
                'schema_info',
                0.95, // Very high expertise in schema evaluation
                'analytics',
                ['accuracy' => 0.4, 'completeness' => 0.3, 'efficiency' => 0.1, 'clarity' => 0.2],
                ['schema_validation' => true, 'relationship_analysis' => true],
                ['accuracy' => 0.9, 'completeness' => 0.8, 'efficiency' => 0.5, 'clarity' => 0.6]
            )
        ];
    }
    
    /**
     * Provide detailed feedback for an action result
     * 
     * @param ActionResult $result Result to provide feedback on
     * @return Feedback Structured feedback with strengths and improvements
     */
    public function provideFeedback(ActionResult $result): Feedback
    {
        // Evaluate the result first
        $evaluation = $this->evaluateAction($result);
        
        // Create feedback from evaluation
        return new Feedback(
            $result->getProducerAgentId(),
            $result->getResultId(),
            $evaluation->getOverallScore(),
            [
                'correctness' => [$evaluation->getFeedback()],
                'efficiency' => $evaluation->getOverallScore() >= 0.7 ? ['Good efficiency'] : ['Needs optimization'],
                'completeness' => $evaluation->getCompletenessScore() >= 0.7 ? ['Complete solution'] : ['Missing elements'],
                'best_practice' => $evaluation->getClarityScore() >= 0.7 ? ['Follows best practices'] : ['Improve practices']
            ],
            $evaluation->getStrengths(),
            $evaluation->getImprovements()
        );
    }
    
    /**
     * Get unique critic identifier
     * 
     * @return string Critic ID
     */
    public function getCriticId(): string
    {
        return $this->criticId;
    }
    
    // Helper methods for evaluation
    
    private function isValidSqlSyntax(string $sql): bool
    {
        // Basic SQL syntax validation for analytics queries
        $sql = trim($sql);
        if (empty($sql)) return false;
        
        // For analytics, we primarily expect SELECT queries
        // Also allow SHOW, DESCRIBE, EXPLAIN for schema queries
        $allowedKeywords = ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'];
        
        foreach ($allowedKeywords as $keyword) {
            if (stripos($sql, $keyword) === 0) {
                return true;
            }
        }
        
        // If it starts with other keywords (INSERT, UPDATE, DELETE, etc.), 
        // it's not valid for analytics
        return false;
    }
    
    private function validateTablesExist(array $tables): bool
    {
        if (empty($tables)) return true;
        
        try {
            $schema = $this->schemaManager->getDatabaseSchema();
            foreach ($tables as $table) {
                if (!isset($schema[$table])) {
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function validateColumnsExist(array $columns): bool
    {
        // Basic validation - in a real implementation, this would check against actual schema
        return !empty($columns) && is_array($columns);
    }
    
    private function queryAddressesContext(string $sql, $context): bool
    {
        // Basic check if SQL contains relevant terms from context
        if (!is_object($context) || !method_exists($context, 'getSystemState')) {
            return true; // Assume true if we can't check
        }
        
        $systemState = $context->getSystemState();
        $userQuery = $systemState['user_query'] ?? '';
        
        if (empty($userQuery)) return true;
        
        // Simple keyword matching
        $queryWords = explode(' ', strtolower($userQuery));
        $sqlLower = strtolower($sql);
        
        $matches = 0;
        foreach ($queryWords as $word) {
            if (strlen($word) > 3 && strpos($sqlLower, $word) !== false) {
                $matches++;
            }
        }
        
        return $matches > 0;
    }
    
    private function hasProperIndexing(string $sql, array $tables): bool
    {
        // Check for WHERE clauses that might benefit from indexes
        return strpos($sql, 'WHERE') !== false;
    }
    
    private function hasUnnecessaryJoins(string $sql): bool
    {
        // Count JOINs - more than 5 might be excessive
        return substr_count(strtoupper($sql), 'JOIN') > 5;
    }
    
    private function hasLimitClause(string $sql): bool
    {
        return stripos($sql, 'LIMIT') !== false;
    }
    
    private function isWellFormatted(string $sql): bool
    {
        // Check for basic formatting (line breaks, indentation)
        return strpos($sql, "\n") !== false || strpos($sql, "\r") !== false;
    }
    
    private function hasMeaningfulAliases(string $sql): bool
    {
        // Check for table aliases that are more than single letters
        preg_match_all('/\s+AS\s+(\w+)/i', $sql, $matches);
        if (empty($matches[1])) return true; // No aliases is fine
        
        foreach ($matches[1] as $alias) {
            if (strlen($alias) > 1) return true;
        }
        return false;
    }
    
    private function hasComments(string $sql): bool
    {
        return strpos($sql, '--') !== false || strpos($sql, '/*') !== false;
    }
    
    private function hasConsistentData(array $results): bool
    {
        if (empty($results)) return true;
        
        // Check if all rows have the same structure
        $firstRow = reset($results);
        if (!is_array($firstRow)) return true;
        
        $expectedKeys = array_keys($firstRow);
        foreach ($results as $row) {
            if (!is_array($row) || array_keys($row) !== $expectedKeys) {
                return false;
            }
        }
        
        return true;
    }
    
    private function hasExpectedColumns(array $results): bool
    {
        // Basic check - results should have columns
        if (empty($results)) return false;
        $firstRow = reset($results);
        return is_array($firstRow) && !empty($firstRow);
    }
    
    private function hasConsistentStructure(array $results): bool
    {
        return $this->hasConsistentData($results);
    }
    
    private function hasMeaningfulColumnNames(array $results): bool
    {
        if (empty($results)) return true;
        
        $firstRow = reset($results);
        if (!is_array($firstRow)) return true;
        
        foreach (array_keys($firstRow) as $columnName) {
            if (strlen($columnName) > 2) return true; // At least one meaningful name
        }
        
        return false;
    }
    
    private function hasAmbiguousTerms(string $query): bool
    {
        try {
            // Use the AmbiguousQueryDetector to check for ambiguity
            $analysis = $this->ambiguityDetector->analyzeQuery($query);
            return $analysis['is_ambiguous'] ?? false;
        } catch (\Exception $e) {
            // Fallback to simple keyword detection if detector fails
            $ambiguousTerms = ['best', 'good', 'recent', 'popular', 'top', 'better', 'worse'];
            $queryLower = strtolower($query);
            
            foreach ($ambiguousTerms as $term) {
                if (strpos($queryLower, $term) !== false) {
                    return true;
                }
            }
            
            return false;
        }
    }
    
    private function isPotentiallySlowQuery(string $sql): bool
    {
        $sqlUpper = strtoupper($sql);
        
        // Check for patterns that might be slow
        $slowPatterns = [
            'SELECT *',
            'ORDER BY',
            'GROUP BY',
            'HAVING',
            'DISTINCT'
        ];
        
        foreach ($slowPatterns as $pattern) {
            if (strpos($sqlUpper, $pattern) !== false) {
                return true;
            }
        }
        
        // Check for multiple JOINs
        if (substr_count($sqlUpper, 'JOIN') > 2) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Register this critic in the CriticRegistry
     * 
     * @return void
     */
    private function registerInRegistry(): void
    {
        try {
            // Get or create CriticRegistry instance
            if (!Registry::exists('CriticRegistry')) {
                Registry::set('CriticRegistry', new CriticRegistry());
            }
            
            $registry = Registry::get('CriticRegistry');
            $registry->registerCritic($this);
            
            $this->securityLogger->logSecurityEvent(
                "AnalyticsCritic registered in CriticRegistry",
                'info',
                ['critic_id' => $this->criticId]
            );
        } catch (\Exception $e) {
            $this->securityLogger->logSecurityEvent(
                "Failed to register AnalyticsCritic: " . $e->getMessage(),
                'error',
                ['critic_id' => $this->criticId]
            );
        }
    }
    
    /**
     * Get evaluation history
     * 
     * @return array Evaluation history
     */
    public function getEvaluationHistory(): array
    {
        return $this->evaluationHistory;
    }
    
    /**
     * Check for dangerous SQL patterns
     * 
     * @param string $sql SQL query
     * @return bool True if dangerous patterns found
     */
    private function hasDangerousPatterns(string $sql): bool
    {
        // Dangerous patterns that should never be in analytics queries
        $dangerousPatterns = [
            '/DROP\s+/i',
            '/TRUNCATE\s+/i',
            '/ALTER\s+/i',
            '/DELETE\s+/i',
            '/INSERT\s+/i',
            '/UPDATE\s+/i',
            '/GRANT\s+/i',
            '/REVOKE\s+/i',
            '/INTO\s+OUTFILE/i',
            '/LOAD\s+DATA/i',
            '/INFORMATION_SCHEMA/i',
            '/--/',
            '/\/\*/',
            '/\*\//',
            '/;.*--/',
            '/union.*select/i'
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if SQL is secure (basic security validation)
     * 
     * @param string $sql SQL query
     * @return bool True if secure
     */
    private function isSecureSql(string $sql): bool
    {
        // Basic security checks
        $dangerousPatterns = [
            '/DROP\s+/i',
            '/TRUNCATE\s+/i',
            '/ALTER\s+/i',
            '/DELETE\s+/i',
            '/INSERT\s+/i',
            '/UPDATE\s+/i',
            '/GRANT\s+/i',
            '/REVOKE\s+/i',
            '/INTO\s+OUTFILE/i',
            '/LOAD\s+DATA/i',
            '/INFORMATION_SCHEMA/i',
            '/--/',
            '/\/\*/',
            '/\*\//',
            '/;.*--/',
            '/union.*select/i'
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return false;
            }
        }
        
        return true;
    }
}