# Conventions

## Coding Standards
The Watermarker module follows PSR-2 coding standards and Omeka S conventions:

### Naming Conventions
- **Classes**: PascalCase (e.g., `WatermarkManager`, `ResourceSetting`)
- **Methods**: camelCase (e.g., `applyWatermark()`, `getConfigValue()`)
- **Variables**: camelCase (e.g., `$watermarkSet`, `$resourceType`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `DEFAULT_OPACITY`, `WATERMARK_POSITION_CENTER`)
- **Database Tables**: snake_case with module prefix (e.g., `watermarker_sets`, `watermarker_images`)
- **Form Elements**: kebab-case for IDs and classes (e.g., `watermark-opacity`, `position-selector`)

### File Organization
- `/src/Controller/` - All controller classes
- `/src/Form/` - Form classes
- `/src/Entity/` - Entity classes for database models
- `/src/Service/` - Service classes
- `/src/Api/` - API adapters and representations
- `/view/watermarker/` - Templates organized by controller
- `/asset/` - JavaScript, CSS, and images
- `/config/` - Module configuration

### Documentation
- PHPDoc blocks for all classes and methods
- Clear parameter and return type declarations
- Inline comments for complex logic

## Database Schema Conventions
- All tables prefixed with `watermarker_`
- Primary keys named `id`
- Foreign keys named `entity_id` (e.g., `watermark_set_id`)
- Timestamps named `created` and `modified`
- Boolean fields prefixed with `is_` (e.g., `is_active`)

## UI Conventions
- Follow Omeka S admin interface patterns
- Use existing Omeka S form elements when possible
- Consistent button labeling (Save, Cancel, Delete)
- Form validation with clear error messages
- Confirmation dialogs for destructive actions

## JavaScript Conventions
- Use ES6 syntax
- Namespace all functions under `Watermarker`
- Use event delegation for dynamic elements
- Initialize with document ready handlers
- Comment complex operations

## CSS Conventions
- Follow BEM (Block, Element, Modifier) methodology
- Prefix classes with `watermarker-` to avoid conflicts
- Use SASS for preprocessing
- Organize by component
