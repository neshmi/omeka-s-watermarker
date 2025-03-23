<?php
/**
 * Watermarker assignment form
 */

namespace Watermarker\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

class WatermarkAssignmentForm extends Form
{
    /**
     * @var array
     */
    protected $watermarkSets = [];

    /**
     * Initialize the watermark assignment form
     */
    public function init()
    {
        // Add the hidden resource ID
        $this->add([
            'name' => 'resource_id',
            'type' => Element\Hidden::class,
        ]);

        // Add the hidden resource type
        $this->add([
            'name' => 'resource_type',
            'type' => Element\Hidden::class,
        ]);

        // Create options array for watermark sets
        $valueOptions = [
            '' => '[Use default watermark settings]',
            'none' => '[No watermark]',
        ];

        // Add watermark sets to options
        foreach ($this->watermarkSets as $set) {
            $valueOptions[$set['id']] = $set['name'] . ($set['is_default'] ? ' (Default)' : '');
        }

        // Add the watermark set select
        $this->add([
            'name' => 'watermark_set_id',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Watermark Set',
                'info' => 'Select which watermark set to use for this resource. Select "No watermark" to disable watermarking for this resource.',
                'value_options' => $valueOptions,
            ],
            'attributes' => [
                'id' => 'watermark-set-id',
                'class' => 'chosen-select',
            ],
        ]);

        // Input filter
        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'resource_id',
            'required' => true,
        ]);

        $inputFilter->add([
            'name' => 'resource_type',
            'required' => true,
        ]);

        $inputFilter->add([
            'name' => 'watermark_set_id',
            'required' => false,
        ]);
    }

    /**
     * Set available watermark sets
     *
     * @param array $watermarkSets
     * @return self
     */
    public function setWatermarkSets(array $watermarkSets)
    {
        $this->watermarkSets = $watermarkSets;
        return $this;
    }
}