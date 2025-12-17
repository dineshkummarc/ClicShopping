<?php

namespace ClicShopping\AI\Agents\Planning;

/**
 * TaskStep Class
 *
 * Représente une étape atomique d'un plan d'exécution
 */
#[AllowDynamicProperties]
class TaskStep
{
  private string $id;
  private string $type;
  private string $description;
  private array $metadata;
  private string $status = 'pending'; // pending, in_progress, completed, failed
  private $result = null;
  private ?string $error = null;
  private float $executionTime = 0.0;
  private float $startTime = 0.0;

  /**
   * Constructor
   *
   * @param string $id Identifiant unique de l'étape
   * @param string $type Type d'étape (analytics_query, semantic_search, synthesis, etc.)
   * @param string $description Description de l'étape
   * @param array $metadata Métadonnées additionnelles
   */
  public function __construct(
    string $id,
    string $type,
    string $description,
    array $metadata = []
  ) {
    $this->id = $id;
    $this->type = $type;
    $this->description = $description;
    $this->metadata = $metadata;
  }

  /**
   * Démarre l'exécution de l'étape
   */
  public function start(): void
  {
    $this->status = 'in_progress';
    $this->startTime = microtime(true);
  }

  /**
   * Marque l'étape comme complétée
   */
  public function complete($result): void
  {
    $this->status = 'completed';
    $this->result = $result;

    if ($this->startTime > 0) {
      $this->executionTime = microtime(true) - $this->startTime;
    }
  }

  /**
   * Marque l'étape comme échouée
   */
  public function fail(string $error): void
  {
    $this->status = 'failed';
    $this->error = $error;

    if ($this->startTime > 0) {
      $this->executionTime = microtime(true) - $this->startTime;
    }
  }

  /**
   * Réinitialise l'étape
   */
  public function reset(): void
  {
    $this->status = 'pending';
    $this->result = null;
    $this->error = null;
    $this->executionTime = 0.0;
    $this->startTime = 0.0;
  }

  /**
   * Obtient une métadonnée spécifique
   */
  public function getMeta(string $key, $default = null)
  {
    return $this->metadata[$key] ?? $default;
  }

  /**
   * Définit une métadonnée
   */
  public function setMeta(string $key, $value): void
  {
    $this->metadata[$key] = $value;
  }

  /**
   * Vérifie si l'étape est finale
   */
  public function isFinal(): bool
  {
    return $this->metadata['is_final'] ?? false;
  }

  /**
   * Vérifie si l'étape peut s'exécuter en parallèle
   */
  public function canRunParallel(): bool
  {
    return $this->metadata['can_run_parallel'] ?? false;
  }

  /**
   * Obtient les dépendances
   */
  public function getDependencies(): array
  {
    return $this->metadata['depends_on'] ?? [];
  }

  // Getters
  // Need entityId ? to check

  public function getId(): string { return $this->id; }
  public function getType(): string { return $this->type; }
  public function getDescription(): string { return $this->description; }
  public function getMetadata(): array { return $this->metadata; }
  public function getStatus(): string { return $this->status; }
  public function getResult() { return $this->result; }
  public function getError(): ?string { return $this->error; }
  public function getExecutionTime(): float { return $this->executionTime; }

  // Setters
  public function setResult($result): void { $this->result = $result; }
  public function setStatus(string $status): void { $this->status = $status; }
}