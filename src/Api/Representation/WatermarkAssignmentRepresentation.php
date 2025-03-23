<?php
namespace Watermarker\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Watermarker\Entity\WatermarkAssignment;

class WatermarkAssignmentRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLdType()
    {
        return 'o-module-watermarker:WatermarkAssignment';
    }

    public function getJsonLd()
    {
        $watermarkSet = $this->watermarkSet();
        $watermarkSetData = null;

        if ($watermarkSet) {
            $watermarkSetData = $watermarkSet->getReference();
        }

        $data = [
            'o:id' => $this->id(),
            'o:resource_type' => $this->resourceType(),
            'o:resource_id' => $this->resourceId(),
            'o:explicitly_no_watermark' => $this->explicitlyNoWatermark(),
            'o:watermark_set' => $watermarkSetData,
            'o:created' => $this->created(),
            'o:modified' => $this->modified(),
        ];

        return $data;
    }

    public function id()
    {
        return $this->resource->getId();
    }

    public function resourceType()
    {
        return $this->resource->getResourceType();
    }

    public function resourceId()
    {
        return $this->resource->getResourceId();
    }

    public function watermarkSet()
    {
        $watermarkSet = $this->resource->getWatermarkSet();
        return $watermarkSet
            ? $this->getAdapter('watermark_sets')->getRepresentation($watermarkSet)
            : null;
    }

    public function explicitlyNoWatermark()
    {
        return $this->resource->getExplicitlyNoWatermark();
    }

    public function created()
    {
        return $this->resource->getCreated();
    }

    public function modified()
    {
        return $this->resource->getModified();
    }

    /**
     * Get the resource representation (item, item set, media)
     *
     * @return AbstractEntityRepresentation|null
     */
    public function resource()
    {
        $type = $this->resourceType();
        $id = $this->resourceId();

        if (!$type || !$id) {
            return null;
        }

        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        switch ($type) {
            case 'items':
                return $api->read('items', $id)->getContent();
            case 'item_sets':
                return $api->read('item_sets', $id)->getContent();
            case 'media':
                return $api->read('media', $id)->getContent();
            default:
                return null;
        }
    }
}