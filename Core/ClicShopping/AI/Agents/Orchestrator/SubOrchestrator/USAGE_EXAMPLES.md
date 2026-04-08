# 📖 Exemples d'Utilisation - SubOrchestrator Components

Ce document fournit des exemples pratiques d'utilisation des composants SubOrchestrator.

---

## 1. IntentAnalyzer

### Analyse Simple d'une Requête

```php
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\IntentAnalyzer;
use ClicShopping\AI\Agents\Memory\ConversationMemory;

// Initialisation
$conversationMemory = new ConversationMemory($userId, $languageId);
$intentAnalyzer = new IntentAnalyzer($conversationMemory, $debug = true);

// Analyse d'une requête
$query = "Quelles sont nos marques de produit?";
$intent = $intentAnalyzer->analyze($query);

// Résultat
print_r($intent);
/*
Array (
    [type] => analytics
    [confidence] => 0.95
    [translated_query] => What are our product brands?
    [is_hybrid] => false
    [requires_context] => false
    [metadata] => Array (
        [entities] => Array (
            [0] => Array (
                [type] => manufacturer
                [value] => brands
            )
        )
        [filters] => Array ()
        [time_range] => null
    )
)
*/
```

### Détection de Requête Hybride

```php
$query = "Compare nos ventes avec Amazon et résume nos CGV";
$intent = $intentAnalyzer->analyze($query);

if ($intent['is_hybrid']) {
    echo "Requête hybride détectée!";
    echo "Types: " . implode(', ', $intent['hybrid_types']);
    // Types: analytics, semantic, web_search
}
```

### Vérification du Besoin de Contexte

```php
$query = "Quel est son prix?"; // Référence contextuelle
$intent = $intentAnalyzer->analyze($query);

if ($intent['requires_context']) {
    echo "Cette requête nécessite du contexte conversationnel";
    // Résoudre les références avec ConversationMemory
}
```

---

## 2. EntityExtractor

### Extraction Basique

```php
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\EntityExtractor;

// Initialisation
$entityExtractor = new EntityExtractor($debug = true);

// Extraction depuis executionResult
$executionResult = [
    'success' => true,
    'result' => [
        'entity_id' => 123,
        'entity_type' => 'product',
        'data' => [...]
    ]
];

$intent = ['type' => 'analytics', 'metadata' => [...]];

$entityId = $entityExtractor->extractEntityId($executionResult, $intent);
$entityType = $entityExtractor->extractEntityType($executionResult, $intent);

echo "Entity ID: {$entityId}"; // 123
echo "Entity Type: {$entityType}"; // product
```

### Extraction avec ExecutionPlan

```php
// Avec un plan d'exécution
$plan = $taskPlanner->createPlan($intent, $query, $context);
$executionResult = $planExecutor->execute($plan);

// Extraction avec plan pour meilleure précision
$entityId = $entityExtractor->extractEntityId($executionResult, $intent, $plan);
$entityType = $entityExtractor->extractEntityType($executionResult, $intent, $plan);

// L'extracteur cherchera dans:
// 1. executionResult direct
// 2. result wrapper
// 3. Plan step results (grâce au $plan)
// 4. Intent metadata
```

### Gestion des Cas sans Entity

```php
$entityId = $entityExtractor->extractEntityId($executionResult, $intent);

if ($entityId === null) {
    // Aucune entité trouvée, utiliser valeur par défaut
    $entityId = 0;
    $entityType = 'general';
    echo "Aucune entité spécifique, utilisation de valeurs par défaut";
}
```

---

## 3. DiagnosticManager

### Initialisation et Stockage d'Erreurs

```php
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\DiagnosticManager;

// Initialisation avec référence aux stats
$executionStats = [
    'total_requests' => 0,
    'total_execution_time' => 0,
    // ...
];

$diagnosticManager = new DiagnosticManager($executionStats, $debug = true);

// Stockage d'une erreur
try {
    // Code qui peut échouer
    throw new \Exception("Database connection failed");
} catch (\Exception $e) {
    $diagnosticManager->storeError(
        $e->getMessage(),
        $query,
        [
            'intent' => $intent,
            'stack_trace' => $e->getTraceAsString(),
            'execution_id' => $executionId
        ]
    );
}
```

### Rapport de Santé Système

