<?php
/**
 * Watermarker watermark set form
 */

namespace Watermarker\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

class WatermarkSetForm extends Form
{
    /**
     * Initialize the watermark set form
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
                'label' => 'Watermark Set Name',
                'info' => 'Name for this watermark set.',
            ],
            'attributes' => [
                'id' => 'watermark-set-name',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'is_default',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Default Watermark Set',
                'info' => 'Check to make this the default watermark set. Only one set can be default.',
            ],
            'attributes' => [
                'id' => 'watermark-set-default',
            ],
        ]);

        $this->add([
            'name' => 'enabled',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enabled',
                'info' => 'Check to enable this watermark set.',
            ],
            'attributes' => [
                'id' => 'watermark-set-enabled',
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
            'name' => 'is_default',
            'required' => false,
        ]);

        $inputFilter->add([
            'name' => 'enabled',
            'required' => false,
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