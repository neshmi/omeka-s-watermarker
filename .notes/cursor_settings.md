# Cursor AI Settings

## Preferred Solutions
- **Prioritize**: Maintainability and readability over premature optimization
- **Code style**: Object-oriented following Omeka S patterns
- **Error handling approach**: Use Omeka S logging system for non-critical errors, throw exceptions for critical errors that should halt execution
- **Documentation**: Comprehensive PHPDoc blocks for all public methods
- **Testing**: Unit tests for core functionality, particularly image processing

## AI Assistance Level
- **Code completion**: Moderately aggressive for boilerplate, conservative for business logic
- **Documentation generation**: Detailed for public interfaces, moderate for internal classes
- **Test generation**: On request, focusing on edge cases
- **Refactoring suggestions**: Welcomed, especially for performance improvements
- **Code reviews**: Detailed feedback on style and potential issues

## Project Context
- **Omeka S**: A web publication system for cultural institutions, libraries, and archives
- **Digital collections**: Focus on managing and displaying media (especially images)
- **Watermarking purpose**: Copyright protection and branding for publicly accessible images
- **User expertise**: Mixed technical ability among administrators
- **Performance considerations**: Many cultural institutions have limited server resources

## Domain-Specific Knowledge
- **Derivative images**: Omeka S creates multiple sizes of uploaded images (thumbnail, square thumbnail, medium, large)
- **Batch processing**: Collections can contain thousands of images
- **GLAM sector**: Galleries, Libraries, Archives, and Museums have specific needs for attribution and watermarking
- **Copyright concerns**: Images may have complex licensing requirements
- **Responsive design**: Watermarks should work across various display sizes

## Implementation Preferences
- **Configuration over code**: Make features configurable rather than hardcoded
- **Progressive enhancement**: Core features should work without JavaScript
- **Accessibility**: Admin interface should be accessible
- **Internationalization**: All user-facing strings should use translation functions
- **Security**: Validate and sanitize all inputs, especially file uploads

## Specific Patterns
- Use Omeka S entity manager for database operations
- Follow Omeka S controller and view architecture
- Leverage Laminas form components for configuration
- Use event listeners for integration points
- Implement service classes for core functionality
