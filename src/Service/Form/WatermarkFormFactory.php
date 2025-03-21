<?php
/**
 * Watermarker form factory
 */

namespace Watermarker\Service\Form;

use Watermarker\Form\WatermarkForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Form\Fieldset;
use Laminas\InputFilter\InputFilter;

class WatermarkFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $form = new WatermarkForm();
        
        // Add basic form elements - these are now directly in our raw HTML form
        // but we'll keep them here for compatibility with the controller
        $form->add([
            'name' => 'o:id',
            'type' => 'hidden',
        ]);

        $form->add([
            'name' => 'name',
            'type' => 'text',
            'options' => [
                'label' => 'Watermark Name',
                'info' => 'Name for this watermark configuration.',
            ],
            'attributes' => [
                'id' => 'watermark-name',
                'required' => true,
            ],
        ]);

        $form->add([
            'name' => 'enabled',
            'type' => 'checkbox',
            'options' => [
                'label' => 'Enabled',
                'info' => 'Check to enable this watermark configuration.',
            ],
            'attributes' => [
                'id' => 'watermark-enabled',
            ],
        ]);
        
        // Add direct fields for landscape, portrait, and square variants
        $this->addDirectFieldsToForm($form);
        
        // Set up a simple input filter to validate the form
        $inputFilter = $form->getInputFilter();
        
        // Configure CSRF protection
        $form->add([
            'name' => 'csrf',
            'type' => 'csrf',
            'options' => [
                'csrf_options' => ['timeout' => 3600]
            ],
        ]);
        
        return $form;
    }

    /**
     * Add direct fields for landscape, portrait, and square variants
     *
     * @param WatermarkForm $form
     */
    protected function addDirectFieldsToForm(WatermarkForm $form)
    {
        // Add direct fields for landscape
        $form->add([
            'name' => 'landscape_image',
            'type' => 'hidden',
            'attributes' => [
                'id' => 'landscape-image',
            ],
        ]);
        
        $form->add([
            'name' => 'landscape_position',
            'type' => 'Select',
            'options' => [
                'label' => 'Landscape Position',
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
                'id' => 'landscape-position',
                'value' => 'bottom-right',
            ],
        ]);
        
        $form->add([
            'name' => 'landscape_opacity',
            'type' => 'Number',
            'options' => [
                'label' => 'Landscape Opacity',
            ],
            'attributes' => [
                'id' => 'landscape-opacity',
                'min' => '0.1',
                'max' => '1.0',
                'step' => '0.05',
                'value' => '0.7',
            ],
        ]);
        
        // Add direct fields for portrait
        $form->add([
            'name' => 'portrait_image',
            'type' => 'hidden',
            'attributes' => [
                'id' => 'portrait-image',
            ],
        ]);
        
        $form->add([
            'name' => 'portrait_position',
            'type' => 'Select',
            'options' => [
                'label' => 'Portrait Position',
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
                'id' => 'portrait-position',
                'value' => 'bottom-right',
            ],
        ]);
        
        $form->add([
            'name' => 'portrait_opacity',
            'type' => 'Number',
            'options' => [
                'label' => 'Portrait Opacity',
            ],
            'attributes' => [
                'id' => 'portrait-opacity',
                'min' => '0.1',
                'max' => '1.0',
                'step' => '0.05',
                'value' => '0.7',
            ],
        ]);
        
        // Add direct fields for square
        $form->add([
            'name' => 'square_image',
            'type' => 'hidden',
            'attributes' => [
                'id' => 'square-image',
            ],
        ]);
        
        $form->add([
            'name' => 'square_position',
            'type' => 'Select',
            'options' => [
                'label' => 'Square Position',
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
                'id' => 'square-position',
                'value' => 'bottom-right',
            ],
        ]);
        
        $form->add([
            'name' => 'square_opacity',
            'type' => 'Number',
            'options' => [
                'label' => 'Square Opacity',
            ],
            'attributes' => [
                'id' => 'square-opacity',
                'min' => '0.1',
                'max' => '1.0',
                'step' => '0.05',
                'value' => '0.7',
            ],
        ]);
    }
}