<?php
/**
 * Watermarker configuration form
 * 
 * This form is used for compatibility with the Omeka form system, but we're now
 * using a raw HTML form for better reliability. This is a simplified version
 * that only defines the form structure in a way that's compatible with the controller.
 */

namespace Watermarker\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

class WatermarkForm extends Form
{
    /**
     * Initialize the watermark form
     */
    public function init()
    {
        // The form is constructed in the factory
        // This empty init method is kept for compatibility
    }
}