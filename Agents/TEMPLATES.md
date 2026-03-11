# TEMPLATES.md — ClicShopping v4.20+

> Architecture de rendu — front-office (Shop) et back-office (ClicShoppingAdmin).  
> Les deux contextes ont des philosophies fondamentalement différentes.  
> Règles agents : `AGENTS.md` — Architecture core : `ARCHITECTURE.md`

---

## 1. Principe fondamental

**Un template ne fait que rendre.** Aucune logique métier, aucun accès DB, aucune chaîne
hardcodée. Toutes les données arrivent préparées depuis le contrôleur ou le module.

Note de lecture du core actuel : certains templates de pages historiques sous
`Core/ClicShopping/Sites/*/Pages/*/templates` incluent de la logique d'orchestration.
Pour tout nouveau développement, conserver la règle "render-only".

```
✗ Logique métier ou calcul
✗ Accès direct à la base de données
✗ Chaînes visibles hardcodées
✗ Redirection, manipulation de session
✗ Appel LLM ou service AI
✓ Affichage de variables préparées
✓ Boucles d'affichage sur tableaux construits en amont
✓ Includes de sous-composants via le moteur
✓ Échappement systématique des sorties
```

---

## 2. Vue d'ensemble — séparation volontaire HTML / logique

La séparation entre les fichiers HTML et la logique applicative est **architecturalement
intentionnelle** pour permettre à l'utilisateur de créer ou modifier des thèmes librement,
sans toucher au code PHP métier.

```
Core/ClicShopping/Sites/Shop/        ← controllers et templates de pages
                                      (Pages/*/templates)

sources/template/{Theme}/   ← fichiers HTML du thème choisi par l'utilisateur
                              totalement séparé de la logique

Core/ClicShopping/Apps/{Vendor}/{App}/  ← une App peut exposer des pages Shop
                                          via Sites/Shop/ (déclaré en JSON)
```

**Ne jamais déplacer des templates de thème (`sources/template/`) dans `Core/ClicShopping/Sites/Shop/`.**  
**Ne jamais mettre de logique applicative dans `sources/template/`.**

---

## 3. Front-office (Shop) — architecture détaillée

### 3.1 Rôle de `sources/template/` — Default et thèmes custom

`Default` est le **thème de base livré avec le core**. Tout autre thème **surcharge
`Default` fichier par fichier** : si un fichier n'existe pas dans le thème custom,
le système utilise automatiquement le fichier correspondant de `Default`.
`Default` devient donc le **fallback** dès qu'un thème custom est actif.

Le choix du thème actif est contrôlé par l'utilisateur via l'interface admin.

```
sources/template/
├── Default/                       ← thème de base — fallback universel
│   ├── header.php                 # Header global ← racine de Default/
│   ├── footer.php                 # Footer global ← racine de Default/
│   ├── css/                       # CSS organisé par langue
│   │   └── {lang}/               # Répertoire par langue (ex: en/, fr/)
│   │       ├── compressed_css.php # Point d'entrée — lit les sous-répertoires et compresse
│   │       ├── general/           # Styles globaux : stylesheet, bootstrap, appels généraux
│   │       └── modules_{module_name}/ # CSS spécifique à un module — nommé selon le module
│   │           └── {module_name}.css  # ex: pi_products_info_also_purchased.css
│   ├── javascript/                # JavaScript du thème
│   │   └── *.js
│   ├── files/                     # Gabarit de la page (structure HTML globale)
│   │   └── *.php
│   ├── modules/                   # Templates HTML des modules
│   │   ├── {module_name}/
│   │   │   ├── content/           # HTML du module (template fixe)
│   │   │   │   └── {module_name}.php
│   │   │   └── template_html/    # HTML listing — uniquement si le module affiche une liste
│   │   │       └── {module_name}.php
│   │   └── ...
│   └── images/
│
└── {CustomTheme}/                 ← thème custom choisi par l'utilisateur
    ├── header.php                 # Si présent, surcharge Default/header.php
    ├── footer.php                 # Si présent, surcharge Default/footer.php
    ├── css/                       # Surcharge le CSS de Default par langue
    ├── javascript/                # Surcharge le JS de Default
    ├── files/                     # Surcharge le gabarit de Default
    ├── modules/                   # Surcharge les modules de Default
    │   └── ...                    # Tout fichier absent → fallback sur Default/
    └── images/
```

**Règle de résolution :**
```
1. sources/template/{CustomTheme}/{fichier}   ← priorité — thème custom actif
2. sources/template/Default/{fichier}         ← fallback automatique si absent du custom
```

Un thème custom ne doit contenir que les fichiers qu'il surcharge réellement.
Ne pas copier l'intégralité de `Default/` dans un thème custom.

### 3.2 Distinction critique : template fixe vs listing

Chaque module dans `modules/` a son propre sous-répertoire, avec deux sous-dossiers
selon le type de rendu — ne pas les confondre.

