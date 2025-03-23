<?php
namespace Watermarker\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Watermarker\Entity\WatermarkSetting;

class WatermarkSettingRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLdType()
    {
        return 'o-module-watermarker:WatermarkSetting';
    }

    public function getJsonLd()
    {
        $watermarkSet = $this->watermarkSet();
        $media = $this->media();

        $data = [
            'o:id' => $this->id(),
            'o:set' => $watermarkSet ? $watermarkSet->getReference() : null,
            'o:type' => $this->type(),
            'o:position' => $this->position(),
            'o:opacity' => $this->opacity(),
            'o:media' => $media ? $media->getReference() : null,
            'o:created' => $this->created(),
            'o:modified' => $this->modified(),
        ];

        return $data;
    }

    public function id()
    {
        return $this->resource->getId();
    }

    public function watermarkSet()
    {
        $set = $this->resource->getSet();
        return $set
            ? $this->getAdapter('watermark_sets')->getRepresentation($set)
            : null;
    }

    public function type()
    {
        return $this->resource->getType();
    }

    public function position()
    {
        return $this->resource->getPosition();
    }

    public function opacity()
    {
        return $this->resource->getOpacity();
    }

    public function media()
    {
        $mediaId = $this->resource->getMediaId();
        if (!$mediaId) {
            return null;
        }

        try {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            return $api->read('media', $mediaId)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function mediaId()
    {
        return $this->resource->getMediaId();
    }

    public function created()
    {
        return $this->resource->getCreated();
    }

    public function modified()
    {
        return $this->resource->getModified();
    }
}