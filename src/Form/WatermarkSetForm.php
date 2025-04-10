<?php
/**
 * Watermarker set form
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
                'label' => 'Name',
                'info' => 'Name of the watermark set',
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
                'label' => 'Default Set',
                'info' => 'Make this the default watermark set',
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
                'info' => 'Enable or disable this watermark set',
            ],
            'attributes' => [
                'id' => 'watermark-set-enabled',
                'value' => '1',
            ],
        ]);

        // Input filter for validation
        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'name',
            'required' => true,
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