```php
// Obtenir un rapport de santé complet
$healthReport = $diagnosticManager->getHealthReport();

print_r($healthReport);
/*
Array (
    [status] => healthy
    [timestamp] => 2025-10-24 15:30:45
    [metrics] => Array (
        [success_rate] => 95.5
        [total_requests] => 1000
        [total_errors] => 45
        [avg_response_time] => 1.234
        [classification_accuracy] => 92.3
        [memory_usage] => 45.67 MB
        [memory_peak] => 52.34 MB
        [memory_limit] => 256M
    )
    [components] => Array (
        [task_planner] => operational
        [plan_executor] => operational
        [conversation_memory] => operational
        [monitoring] => operational
    )
)
*/

// Vérifier le statut
if ($healthReport['status'] === 'unhealthy') {
    echo "⚠️ Système en mauvaise santé!";
    echo "Success rate: {$healthReport['metrics']['success_rate']}%";
}
```

### Explication d'Erreur avec GPT

```php
// Obtenir une explication en langage naturel de la dernière erreur
$explanation = $diagnosticManager->explainLastError();

echo $explanation;
/*
"La dernière erreur s'est produite lors de la connexion à la base de données.
Cela peut être dû à:
1. Le serveur de base de données est hors ligne
2. Les identifiants de connexion sont incorrects
3. Le pare-feu bloque la connexion

Pour résoudre ce problème:
- Vérifiez que le serveur MySQL est démarré
- Vérifiez les paramètres de connexion dans config.php
- Vérifiez les règles du pare-feu"
*/
```

### Liste des Erreurs Récentes

```php
// Obtenir les 10 dernières erreurs
$recentErrors = $diagnosticManager->getRecentErrors(10);

foreach ($recentErrors as $error) {
    echo "Erreur: {$error['message']}\n";
    echo "Requête: {$error['query']}\n";
    echo "Date: {$error['datetime']}\n";
    echo "---\n";
}
```

### Suggestions d'Amélioration

```php
// Analyser les patterns d'erreur et obtenir des suggestions
$suggestions = $diagnosticManager->suggestImprovements();

print_r($suggestions);
/*
Array (
    [status] => analyzed
    [total_errors] => 45
    [error_types] => Array (
        [database] => 20
        [timeout] => 15
        [validation] => 10
    )
    [suggestions] => Array (
        [0] => Array (
            [priority] => high
            [category] => database
            [impact] => Affects 44% of errors
            [suggestion] => Check database connection, optimize queries, and ensure proper indexing.
        )
        [1] => Array (
            [priority] => medium
            [category] => timeout
            [impact] => Affects 33% of errors
            [suggestion] => Increase timeout limits, optimize slow operations, or implement caching.
        )
    )
)
*/

// Afficher les suggestions prioritaires
foreach ($suggestions['suggestions'] as $suggestion) {
    if ($suggestion['priority'] === 'high') {
        echo "🔴 PRIORITÉ HAUTE: {$suggestion['category']}\n";
        echo "   Impact: {$suggestion['impact']}\n";
        echo "   Solution: {$suggestion['suggestion']}\n\n";
    }
}
```

---

## 4. Utilisation Intégrée dans OrchestratorAgent

### Exemple Complet

```php
class OrchestratorAgent
{
    private IntentAnalyzer $intentAnalyzer;
    private EntityExtractor $entityExtractor;
    private DiagnosticManager $diagnosticManager;

    public function __construct(string $userId, ?int $languageId = null)
    {
        // Initialisation des composants
        $this->intentAnalyzer = new IntentAnalyzer($this->conversationMemory, $this->debug);
        $this->entityExtractor = new EntityExtractor($this->debug);
        $this->diagnosticManager = new DiagnosticManager($this->executionStats, $this->debug);
    }

    public function processWithValidation(string $query, array $options = []): array
    {
        try {
            // 1. Analyser l'intention
            $intent = $this->intentAnalyzer->analyze($query);

            // 2. Créer et exécuter le plan
            $plan = $this->taskPlanner->createPlan($intent, $query, $context);
            $executionResult = $this->planExecutor->execute($plan);

            // 3. Extraire les entités
            $entityId = $this->entityExtractor->extractEntityId($executionResult, $intent, $plan);
            $entityType = $this->entityExtractor->extractEntityType($executionResult, $intent, $plan);

            // 4. Construire la réponse
            return [
                'success' => true,
                'type' => $intent['type'],
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                // ...
            ];

        } catch (\Exception $e) {
            // 5. Stocker l'erreur pour diagnostic
            $this->diagnosticManager->storeError(
                $e->getMessage(),
                $query,
                ['intent' => $intent ?? null, 'stack_trace' => $e->getTraceAsString()]
            );

            return $this->buildErrorResponse($e->getMessage());
        }
    }

    // Méthodes de diagnostic déléguées
    public function getHealthReport(): array
    {
        return $this->diagnosticManager->getHealthReport();
    }

    public function explainLastError(): string
    {
        return $this->diagnosticManager->explainLastError();
    }

    public function getRecentErrors(int $limit = 10): array
    {
        return $this->diagnosticManager->getRecentErrors($limit);
    }

    public function suggestImprovements(): array
    {
        return $this->diagnosticManager->suggestImprovements();
    }
}
```

