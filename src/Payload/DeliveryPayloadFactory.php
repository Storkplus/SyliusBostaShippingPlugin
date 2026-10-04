<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Payload;

use Storkplus\SyliusBostaShippingPlugin\Address\BostaAddressResolverInterface;
use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DeliveryPayloadFactory implements DeliveryPayloadFactoryInterface
{
    private const DESCRIPTION_MAX_LENGTH = 300;

    public function __construct(
        private readonly BostaAddressResolverInterface $addressResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function create(BostaDelivery $delivery, BostaConfiguration $configuration): array
    {
        $shipment = $delivery->getShipment();
        $order = $shipment->getOrder();
        if (!$order instanceof OrderInterface) {
            throw new \InvalidArgumentException('The shipment has no order.');
        }

        $address = $order->getShippingAddress();
        if (!$address instanceof AddressInterface) {
            throw new \InvalidArgumentException('The order has no shipping address.');
        }
        if (null !== $address->getCountryCode() && 'EG' !== $address->getCountryCode()) {
            throw new \InvalidArgumentException(sprintf('Bosta only delivers in Egypt; the shipping address is in %s.', $address->getCountryCode()));
        }

        $reference = $this->reference($delivery, $configuration);
        $cod = $this->cashOnDelivery($order, $configuration);
        if ($cod > Bosta::MAX_COD) {
            throw new \InvalidArgumentException(sprintf('Bosta collects at most %d EGP cash on delivery; this order needs %s.', Bosta::MAX_COD, $cod));
        }

        $units = 0;
        $names = [];
        foreach ($shipment->getUnits() as $unit) {
            ++$units;
            $item = $unit instanceof OrderItemUnitInterface ? $unit->getOrderItem() : null;
            if ($item instanceof OrderItemInterface) {
                $name = $item->getProductName() ?? $item->getVariantName();
                if (null !== $name) {
                    $names[$name] = ($names[$name] ?? 0) + 1;
                }
            }
        }

        $description = implode(', ', array_map(static fn (string $name, int $qty): string => sprintf('%d × %s', $qty, $name), array_keys($names), $names));
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            $description = mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH - 1) . '…';
        }

        $payload = [
            'businessReference' => $reference,
            'cod' => $cod,
            'dropOffAddress' => $this->addressResolver->resolve($address),
            'goodsInfo' => ['amount' => round($order->getItemsTotal() / 100, 2)],
            'notes' => mb_substr(trim(sprintf('Order #%s %s', (string) $order->getNumber(), (string) $order->getNotes())), 0, 500),
            'receiver' => $this->receiver($address, $order),
            'specs' => [
                'packageDetails' => array_filter([
                    'description' => '' !== $description ? $description : null,
                    'itemsCount' => max(1, $units),
                ]),
                'size' => $configuration->packageSize(),
            ],
            'type' => Bosta::DELIVERY_TYPE_SEND,
            'uniqueBusinessReference' => $reference,
            'webhookCustomHeaders' => [Bosta::WEBHOOK_SECRET_HEADER => $configuration->webhookSecret()],
            'webhookUrl' => $this->urlGenerator->generate('storkplus_sylius_bosta_shipping_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        if ('' !== $configuration->businessLocationId()) {
            $payload['businessLocationId'] = $configuration->businessLocationId();
        }

        return $payload;
    }

    public function reference(BostaDelivery $delivery, BostaConfiguration $configuration): string
    {
        $order = $delivery->getShipment()->getOrder();
        $channel = $order instanceof OrderInterface ? $order->getChannel() : null;

        // Several stores can share one Bosta account: prefix with something unique per store.
        $prefix = $configuration->referencePrefix()
            ?: (string) ($channel?->getHostname() ?: $channel?->getCode() ?: 'sylius');
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', explode('.', $prefix)[0]));

        $reference = sprintf('%s-%s-%d', $prefix, $order instanceof OrderInterface ? (string) $order->getNumber() : '0', (int) $delivery->getShipment()->getId());
        if ($delivery->getSequence() > 1) {
            $reference .= '-R' . $delivery->getSequence();
        }

        return $reference;
    }

    /** Amount Bosta collects at the door, in EGP: the outstanding total of COD payments. */
    private function cashOnDelivery(OrderInterface $order, BostaConfiguration $configuration): float
    {
        $codMethods = $configuration->codPaymentMethods();
        $amount = 0;

        foreach ($order->getPayments() as $payment) {
            if (!in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING, PaymentInterface::STATE_AUTHORIZED], true)) {
                continue;
            }
            if (in_array($payment->getMethod()?->getCode(), $codMethods, true)) {
                $amount += (int) $payment->getAmount();
            }
        }

        return round($amount / 100, 2);
    }

    /** @return array<string, string> */
    private function receiver(AddressInterface $address, OrderInterface $order): array
    {
        $firstName = trim((string) $address->getFirstName());
        $lastName = trim((string) $address->getLastName());

        $phone = $this->phone($address->getPhoneNumber() ?? $order->getCustomer()?->getPhoneNumber());
        if (null === $phone) {
            throw new \InvalidArgumentException('The shipping address has no valid Egyptian mobile number, which Bosta requires.');
        }

        return array_filter([
            'email' => $order->getCustomer()?->getEmail(),
            'firstName' => '' !== $firstName ? $firstName : ($lastName ?: 'Customer'),
            'fullName' => trim($firstName . ' ' . $lastName),
            'lastName' => $lastName,
            'phone' => $phone,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);
    }

    /** Bosta wants the local format 01XXXXXXXXX. */
    private function phone(?string $phone): ?string
    {
        if (null === $phone) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', strtr($phone, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '20') && 12 === strlen($digits)) {
            $digits = substr($digits, 2);
        }
        if (10 === strlen($digits) && str_starts_with($digits, '1')) {
            $digits = '0' . $digits;
        }

        return 1 === preg_match('/^01[0125]\d{8}$/', $digits) ? $digits : null;
    }
}
