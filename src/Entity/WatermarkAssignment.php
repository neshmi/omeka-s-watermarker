<?php
namespace Watermarker\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="watermark_assignment",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(
 *             name="resource",
 *             columns={"resource_type", "resource_id"}
 *         )
 *     }
 * )
 * @ORM\HasLifecycleCallbacks
 */
class WatermarkAssignment
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=50, name="resource_type")
     */
    protected $resourceType;

    /**
     * @ORM\Column(type="integer", name="resource_id")
     */
    protected $resourceId;

    /**
     * @ORM\ManyToOne(
     *     targetEntity="WatermarkSet",
     *     inversedBy="assignments"
     * )
     * @ORM\JoinColumn(
     *     name="watermark_set_id",
     *     referencedColumnName="id",
     *     nullable=true,
     *     onDelete="SET NULL"
     * )
     */
    protected $watermarkSet;

    /**
     * @ORM\Column(type="boolean", name="explicitly_no_watermark")
     */
    protected $explicitlyNoWatermark = false;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $created;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $modified;

    /**
     * @ORM\PrePersist
     */
    public function prePersist()
    {
        $this->created = new DateTime('now');
    }

    /**
     * @ORM\PreUpdate
     */
    public function preUpdate()
    {
        $this->modified = new DateTime('now');
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
     * Set resource type.
     *
     * @param string $resourceType
     * @return self
     */
    public function setResourceType($resourceType)
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    /**
     * Get resource type.
     *
     * @return string
     */
    public function getResourceType()
    {
        return $this->resourceType;
    }

    /**
     * Set resource ID.
     *
     * @param int $resourceId
     * @return self
     */
    public function setResourceId($resourceId)
    {
        $this->resourceId = $resourceId;
        return $this;
    }

    /**
     * Get resource ID.
     *
     * @return int
     */
    public function getResourceId()
    {
        return $this->resourceId;
    }

    /**
     * Set watermark set.
     *
     * @param WatermarkSet|null $watermarkSet
     * @return self
     */
    public function setWatermarkSet(WatermarkSet $watermarkSet = null)
    {
        $this->watermarkSet = $watermarkSet;
        return $this;
    }

    /**
     * Get watermark set.
     *
     * @return WatermarkSet|null
     */
    public function getWatermarkSet()
    {
        return $this->watermarkSet;
    }

    /**
     * Get watermark set ID.
     *
     * @return int|null
     */
    public function getWatermarkSetId()
    {
        return $this->watermarkSet ? $this->watermarkSet->getId() : null;
    }

    /**
     * Set explicitly no watermark.
     *
     * @param bool $explicitlyNoWatermark
     * @return self
     */
    public function setExplicitlyNoWatermark($explicitlyNoWatermark)
    {
        $this->explicitlyNoWatermark = (bool) $explicitlyNoWatermark;
        return $this;
    }

    /**
     * Get explicitly no watermark.
     *
     * @return bool
     */
    public function getExplicitlyNoWatermark()
    {
        return $this->explicitlyNoWatermark;
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