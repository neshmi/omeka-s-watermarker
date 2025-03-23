<?php
namespace Watermarker\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Watermarker\Entity\WatermarkSet;

class WatermarkSetRepresentation extends AbstractEntityRepresentation
{
    /**
     * {@inheritDoc}
     */
    public function getJsonLdType()
    {
        return 'o-module-watermarker:WatermarkSet';
    }

    /**
     * {@inheritDoc}
     */
    public function getJsonLd()
    {
        $settings = [];
        foreach ($this->resource->getSettings() as $setting) {
            $settings[] = [
                'id' => $setting->getId(),
                'type' => $setting->getType(),
                'media_id' => $setting->getMediaId(),
                'position' => $setting->getPosition(),
                'opacity' => $setting->getOpacity(),
            ];
        }

        return [
            'o:id' => $this->id(),
            'o:name' => $this->name(),
            'o:is_default' => $this->isDefault(),
            'o:enabled' => $this->enabled(),
            'o:created' => $this->getDateTime($this->created()),
            'o:modified' => $this->getDateTime($this->modified()),
            'o:settings' => $settings,
        ];
    }

    /**
     * Get watermark set ID.
     *
     * @return int
     */
    public function id()
    {
        return $this->resource->getId();
    }

    /**
     * Get watermark set name.
     *
     * @return string
     */
    public function name()
    {
        return $this->resource->getName();
    }

    /**
     * Get whether this is the default watermark set.
     *
     * @return bool
     */
    public function isDefault()
    {
        return $this->resource->getIsDefault();
    }

    /**
     * Get whether this watermark set is enabled.
     *
     * @return bool
     */
    public function enabled()
    {
        return $this->resource->getEnabled();
    }

    /**
     * Get the created timestamp.
     *
     * @return \DateTime
     */
    public function created()
    {
        return $this->resource->getCreated();
    }

    /**
     * Get the modified timestamp.
     *
     * @return \DateTime|null
     */
    public function modified()
    {
        return $this->resource->getModified();
    }

    /**
     * Get the watermark settings.
     *
     * @return WatermarkSettingRepresentation[]
     */
    public function settings()
    {
        $settings = [];
        $settingAdapter = $this->getAdapter('watermark_settings');
        foreach ($this->resource->getSettings() as $setting) {
            $settings[] = $settingAdapter->getRepresentation($setting);
        }
        return $settings;
    }

    /**
     * Get the watermark media.
     *
     * @return \Omeka\Api\Representation\MediaRepresentation|null
     */
    public function getWatermarkMedia()
    {
        foreach ($this->resource->getSettings() as $setting) {
            if ($setting->getType() === 'watermark') {
                try {
                    return $this->getAdapter('media')->getRepresentation($setting->getMediaId());
                } catch (\Exception $e) {
                    // Media not found
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Get the watermark media ID.
     *
     * @return int|null
     */
    public function getWatermarkMediaId()
    {
        foreach ($this->resource->getSettings() as $setting) {
            if ($setting->getType() === 'watermark') {
                return $setting->getMediaId();
            }
        }
        return null;
    }
}