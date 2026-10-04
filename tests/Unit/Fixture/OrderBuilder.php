<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture;

use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;

final class OrderBuilder
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, string|null> $address
     */
    public static function shipment(
        array $config = [],
        array $address = [],
        string $paymentMethodCode = 'cod',
        string $paymentState = PaymentInterface::STATE_NEW,
    ): Shipment {
        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->setName('Smash');
        $channel->setHostname('smash.storkplus.com');

        $customer = new Customer();
        $customer->setEmail('aya@example.com');

        $shippingAddress = new Address();
        $shippingAddress->setFirstName('Aya');
        $shippingAddress->setLastName('Hassan');
        $shippingAddress->setCountryCode($address['country'] ?? 'EG');
        $shippingAddress->setProvinceName($address['province'] ?? 'Cairo');
        $shippingAddress->setProvinceCode($address['provinceCode'] ?? null);
        $shippingAddress->setCity($address['city'] ?? 'Nasr City');
        $shippingAddress->setStreet($address['street'] ?? '12 Abbas El Akkad St');
        $shippingAddress->setPostcode($address['postcode'] ?? null);
        $shippingAddress->setPhoneNumber(array_key_exists('phone', $address) ? $address['phone'] : '+20 100 123 4567');

        $order = new Order();
        $order->setNumber('000042');
        $order->setChannel($channel);
        $order->setCustomer($customer);
        $order->setCurrencyCode('EGP');
        $order->setLocaleCode('ar');
        $order->setShippingAddress($shippingAddress);

        $item = new OrderItem();
        $item->setProductName('Smash Burger');
        $item->setVariantName('Double');
        $item->setUnitPrice(15000);
        $order->addItem($item);
        new OrderItemUnit($item);
        new OrderItemUnit($item);

        $method = new ShippingMethod();
        $method->setCode('bosta');
        $method->setCurrentLocale('en');
        $method->setFallbackLocale('en');
        $method->setName('Bosta');
        $method->setCalculator(Bosta::CALCULATOR);
        $method->setConfiguration(array_merge([
            Bosta::CONFIG_API_KEY => 'test-key',
            Bosta::CONFIG_CHANNELS => ['WEB' => ['amount' => 6000]],
            Bosta::CONFIG_COD_PAYMENT_METHODS => ['cod'],
            Bosta::CONFIG_WEBHOOK_SECRET => 'secret-0123456789abcdef',
        ], $config));

        $shipment = new Shipment();
        $shipment->setMethod($method);
        $shipment->setState('ready');
        foreach ($item->getUnits() as $unit) {
            $shipment->addUnit($unit);
        }
        $order->addShipment($shipment);
        (new \ReflectionProperty(Shipment::class, 'id'))->setValue($shipment, 77);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode($paymentMethodCode);
        $payment = new Payment();
        $payment->setMethod($paymentMethod);
        $payment->setCurrencyCode('EGP');
        $payment->setAmount(36000);
        $payment->setState($paymentState);
        $order->addPayment($payment);

        return $shipment;
    }
}