### Utilisation depuis un Endpoint

```php
// Dans un contrôleur ou endpoint
$orchestrator = new OrchestratorAgent($userId, $languageId);

// Traiter une requête
$response = $orchestrator->processWithValidation($query);

// Obtenir le rapport de santé
$health = $orchestrator->getHealthReport();

// Obtenir les erreurs récentes
$errors = $orchestrator->getRecentErrors(5);

// Obtenir des suggestions d'amélioration
$suggestions = $orchestrator->suggestImprovements();

// Retourner au client
return [
    'response' => $response,
    'system_health' => $health,
    'recent_errors' => $errors,
    'suggestions' => $suggestions
];
```

---

## 5. Patterns Avancés

### Pattern: Retry avec Diagnostic

```php
$maxRetries = 3;
$attempt = 0;

while ($attempt < $maxRetries) {
    try {
        $response = $orchestrator->processWithValidation($query);
        break; // Succès
    } catch (\Exception $e) {
        $attempt++;
        
        if ($attempt >= $maxRetries) {
            // Dernière tentative échouée, obtenir des suggestions
            $suggestions = $orchestrator->suggestImprovements();
            
            // Logger les suggestions
            error_log("Failed after {$maxRetries} attempts. Suggestions:");
            foreach ($suggestions['suggestions'] as $suggestion) {
                error_log("- {$suggestion['category']}: {$suggestion['suggestion']}");
            }
            
            throw $e;
        }
        
        // Attendre avant de réessayer
        sleep(pow(2, $attempt)); // Exponential backoff
    }
}
```

### Pattern: Monitoring Proactif

```php
// Vérifier la santé du système périodiquement
function checkSystemHealth(OrchestratorAgent $orchestrator): void
{
    $health = $orchestrator->getHealthReport();
    
    if ($health['status'] === 'unhealthy') {
        // Alerter l'équipe
        sendAlert("System unhealthy: " . json_encode($health['metrics']));
        
        // Obtenir des suggestions
        $suggestions = $orchestrator->suggestImprovements();
        
        // Appliquer des corrections automatiques si possible
        foreach ($suggestions['suggestions'] as $suggestion) {
            if ($suggestion['priority'] === 'high') {
                applyAutoFix($suggestion);
            }
        }
    }
}
```

### Pattern: Analyse de Tendances

```php
// Analyser les erreurs sur une période
function analyzeErrorTrends(OrchestratorAgent $orchestrator): array
{
    $errors = $orchestrator->getRecentErrors(50);
    
    $trends = [
        'hourly' => [],
        'by_type' => [],
        'by_query_pattern' => []
    ];
    
    foreach ($errors as $error) {
        $hour = date('H', $error['timestamp']);
        $trends['hourly'][$hour] = ($trends['hourly'][$hour] ?? 0) + 1;
        
        // Catégoriser par type
        $type = categorizeError($error['message']);
        $trends['by_type'][$type] = ($trends['by_type'][$type] ?? 0) + 1;
    }
    
    return $trends;
}
```

---

## 📚 Ressources Supplémentaires

- **README Principal:** `SubOrchestrator/README.md`
- **Rapport Détaillé:** `REFACTORISATION_ORCHESTRATOR_24_OCT_2025.md`
- **Synthèse:** `SYNTHESE_REFACTORISATION.md`

---

**Date:** 24 Octobre 2025  
**Version:** 1.2  
**Status:** Documentation complète
