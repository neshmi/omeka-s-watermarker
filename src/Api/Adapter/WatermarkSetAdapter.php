<?php
namespace Watermarker\Api\Adapter;

use DateTime;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use Watermarker\Entity\WatermarkSet;
use Watermarker\Api\Representation\WatermarkSetRepresentation;

class WatermarkSetAdapter extends AbstractEntityAdapter
{
    /**
     * {@inheritDoc}
     */
    public function getResourceName()
    {
        return 'watermark_sets';
    }

    /**
     * {@inheritDoc}
     */
    public function getRepresentationClass()
    {
        return WatermarkSetRepresentation::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityClass()
    {
        return WatermarkSet::class;
    }

    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryBuilder $qb, array $query)
    {
        // Add ID filter
        if (isset($query['id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.id',
                $qb->expr()->literal((int) $query['id'])
            ));
        }

        // Add search filter
        if (isset($query['search']) && is_string($query['search']) && $query['search'] !== '') {
            $qb->andWhere($qb->expr()->like(
                'omeka_root.name',
                $qb->expr()->literal('%' . $query['search'] . '%')
            ));
        }

        // Add enabled filter
        if (isset($query['enabled']) && $query['enabled'] !== null) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.enabled',
                $qb->expr()->literal((bool) $query['enabled'])
            ));
        }

        // Add is_default filter
        if (isset($query['is_default']) && $query['is_default'] !== null) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.isDefault',
                $qb->expr()->literal((bool) $query['is_default'])
            ));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        $isUpdate = $request->getOperation() === Request::UPDATE;

        // Set the modified timestamp for updates
        if ($isUpdate) {
            $entity->setModified(new DateTime('now'));
        }

        // Handle special "set as default" case
        if (isset($data['is_default']) && $data['is_default']) {
            // If this is being set as default, unset any other defaults
            $this->clearOtherDefaults($entity);
        }

        // Set provided data
        if (isset($data['name'])) {
            $entity->setName($data['name']);
        }

        if (isset($data['is_default']) && $data['is_default'] !== null) {
            $entity->setIsDefault((bool) $data['is_default']);
        } elseif (!$isUpdate) {
            $entity->setIsDefault(false);
        }

        if (isset($data['enabled']) && $data['enabled'] !== null) {
            $entity->setEnabled((bool) $data['enabled']);
        } elseif (!$isUpdate) {
            $entity->setEnabled(true);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore)
    {
        // Check that name is not empty
        if (empty($entity->getName())) {
            $errorStore->addError('name', 'The watermark set must have a name.');
        }
    }

    /**
     * Clears the "is_default" flag for all other watermark sets.
     *
     * @param WatermarkSet $entity The watermark set to maintain as default
     */
    protected function clearOtherDefaults(WatermarkSet $entity)
    {
        if (!$entity->getId()) {
            // For a new entity being set as default, clear all defaults
            $qb = $this->getEntityManager()->createQueryBuilder();
            $qb->update(WatermarkSet::class, 'ws')
                ->set('ws.isDefault', ':false')
                ->setParameter('false', false)
                ->getQuery()
                ->execute();
        } else {
            // For an existing entity, clear defaults for all except this one
            $qb = $this->getEntityManager()->createQueryBuilder();
            $qb->update(WatermarkSet::class, 'ws')
                ->set('ws.isDefault', ':false')
                ->where($qb->expr()->neq('ws.id', ':id'))
                ->setParameter('false', false)
                ->setParameter('id', $entity->getId())
                ->getQuery()
                ->execute();
        }
    }
}