| Type | Sous-répertoire | Usage |
|---|---|---|
| **Header / Footer** | `Default/header.php` et `Default/footer.php` | À la **racine de `Default/`** — hors `modules/` |
| **Template fixe** | `modules/{module_name}/content/` | HTML du module — rendu d'un bloc unique |
| **Template listing** | `modules/{module_name}/template_html/` | HTML listing — uniquement quand le module affiche une liste |

Le sous-répertoire `template_html/` n'est présent que si le module gère un listing.
Un module simple n'a que `content/`. Ne pas créer `template_html/` par défaut.

Un agent qui place le HTML de listing dans `content/` au lieu de `template_html/` cassera le rendu.
Un agent qui place header.php dans `modules/` au lieu de la racine de `Default/` cassera le rendu.

### 3.3 Rôle des modules dans `Apps/`

Les modules dans `Core/ClicShopping/Apps/{Vendor}/{AppName}/Module/` définissent :
- Les **settings** et la configuration du module
- Le script d'**installation / désinstallation**
- Les **fonctions PHP** qui préparent les données
- L'**appel au template HTML** correspondant dans `sources/template/{Theme}/modules/`

Le module PHP **appelle** le template HTML — il ne le contient pas.

```
Core/ClicShopping/Apps/{Vendor}/{AppName}/Module/{Type}/{ModuleName}/
├── {ModuleName}.php    ← settings, install, fonctions, appel du template HTML
└── languages/          ← constantes de langue utilisées par le module
```

Le fichier HTML rendu est résolu dans cet ordre :
1. `sources/template/{CustomTheme}/modules/{module_name}/content/{module_name}.php` si présent
2. `sources/template/Default/modules/{module_name}/content/{module_name}.php` sinon (fallback)

Pour un listing, le même ordre s'applique avec `template_html/` à la place de `content/`.

### 3.4 Templates HTML d'une App via Sites/Shop/ et JSON

Une App peut aussi exposer ses propres templates HTML directement depuis son répertoire,
sans passer par `sources/template/`. Ce cas est déclaré en JSON dans l'App.

```
Core/ClicShopping/Apps/{Vendor}/{AppName}/
├── Sites/Shop/         ← pages et templates propres à l'App
│   └── Pages/{PageName}/templates/
└── clicshopping.json   ← déclare les pages/templates exposés par l'App
```

