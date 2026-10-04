<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Repository;

use Doctrine\ORM\EntityRepository;
use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * @extends EntityRepository<BostaDelivery>
 */
class BostaDeliveryRepository extends EntityRepository
{
    public function findOneByShipment(ShipmentInterface $shipment): ?BostaDelivery
    {
        /** @var BostaDelivery|null $delivery */
        $delivery = $this->findOneBy(['shipment' => $shipment]);

        return $delivery;
    }

    public function findOneByTrackingNumber(string $trackingNumber): ?BostaDelivery
    {
        /** @var BostaDelivery|null $delivery */
        $delivery = $this->findOneBy(['trackingNumber' => $trackingNumber]);

        return $delivery;
    }

    public function findOneByDeliveryId(string $deliveryId): ?BostaDelivery
    {
        /** @var BostaDelivery|null $delivery */
        $delivery = $this->findOneBy(['deliveryId' => $deliveryId]);

        return $delivery;
    }

    /** @return list<BostaDelivery> */
    public function findByOrder(OrderInterface $order): array
    {
        /** @var list<BostaDelivery> $deliveries */
        $deliveries = $this->createQueryBuilder('d')
            ->innerJoin('d.shipment', 's')
            ->andWhere('s.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getResult();

        return $deliveries;
    }

    /** @return list<BostaDelivery> */
    public function findFailed(int $maxAttempts, int $limit = 50): array
    {
        /** @var list<BostaDelivery> $deliveries */
        $deliveries = $this->createQueryBuilder('d')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.attempts < :maxAttempts')
            ->setParameter('statuses', [BostaDelivery::STATUS_FAILED, BostaDelivery::STATUS_PENDING])
            ->setParameter('maxAttempts', $maxAttempts)
            ->orderBy('d.updatedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $deliveries;
    }

    /**
     * Created deliveries Bosta has not finished with, oldest update first; used to
     * catch up on webhooks that never arrived.
     *
     * @return list<BostaDelivery>
     */
    public function findInFlight(int $limit = 100): array
    {
        /** @var list<BostaDelivery> $deliveries */
        $deliveries = $this->createQueryBuilder('d')
            ->andWhere('d.status = :created')
            ->andWhere('d.stateCode IS NULL OR d.stateCode NOT IN (:final)')
            ->setParameter('created', BostaDelivery::STATUS_CREATED)
            ->setParameter('final', array_merge([Bosta::STATE_DELIVERED], Bosta::PROBLEM_STATES))
            ->orderBy('d.updatedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $deliveries;
    }
}
