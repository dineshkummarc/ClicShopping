# AI_SYSTEM.md — ClicShopping AI v4.20+

> PHP framework agnostic AI system.
> Agent operational rules: `AGENTS.md` — Core architecture: `ARCHITECTURE.md`

---

## 1. Positioning

The AI system is the **highest architectural priority** component of ClicShopping.
It is designed as an **agnostic** layer: its agents, interfaces and pipelines do not
do not depend on the underlying PHP framework and can evolve independently.

Location: `Core/ClicShopping/AI/`, organized by business domain.

---

## 2. Hybrid RAG architecture

The system combines three recovery modes depending on the detected intention:

| Type | Mechanism | Use cases |
|---|---|---|
| **Analytics** | Natural language → Generated SQL | KPIs, reports, aggregates |
| **Semantics** | Vector search `VECTOR(3072)` | Product similarity, recommendations |
| **Web** | External search | Real-time data outside the base |
| **Hybrid** | Weighted combination of all three | Complex multi-source queries |

The mode is automatically selected by the `QueryClassifier` before execution.

---
## 3. Multi-agent architecture

### Agents and responsibilities

| Component | Type | Role |
|---|---|---|
| `OrchestratorAgent` | Senior Agent | Task decomposition, inter-agent coordination |
| `ReasoningAgent` | Specialized agent | Multi-step reasoning (CoT / ToT / self-consistency modes) |
| `CorrectionAgent` | Specialized agent | Error detection/correction and response improvement |
| `ValidationAgent` | Specialized agent | Output validation and consistency |
| `AnalyticsAgent` | Domain Agent | SQL generation/execution from natural language |
| `SemanticAgent` | Domain Agent | Semantic search on vector tables |
| `WebSearchTool` + `WebSearchQueryExecutor` | Domain tools | External Data Recovery |
| `QueryClassifier` | Classify | Intent analysis and routing |
| `MemoryRetentionService` | Memory service | Short/medium/long term memory |

### Registration rule

Any new agent must register via the existing mechanism in `Core/ClicShopping/AI/`:
- If it s core Agents For all system must be registered in `Core/ClicShopping/AI/`:
- if the agent is not "important" it must be implemented in the Core/ClicShopping/Apps/{Vendor}/{AppName}/. in this case vendor is AI (example SEO agent)
- Do not create an alternative agent register
- Do not instantiate agents directly from PHP Apps
- Go through the orchestrator for any inter-agent invocation
- Each agent must use the Actor interface for agentic approach Core/ClicShopping/AI/InterfacesAI/ActorAgentInterface.php.
---

## 4. Reasoning (real state of the core)

The current core does not expose a single `ReasoningInterface` interface.
Reasoning is implemented in `ReasoningAgent` with configurable modes:
- `chain_of_thought`
- `tree_of_thought`
- `self_consistency`

These modes are orchestrated by `OrchestratorAgent` and used depending on the request context.

---

## 5. LLM Providers

Abstraction via **LLPhant** — never call LLM APIs directly.

### Example 

| Provider | Configuration constant | Main use |
|---|---|---|
| OpenAI (GPT-4/5) | `CLICSHOPPING_APP_CHATGPT_CH_API_KEY` | Generation, reasoning |
| Anthropic (Claude) | `CLICSHOPPING_APP_CHATGPT_CH_API_KEY_ANTHROPIC` | Generation, reasoning |
| Mistral | `CLICSHOPPING_APP_CHATGPT_CH_API_KEY_MISTRAL` | Generation |
| VoyageAI | `CLICSHOPPING_APP_CHATGPT_RA_API_KEY_VOYAGE_AI` | Embeddings only |
| Ollama / LmStudio | Local server configuration | Local/Private LLM |

Rules:
- API keys only via configuration constants — never hardcoded
- The choice of provider is configured via the admin interface, not in the code
- If adding a new provider, go through LLPhant — no direct HTTP client

---
## 6. Embedding pipeline

### Embedding tables (real state of the schema)

E-commerce core (3072):
- `clic_products_embedding`
- `clic_categories_embedding`
- `clic_reviews_embedding`
- `clic_reviews_sentiment_embedding`
- `clic_orders_embedding`
- `clic_pages_manager_embedding`
- `clic_manufacturers_embedding`
- `clic_suppliers_embedding`
- `clic_return_orders_embedding`

Additional memory/security tables:
- `clic_conversation_memory_embedding` (3072)
- `clic_correction_pattern_embedding` (3072)

### Common table structure

| Column | Type | Role                                    |
|---|---|-----------------------------------------|
| `embedding` | `VECTOR(3072)`  | Semantic vector                         |
| `entity_id` | INT | Link to source table                    |
| `content` | TEXT | Original text indexed                   |
| `metadata` | JSON | Contextual enrichment (added v4.11)     |
| `chunknumber` | INT | Sequential chunk index within the document (chunk size: 128 tokens) |
| `date_modified` | DATETIME | Last Updated Timestamp                  |

### Pipeline rules

- The generation of embeddings is managed by **existing crons** — do not recreate this mechanism
- The vector dimensions are driven by the existing diagram (mostly 3072)
- MariaDB ≥ 11.7 is imperative for native `VECTOR INDEX`
- Do not modify the structure of the embedding tables without agreement from the human coder.

---

## 7. AI Security (Guardrails)

The system includes a security layer dedicated to LLM interactions.

Active mechanisms:
- **Prompt injection detection** — security checks applied via existing AI/API components before full execution
- **Obfuscation scan** — detection of encoded evasion attempts
- **Rate limiting AI** — window 900s, 20 requests max per identifier
- **Audit** — `clic_rag_security_events` table for traceability

Constants:
```
CLICSHOPPING_APP_API_AI_RATE_LIMIT_WINDOW = 900
CLICSHOPPING_APP_API_AI_MAX_REQUEST_PER_WINDOW = 20
CLICSHOPPING_APP_API_AI_MAX_LOGIN_ATTEMPTS = 5
CLICSHOPPING_APP_API_AI_ACCOUNT_LOCK_DURATION = 1800
```

**Do not bypass guardrails in application code**; For testing, use the planned configurations and a dedicated environment.

---

## 8. MCP — Model Context Protocol

ClicShopping exposes an MCP server for agentic commerce (port 3001 by default, can be modified inside the administration).

Roles:
- Health monitoring of the AI system
- Performance metrics (latency, error rate, uptime)
- Integration with external agents via standardized MCP protocol

Storage table: `clic_mcp_performance_history`
Do not modify the MCP protocol without agreement from the human coder.

---

## 9. AI development rules

```
✓ Always go through LLPhant for LLM calls
✓ Leverage `ReasoningAgent` and existing orchestration
✓ Register new agents via Core/ClicShopping/AI/
✓ API keys via configuration constants only
✓ Respect existing guardrails

✗ Direct calls to LLM APIs (OpenAI, Anthropic, etc.)
✗ Recreate the embeddings pipeline or associated crons
✗ Modify the structure of *_embedding tables without agreement
✗ Edit existing vector dimensions without proprietary validation
✗ Bypass guardrails for testing
✗ Instantiate agents directly from PHP Apps
```

---

## 10. References

- Architecture framework: `ARCHITECTURE.md`
- Vector database: `DATABASE.md`
- Guardrails security: `SECURITY.md`
- DeepWiki AI section: https://deepwiki.com/ClicShopping/ClicShopping/5-ai-integration-and-rag-system