Règle : si une App expose ses templates via `Sites/Shop/` + `clicshopping.json`, ces templates
suivent les mêmes règles que tous les autres templates HTML (pas de logique métier,
pas d'accès DB, échappement obligatoire).

### 3.5 Controllers et classes — où ils sont

```
Core/ClicShopping/Sites/Shop/
├── Pages/              ← controllers de page (implémentent PagesInterface)
│   └── {PageName}/
│       ├── {PageName}.php
│       └── Actions/
└── Classes/            ← classes métier du Shop
```

Ces fichiers ne contiennent **aucun HTML**. Ils préparent les données et les passent
au moteur de template.

### 3.6 Flux de rendu complet (Shop)

```
Requête HTTP
  → Core/ClicShopping/Sites/Shop/Pages/{ModuleName}/{ModuleName}.php   (controller — logique, prépare données)
  → Core/ClicShopping/Sites/Shop/Pages/{ModuleName}/Actions/           (si action POST)
  → Core/ClicShopping/Apps/*/Module/{Type}/{Module}.php                (module — settings, fonctions, appel template)
  → sources/template/{Custom}/modules/{mod}/content/{mod}.php   ← si présent
    ou sources/template/Default/modules/{mod}/content/{mod}.php  ← fallback
  → sources/template/{Custom ou Default}/files/      (gabarit de page)
  → sources/template/{Custom ou Default}/header.php  (header global — racine Default/)
  → sources/template/{Custom ou Default}/footer.php  (footer global — racine Default/)
```

### 3.7 SEO

- Balises `<title>` et `<meta>` — injectées via des **modules dédiés** (ex: module header tags)
  qui poussent leur code dans le header ou le footer via le système de modules
  — jamais hardcodées directement dans les fichiers de template
- `<h1>` unique par page, hiérarchie `h1 > h2 > h3` respectée
- Attribut `alt` sur toutes les images — valeur issue des données préparées
- URLs via helpers de routing existants — jamais hardcodées
- Cache statique actif sur les pages catalogue — invalider `Core/ClicShopping/Work/` après modification

---

## 4. Back-office (ClicShoppingAdmin) — architecture distincte

### 4.1 Principe

L'admin est **entièrement géré par les Apps**. Il n'y a pas de thème global
interchangeable, pas de `sources/template/` pour l'admin.

La logique et les templates admin sont dans `Core/ClicShopping/Sites/ClicShoppingAdmin/`.

### 4.2 Structure de `Sites/`

Les deux contextes ont leur propre arborescence sous `Sites/` :

```
Core/ClicShopping/Sites/
├── ClicShoppingAdmin/
│   └── Pages/
│       └── {PageName}/               ← ex: Home
│           ├── Actions/              # Actions du contrôleur (POST, traitements)
│           ├── templates/            # Templates HTML de la page admin
│           └── {PageName}.php        # Contrôleur principal de la page
│
└── Shop/
    └── Pages/
        └── {ModuleName}/             ← nom du module/page
            ├── Actions/              # Actions du contrôleur
            ├── templates/            # Templates HTML de la page Shop
            └── {ModuleName}.php      # Contrôleur principal
```

**Les templates front-office principaux restent dans `sources/template/Default/` ou le thème custom.**  
`Core/ClicShopping/Sites/*/Pages/*/templates` contient des templates de pages ; viser une logique minimale côté template.

### 4.3 Philosophie admin

- Orienté productivité : tableaux, formulaires CRUD, confirmations, retours d'état
- Données reçues déjà validées et préparées par le contrôleur
- Aucune décision métier dans le template
- Vérification de session dans le **contrôleur**, jamais dans le template
- Token CSRF obligatoire sur chaque formulaire via helper existant
- Pas de cache statique — données fraîches à chaque rendu

---

## 5. Langues — règles communes

| Couche | Chemin | Portée |
|---|---|---|
| App / Module | `Core/ClicShopping/Apps/*/languages/{lang}/` | Textes propres à l'App — priorité |
| Global / Thème | `sources/languages/{lang}/` | Textes transversaux — fallback |

- Aucune chaîne visible hardcodée — toujours via `getDef()`
- Compatibilité minimum : EN + FR
- Les clés suivent le format existant dans la portée cible

```php
// ✓ Correct — lecture via getDef()
echo $CLICSHOPPING->getDef('text_add_to_cart');

// ✗ Interdit — chaîne hardcodée
echo 'Ajouter au panier';
```

---

## 6. Règles communes aux deux contextes

### Formulaires — CSRF

Le token CSRF est obligatoire sur tout formulaire, **Shop (catalog) et Admin**.
Il est géré via le paramètre `['tokenize' => true]` dans `HTML::form()` :

```php
$form = HTML::form('cart_quantity', CLICSHOPPING::link(null, 'Cart&Add'), 'post', 'class="justify-content-center"', ['tokenize' => true]) . "\n";
```

Ne jamais construire un formulaire `<form>` HTML brut sans passer par `HTML::form()` avec `tokenize`.

```php
echo HTML::outputProtected($variable);          // chaîne HTML
echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); // URL ou attribut
```

Ne jamais afficher une variable sans échappement, même en contexte admin.

### Ce qu'un template ne doit jamais contenir

```php
// ✗ Accès DB
Registry::get('Db')->query('SELECT ...');

// ✗ Logique métier
if ($price * $tax > $threshold) { ... }

// ✗ Chaîne hardcodée
echo 'Add to cart';

// ✗ Session ou redirection
header('Location: ...');
$_SESSION['key'] = 'value';

// ✗ Appel LLM
$ai->generate('...');
```

---

## 7. Checklist avant de soumettre un template

```
[ ] Aucune logique métier ni accès DB dans le template
[ ] Toutes les sorties échappées (HTML::outputProtected)
[ ] Aucune chaîne visible hardcodée — toujours via $CLICSHOPPING->getDef('clé')
[ ] URLs via helpers de routing, pas hardcodées
[ ] Emplacement correct du template :
      header/footer  → racine de Default/ (pas dans modules/)
      module fixe    → modules/{module_name}/content/
      module listing → modules/{module_name}/template_html/ (si le module gère une liste)
[ ] template_html/ créé uniquement si le module gère effectivement un listing
[ ] Thème custom : ne contient que les fichiers surchargés — pas de copie de Default/
[ ] Nouveau template destiné à tous les thèmes → le créer dans Default/
[ ] Balises SEO (title, meta) → via modules dédiés qui injectent dans header/footer
[ ] Front : h1 unique par page, alt sur toutes les images
[ ] Front : invalidation cache Core/ClicShopping/Work/ prévue si template catalogue modifié
[ ] Token CSRF sur chaque formulaire Shop et Admin — HTML::form() avec ['tokenize' => true]
[ ] Admin : vérification session dans le contrôleur (Core/ClicShopping/Sites/ClicShoppingAdmin/Pages/), pas dans le template
[ ] App avec Sites/Shop/ + clicshopping.json : templates déclarés correctement dans le JSON
```

---

## 8. Références

- Architecture core, controllers, routing : `ARCHITECTURE.md`
- Sécurité et échappement : `SECURITY.md`
- Wiki affichage template : https://github.com/ClicShopping/ClicShopping/wiki/How-to-display-information-inside-a-template
- DeepWiki templates : https://deepwiki.com/ClicShopping/ClicShopping/8-template-and-module-system
