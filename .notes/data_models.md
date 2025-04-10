# Data Models

## Core Entities

### WatermarkSet
Represents a collection of related watermark images.

```
Table: watermarker_sets
- id (integer, primary key, auto-increment)
- name (string, 255) - Display name of the set
- description (text, nullable) - Optional description
- is_active (boolean, default true) - Whether this set is currently active
- created (datetime) - Creation timestamp
- modified (datetime) - Last modified timestamp
- owner_id (integer, foreign key to users.id) - User who created the set
```

### WatermarkImage
Represents a single watermark image with its configuration.

```
Table: watermarker_images
- id (integer, primary key, auto-increment)
- watermark_set_id (integer, foreign key to watermarker_sets.id) - The set this image belongs to
- filename (string, 255) - Original filename
- storage_id (string, 255) - Storage ID in Omeka's file system
- media_type (string, 100) - MIME type
- position (string, 50) - Position (e.g., top-left, center, bottom-right)
- opacity (decimal, 0-1) - Opacity level from transparent to opaque
- scale (decimal, 0-1) - Scale relative to the target image size
- margin_x (integer) - Horizontal margin in pixels
- margin_y (integer) - Vertical margin in pixels
- created (datetime) - Creation timestamp
- modified (datetime) - Last modified timestamp
```

### ResourceSetting
Maps watermark settings to specific resource types.

```
Table: watermarker_resource_settings
- id (integer, primary key, auto-increment)
- watermark_set_id (integer, foreign key to watermarker_sets.id) - Associated watermark set
- resource_type (string, 100) - Type of resource (item, item-set, etc.)
- size_constraint (string, 100, nullable) - Optional size constraints for application
- apply_to_originals (boolean, default false) - Whether to apply to original files
- apply_to_thumbnails (boolean, default true) - Whether to apply to thumbnails
- apply_to_square_thumbnails (boolean, default true) - Whether to apply to square thumbnails
- apply_to_medium (boolean, default true) - Whether to apply to medium displays
- apply_to_large (boolean, default true) - Whether to apply to large displays
- item_type_id (integer, nullable, foreign key to item_types.id) - Optional specific item type
- media_type (string, 100, nullable) - Optional specific media type (e.g., image/jpeg)
- created (datetime) - Creation timestamp
- modified (datetime) - Last modified timestamp
```

### GlobalSettings
Module-wide configuration settings.

```
Table: watermarker_settings
- id (string, primary key) - Setting key
- value (text) - Setting value (serialized if needed)
```

## Relationships
- A **WatermarkSet** can have multiple **WatermarkImages** (one-to-many)
- A **WatermarkSet** can be associated with multiple **ResourceSettings** (one-to-many)
- **ResourceSettings** can be applied to different resource types, optionally filtered by item type or media type

## Special Types and Enums

### Position Options
```
Enum: WatermarkPosition
- TOP_LEFT
- TOP_CENTER
- TOP_RIGHT
- MIDDLE_LEFT
- MIDDLE_CENTER
- MIDDLE_RIGHT
- BOTTOM_LEFT
- BOTTOM_CENTER
- BOTTOM_RIGHT
- TILED
- CUSTOM
```

### Resource Types
```
Enum: ResourceType
- ITEM
- ITEM_SET
- MEDIA
- ALL
```

### Media Size Types
```
Enum: MediaSize
- ORIGINAL
- THUMBNAIL
- SQUARE_THUMBNAIL
- MEDIUM
- LARGE
```

## Database Migrations
- Initial migration creates all tables
- Update migrations for schema changes
- Data migrations for configuration changes
