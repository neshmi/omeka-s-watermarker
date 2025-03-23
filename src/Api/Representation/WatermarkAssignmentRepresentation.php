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

        $data = [
            'o:id' => $this->id(),
            'o:resource_type' => $this->resourceType(),
            'o:resource_id' => $this->resourceId(),
            'o:watermark_set' => $watermarkSet ? $watermarkSet->getReference() : null,
            'o:explicitly_no_watermark' => $this->explicitlyNoWatermark(),
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
        $set = $this->resource->getWatermarkSet();
        return $set ? $this->getAdapter('watermark_sets')->getRepresentation($set) : null;
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
     * Get the associated resource representation
     *
     * @return AbstractEntityRepresentation|null
     */
    public function resource()
    {
        $resourceType = $this->resourceType();
        $resourceId = $this->resourceId();

        if (!$resourceType || !$resourceId) {
            return null;
        }

        // Convert from "items" to "item" for API
        $apiResourceType = $resourceType;
        if (substr($apiResourceType, -1) === 's') {
            $apiResourceType = substr($apiResourceType, 0, -1);
        }

        try {
            return $this->getServiceLocator()->get('Omeka\ApiManager')
                ->read($apiResourceType, $resourceId)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }
}