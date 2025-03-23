<?php
namespace Watermarker\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Omeka\Entity\Resource;

/**
 * @ORM\Entity
 * @ORM\Table(name="watermark_set")
 */
class WatermarkSet
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    protected $name;

    /**
     * @ORM\Column(type="boolean", name="is_default")
     */
    protected $isDefault = false;

    /**
     * @ORM\Column(type="boolean")
     */
    protected $enabled = true;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $created;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $modified;

    /**
     * @ORM\OneToMany(
     *     targetEntity="WatermarkSetting",
     *     mappedBy="set",
     *     orphanRemoval=true,
     *     cascade={"persist", "remove"}
     * )
     */
    protected $settings;

    /**
     * @ORM\OneToMany(
     *     targetEntity="WatermarkAssignment",
     *     mappedBy="watermarkSet",
     *     orphanRemoval=false
     * )
     */
    protected $assignments;

    public function __construct()
    {
        $this->settings = new ArrayCollection();
        $this->assignments = new ArrayCollection();
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
     * Set name.
     *
     * @param string $name
     * @return self
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set whether this is the default set.
     *
     * @param bool $isDefault
     * @return self
     */
    public function setIsDefault($isDefault)
    {
        $this->isDefault = (bool) $isDefault;
        return $this;
    }

    /**
     * Get whether this is the default set.
     *
     * @return bool
     */
    public function getIsDefault()
    {
        return $this->isDefault;
    }

    /**
     * Set whether this set is enabled.
     *
     * @param bool $enabled
     * @return self
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    /**
     * Get whether this set is enabled.
     *
     * @return bool
     */
    public function getEnabled()
    {
        return $this->enabled;
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

    /**
     * Get watermark settings.
     *
     * @return Collection|WatermarkSetting[]
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * Add a watermark setting.
     *
     * @param WatermarkSetting $setting
     * @return self
     */
    public function addSetting(WatermarkSetting $setting)
    {
        if (!$this->settings->contains($setting)) {
            $this->settings->add($setting);
            $setting->setSet($this);
        }
        return $this;
    }

    /**
     * Remove a watermark setting.
     *
     * @param WatermarkSetting $setting
     * @return self
     */
    public function removeSetting(WatermarkSetting $setting)
    {
        if ($this->settings->contains($setting)) {
            $this->settings->removeElement($setting);
            $setting->setSet(null);
        }
        return $this;
    }

    /**
     * Get watermark assignments.
     *
     * @return Collection|WatermarkAssignment[]
     */
    public function getAssignments()
    {
        return $this->assignments;
    }
}