# Watermarker

A module for Omeka S that adds watermarking capabilities to uploaded and imported media.

## Features

- Apply watermarks to image media items
- Configure different watermarks for portrait and landscape orientations
- Adjust watermark position and opacity
- Configure watermarks to be applied on upload or import

## Installation

1. Download the latest release from the [Releases](https://github.com/yourusername/omeka-s-module-watermarker/releases) page
2. Unzip the module into the `modules` directory
3. Log in to your Omeka S admin panel and navigate to Modules
4. Click "Install" next to the Watermarker module

## Usage

### Configuration

1. Navigate to Modules > Watermarker > Configure Module
2. Choose whether to enable watermarking globally
3. Choose whether to apply watermarks on upload and/or import

### Managing Watermarks

1. Navigate to Modules > Watermarker
2. Click "Add new watermark"
3. Upload an image to use as a watermark (PNG with transparency works best)
4. Configure the orientation, position, and opacity
5. Save the watermark configuration

### Using Watermarks

Once configured, watermarks will be automatically applied to eligible image media when they are uploaded or imported, based on your settings.

## Requirements

- Omeka S version 3.0 or higher
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

Developed by [Your Name/Organization].

## Troubleshooting

### Watermarks not appearing

1. Check that the module is enabled and configured correctly
2. Verify that the watermark image exists and is accessible
3. Check that the media being uploaded is of a supported file type
4. Check the Omeka S logs for any errors

### Image quality issues

If you notice a reduction in image quality after watermarking:
1. Adjust the quality settings in the module configuration
2. Use a smaller watermark image
3. Reduce the opacity of the watermark

## Future Development

Planned features for future releases:
- Scale watermark relative to image size
- Text-based watermarks
- Batch apply watermarks to existing media
- More positions and custom offsets
- Watermark templates for different item sets