<?php
namespace Watermarker\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Omeka\Entity\Resource;

/**
 * @ORM\Entity
 * @ORM\Table(name="watermark_setting")
 */
class WatermarkSetting
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\ManyToOne(
     *     targetEntity="WatermarkSet",
     *     inversedBy="settings"
     * )
     * @ORM\JoinColumn(
     *     name="set_id",
     *     referencedColumnName="id",
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $set;

    /**
     * @ORM\Column(type="string", length=50)
     */
    protected $type;

    /**
     * @ORM\Column(type="integer", name="media_id")
     */
    protected $mediaId;

    /**
     * @ORM\Column(type="string", length=50)
     */
    protected $position = 'bottom-right';

    /**
     * @ORM\Column(type="float")
     */
    protected $opacity = 1.0;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $created;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $modified;

    public function __construct()
    {
        $this->created = new DateTime('now');
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set watermark set.
     *
     * @param WatermarkSet|null $set
     * @return self
     */
    public function setSet(WatermarkSet $set = null)
    {
        $this->set = $set;
        return $this;
    }

    /**
     * Get watermark set.
     *
     * @return WatermarkSet|null
     */
    public function getSet()
    {
        return $this->set;
    }

    /**
     * Set type.
     *
     * @param string $type
     * @return self
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get type.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set media ID.
     *
     * @param int $mediaId
     * @return self
     */
    public function setMediaId($mediaId)
    {
        $this->mediaId = $mediaId;
        return $this;
    }

    /**
     * Get media ID.
     *
     * @return int
     */
    public function getMediaId()
    {
        return $this->mediaId;
    }

    /**
     * Set position.
     *
     * @param string $position
     * @return self
     */
    public function setPosition($position)
    {
        $this->position = $position;
        return $this;
    }

    /**
     * Get position.
     *
     * @return string
     */
    public function getPosition()
    {
        return $this->position;
    }

    /**
     * Set opacity.
     *
     * @param float $opacity
     * @return self
     */
    public function setOpacity($opacity)
    {
        $this->opacity = (float) $opacity;
        return $this;
    }

    /**
     * Get opacity.
     *
     * @return float
     */
    public function getOpacity()
    {
        return $this->opacity;
    }

    /**
     * Get created timestamp.
     *
     * @return DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set modified timestamp.
     *
     * @param DateTime|null $modified
     * @return self
     */
    public function setModified(DateTime $modified = null)
    {
        $this->modified = $modified;
        return $this;
    }

    /**
     * Get modified timestamp.
     *
     * @return DateTime|null
     */
    public function getModified()
    {
        return $this->modified;
    }
}