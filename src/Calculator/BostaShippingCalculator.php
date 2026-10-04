<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Calculator;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusBostaShippingPlugin\Address\BostaAddressResolverInterface;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaClientInterface;
use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface as CoreShipmentInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Webmozart\Assert\Assert;

/**
 * "bosta" shipping calculator.
 *
 * Flat mode: the per-channel amount set on the shipping method (like flat_rate).
 * Estimate mode: Bosta's fee estimate for the drop-off city, cached for an hour;
 * whenever it cannot be obtained (no city yet, timeout, unexpected answer) the
 * flat amount is charged instead, so checkout never breaks because of Bosta.
 */
final class BostaShippingCalculator implements CalculatorInterface
{
    /** Keys that may carry the total fee in Bosta's estimate response, best first. */
    private const FEE_KEYS = ['priceAfterVat', 'totalPrice', 'total', 'shippingFeesAfterVat', 'shippingFee', 'price', 'priceBeforeVat'];

    public function __construct(
        private readonly BostaClientInterface $client,
        private readonly BostaAddressResolverInterface $addressResolver,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function calculate(ShipmentInterface $subject, array $configuration): int
    {
        Assert::isInstanceOf($subject, CoreShipmentInterface::class);

        $order = $subject->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);
        $channel = $order->getChannel();
        Assert::notNull($channel);

        $config = new BostaConfiguration($configuration);
        $flat = $config->channelAmount((string) $channel->getCode());

        if (Bosta::FEE_MODE_ESTIMATE === $config->feeMode()) {
            $estimate = $this->estimate($order, $config);
            if (null !== $estimate) {
                return $estimate;
            }
        }

        if (null === $flat) {
            throw new MissingChannelConfigurationException(sprintf(
                'Channel %s has no amount defined for the Bosta shipping method.',
                $channel->getName(),
            ));
        }

        return $flat;
    }

    public function getType(): string
    {
        return Bosta::CALCULATOR;
    }

    private function estimate(OrderInterface $order, BostaConfiguration $config): ?int
    {
        $address = $order->getShippingAddress();
        if (!$address instanceof AddressInterface || '' === $config->apiKey() || 'EGP' !== $order->getCurrencyCode()) {
            return null;
        }

        $dropOffCity = $this->addressResolver->resolveCityName($address);
        if (null === $dropOffCity) {
            return null;
        }

        $query = [
            'dropOffCity' => $dropOffCity,
            'pickupCity' => '' !== $config->pickupCity() ? $config->pickupCity() : 'Cairo',
            'size' => in_array($config->packageSize(), ['Light Bulky', 'Heavy Bulky'], true) ? $config->packageSize() : 'Normal',
            'type' => 'SEND',
            // Bucketed so the cache key does not change on every cent of the cart.
            'cod' => (int) (ceil($order->getTotal() / 100 / 500) * 500),
        ];

        $key = 'storkplus_bosta_fee_' . md5(serialize($query) . substr(hash('sha256', $config->apiKey()), 0, 16));

        try {
            /** @var int|null $fee */
            $fee = $this->cache->get($key, function (ItemInterface $item) use ($config, $query): ?int {
                $item->expiresAfter(3600);

                return $this->extractFee($this->client->estimateShippingFees($config->apiKey(), $query));
            });
        } catch (\Throwable $exception) {
            // BostaApiException from the cache callback, or a cache failure: fall back to flat.
            $this->logger->notice('Bosta fee estimate unavailable, using the flat amount', [
                'error' => $exception instanceof BostaApiException ? $exception->describe() : $exception->getMessage(),
            ]);

            return null;
        }

        return $fee;
    }

    /**
     * Bosta does not document the estimate response; take the first known fee field
     * found anywhere in it (EGP, may be decimal) and convert to minor units.
     *
     * @param array<array-key, mixed> $data
     */
    private function extractFee(array $data): ?int
    {
        foreach (self::FEE_KEYS as $key) {
            $value = $this->find($data, $key);
            if (is_numeric($value) && (float) $value >= 0) {
                return (int) round((float) $value * 100);
            }
        }

        $this->logger->warning('Unrecognised Bosta fee estimate response', ['keys' => array_keys($data)]);

        return null;
    }

    /** @param array<array-key, mixed> $data */
    private function find(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->find($value, $key);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
