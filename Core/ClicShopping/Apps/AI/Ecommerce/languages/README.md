# Ecommerce Domain - Language Files

## Structure

Ces fichiers de langue sont des **copies** des prompts généraux de `ClicShoppingAdmin/Core/languages/`.

### Objectif

Permettre la **fusion des prompts** :
- **Prompts généraux** : `ClicShoppingAdmin/Core/languages/` (base commune)
- **Prompts Ecommerce** : `Core/ClicShopping/Apps/AI/Ecommerce/languages/` (customisations domaine)

### Fichiers présents

#### English
- `rag_analytics_agent.txt`
- `rag_classification.txt`
- `rag_hybrid_agent.txt`
- `rag_prompt_system.txt`
- `rag_semantic_agent.txt`
- `rag_websearch_agent.txt`

#### French
- `rag_analytics_agent.txt`
- `rag_classification.txt`
- `rag_hybrid_agent.txt`
- `rag_prompt_system.txt`
- `rag_semantic_agent.txt`
- `rag_websearch_agent.txt`

## Utilisation

### Phase 1 : Simulation (Tâches 5.4.1-5.4.5)
- Fichiers identiques aux prompts généraux
- Test de la fusion des prompts
- Validation de l'approche

### Phase 2 : Customisation (Après validation)
- Ajouter des règles spécifiques e-commerce
- Ajouter des exemples spécifiques e-commerce
- Garder la compatibilité avec les prompts généraux

## Fusion des prompts

Deux approches testées :

### Approche A : Général + Ecommerce
```php
$prompt = $general . ' ' . $ecommerce;
```
- Règles générales d'abord
- Customisations e-commerce ensuite

### Approche B : Ecommerce + Général
```php
$prompt = $ecommerce . ' ' . $general;
```
- Customisations e-commerce d'abord
- Règles générales ensuite

## Maintenance

### Synchronisation
Quand les prompts généraux changent :
1. Copier les nouveaux prompts depuis `ClicShoppingAdmin/Core/languages/`
2. Réappliquer les customisations e-commerce
3. Tester la cohérence des requêtes

### Vérification
```bash
# Vérifier que les deux langues ont les mêmes fichiers
diff <(ls -1 Core/ClicShopping/Apps/AI/Ecommerce/languages/english/*.txt | xargs -n1 basename | sort) \
     <(ls -1 Core/ClicShopping/Apps/AI/Ecommerce/languages/french/*.txt | xargs -n1 basename | sort)
```

## Pour les autres domaines (HR, Finance, etc.)

Suivre le même pattern :
```
Core/ClicShopping/Apps/AI/HR/languages/
├── english/
│   ├── rag_analytics_agent.txt
│   ├── rag_semantic_agent.txt
│   └── ...
└── french/
    ├── rag_analytics_agent.txt
    ├── rag_semantic_agent.txt
    └── ...
```
