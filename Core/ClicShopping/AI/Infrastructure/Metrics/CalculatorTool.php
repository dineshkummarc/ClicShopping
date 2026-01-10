<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Infrastructure\Metrics;

use AllowDynamicProperties;
use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Security\InputValidator;

/**
 * CalculatorTool Class
 *
 * Outil de calcul avancé pour le système RAGBI.
 * Permet d'effectuer des opérations mathématiques complexes de manière sécurisée.
 *
 * Fonctionnalités :
 * - Opérations de base (+, -, *, /, %, **)
 * - Fonctions mathématiques (sin, cos, tan, sqrt, log, etc.)
 * - Constantes (pi, e)
 * - Variables et expressions
 * - Validation et sécurité stricte
 * - Historique des calculs
 * - Support des nombres décimaux et scientifiques
 */
#[AllowDynamicProperties]
class CalculatorTool
{
  private SecurityLogger $securityLogger;
  private bool $debug;
  private array $calculationHistory = [];
  private int $maxHistorySize = 100;
  private mixed $db;
  private bool $enableCache = false;
  private bool $enableLogging = false;
  private int $cacheTTL = 3600;

  /**
   * Calculator Configuration Constants (2026-01-09)
   * 
   * These are internal configuration values that rarely need to be changed.
   * They are defined as class constants rather than global config to:
   * - Reduce global config pollution
   * - Keep technical details close to implementation
   * - Simplify configuration for administrators
   * 
   * Only CALCULATOR_ENABLED should be in global config for admin control.
   */
  private const MAX_HISTORY_SIZE = 100;           // Maximum calculation history entries
  private const STRICT_VALIDATION = true;         // Enable strict expression validation
  private const MAX_EXECUTION_TIME = 5;           // Max calculation time (seconds)
  private const CACHE_TTL = 3600;                 // Cache TTL (1 hour)

  // Variables définies par l'utilisateur
  private array $variables = [];

  // Fonctions mathématiques autorisées
  private array $allowedFunctions = [
    // Trigonométrie
    'sin',
    'cos',
    'tan',
    'asin',
    'acos',
    'atan',
    'atan2',
    'sinh',
    'cosh',
    'tanh',
    'asinh',
    'acosh',
    'atanh',

    // Exponentielles et logarithmes
    'exp',
    'log',
    'log10',
    'log1p',
    'expm1',

    // Racines et puissances
    'sqrt',
    'pow',
    'hypot',

    // Arrondis
    'abs',
    'ceil',
    'floor',
    'round',

    // Comparaisons
    'min',
    'max',

    // Autres
    'deg2rad',
    'rad2deg',
    'fmod',
    'pi',
    'M_PI',
    'M_E'
  ];

  // Constantes mathématiques
  private array $constants = [
    'pi' => M_PI,
    'e' => M_E,
    'phi' => 1.618033988749895, // Nombre d'or
    'sqrt2' => M_SQRT2,
    'sqrt3' => 1.7320508075688772,
    'ln2' => M_LN2,
    'ln10' => M_LN10,
  ];

  /**
   * Constructor
   * 
   * Uses global RAG configuration:
   * - Cache: CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER
   * - Debug/Logging: CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER
   * 
   * Technical settings (history size, validation, timeouts) are defined as class constants.
   */
  public function __construct()
  {
    $this->securityLogger = new SecurityLogger();
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    // Initialiser la connexion à la base de données
    if (Registry::exists('Db')) {
      $this->db = Registry::get('Db');
    }

    // Activer le cache si configuré (utilise la config RAG globale)
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER')
      && CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True') {
      $this->enableCache = true;
    }

