# SECURITY.md — ClicShopping AI v4.20+

> Sécurité transversale — s'applique au framework, aux modules, aux agents AI et aux APIs.
> Règles opérationnelles agents : `AGENTS.md`

---

## 1. Principe général

La sécurité de ClicShopping AI est organisée en **10 couches indépendantes**.
Chaque couche doit rester active. Ne jamais en contourner une, même en développement.

---

## 2. Architecture en 10 couches

| # | Couche | Mécanisme | Portée |
|---|---|---|---|
| 1 | **Web server** | Apache `.htaccess` : headers sécurité, blocage bots, restriction chemins | Toutes requêtes HTTP |
| 2 | **Sanitisation** | Module `SecurityPro` : filtrage XSS, prévention SQLi | Inputs utilisateur |
| 3 | **Authentification** | `Hash::verify`, session sécurisée, tokens | Login admin et shop |
| 4 | **2FA email** | Codes 4-8 chiffres, expiry 5 min | Login admin |
| 5 | **Rate limiting** | Fenêtre 900s, max 20 requêtes par identifiant | APIs et endpoints AI |
| 6 | **Verrouillage compte** | 5 tentatives échouées → lock 30 min | Authentification |
| 7 | **Guardrails AI** | Détection injection de prompt, scan obfuscation, scoring | Endpoints LLM |
| 8 | **Chiffrement** | AES-256 pour les données sensibles | Stockage données |
| 9 | **GDPR** | Export/suppression données, audit log | Données personnelles |
| 10 | **Monitoring** | Table `rag_security_events`, health scoring, MCP | Observabilité |

---

## 3. Règles obligatoires pour tout code

### Inputs
- Valider **toutes** les entrées utilisateur côté serveur
- Utiliser les helpers de sanitisation existants — ne pas écrire de filtrage custom
- Ne jamais faire confiance aux données de `$_GET`, `$_POST`, `$_COOKIE` sans traitement

### Outputs
- Échapper toutes les sorties HTML (`htmlspecialchars` ou helpers existants)
- Ne jamais afficher de données brutes issues de la base ou de l'utilisateur
- Les templates ne peuvent pas recevoir de données non préparées

### Credentials et secrets
```
✗ Jamais de clé API hardcodée dans le code
✗ Jamais de credential dans un fichier versionné
✗ Jamais de chemin interne exposé dans les messages d'erreur
✗ Jamais de données personnelles non chiffrées dans les logs
```

Toujours utiliser les **constantes de configuration** définies via l'interface admin.

---

## 4. Sécurité des endpoints admin

- Tout endpoint admin doit vérifier la session admin active
- Ne jamais exposer un endpoint admin sans authentification
- Les actions destructives (suppression, modification de config) doivent vérifier le token CSRF
- Respecter la couche de rate limiting pour les endpoints API

---

## 5. Sécurité AI — Guardrails

### Détection d'injection de prompt

Tout input envoyé à un LLM passe par le système de scoring avant traitement :
- Scan des patterns d'injection connus
- Détection d'obfuscation (encodage, homoglyphes, etc.)
- Score de menace calculé — requête bloquée si seuil dépassé

**Ne jamais contourner cette validation**, même pour des tests de développement.  
Utiliser les environnements de staging dédiés pour les tests AI.

### Rate limiting AI

| Constante | Valeur | Rôle |
|---|---|---|
| `CLICSHOPPING_APP_API_AI_RATE_LIMIT_WINDOW` | 900s | Fenêtre de temps |
| `CLICSHOPPING_APP_API_AI_MAX_REQUEST_PER_WINDOW` | 20 | Requêtes max par identifiant |
| `CLICSHOPPING_APP_API_AI_MAX_LOGIN_ATTEMPTS` | 5 | Tentatives avant lock |
| `CLICSHOPPING_APP_API_AI_ACCOUNT_LOCK_DURATION` | 1800s | Durée du verrouillage |

Tables associées :
- `clic_api_rate_limit` — suivi des requêtes par identifiant + timestamp
- `clic_api_failed_attempts` — suivi des tentatives échouées
- `rag_security_events` — audit des événements de sécurité AI

---

## 6. Authentification et sessions

### Sessions
- Backend préféré : Redis (prefix `sess_`, TTL basé sur `session.gc_maxlifetime`)
- Fallback : Database → File
- Ne jamais stocker de données sensibles en clair dans la session

### 2FA (Two-Factor Authentication email)
- Activé sur l'interface admin
- Code : 4 à 8 chiffres, expiry 5 minutes
- Résistant aux attaques par rejeu (code à usage unique)

### TOTP (admin)
- Configuration : voir wiki `How-to-set-Double-authentification-for-Catalog-and-Administration-Login-by-TOTP`

---

## 7. Chiffrement et GDPR

### Chiffrement des données sensibles
- Algorithme : AES-256 pour les données personnelles critiques
- Ne pas implémenter de chiffrement custom — utiliser les helpers existants du core

### Conformité GDPR
- Export de données personnelles : fonctionnalité native à ne pas contourner
- Suppression de données : cascade gérée par les scripts existants
- Audit log : ne pas désactiver les tables d'audit

---

## 8. Configuration Apache (.htaccess)

Le fichier `.htaccess` est une couche de sécurité critique.

Règles :
- Ne pas affaiblir les directives de sécurité existantes
- Ne pas exposer les répertoires `Core/`, `Core/ClicShopping/Work/`, `install/` via HTTP
- Maintenir les headers de sécurité (`X-Frame-Options`, `X-Content-Type-Options`, etc.)
- Les règles de réécriture SEO ne doivent pas créer de failles de traversée de chemin

---

## 9. Ce qu'il ne faut jamais exposer

```
✗ Fichiers de configuration (config.php, .env, composer.json via HTTP)
✗ Répertoire Core/ClicShopping/Work/ (cache et fichiers temporaires)
✗ Répertoire install/ après installation
✗ Credentials, clés API, mots de passe
✗ Chemins internes du serveur dans les messages d'erreur
✗ Stack traces PHP en production
✗ Données personnelles non anonymisées dans les logs
```

---

## 10. Checklist sécurité avant commit

```
[ ] Inputs validés côté serveur
[ ] Outputs échappés dans tous les templates
[ ] Aucun credential ou clé API dans le code
[ ] Endpoints admin protégés par vérification de session
[ ] Guardrails AI non contournés
[ ] Aucune table d'audit ou de rate limiting supprimée
[ ] .htaccess non affaibli
[ ] Aucune donnée personnelle en clair dans les logs
```

---

## 11. Références

- Architecture framework : `ARCHITECTURE.md`
- Sécurité AI guardrails : `AI_SYSTEM.md` §7
- Wiki sécurité : https://github.com/ClicShopping/ClicShopping_V3/wiki/Secure-ClicShopping
- DeepWiki sécurité : https://deepwiki.com/ClicShopping/ClicShopping/7-security-architecture
