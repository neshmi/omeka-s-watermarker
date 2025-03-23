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
        $set = $this->set();
        $media = $this->watermarkMedia();

        $data = [
            'o:id' => $this->id(),
            'o:set' => $set ? $set->getReference() : null,
            'o:type' => $this->type(),
            'o:media' => $media ? $media->getReference() : null,
            'o:media_id' => $this->mediaId(),
            'o:position' => $this->position(),
            'o:opacity' => $this->opacity(),
            'o:created' => $this->created(),
            'o:modified' => $this->modified(),
        ];

        return $data;
    }

    public function id()
    {
        return $this->resource->getId();
    }

    public function set()
    {
        $set = $this->resource->getSet();
        return $set ? $this->getAdapter('watermark_sets')->getRepresentation($set) : null;
    }

    public function type()
    {
        return $this->resource->getType();
    }

    public function mediaId()
    {
        return $this->resource->getMediaId();
    }

    public function position()
    {
        return $this->resource->getPosition();
    }

    public function opacity()
    {
        return $this->resource->getOpacity();
    }

    public function created()
    {
        return $this->resource->getCreated();
    }

    public function modified()
    {
        return $this->resource->getModified();
    }

    public function watermarkMedia()
    {
        $mediaId = $this->mediaId();
        if (!$mediaId) {
            return null;
        }

        try {
            return $this->getServiceLocator()->get('Omeka\ApiManager')
                ->read('assets', $mediaId)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }
}