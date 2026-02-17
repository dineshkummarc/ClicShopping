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

use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Action;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Prediction;
use ClicShopping\OM\Registry;

use ClicShopping\AI\InterfacesAI\CriticAgentInterface;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\ActionResult;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Evaluation;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\Feedback;
use ClicShopping\AI\Agents\Orchestrator\SubActorCritic\EvaluationCriteria;
use ClicShopping\AI\RegistryAI\CriticRegistry;
use ClicShopping\AI\Security\SecurityLogger;

/**
 * SqlQualityCritic - Generalist critic for SQL quality checks.
 */
class SqlQualityCritic implements CriticAgentInterface
{
    private string $criticId;
    private SecurityLogger $securityLogger;
    private bool $debug;

    public function __construct(bool $debug = false)
    {
        $this->criticId = 'sql_quality_critic_' . uniqid();
        $this->debug = $debug;
        $this->securityLogger = new SecurityLogger();

        $this->registerInRegistry();
    }

    private function registerInRegistry(): void
    {
        try {
            if (!Registry::exists('CriticRegistry')) {
                Registry::set('CriticRegistry', new CriticRegistry());
            }
            $registry = Registry::get('CriticRegistry');
            $registry->registerCritic($this);
        } catch (\Exception $e) {
            if ($this->debug) {
                $this->securityLogger->logSecurityEvent(
                    "Failed to register SqlQualityCritic: " . $e->getMessage(),
                    'warning'
                );
            }
        }
    }

    public function getEvaluationCriteria(): array
    {
        return [
            'sql_query' => new EvaluationCriteria(
                'sql_query',
                0.6,
                'analytics',
                ['accuracy' => 0.4, 'completeness' => 0.3, 'efficiency' => 0.2, 'clarity' => 0.1],
                ['basic_sql_checks' => true],
                ['accuracy' => 0.6, 'completeness' => 0.6, 'efficiency' => 0.5, 'clarity' => 0.5]
            )
        ];
    }

    public function provideFeedback(ActionResult $result): Feedback
    {
        $evaluation = $this->evaluateAction($result);
        return new Feedback(
            $result->getProducerAgentId(),
            $result->getResultId(),
            $evaluation->getOverallScore(),
            [
                'correctness' => [$evaluation->getFeedback()],
                'efficiency' => ['Verifier les indexes et limites'],
                'completeness' => ['Verifier les filtres'],
                'best_practice' => ['Eviter SELECT * si possible']
            ],
            $evaluation->getStrengths(),
            $evaluation->getImprovements()
        );
    }

    public function evaluateAction(ActionResult $result): Evaluation
    {
        $outputType = $result->getOutputType();
        $output = $result->getOutput();

        $scores = $outputType === 'sql_query'
            ? $this->evaluateSqlQuery($output)
            : $this->evaluateGeneric($output);

        $feedback = $this->generateFeedback($scores, $outputType);
        $strengths = $this->identifyStrengths($scores);
        $improvements = $this->identifyImprovements($scores);

        return new Evaluation(
            $this->criticId,
            $result->getResultId(),
            $scores,
            $feedback,
            $strengths,
            $improvements
        );
    }

    private function evaluateSqlQuery(array $output): array
    {
        $sql = strtoupper((string)($output['sql'] ?? ''));
        $hasSelect = str_contains($sql, 'SELECT');
        $hasFrom = str_contains($sql, 'FROM');
        $hasWhere = str_contains($sql, 'WHERE');
        $usesWildcard = str_contains($sql, 'SELECT *');
        $hasLimit = str_contains($sql, 'LIMIT');

        $accuracy = ($hasSelect && $hasFrom) ? 0.7 : 0.3;
        $accuracy += $hasWhere ? 0.1 : 0.0;
        $accuracy = $usesWildcard ? $accuracy - 0.1 : $accuracy;

        $completeness = ($hasSelect && $hasFrom) ? 0.65 : 0.35;
        $completeness += $hasWhere ? 0.1 : 0.0;

        $efficiency = $usesWildcard ? 0.45 : 0.65;
        $efficiency += $hasLimit ? 0.1 : 0.0;

        $clarity = $hasSelect ? 0.6 : 0.4;

        return [
            'accuracy' => $this->clamp($accuracy),
            'completeness' => $this->clamp($completeness),
            'efficiency' => $this->clamp($efficiency),
            'clarity' => $this->clamp($clarity)
        ];
    }

    private function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }
        return $value;
    }

    private function evaluateGeneric($output): array
    {
        $hasOutput = !empty($output);
        $isStructured = is_array($output);

        return [
            'accuracy' => $hasOutput ? 0.55 : 0.3,
            'completeness' => $hasOutput ? 0.55 : 0.3,
            'efficiency' => 0.5,
            'clarity' => $isStructured ? 0.6 : 0.4
        ];
    }

    private function generateFeedback(array $scores, string $outputType): string
    {
        $overall = array_sum($scores) / count($scores);
        $quality = $overall >= 0.75 ? 'bonne' : ($overall >= 0.6 ? 'acceptable' : 'faible');

        return "Evaluation SQL ({$outputType}): qualité {$quality}, score moyen " . number_format($overall, 2);
    }

    private function identifyStrengths(array $scores): array
    {
        $strengths = [];
        foreach ($scores as $dimension => $score) {
            if ($score >= 0.7) {
                $strengths[] = ucfirst($dimension) . ' solide';
            }
        }
        return $strengths ?: ['Structure globale correcte'];
    }

    private function identifyImprovements(array $scores): array
    {
        $improvements = [];
        foreach ($scores as $dimension => $score) {
            if ($score < 0.6) {
                $improvements[] = 'Ameliorer ' . $dimension;
            }
        }
        return $improvements ?: ['Verifier les conditions de filtrage'];
    }

    public function getCriticId(): string
    {
        return $this->criticId;
    }

  public function predictOutcome(Action $action): Prediction
  {
    // TODO: Implement predictOutcome() method.
  }
}
