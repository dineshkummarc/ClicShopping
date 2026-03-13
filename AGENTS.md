# AGENTS.md — ClicShopping AI v4.20+

This repository contains **ClicShopping AI™** - a powerful, open-source e-commerce platform designed for B2B, B2C, and B2B-B2C businesses, enhanced with advanced Agentic and Generative AI capabilities.

# Product

## Purpose
- Modern e-commerce platform with AI-powered features
- Integration with GPT, Ollama, and Anthropic AI models
- RAG-powered Business Intelligence for data analysis and insights
- Comprehensive testing and development environment

## Key AI Features
- **Generative AI Integration**: GPT API, Ollama, and Anthropic support
- **RAG Business Intelligence**: Vector embeddings, semantic search, natural language to SQL
- **AI-Powered Content**: Product descriptions, SEO optimization, review analysis
- **Predictive Analytics**: Real-time safety stock prediction
- **MCP Server**: Model Context Protocol for agentic e-commerce approach

## Core E-commerce Features
- Multi-template responsive design with Bootstrap 5.x
- Dynamic pricing with customer groups and special prices
- Multi-currency and multi-language support
- Advanced security with GDPR compliance, AES encryption, 2FA
- Performance optimization with OpCache, Memcached, Redis

## Target Audience
E-commerce developers, AI engineers, and businesses looking to integrate advanced AI capabilities into their online stores.
This project **is not** osCommerce nor a legacy ClicShopping V2/V3 fork.

---

## Absolute rule

```
PROHIBITED: Generating code without reading existing files in the target scope.
PROHIBITED: assuming a structure without verification in the actual repository.
IMPORTANT: Self-Correction Protocol: Before delivering any code, the Agent MUST verify its output against the Absolute Prohibitions list of ALL six .md files. If a conflict is found, the Agent MUST prioritize AGENTS.md and SECURITY.md
```

Before any code: read existing files → check hooks → minimal modification.

---

## Reference documentation

All technical documentation is in the `Agents/` directory.

| File | Content |
|---|---|
| `Agents/ARCHITECTURE.md` | Bootstrap, routing, Registry, hooks, namespaces, Custom/, cache |
| `Agents/AI_SYSTEM.md` | Agents, RAG, LLM providers, reasoning, embeddings, MCP |
| `Agents/DATABASE.md` | MariaDB 11.7+, SQL schema, SQL file routing, migrations |
| `Agents/SECURITY.md` | 10 security layers, AI guardrails, rate limiting, GDPR |
| `Agents/TEMPLATES.md` | Front-office vs back-office rendering, helpers, SEO, i18n |

---

## Scalability Priority Order

```
1. Existing Hook → always check first → Core/ClicShopping/Apps/*/Module/Hooks
2. Module → Core/ClicShopping/Apps/*/Module/
3. New App → Core/ClicShopping/Apps/{Vendor}/{AppName}/
4. Core/ClicShopping/Custom/ → override core without modifying Core/ClicShopping/OM/
5. Core/ClicShopping/OM/ direct → PROHIBITED without agreement from the human coder
6. IF you cannot list the directory content in real-time, YOU MUST ASK the user to provide the file list of Core/ClicShopping/Apps/ or Custom/ before suggesting any file creation to avoid path collisions
```

---

## Stack — critical constraints

- **PHP ≥ 8.4** — `public private(set)` on critical service properties
- **MariaDB ≥ 11.7** — MySQL incompatible with `VECTOR(3072)`
- **LLPhant** — only access layer to LLMs, no direct API call. for version look composer.json inside the root
- **Autoload**: `CLICSHOPPING::autoload` + Composer vendor (`Core/ClicShopping/External/vendor`) — no alternative autoload mechanism. use composer.json in the root

---

## Absolute prohibitions

```
✗ Legacy osCommerce / ClicShopping V2/V3
✗ Modify Core/ClicShopping/OM/ without the human coder consent. Core of the application
✗ Modify Core/ClicShopping/Work/ or Core/ClicShopping/External/vendor/
✗ Application SQL in sql_upgrade/ - just the sql must be updated for the user (migration)
✗ Business logic or DB access in templates
✗ Hardcoded channel identifiers (B2B/B2C must be dynamic, never hardcoded)
✗ Hardcoded API keys
✗ Direct LLM calls without LLPhant
✗ MySQL 9.x
```

---

## External references

- Wiki: https://github.com/ClicShopping/ClicShopping/wiki
- DeepWiki: https://deepwiki.com/ClicShopping/ClicShopping
- Forum: https://www.clicshopping.org
- Documentation: docs/documentation.md - root of the application