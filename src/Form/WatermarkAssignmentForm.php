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
        $this->add([
            'name' => 'resource_type',
            'type' => Element\Hidden::class,
        ]);

        $this->add([
            'name' => 'resource_id',
            'type' => Element\Hidden::class,
        ]);

        $this->add([
            'name' => 'redirect_url',
            'type' => Element\Hidden::class,
        ]);

        $this->add([
            'name' => 'watermark_set_id',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Watermark Set',
                'value_options' => [],
                'empty_option' => 'Use default watermark set',
            ],
            'attributes' => [
                'id' => 'watermark-set-id',
                'class' => 'chosen-select',
            ],
        ]);

        $this->add([
            'name' => 'explicitly_no_watermark',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'No Watermark',
                'info' => 'Check to explicitly prevent watermarking for this resource.',
            ],
            'attributes' => [
                'id' => 'explicitly-no-watermark',
            ],
        ]);

        // Input filter for validation
        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'resource_type',
            'required' => true,
        ]);

        $inputFilter->add([
            'name' => 'resource_id',
            'required' => true,
        ]);

        $inputFilter->add([
            'name' => 'watermark_set_id',
            'required' => false,
        ]);

        $inputFilter->add([
            'name' => 'explicitly_no_watermark',
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

    /**
     * Set watermark set options
     *
     * @param array $watermarkSets
     */
    public function setWatermarkSetOptions(array $watermarkSets)
    {
        $options = [];
        foreach ($watermarkSets as $watermarkSet) {
            $options[$watermarkSet->id()] = $watermarkSet->name();
        }

        $this->get('watermark_set_id')->setValueOptions($options);
        return $this;
    }
}