    // Activer le logging si configuré (utilise la config RAG globale)
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER')
      && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      $this->enableLogging = true;
    }

    // Use class constants for technical settings
    $this->cacheTTL = self::CACHE_TTL;
    $this->maxHistorySize = self::MAX_HISTORY_SIZE;

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "CalculatorTool initialized (cache: " . ($this->enableCache ? 'ON' : 'OFF') .
        ", logging: " . ($this->enableLogging ? 'ON' : 'OFF') . 
        ", history: {$this->maxHistorySize}, cacheTTL: {$this->cacheTTL}s)",
        'info'
      );
    }
  }

  /**
   * Exécute un calcul mathématique
   *
   * @param string $expression Expression mathématique à évaluer
   * @param array $variables Variables optionnelles (ex: ['x' => 5, 'y' => 10])
   * @return array Résultat du calcul
   */
  public function calculate(string $expression, array $variables = []): array
  {
    $startTime = microtime(true);

    try {
      // Validation de l'entrée
      $safeExpression = InputValidator::validateParameter($expression, 'string');

      if ($safeExpression !== $expression) {
        $this->securityLogger->logSecurityEvent(
          "Expression sanitized in calculate",
          'warning'
        );
        $expression = $safeExpression;
      }

      // Vérifier que l'expression n'est pas vide
      if (empty(trim($expression))) {
        return [
          'success' => false,
          'error' => 'Empty expression',
          'expression' => $expression,
        ];
      }

      // Fusionner les variables
      $this->variables = array_merge($this->variables, $variables);

      // 🆕 Vérifier le cache
      if ($this->enableCache) {
        $cachedResult = $this->getCachedResult($expression, $this->variables);
        if ($cachedResult !== null) {
          if ($this->debug) {
            $this->securityLogger->logSecurityEvent(
              "Cache hit for expression: " . substr($expression, 0, 50),
              'info'
            );
          }
          return $cachedResult;
        }
      }

      // Préparer l'expression
      $preparedExpression = $this->prepareExpression($expression);

      // Valider la sécurité
      if (!$this->validateSecurity($preparedExpression)) {
        throw new \Exception('Expression contains unsafe patterns');
      }

      // Évaluer l'expression
      $result = $this->evaluateExpression($preparedExpression);

      // Construire la réponse
      $response = [
        'success' => true,
        'result' => $result,
        'expression' => $expression,
        'prepared_expression' => $preparedExpression,
        'execution_time' => microtime(true) - $startTime,
        'type' => $this->detectResultType($result),
      ];

      // 🆕 Mettre en cache
      if ($this->enableCache) {
        $this->cacheResult($expression, $this->variables, $response);
      }

      // 🆕 Logger le calcul
      if ($this->enableLogging) {
        $this->logCalculation($expression, $result, true, null, $response['execution_time']);
      }

      // Stocker dans l'historique
      $this->addToHistory($expression, $result, $response['execution_time']);

      return $response;
    } catch (\Exception $e) {
      $executionTime = microtime(true) - $startTime;

      $this->securityLogger->logSecurityEvent(
        "Calculation error: " . $e->getMessage(),
        'error',
        ['expression' => $expression]
      );

      // 🆕 Logger l'erreur
      if ($this->enableLogging) {
        $this->logCalculation($expression, null, false, $e->getMessage(), $executionTime);
      }

      return [
        'success' => false,
        'error' => $e->getMessage(),
        'expression' => $expression,
        'execution_time' => $executionTime,
      ];
    }
  }

  /**
   * Prépare l'expression pour l'évaluation
   */
  private function prepareExpression(string $expression): string
  {
    // Supprimer les espaces superflus
    $expression = trim($expression);

    // Remplacer les constantes
    foreach ($this->constants as $name => $value) {
      $expression = preg_replace('/\b' . preg_quote($name, '/') . '\b/i', (string)$value, $expression);
    }

    // Remplacer les variables
    foreach ($this->variables as $name => $value) {
      $expression = preg_replace('/\b' . preg_quote($name, '/') . '\b/', (string)$value, $expression);
    }

    // Remplacer ^ par ** pour les puissances
    $expression = str_replace('^', '**', $expression);

    // Remplacer les fonctions spéciales
    $expression = $this->replaceFunctions($expression);

    return $expression;
  }

  /**
   * Remplace les fonctions mathématiques par leur équivalent PHP
   */
  private function replaceFunctions(string $expression): string
  {
    // Fonctions trigonométriques
    $replacements = [
      '/\bsin\s*\(/i' => 'sin(',
      '/\bcos\s*\(/i' => 'cos(',
      '/\btan\s*\(/i' => 'tan(',
      '/\basin\s*\(/i' => 'asin(',
      '/\bacos\s*\(/i' => 'acos(',
      '/\batan\s*\(/i' => 'atan(',

      // Racines et puissances
      '/\bsqrt\s*\(/i' => 'sqrt(',
      '/\bpow\s*\(/i' => 'pow(',

      // Logarithmes
      '/\blog\s*\(/i' => 'log(',
      '/\blog10\s*\(/i' => 'log10(',
      '/\bln\s*\(/i' => 'log(',

      // Exponentielles
      '/\bexp\s*\(/i' => 'exp(',

      // Arrondis
      '/\babs\s*\(/i' => 'abs(',
      '/\bceil\s*\(/i' => 'ceil(',
      '/\bfloor\s*\(/i' => 'floor(',
      '/\bround\s*\(/i' => 'round(',

      // Min/Max
      '/\bmin\s*\(/i' => 'min(',
      '/\bmax\s*\(/i' => 'max(',
    ];

    foreach ($replacements as $pattern => $replacement) {
      $expression = preg_replace($pattern, $replacement, $expression);
    }

    return $expression;
  }

  /**
   * Valide la sécurité de l'expression
   */
  private function validateSecurity(string $expression): bool
  {
    // Patterns dangereux
    $dangerousPatterns = [
      '/\$/', // Variables PHP
      '/\beval\b/i',
      '/\bexec\b/i',
      '/\bsystem\b/i',
      '/\bpassthru\b/i',
      '/\bshell_exec\b/i',
      '/\bfile\b/i',
      '/\bfopen\b/i',
      '/\binclude\b/i',
      '/\brequire\b/i',
      '/\bunlink\b/i',
      '/\bphpinfo\b/i',
      '/\bvar_dump\b/i',
      '/\bprint_r\b/i',
      '/\bdie\b/i',
      '/\bexit\b/i',
      '/function\s*\(/i',
      '/class\s+\w+/i',
      '/new\s+\w+/i',
      '/::/', // Appels statiques
      '/->/', // Appels de méthodes
      '/;/', // Multiple instructions
      '/`/', // Backticks
    ];

    foreach ($dangerousPatterns as $pattern) {
      if (preg_match($pattern, $expression)) {
        $this->securityLogger->logSecurityEvent(
          "Dangerous pattern detected in expression",
          'warning',
          ['pattern' => $pattern, 'expression' => $expression]
        );
        return false;
      }
    }

    // Vérifier que seuls les caractères autorisés sont présents
    if (!preg_match('/^[0-9+\-*\/%().a-z_,\s]+$/i', $expression)) {
      $this->securityLogger->logSecurityEvent(
        "Invalid characters in expression",
        'warning',
        ['expression' => $expression]
      );
      return false;
    }

    // Vérifier que les parenthèses sont équilibrées
    if (substr_count($expression, '(') !== substr_count($expression, ')')) {
      return false;
    }

    return true;
  }

  /**
   * Évalue l'expression mathématique de manière sécurisée
   * Uses a safe token-based parser instead of eval()
   */
  private function evaluateExpression(string $expression): float|int
  {
    try {
      // Parse and evaluate using a safe recursive descent parser
      $result = $this->parseExpression($expression);

      // Vérifier que le résultat est valide
      if (!is_numeric($result)) {
        throw new \Exception('Result is not a number');
      }

      return $result;
    } catch (\Throwable $e) {
      throw new \Exception('Evaluation error: ' . $e->getMessage());
    }
  }

  /**
   * Safe expression parser using recursive descent
   */
  private function parseExpression(string $expr): float|int
  {
    $expr = str_replace(' ', '', $expr);
    $pos = 0;
    
    $parseNumber = function() use ($expr, &$pos) {
      $start = $pos;
      if ($pos < strlen($expr) && ($expr[$pos] === '-' || $expr[$pos] === '+')) {
        $pos++;
      }
      while ($pos < strlen($expr) && (ctype_digit($expr[$pos]) || $expr[$pos] === '.')) {
        $pos++;
      }
      if ($start === $pos) {
        throw new \Exception('Expected number at position ' . $pos);
      }
      return (float)substr($expr, $start, $pos - $start);
    };

    $parseFunction = function() use ($expr, &$pos, &$parseAddSub) {
      $funcStart = $pos;
      while ($pos < strlen($expr) && ctype_alpha($expr[$pos])) {
        $pos++;
      }
      $funcName = substr($expr, $funcStart, $pos - $funcStart);
      
      if ($pos >= strlen($expr) || $expr[$pos] !== '(') {
        throw new \Exception('Expected ( after function name');
      }
      $pos++; // skip (
      
      $args = [];
      while (true) {
        $args[] = $parseAddSub();
        if ($pos >= strlen($expr)) {
          throw new \Exception('Unexpected end of expression');
        }
        if ($expr[$pos] === ')') {
          $pos++;
          break;
        }
        if ($expr[$pos] === ',') {
          $pos++;
          continue;
        }
        throw new \Exception('Expected , or ) in function arguments');
      }
      
      return match($funcName) {
        'sin' => sin($args[0]),
        'cos' => cos($args[0]),
        'tan' => tan($args[0]),
        'asin' => asin($args[0]),
        'acos' => acos($args[0]),
        'atan' => atan($args[0]),
        'sinh' => sinh($args[0]),
        'cosh' => cosh($args[0]),
        'tanh' => tanh($args[0]),
        'sqrt' => sqrt($args[0]),
        'abs' => abs($args[0]),
        'ceil' => ceil($args[0]),
        'floor' => floor($args[0]),
        'round' => round($args[0]),
        'exp' => exp($args[0]),
        'log' => log($args[0]),
        'log10' => log10($args[0]),
        'pow' => pow($args[0], $args[1] ?? 1),
        'min' => min(...$args),
        'max' => max(...$args),
        'atan2' => atan2($args[0], $args[1] ?? 0),
        'hypot' => hypot($args[0], $args[1] ?? 0),
        default => throw new \Exception('Unknown function: ' . $funcName)
      };
    };

    $parsePrimary = function() use ($expr, &$pos, $parseNumber, $parseFunction, &$parseAddSub) {
      if ($pos >= strlen($expr)) {
        throw new \Exception('Unexpected end of expression');
      }
      
      // Check for function
      if (ctype_alpha($expr[$pos])) {
        return $parseFunction();
      }
      
      // Check for parentheses
      if ($expr[$pos] === '(') {
        $pos++;
        $result = $parseAddSub();
        if ($pos >= strlen($expr) || $expr[$pos] !== ')') {
          throw new \Exception('Expected closing parenthesis');
        }
        $pos++;
        return $result;
      }
      
      // Parse number
      return $parseNumber();
    };

    $parsePower = function() use (&$parsePrimary, $expr, &$pos) {
      $left = $parsePrimary();
      while ($pos < strlen($expr) && substr($expr, $pos, 2) === '**') {
        $pos += 2;
        $right = $parsePrimary();
        $left = pow($left, $right);
      }
      return $left;
    };

    $parseMulDiv = function() use (&$parsePower, $expr, &$pos) {
      $left = $parsePower();
      while ($pos < strlen($expr) && in_array($expr[$pos], ['*', '/', '%'])) {
        $op = $expr[$pos++];
        $right = $parsePower();
        $left = match($op) {
          '*' => $left * $right,
          '/' => $right != 0 ? $left / $right : throw new \Exception('Division by zero'),
          '%' => $left % $right,
        };
      }
      return $left;
    };

    $parseAddSub = function() use (&$parseMulDiv, $expr, &$pos) {
      $left = $parseMulDiv();
      while ($pos < strlen($expr) && in_array($expr[$pos], ['+', '-'])) {
        $op = $expr[$pos++];
        $right = $parseMulDiv();
        $left = $op === '+' ? $left + $right : $left - $right;
      }
      return $left;
    };

    $result = $parseAddSub();
    
    if ($pos < strlen($expr)) {
      throw new \Exception('Unexpected characters at position ' . $pos);
    }
    
    return $result;
  }

  /**
   * Détecte le type du résultat
   */
  private function detectResultType($result): string
  {
    if (is_int($result)) {
      return 'integer';
    }

    if (is_float($result)) {
      if (floor($result) == $result) {
        return 'integer_as_float';
      }
      return 'float';
    }

    return 'unknown';
  }

  /**
   * Ajoute un calcul à l'historique
   */
  private function addToHistory(string $expression, $result, float $executionTime): void
  {
    $entry = [
      'expression' => $expression,
      'result' => $result,
      'execution_time' => $executionTime,
      'timestamp' => microtime(true),
    ];

    $this->calculationHistory[] = $entry;

    // Limiter la taille de l'historique
    if (count($this->calculationHistory) > $this->maxHistorySize) {
      array_shift($this->calculationHistory);
    }
  }

  /**
   * Obtient l'historique des calculs
   */
  public function getHistory(int $limit = 10): array
  {
    return array_slice($this->calculationHistory, -$limit);
  }

  /**
   * Efface l'historique
   */
  public function clearHistory(): void
  {
    $this->calculationHistory = [];
  }

  /**
   * Définit une variable
   */
  public function setVariable(string $name, float|int $value): bool
  {
    // Valider le nom de la variable
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
      return false;
    }

    $this->variables[$name] = $value;
    return true;
  }

  /**
   * Obtient une variable
   */
  public function getVariable(string $name): float|int|null
  {
    return $this->variables[$name] ?? null;
  }

  /**
   * Obtient toutes les variables
   */
  public function getVariables(): array
  {
    return $this->variables;
  }

  /**
   * Efface toutes les variables
   */
  public function clearVariables(): void
  {
    $this->variables = [];
  }

  /**
   * Calcule une série de valeurs
   *
   * @param string $expression Expression avec variable $x
   * @param float $start Début de l'intervalle
   * @param float $end Fin de l'intervalle
   * @param int $steps Nombre de points
   * @return array Tableau de [x, y]
   */
  public function calculateSeries(string $expression, float $start, float $end, int $steps = 10): array
  {
    $results = [];
    $step = ($end - $start) / ($steps - 1);

    for ($i = 0; $i < $steps; $i++) {
      $x = $start + ($i * $step);
      $this->setVariable('x', $x);

      $result = $this->calculate($expression);

      if ($result['success']) {
        $results[] = [
          'x' => $x,
          'y' => $result['result'],
        ];
      }
    }

    return $results;
  }

  /**
   * Résout une équation simple (ax + b = 0)
   *
   * @param float $a Coefficient a
   * @param float $b Coefficient b
   * @return array Solution
   */
  public function solveLinear(float $a, float $b): array
  {
    if ($a == 0) {
      return [
        'success' => false,
        'error' => 'Coefficient a cannot be zero',
      ];
    }

    $x = -$b / $a;

    return [
      'success' => true,
      'solution' => $x,
      'equation' => "{$a}x + {$b} = 0",
    ];
  }

  /**
   * Résout une équation quadratique (ax² + bx + c = 0)
   *
   * @param float $a Coefficient a
   * @param float $b Coefficient b
   * @param float $c Coefficient c
   * @return array Solutions
   */
  public function solveQuadratic(float $a, float $b, float $c): array
  {
    if ($a == 0) {
      return $this->solveLinear($b, $c);
    }

    $discriminant = ($b * $b) - (4 * $a * $c);

    if ($discriminant < 0) {
      return [
        'success' => true,
        'solutions' => 'complex',
        'equation' => "{$a}x² + {$b}x + {$c} = 0",
        'discriminant' => $discriminant,
      ];
    }

    $sqrtDiscriminant = sqrt($discriminant);

    $x1 = (-$b + $sqrtDiscriminant) / (2 * $a);
    $x2 = (-$b - $sqrtDiscriminant) / (2 * $a);

    return [
      'success' => true,
      'solutions' => [$x1, $x2],
      'equation' => "{$a}x² + {$b}x + {$c} = 0",
      'discriminant' => $discriminant,
    ];
  }

  /**
   * Calcule une statistique sur un ensemble de valeurs
   *
   * @param array $values Valeurs
   * @param string $operation Type de statistique (sum, avg, min, max, stddev)
   * @return array Résultat
   */
  public function calculateStatistic(array $values, string $operation = 'avg'): array
  {
    if (empty($values)) {
      return [
        'success' => false,
        'error' => 'Empty values array',
      ];
    }

    $result = match ($operation) {
      'sum' => array_sum($values),
      'avg' => array_sum($values) / count($values),
      'min' => min($values),
      'max' => max($values),
      'stddev' => $this->standardDeviation($values),
      'variance' => $this->variance($values),
      'median' => $this->median($values),
      default => throw new \Exception("Unknown operation: {$operation}"),
    };

    return [
      'success' => true,
      'result' => $result,
      'operation' => $operation,
      'count' => count($values),
    ];
  }

  /**
   * Calcule l'écart-type
   */
  private function standardDeviation(array $values): float
  {
    return sqrt($this->variance($values));
  }

  /**
   * Calcule la variance
   */
  private function variance(array $values): float
  {
    $mean = array_sum($values) / count($values);
    $squaredDiffs = array_map(fn($x) => pow($x - $mean, 2), $values);
    return array_sum($squaredDiffs) / count($values);
  }

  /**
   * Calcule la médiane
   */
  private function median(array $values): float
  {
    sort($values);
    $count = count($values);
    $middle = floor($count / 2);

    if ($count % 2 == 0) {
      return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    return $values[$middle];
  }

  /**
   * Formate un nombre pour l'affichage
   *
   * @param float|int $number Nombre à formater
   * @param int $decimals Nombre de décimales
   * @return string Nombre formaté
   */
  public function formatNumber($number, int $decimals = 2): string
  {
    return number_format($number, $decimals, '.', ',');
  }

  /**
   * Obtient les statistiques d'utilisation
   */
  public function getStats(): array
  {
    return [
      'total_calculations' => count($this->calculationHistory),
      'variables_defined' => count($this->variables),
      'available_functions' => count($this->allowedFunctions),
      'available_constants' => count($this->constants),
    ];
  }

  /**
   * Obtient l'aide sur les fonctions disponibles
   */
  public function getHelp(): array
  {
    return [
      'basic_operations' => [
        '+' => 'Addition',
        '-' => 'Subtraction',
        '*' => 'Multiplication',
        '/' => 'Division',
        '%' => 'Modulo',
        '**' => 'Power (or ^)',
      ],
      'functions' => [
        'sin(x)' => 'Sine',
        'cos(x)' => 'Cosine',
        'tan(x)' => 'Tangent',
        'sqrt(x)' => 'Square root',
        'abs(x)' => 'Absolute value',
        'log(x)' => 'Natural logarithm',
        'log10(x)' => 'Base-10 logarithm',
        'exp(x)' => 'Exponential',
        'pow(x, y)' => 'x to the power of y',
        'min(x, y, ...)' => 'Minimum value',
        'max(x, y, ...)' => 'Maximum value',
        'round(x)' => 'Round to nearest integer',
        'ceil(x)' => 'Round up',
        'floor(x)' => 'Round down',
      ],
      'constants' => [
        'pi' => 'π ≈ 3.14159',
        'e' => 'e ≈ 2.71828',
        'phi' => 'φ (golden ratio) ≈ 1.61803',
      ],
      'examples' => [
        '2 + 2' => '4',
        'sqrt(16)' => '4',
        'sin(pi/2)' => '1',
        'pow(2, 3)' => '8',
        '5 * (3 + 2)' => '25',
      ],
    ];
  }

  /**
   * 🆕 Interface pour le système d'agents
   * Exécute un calcul dans le contexte d'un plan d'exécution
   *
   * @param array $context Contexte fourni par PlanExecutor
   * @return array Résultat formaté pour le plan
   */
  public function executeInAgentContext(array $context): array
  {
    try {
      // Extraire les paramètres du contexte
      $expression = $context['expression'] ?? '';
      $variables = $context['variables'] ?? [];
      $operation = $context['operation'] ?? 'calculate';

      // Récupérer les résultats des dépendances
      if (isset($context['dependency_results'])) {
        $variables = array_merge(
          $variables,
          $this->extractVariablesFromDependencies($context['dependency_results'])
        );
      }

      // Exécuter l'opération demandée
      $result = match ($operation) {
        'calculate' => $this->calculate($expression, $variables),
        'statistic' => $this->calculateStatistic(
          $context['values'] ?? [],
          $context['stat_type'] ?? 'avg'
        ),
        'solve_linear' => $this->solveLinear(
          $context['a'] ?? 0,
          $context['b'] ?? 0
        ),
        'solve_quadratic' => $this->solveQuadratic(
          $context['a'] ?? 0,
          $context['b'] ?? 0,
          $context['c'] ?? 0
        ),
        'series' => $this->calculateSeries(
          $expression,
          $context['start'] ?? 0,
          $context['end'] ?? 10,
          $context['steps'] ?? 10
        ),
        default => throw new \Exception("Unknown operation: {$operation}"),
      };

      // Formater pour le système d'agents
      return [
        'type' => 'calculation_result',
        'success' => $result['success'] ?? false,
        'result' => $result['result'] ?? $result,
        'operation' => $operation,
        'expression' => $expression,
        'variables_used' => array_keys($variables),
        'metadata' => [
          'execution_time' => $result['execution_time'] ?? 0,
          'result_type' => $result['type'] ?? 'unknown',
        ],
      ];
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Agent context execution error: " . $e->getMessage(),
        'error',
        ['context' => $context]
      );

      return [
        'type' => 'calculation_error',
        'success' => false,
        'error' => $e->getMessage(),
        'operation' => $context['operation'] ?? 'unknown',
      ];
    }
  }

  /**
   * Extrait des variables depuis les résultats des dépendances
   */
  private function extractVariablesFromDependencies(array $dependencyResults): array
  {
    $variables = [];

    foreach ($dependencyResults as $depId => $depResult) {
      // Si le résultat contient une valeur numérique directe
      if (isset($depResult['result']) && is_numeric($depResult['result'])) {
        $variables[$depId] = $depResult['result'];
      }

      // Si c'est un résultat de calcul
      if (isset($depResult['type']) && $depResult['type'] === 'calculation_result') {
        if (is_numeric($depResult['result'])) {
          $variables[$depId . '_result'] = $depResult['result'];
        }
      }

      // Si c'est un résultat analytique avec des agrégations
      if (isset($depResult['type']) && $depResult['type'] === 'aggregated_result') {
        foreach ($depResult['results'] as $aggResult) {
          foreach ($aggResult as $key => $value) {
            if (is_numeric($value)) {
              $safeKey = preg_replace('/[^a-z0-9_]/i', '_', $key);
              $variables[$depId . '_' . $safeKey] = $value;
            }
          }
        }
      }

      // Extraction récursive si nécessaire
      if (is_array($depResult)) {
        $extracted = $this->extractNumericValues($depResult, $depId);
        $variables = array_merge($variables, $extracted);
      }
    }

    return $variables;
  }

  /**
   * Extrait les valeurs numériques d'un tableau récursivement
   */
  private function extractNumericValues(array $data, string $prefix = '', int $depth = 0): array
  {
    $values = [];

    // Limiter la profondeur de récursion
    if ($depth > 3) {
      return $values;
    }

    foreach ($data as $key => $value) {
      if (is_numeric($value)) {
        $safeKey = preg_replace('/[^a-z0-9_]/i', '_', $key);
        $fullKey = $prefix ? "{$prefix}_{$safeKey}" : $safeKey;
        $values[$fullKey] = $value;
      } elseif (is_array($value)) {
        $subPrefix = $prefix ? "{$prefix}_{$key}" : $key;
        $subValues = $this->extractNumericValues($value, $subPrefix, $depth + 1);
        $values = array_merge($values, $subValues);
      }
    }

    return $values;
  }

  // ============================================================================
  // 🆕 MÉTHODES DE CACHE
  // ============================================================================

  /**
   * Génère un hash pour le cache
   */
  private function generateCacheHash(string $expression, array $variables): string
  {
    $data = $expression . json_encode($variables, JSON_UNESCAPED_UNICODE);
    return hash('sha256', $data);
  }

  /**
   * Récupère un résultat depuis le cache
   */
  private function getCachedResult(string $expression, array $variables): ?array
  {
    if (!$this->db) {
      return null;
    }

    try {
      $hash = $this->generateCacheHash($expression, $variables);

      $sql = "SELECT * FROM :table_rag_calculator_cache 
              WHERE expression_hash = :hash 
              AND created_at > DATE_SUB(NOW(), INTERVAL :ttl SECOND)
              LIMIT 1";

      $stmt = $this->db->prepare($sql);
      $stmt->execute([
        'hash' => $hash,
        'ttl' => $this->cacheTTL
      ]);

      $row = $stmt->fetch(\PDO::FETCH_ASSOC);

      if ($row) {
        // Mettre à jour les statistiques d'accès
        $updateSql = "UPDATE :table_rag_calculator_cache 
                      SET last_accessed = NOW(), 
                          access_count = access_count + 1 
                      WHERE cache_id = :id";
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->execute(['id' => $row['cache_id']]);

        // Retourner le résultat mis en cache
        return [
          'success' => true,
          'result' => (float)$row['result'],
          'expression' => $expression,
          'prepared_expression' => $expression,
          'execution_time' => (float)$row['execution_time'],
          'type' => $row['result_type'],
          'from_cache' => true,
          'cache_age' => time() - strtotime($row['created_at']),
          'access_count' => (int)$row['access_count'] + 1,
        ];
      }

      return null;
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Cache retrieval error: " . $e->getMessage(),
          'error'
        );
      }
      return null;
    }
  }

  /**
   * Met en cache un résultat
   */
  private function cacheResult(string $expression, array $variables, array $result): bool
  {
    if (!$this->db) {
      return false;
    }

    try {
      $hash = $this->generateCacheHash($expression, $variables);

      $sql = "INSERT INTO :table_rag_calculator_cache 
              (expression, expression_hash, result, result_type, variables, 
               execution_time, created_at, last_accessed, access_count) 
              VALUES 
              (:expression, :hash, :result, :type, :variables, 
               :exec_time, NOW(), NOW(), 0)
              ON DUPLICATE KEY UPDATE 
                result = VALUES(result),
                result_type = VALUES(result_type),
                execution_time = VALUES(execution_time),
                last_accessed = NOW(),
                access_count = access_count + 1";

      $stmt = $this->db->prepare($sql);

      return $stmt->execute([
        'expression' => substr($expression, 0, 500),
        'hash' => $hash,
        'result' => $result['result'],
        'type' => $result['type'],
        'variables' => json_encode($variables, JSON_UNESCAPED_UNICODE),
        'exec_time' => $result['execution_time'],
      ]);
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Cache storage error: " . $e->getMessage(),
          'error'
        );
      }
      return false;
    }
  }

  /**
   * Nettoie le cache expiré
   */
  public function cleanCache(): int
  {
    if (!$this->db) {
      return 0;
    }

    try {
      $sql = "DELETE FROM :table_rag_calculator_cache 
              WHERE created_at < DATE_SUB(NOW(), INTERVAL :ttl SECOND)";

      $stmt = $this->db->prepare($sql);
      $stmt->execute(['ttl' => $this->cacheTTL]);

      $deleted = $stmt->rowCount();

      if ($this->debug && $deleted > 0) {
        $this->securityLogger->logSecurityEvent(
          "Cleaned {$deleted} expired cache entries",
          'info'
        );
      }

      return $deleted;
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Cache cleaning error: " . $e->getMessage(),
        'error'
      );
      return 0;
    }
  }

  /**
   * Vide complètement le cache
   */
  public function clearCache(): bool
  {
    if (!$this->db) {
      return false;
    }

    try {
      $this->db->exec("TRUNCATE TABLE calculator_cache");

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Calculator cache cleared",
          'info'
        );
      }

      return true;
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Cache clearing error: " . $e->getMessage(),
        'error'
      );
      return false;
    }
  }

  /**
   * Obtient les statistiques du cache
   */
  public function getCacheStats(): array
  {
    if (!$this->db) {
      return ['enabled' => false];
    }

    try {
      $stats = [];

      // Nombre total d'entrées
      $stmt = $this->db->query("SELECT COUNT(*) as total FROM :table_rag_calculator_cache");
      $stats['total_entries'] = (int)$stmt->fetchColumn();

      // Nombre d'entrées valides (non expirées)
      $sql = "SELECT COUNT(*) as valid FROM :table_rag_calculator_cache 
              WHERE created_at > DATE_SUB(NOW(), INTERVAL :ttl SECOND)";
      $stmt = $this->db->prepare($sql);
      $stmt->execute(['ttl' => $this->cacheTTL]);
      $stats['valid_entries'] = (int)$stmt->fetchColumn();

      // Nombre d'accès total
      $stmt = $this->db->query("SELECT SUM(access_count) as total FROM :table_rag_calculator_cache");
      $stats['total_accesses'] = (int)$stmt->fetchColumn();

      // Entrée la plus populaire
      $stmt = $this->db->query(
        "SELECT expression, 
                access_count 
         FROM :table_rag_calculator_cache 
         ORDER BY access_count DESC 
         LIMIT 1"
      );
      $popular = $stmt->fetch(\PDO::FETCH_ASSOC);
      $stats['most_popular'] = $popular ? [
        'expression' => $popular['expression'],
        'accesses' => (int)$popular['access_count']
      ] : null;

      // Taux de hit (estimé)
      if ($stats['total_accesses'] > 0) {
        $stats['hit_rate'] = round(
          ($stats['total_accesses'] / ($stats['total_entries'] + $stats['total_accesses'])) * 100,
          2
        );
      } else {
        $stats['hit_rate'] = 0;
      }

      $stats['enabled'] = true;
      $stats['ttl'] = $this->cacheTTL;

      return $stats;
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Cache stats error: " . $e->getMessage(),
        'error'
      );
      return ['enabled' => true, 'error' => $e->getMessage()];
    }
  }

  // ============================================================================
  // 🆕 MÉTHODES DE LOGGING
  // ============================================================================

  /**
   * Enregistre un calcul dans les logs
   */
  private function logCalculation(
    string $expression,
    ?float $result,
    bool $success,
    ?string $errorMessage,
    float $executionTime,
    ?string $stepId = null,
    ?string $planId = null,
    ?array $metadata = null
  ): bool {
    if (!$this->db || !$this->enableLogging) {
      return false;
    }

    try {
      $sql = "INSERT INTO :table_rag_calculator_logs 
              (user_id, expression, result, success, error_message, 
               execution_time, step_id, plan_id, metadata, created_at) 
              VALUES 
              (:user_id, :expression, :result, :success, :error, 
               :exec_time, :step_id, :plan_id, :metadata, NOW())";

      $stmt = $this->db->prepare($sql);

      return $stmt->execute([
        'user_id' => $this->getUserId(),
        'expression' => $expression,
        'result' => $result,
        'success' => $success ? 1 : 0,
        'error' => $errorMessage,
        'exec_time' => $executionTime,
        'step_id' => $stepId,
        'plan_id' => $planId,
        'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
      ]);
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Logging error: " . $e->getMessage(),
          'error'
        );
      }
      return false;
    }
  }

  /**
   * Récupère les logs de calcul
   */
  public function getLogs(int $limit = 100, array $filters = []): array
  {
    if (!$this->db) {
      return [];
    }

    try {
      $where = [];
      $params = [];

      if (isset($filters['success'])) {
        $where[] = "success = :success";
        $params['success'] = $filters['success'] ? 1 : 0;
      }

      if (isset($filters['user_id'])) {
        $where[] = "user_id = :user_id";
        $params['user_id'] = $filters['user_id'];
      }

      if (isset($filters['plan_id'])) {
        $where[] = "plan_id = :plan_id";
        $params['plan_id'] = $filters['plan_id'];
      }

      if (isset($filters['from_date'])) {
        $where[] = "created_at >= :from_date";
        $params['from_date'] = $filters['from_date'];
      }

      $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

      $sql = "SELECT * FROM :table_rag_calculator_logs 
              {$whereClause}
              ORDER BY created_at DESC 
              LIMIT :limit";

      $stmt = $this->db->prepare($sql);

      foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
      }
      $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);

      $stmt->execute();

      return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Log retrieval error: " . $e->getMessage(),
        'error'
      );
      return [];
    }
  }

  /**
   * Obtient les statistiques des logs
   */
  public function getLogStats(array $filters = []): array
  {
    if (!$this->db) {
      return ['enabled' => false];
    }

    try {
      $where = [];
      $params = [];

      if (isset($filters['user_id'])) {
        $where[] = "user_id = :user_id";
        $params['user_id'] = $filters['user_id'];
      }

      if (isset($filters['from_date'])) {
        $where[] = "created_at >= :from_date";
        $params['from_date'] = $filters['from_date'];
      }

      $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

      // Statistiques globales
      $sql = "SELECT 
                COUNT(*) as total_calculations,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed,
                AVG(execution_time) as avg_execution_time,
                MIN(execution_time) as min_execution_time,
                MAX(execution_time) as max_execution_time
              FROM calculator_logs
              {$whereClause}";

      $stmt = $this->db->prepare($sql);
      foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
      }
      $stmt->execute();

      $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

      // Taux de succès
      if ($stats['total_calculations'] > 0) {
        $stats['success_rate'] = round(
          ($stats['successful'] / $stats['total_calculations']) * 100,
          2
        );
      } else {
        $stats['success_rate'] = 0;
      }

      $stats['enabled'] = true;

      return $stats;
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Log stats error: " . $e->getMessage(),
        'error'
      );
      return ['enabled' => true, 'error' => $e->getMessage()];
    }
  }

  /**
   * Nettoie les vieux logs
   */
  public function cleanLogs(int $daysToKeep = 30): int
  {
    if (!$this->db) {
      return 0;
    }

    try {
      $sql = "DELETE FROM :table_rag_calculator_logs 
              WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

      $stmt = $this->db->prepare($sql);
      $stmt->execute(['days' => $daysToKeep]);

      $deleted = $stmt->rowCount();

      if ($this->debug && $deleted > 0) {
        $this->securityLogger->logSecurityEvent(
          "Cleaned {$deleted} old log entries",
          'info'
        );
      }

      return $deleted;
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Log cleaning error: " . $e->getMessage(),
        'error'
      );
      return 0;
    }
  }

  /**
   * Obtient l'ID utilisateur actuel (à adapter selon votre système)
   */
  private function getUserId(): string
  {
    AdministratorAdmin::getUserAdminId();

/*
    // Essayer de récupérer l'ID utilisateur depuis la session
    if (isset($_SESSION['customer_id'])) {
      return (string)$_SESSION['customer_id'];
    }

    if (Registry::exists('Customer')) {
      $customer = Registry::get('Customer');
      if (method_exists($customer, 'getId')) {
        return (string)$customer->getId();
      }
    }
*/
    return 'system';
  }
}