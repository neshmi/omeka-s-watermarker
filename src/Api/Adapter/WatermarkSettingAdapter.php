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
        if (isset($query['set_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.set',
                $this->createNamedParameter($qb, $query['set_id'])
            ));
        }

        if (isset($query['type'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.type',
                $this->createNamedParameter($qb, $query['type'])
            ));
        }

        if (isset($query['media_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.mediaId',
                $this->createNamedParameter($qb, $query['media_id'])
            ));
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        $data = $request->getContent();

        if ($this->shouldHydrate($request, 'o:set')) {
            $setId = $data['o:set'] ?? null;
            if ($setId) {
                $set = $this->getEntityManager()
                    ->getRepository('Watermarker\Entity\WatermarkSet')
                    ->find($setId);
                $entity->setSet($set);
            }
        }

        if ($this->shouldHydrate($request, 'o:type')) {
            $entity->setType($data['o:type']);
        }

        if ($this->shouldHydrate($request, 'o:media_id')) {
            $entity->setMediaId($data['o:media_id']);
        }

        if ($this->shouldHydrate($request, 'o:position')) {
            $entity->setPosition($data['o:position']);
        }

        if ($this->shouldHydrate($request, 'o:opacity')) {
            $entity->setOpacity($data['o:opacity']);
        }

        if ($request->getOperation() === Request::CREATE) {
            $entity->setCreated(new DateTime('now'));
        }

        if ($request->getOperation() === Request::UPDATE) {
            $entity->setModified(new DateTime('now'));
        }
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore)
    {
        if (!$entity->getSet()) {
            $errorStore->addError('o:set', 'Watermark setting must belong to a set');
        }

        if (!$entity->getType()) {
            $errorStore->addError('o:type', 'Watermark setting must have a type');
        }

        if (!$entity->getMediaId()) {
            $errorStore->addError('o:media_id', 'Watermark setting must have a media');
        }

        if (!$entity->getPosition()) {
            $errorStore->addError('o:position', 'Watermark setting must have a position');
        }

        if (!$entity->getOpacity()) {
            $errorStore->addError('o:opacity', 'Watermark setting must have an opacity');
        }
    }
}