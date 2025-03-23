<?php
namespace Watermarker\Api\Adapter;

use DateTime;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use Watermarker\Entity\WatermarkAssignment;
use Watermarker\Api\Representation\WatermarkAssignmentRepresentation;

class WatermarkAssignmentAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource_type' => 'resourceType',
        'resource_id' => 'resourceId',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'watermark_assignments';
    }

    public function getRepresentationClass()
    {
        return WatermarkAssignmentRepresentation::class;
    }

    public function getEntityClass()
    {
        return WatermarkAssignment::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['resource_type'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.resourceType',
                $this->createNamedParameter($qb, $query['resource_type'])
            ));
        }

        if (isset($query['resource_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.resourceId',
                $this->createNamedParameter($qb, $query['resource_id'])
            ));
        }

        if (isset($query['watermark_set_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.watermarkSet',
                $this->createNamedParameter($qb, $query['watermark_set_id'])
            ));
        }

        if (isset($query['explicitly_no_watermark'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.explicitlyNoWatermark',
                $this->createNamedParameter($qb, (bool) $query['explicitly_no_watermark'])
            ));
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        $data = $request->getContent();

        if (isset($data['o:resource_type'])) {
            $entity->setResourceType($data['o:resource_type']);
        }

        if (isset($data['o:resource_id'])) {
            $entity->setResourceId($data['o:resource_id']);
        }

        // Handle watermark set relationship
        if (isset($data['o:watermark_set']['o:id']) && !empty($data['o:watermark_set']['o:id'])) {
            $watermarkSet = $this->getAdapter('watermark_sets')->findEntity($data['o:watermark_set']['o:id']);
            $entity->setWatermarkSet($watermarkSet);
            $entity->setExplicitlyNoWatermark(false);
        } elseif (isset($data['o:explicitly_no_watermark']) && $data['o:explicitly_no_watermark']) {
            $entity->setWatermarkSet(null);
            $entity->setExplicitlyNoWatermark(true);
        } else {
            $entity->setWatermarkSet(null);
            $entity->setExplicitlyNoWatermark(false);
        }

        if ($request->getOperation() === Request::UPDATE) {
            $entity->setModified(new DateTime('now'));
        }
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore)
    {
        if (!$entity->getResourceType()) {
            $errorStore->addError('o:resource_type', 'Resource type cannot be empty');
        }

        if (!$entity->getResourceId()) {
            $errorStore->addError('o:resource_id', 'Resource ID cannot be empty');
        }

        // Validate that we don't have both a watermark set and explicitly no watermark
        if ($entity->getWatermarkSet() && $entity->getExplicitlyNoWatermark()) {
            $errorStore->addError('o:watermark_set', 'Cannot have both a watermark set and explicitly no watermark');
        }
    }
}