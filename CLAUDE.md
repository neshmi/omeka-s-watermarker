# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build/Test Commands
- Install: No specific build command, module should be installed via Omeka S admin interface
- Run tests: No automated tests available
- PHP lint: `php -l file.php` to check syntax of a specific file
- Coding standards: Follow PSR-1/PSR-2 coding standards

## Code Style Guidelines
- **PHP**: Use strict types when possible with `declare(strict_types=1)`
- **Naming**: 
  - Classes: PascalCase (e.g., `WatermarkService`)
  - Methods/Functions: camelCase (e.g., `getConfig()`)
  - Variables: camelCase (e.g., `$watermarkSet`)
- **Namespaces**: Organized by component type (`Entity`, `Controller`, `Service`, etc.)
- **Documentation**: DocBlocks for classes, methods and properties
- **Error handling**: Use try/catch blocks with appropriate logging
- **Indentation**: 4 spaces
- **Database**: Use prepared statements for all database operations
- **Framework**: This is an Omeka S module following Laminas/Zend Framework patterns