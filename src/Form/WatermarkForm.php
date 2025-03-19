<?php
/**
 * Watermarker configuration form
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
            'name' => 'name',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Watermark Name',
                'info' => 'Name for this watermark configuration.',
            ],
            'attributes' => [
                'id' => 'watermark-name',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'media_id',
            'type' => Asset::class,
            'options' => [
                'label' => 'Watermark Image',
                'info' => 'Select an image to use as watermark. For best results, use a PNG image with transparency.',
            ],
            'attributes' => [
                'id' => 'watermark-media',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'orientation',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Apply To',
                'info' => 'Select which image orientations this watermark should be applied to.',
                'value_options' => [
                    'all' => 'All images',
                    'landscape' => 'Landscape images only',
                    'portrait' => 'Portrait images only',
                ],
            ],
            'attributes' => [
                'id' => 'watermark-orientation',
                'required' => true,
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

        $this->add([
            'name' => 'enabled',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enabled',
                'info' => 'Check to enable this watermark configuration.',
            ],
            'attributes' => [
                'id' => 'watermark-enabled',
            ],
        ]);

        // Input filter for validation
        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'name',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'StringLength',
                    'options' => [
                        'min' => 1,
                        'max' => 255,
                    ],
                ],
            ],
        ]);

        $inputFilter->add([
            'name' => 'media_id',
            'required' => true,
        ]);

        $inputFilter->add([
            'name' => 'orientation',
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

        $inputFilter->add([
            'name' => 'enabled',
            'required' => false,
        ]);
    }
}