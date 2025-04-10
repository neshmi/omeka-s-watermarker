<?php declare(strict_types=1);
/**
 * Watermarker watermark set form
 */

namespace Watermarker\Form\Admin;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;


class BatchEditFieldset extends Fieldset
{
    protected $resourceType = null;
    public function __construct($name = null, array $options = [])
    {
        parent::__construct($name, $options);
        if (isset($options['resource_type'])) {
            $this->resourceType = (string) $options['resource_type'];
        }
    }

    public function init(): void
    {
        $this
            ->setName('watermarkset')
            ->setLabel('Watermark Set')
            ->setAttributes([
                'id' => 'watermarkset',
                'class' => 'field-container',
                'data-collection-action' => 'replace',
            ]);

        $watermarkSettOptions = [
            WatermarkSet::NONE => 'None',
            WatermarkSet::DEFAULT => 'Default',
        ];

        $this
            ->add([
                'name' => 'o-watermarkset:option',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    $watermarkSettOptions,
                ],
                'attributes' => [
                    'id' => 'watermarkset_option',
                    'class' => 'watermark',
                    'data-collection-action' => 'replace',
                ],
            ])


            ->add([
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
            ])
            // ->initWatermarkSetRecursive()
        ;
    }

    // protected function initWatermarkSetRecursive(): self;
    // {
    //     if (in_array($this->resourceType, ['itemSet', 'item'])) {
    //         $this
    //             ->add([

    //             ])
    //     }
    // }
}