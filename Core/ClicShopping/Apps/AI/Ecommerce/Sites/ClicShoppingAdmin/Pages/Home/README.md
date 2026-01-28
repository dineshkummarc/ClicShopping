# Ecommerce Domain Admin Pages

This directory contains the admin interface for the Ecommerce domain application.

## Structure

```
Home/
├── Home.php                    # Main page class
├── Actions/                    # Action classes
│   ├── Dashboard.php
│   ├── Overview.php
│   ├── Configuration.php
│   └── Help.php
└── templates/                  # Template files
    ├── main.php
    ├── dashboard.php
    ├── overview.php
    ├── configuration.php
    └── help.php
```

## How It Works

### Page Class (Home.php)

The main page class extends `PagesAbstract` and:
- Initializes the Ecommerce app
- Registers the app in the Registry
- Loads main language definitions

### Action Classes

Each action class extends `PagesActionsAbstract` and:
- Sets the template file to display
- Loads action-specific language definitions
- Passes data to the template

**Available Actions:**
- `Dashboard` - Displays dashboard metrics
- `Overview` - Shows domain overview and capabilities
- `Configuration` - Displays configuration details
- `Help` - Shows FAQ and support information

### Templates

Templates are pure PHP/HTML files that:
- Use `Registry::get()` to access the app and template utilities
- Use `$app->getDef()` to get language strings
- Use Bootstrap 5 components for UI
- Display data passed from actions

## Language Support

Language files are organized by language and site:

```
languages/
├── english/Sites/ClicShoppingAdmin/
│   ├── main.txt
│   ├── dashboard.txt
│   ├── overview.txt
│   ├── configuration.txt
│   └── help.txt
└── french/Sites/ClicShoppingAdmin/
    ├── main.txt
    ├── dashboard.txt
    ├── overview.txt
    ├── configuration.txt
    └── help.txt
```

Each file contains key=value pairs for language strings used in the corresponding template.

## Adding a New Page

To add a new page to the admin interface:

1. **Create an Action Class**
   ```php
   // Actions/MyPage.php
   namespace ClicShopping\Apps\AI\Ecommerce\Sites\ClicShoppingAdmin\Pages\Home\Actions;
   
   use ClicShopping\OM\Registry;
   
   class MyPage extends \ClicShopping\OM\Domains\PagesActionsAbstract
   {
     public function execute()
     {
       $CLICSHOPPING_Ecommerce = Registry::get('Ecommerce');
       $this->page->setFile('mypage.php');
       $this->page->data['action'] = 'MyPage';
       $CLICSHOPPING_Ecommerce->loadDefinitions('Sites/ClicShoppingAdmin/mypage');
     }
   }
   ```

2. **Create a Template**
   ```php
   // templates/mypage.php
   <?php
   use ClicShopping\OM\Registry;
   
   $CLICSHOPPING_Ecommerce = Registry::get('Ecommerce');
   $CLICSHOPPING_Template = Registry::get('TemplateAdmin');
   ?>
   
   <div class="contentBody">
     <!-- Your content here -->
   </div>
   ```

3. **Add Language Strings**
   ```
   # languages/english/Sites/ClicShoppingAdmin/mypage.txt
   heading_mypage = My Page Title
   section_content = Content Section
   ```

4. **Add Navigation Button**
   Update `main.php` to add a button for your new page:
   ```php
   echo HTML::button($CLICSHOPPING_Ecommerce->getDef('button_mypage'), 
                     null, 
                     $CLICSHOPPING_Ecommerce->link('MyPage'), 
                     'primary');
   ```

## UI Components

The templates use Bootstrap 5 components:

- **Cards** - For metric display and content sections
- **Tabs** - For navigation between sections
- **Accordion** - For FAQ and collapsible content
- **Alerts** - For informational messages
- **Tables** - For data display
- **Buttons** - For actions and navigation
- **Badges** - For status indicators

## Language Strings

Language strings are accessed using:

```php
$CLICSHOPPING_Ecommerce->getDef('key_name')
```

The key name should match the key in the language file.

## Navigation

Navigation between pages is handled using:

```php
$CLICSHOPPING_Ecommerce->link('ActionName')
```

This generates the appropriate URL for the action.

## Best Practices

1. **Keep templates simple** - Put complex logic in action classes
2. **Use language strings** - Never hardcode text in templates
3. **Follow Bootstrap patterns** - Use consistent UI components
4. **Organize language files** - One file per action/template
5. **Add documentation** - Comment complex sections
6. **Test multilingual** - Verify both English and French

## Related Files

- `Core/ClicShopping/Apps/AI/Ecommerce/Ecommerce.php` - Main app class
- `Core/ClicShopping/Apps/AI/Ecommerce/languages/` - Language files
- `Core/ClicShopping/OM/Domains/PagesAbstract.php` - Base page class
- `Core/ClicShopping/OM/Domains/PagesActionsAbstract.php` - Base action class

## Support

For questions or issues, refer to:
- Architecture documentation
- ChatGpt app implementation (similar pattern)
- ClicShopping framework documentation
