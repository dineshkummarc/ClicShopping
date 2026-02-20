<?php

declare(strict_types=1);

use ClicShopping\AI\Agents\Orchestrator\SubAutonomous\BusinessDomainPermissionManager;
use ClicShopping\OM\Registry;

require_once __DIR__ . '/../Core/ClicShopping/OM/Registry.php';
require_once __DIR__ . '/../Core/ClicShopping/AI/Agents/Orchestrator/SubAutonomous/BusinessDomainPermissionManager.php';

class FakeStatement
{
  private string $sql;
  private FakeDb $db;
  private array $values = [];
  private array $rows = [];
  private int $cursor = 0;
  private ?array $current = null;

  public function __construct(string $sql, FakeDb $db)
  {
    $this->sql = $sql;
    $this->db = $db;
  }

  public function bindValue(string $key, $value): void
  {
    $this->values[$key] = $value;
  }

  public function bindInt(string $key, int $value): void
  {
    $this->values[$key] = $value;
  }

  public function execute(): bool
  {
    $sql = $this->sql;

    if (strpos($sql, 'FROM :table_rag_agent_business_domain_permissions') !== false) {
      if (stripos($sql, 'SELECT permission_level') !== false) {
        $agentId = $this->values[':agent_id'] ?? '';
        $businessDomain = $this->values[':business_domain'] ?? '';
        if (isset($this->db->permissions[$agentId][$businessDomain])) {
          $this->rows = [[
            'permission_level' => $this->db->permissions[$agentId][$businessDomain]
          ]];
        } else {
          $this->rows = [];
        }
      } elseif (stripos($sql, 'SELECT COUNT(*) as count') !== false) {
        $agentId = $this->values[':agent_id'] ?? '';
        $businessDomain = $this->values[':business_domain'] ?? null;
        $count = 0;
        if ($businessDomain !== null) {
          if (isset($this->db->permissions[$agentId][$businessDomain])) {
            $count = 1;
          }
        } else {
          if (isset($this->db->permissions[$agentId])) {
            $count = count($this->db->permissions[$agentId]);
          }
        }
        $this->rows = [['count' => $count]];
      } else {
        $this->rows = [];
      }
    } elseif (strpos($sql, ':table_rag_agent_business_domain_access_log') !== false) {
      $this->db->logs[] = [
        'agent_id' => $this->values[':agent_id'] ?? null,
        'business_domain' => $this->values[':business_domain'] ?? null,
        'action' => $this->values[':action'] ?? null,
        'granted' => $this->values[':granted'] ?? null
      ];
      $this->rows = [];
    } else {
      $this->rows = [];
    }

    $this->cursor = 0;
    $this->current = null;
    return true;
  }

  public function fetch(): bool
  {
    if ($this->cursor < count($this->rows)) {
      $this->current = $this->rows[$this->cursor];
      $this->cursor++;
      return true;
    }

    $this->current = null;
    return false;
  }

  public function value(string $key): mixed
  {
    return $this->current[$key] ?? null;
  }

  public function valueInt(string $key): int
  {
    return (int)($this->current[$key] ?? 0);
  }
}

class FakeDb
{
  public array $permissions = [];
  public array $logs = [];

  public function prepare(string $sql): FakeStatement
  {
    return new FakeStatement($sql, $this);
  }
}

function assertSameValue($expected, $actual, string $message): void
{
  if ($expected !== $actual) {
    throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ', got: ' . var_export($actual, true));
  }
}

$fakeDb = new FakeDb();
$fakeDb->permissions = [
  'ReaderAgent' => [
    'ecommerce' => BusinessDomainPermissionManager::PERMISSION_READ_ONLY
  ],
  'OrchestratorAgent' => [
    'ecommerce' => BusinessDomainPermissionManager::PERMISSION_EXECUTE_ALL
  ],
  'ProposerAgent' => [
    'ecommerce' => BusinessDomainPermissionManager::PERMISSION_PROPOSE
  ],
  'SafeAgent' => [
    'ecommerce' => BusinessDomainPermissionManager::PERMISSION_EXECUTE_SAFE
  ]
];

Registry::set('Db', $fakeDb, true);
$manager = new BusinessDomainPermissionManager();

$fakeDb->logs = [];
$allowed = $manager->checkPermission('ReaderAgent', 'ecommerce', 'READ');
assertSameValue(true, $allowed, 'READ should be allowed for read_only');
assertSameValue(1, count($fakeDb->logs), 'Access should be logged once');
assertSameValue('read', $fakeDb->logs[0]['action'], 'Action should be normalized to lowercase');

$fakeDb->logs = [];
$denied = $manager->checkPermission('ReaderAgent', 'ecommerce', 'delete');
assertSameValue(false, $denied, 'delete should be denied for read_only');
assertSameValue(1, count($fakeDb->logs), 'Denied access should be logged once');

$fakeDb->logs = [];
$empty = $manager->checkPermission('ReaderAgent', 'ecommerce', '  ');
assertSameValue(false, $empty, 'Empty action should be denied');
assertSameValue(1, count($fakeDb->logs), 'Empty action should be logged once');

$requiresApproval = $manager->requiresApproval('ProposerAgent', 'ecommerce', 'update');
assertSameValue(true, $requiresApproval, 'Propose permission should require approval for update');

$requiresApprovalSafe = $manager->requiresApproval('SafeAgent', 'ecommerce', 'delete');
assertSameValue(true, $requiresApprovalSafe, 'execute_safe should require approval for delete');

$noApprovalSafe = $manager->requiresApproval('SafeAgent', 'ecommerce', 'create');
assertSameValue(false, $noApprovalSafe, 'execute_safe should not require approval for create');

echo "OK\n";
