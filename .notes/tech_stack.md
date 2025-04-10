# Tech Stack

## Core Technologies
- **PHP**: Version 7.4+ (to maintain compatibility with Omeka S)
- **MySQL/MariaDB**: For database storage
- **Laminas Framework**: Used by Omeka S for the MVC structure
- **Doctrine ORM**: For database interaction and entity management
- **JavaScript (ES6)**: For frontend interactivity
- **CSS/SASS**: For styling the admin interface

## Omeka S Integration
- **Omeka S Module System**: Building on the module architecture
- **Omeka S API**: For interacting with resources and media
- **Omeka S Events**: For hooking into the resource lifecycle
- **Omeka S Permissions**: For access control integration
- **Omeka S Form API**: For building configuration forms

## Image Processing
- **GD Library**: Primary option for PHP image manipulation
- **ImageMagick** (via IMagick extension): Alternative for more advanced image processing
- **SVG Support**: For vector watermarks (optional)

## Development Tools
- **Composer**: For dependency management
- **PHPUnit**: For unit and integration testing
- **SASS Compiler**: For preprocessing CSS
- **Webpack**: For asset bundling
- **ESLint**: For JavaScript code quality
- **PHP_CodeSniffer**: For PHP code style checking

## Frontend Libraries
- **jQuery**: Used by Omeka S admin interface
- **jQueryUI**: For advanced UI components
- **Select2**: For enhanced select inputs
- **Spectrum**: For color picking in watermark configuration

## Version Control
- **Git**: For source code management
- **GitHub**: For repository hosting and issue tracking

## Additional Tools
- **Intervention Image**: PHP library for easier image manipulation
- **SVG Sanitizer**: For security when uploading SVG watermarks
- **Transifex**: For translation management (optional)

## Server Requirements
- **Apache** or **Nginx**: Web server
- **PHP Extensions**: GD or IMagick, PDO, XML, JSON, ZIP
- **File Permissions**: Write access to asset directories
- **Memory Limit**: Minimum 128MB PHP memory limit (256MB recommended)

## Development Environment
- **Docker**: For consistent development environment
- **Omeka S Development VM**: For testing with realistic data
- **Xdebug**: For PHP debugging
