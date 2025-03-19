<?php
/**
 * Watermarker module configuration form
 */

namespace Watermarker\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

class ConfigForm extends Form
{
    /**
     * Initialize the configuration form
     */
    public function init()
    {
        // Global Watermarking Settings
        $this->add([
            'name' => 'watermark_enabled',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable watermarking',
                'info' => 'Check to enable watermarking for new and updated media.',
            ],
            'attributes' => [
                'id' => 'watermark-enabled',
            ],
        ]);

        $this->add([
            'name' => 'apply_on_upload',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Apply on manual upload',
                'info' => 'Apply watermarks to media files uploaded through the admin interface.',
            ],
            'attributes' => [
                'id' => 'apply-on-upload',
            ],
        ]);

        $this->add([
            'name' => 'apply_on_import',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Apply on import',
                'info' => 'Apply watermarks to media files imported from URLs or other sources.',
            ],
            'attributes' => [
                'id' => 'apply-on-import',
            ],
        ]);

        // Input filter for validation
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'watermark_enabled',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'apply_on_upload',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'apply_on_import',
            'required' => false,
        ]);
    }
}