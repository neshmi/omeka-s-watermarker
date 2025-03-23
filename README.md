# Watermarker

A module for Omeka S that adds watermarking capabilities to uploaded and imported media.

## Features

- Apply watermarks to image media items
- Create multiple watermark sets for different purposes
- Configure different watermarks for portrait, landscape, and square orientations
- Adjust watermark position and opacity
- Configure watermarks to be applied on upload or import
- Assign specific watermark sets to item sets or individual items
- Disable watermarking for specific item sets or items

## Installation

1. Download the latest release from the [Releases](https://github.com/yourusername/omeka-s-module-watermarker/releases) page
2. Unzip the module into the `modules` directory
3. Log in to your Omeka S admin panel and navigate to Modules
4. Click "Install" next to the Watermarker module

## Usage

### Global Configuration

1. Navigate to Modules > Watermarker > Configure Module
2. Choose whether to enable watermarking globally
3. Choose whether to apply watermarks on upload and/or import

### Managing Watermark Sets

1. Navigate to Modules > Watermarker
2. Click "Add new watermark set"
3. Enter a name for the watermark set
4. Choose whether this should be the default set
5. Save the watermark set

### Managing Watermarks

1. Navigate to Modules > Watermarker and select a watermark set
2. Click "Add new watermark"
3. Choose the image type this watermark applies to (landscape, portrait, square, or all)
4. Upload an image to use as a watermark (PNG with transparency works best)
5. Configure the position and opacity
6. Save the watermark configuration

### Assigning Watermarks to Item Sets or Items

1. Navigate to the item set or item you want to configure
2. Click on the "Watermark" tab
3. Select a watermark set to use for this resource
4. Select "No watermark" to disable watermarking for this resource
5. Select "Use default" to use the default watermark set

### Watermark Inheritance

- Items inherit the watermark set from their parent item set
- If an item belongs to multiple item sets with different watermark assignments, the settings from the first item set will be used
- Item-specific watermark settings override item set settings

## Requirements

- Omeka S version 4.0 or higher
- PHP 7.4 or higher
- GD extension for PHP with support for the image formats you want to watermark

## Supported File Types

The module can apply watermarks to the following image types:
- JPEG/JPG
- PNG
- WebP (if your PHP installation supports it)

## License

This module is licensed under the GNU General Public License v3.0.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Credits

Developed by Matthew Vincent.

## Troubleshooting

### Watermarks not appearing

1. Check that the module is enabled and configured correctly
2. Verify that the watermark image exists and is accessible
3. Check that the media being uploaded is of a supported file type
4. Check the Omeka S logs for any errors

### Image quality issues

If you notice a reduction in image quality after watermarking:
1. Adjust the opacity of the watermark
2. Use a smaller watermark image
3. Consider using different watermarks for different image sizes

## Future Development

Planned features for future releases:
- Scale watermark relative to image size
- Text-based watermarks
- Batch apply watermarks to existing media
- More positions and custom offsets
- Additional automation options