<?php
namespace Watermarker\Api\Adapter;

use DateTime;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use Watermarker\Entity\WatermarkSetting;
use Watermarker\Api\Representation\WatermarkSettingRepresentation;

class WatermarkSettingAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'type' => 'type',
        'position' => 'position',
        'opacity' => 'opacity',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'watermark_settings';
    }

    public function getRepresentationClass()
    {
        return WatermarkSettingRepresentation::class;
    }

    public function getEntityClass()
    {
        return WatermarkSetting::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['watermark_set_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.set',
                $this->createNamedParameter($qb, $query['watermark_set_id'])
            ));
        }

        if (isset($query['media_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.mediaId',
                $this->createNamedParameter($qb, $query['media_id'])
            ));
        }

        if (isset($query['type'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.type',
                $this->createNamedParameter($qb, $query['type'])
            ));
        }

        if (isset($query['position'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.position',
                $this->createNamedParameter($qb, $query['position'])
            ));
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        $data = $request->getContent();

        // Handle watermark set relationship
        if (isset($data['o:set']['o:id'])) {
            $set = $this->getAdapter('watermark_sets')->findEntity($data['o:set']['o:id']);
            $entity->setSet($set);
        }

        if (isset($data['o:type'])) {
            $entity->setType($data['o:type']);
        }

        if (isset($data['o:position'])) {
            $entity->setPosition($data['o:position']);
        }

        if (isset($data['o:opacity'])) {
            $entity->setOpacity((float) $data['o:opacity']);
        }

        if (isset($data['o:media']['o:id'])) {
            $entity->setMediaId($data['o:media']['o:id']);
        }

        if ($request->getOperation() === Request::UPDATE) {
            $entity->setModified(new DateTime('now'));
        }
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore)
    {
        if (!$entity->getSet()) {
            $errorStore->addError('o:set', 'Watermark set cannot be empty');
        }

        if (!$entity->getType()) {
            $errorStore->addError('o:type', 'Type cannot be empty');
        }

        if (!$entity->getPosition()) {
            $errorStore->addError('o:position', 'Position cannot be empty');
        }

        if ($entity->getOpacity() < 0 || $entity->getOpacity() > 1) {
            $errorStore->addError('o:opacity', 'Opacity must be between 0 and 1');
        }

        if (($entity->getType() === 'image' || $entity->getType() === 'text') && !$entity->getMediaId()) {
            $errorStore->addError('o:media', 'Media ID is required for image or text watermarks');
        }
    }
}