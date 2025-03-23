<?php
/**
 * Watermarker watermark form
 */

namespace Watermarker\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;
use Omeka\Form\Element\Asset;

class WatermarkForm extends Form
{
    /**
     * Initialize the watermark form
     */
    public function init()
    {
        $this->add([
            'name' => 'o:id',
            'type' => Element\Hidden::class,
        ]);

        $this->add([
            'name' => 'set_id',
            'type' => Element\Hidden::class,
        ]);

        $this->add([
            'name' => 'type',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Watermark Type',
                'info' => 'Select which image type this watermark should be applied to.',
                'value_options' => [
                    'all' => 'All images',
                    'landscape' => 'Landscape images only',
                    'portrait' => 'Portrait images only',
                    'square' => 'Square images only',
                ],
            ],
            'attributes' => [
                'id' => 'watermark-type',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'media_id',
            'type' => Asset::class,
            'options' => [
                'label' => 'Watermark Image',
                'info' => 'Select an image to use as watermark. For best results, use a PNG image with transparency.',
                'empty_option' => '[No watermark selected - please choose an image]',
            ],
            'attributes' => [
                'id' => 'watermark-media',
                'required' => true,
                'class' => 'chosen-select',
            ],
        ]);

        $this->add([
            'name' => 'position',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Position',
                'info' => 'Select where the watermark should be positioned on the image.',
                'value_options' => [
                    'top-left' => 'Top Left',
                    'top-right' => 'Top Right',
                    'bottom-left' => 'Bottom Left',
                    'bottom-right' => 'Bottom Right',
                    'center' => 'Center',
                    'bottom-full' => 'Bottom Full Width',
                ],
            ],
            'attributes' => [
                'id' => 'watermark-position',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'opacity',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Opacity',
                'info' => 'Set the opacity of the watermark (0.1 - 1.0).',
            ],
            'attributes' => [
                'id' => 'watermark-opacity',
                'min' => '0.1',
                'max' => '1.0',
                'step' => '0.05',
                'value' => '0.7',
                'required' => true,
            ],
        ]);

        // Input filter for validation
        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'type',
            'required' => true,
        ]);

        $inputFilter->add([
            'name' => 'media_id',
            'required' => true,
        ]);

        $inputFilter->add([
            'name' => 'position',
            'required' => true,
        ]);

        $inputFilter->add([
            'name' => 'opacity',
            'required' => true,
            'validators' => [
                [
                    'name' => 'Between',
                    'options' => [
                        'min' => 0.1,
                        'max' => 1.0,
                        'inclusive' => true,
                    ],
                ],
            ],
        ]);
        
        // Add submit button
        $this->add([
            'name' => 'submit',
            'type' => 'submit',
            'attributes' => [
                'value' => 'Save',
                'class' => 'button',
            ],
        ]);
    }
}