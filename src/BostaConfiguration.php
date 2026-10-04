<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin;

use Sylius\Component\Shipping\Model\ShippingMethodInterface;

/** Typed read access to the configuration stored on a Bosta shipping method. */
final class BostaConfiguration
{
    /** @param array<array-key, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function of(ShippingMethodInterface $method): ?self
    {
        return Bosta::CALCULATOR === $method->getCalculator() ? new self($method->getConfiguration()) : null;
    }

    public function apiKey(): string
    {
        return $this->string(Bosta::CONFIG_API_KEY);
    }

    public function webhookSecret(): string
    {
        return $this->string(Bosta::CONFIG_WEBHOOK_SECRET);
    }

    public function pickupCity(): string
    {
        return $this->string(Bosta::CONFIG_PICKUP_CITY);
    }

    public function businessLocationId(): string
    {
        return $this->string(Bosta::CONFIG_BUSINESS_LOCATION_ID);
    }

    public function packageSize(): string
    {
        return $this->string(Bosta::CONFIG_PACKAGE_SIZE) ?: 'SMALL';
    }

    public function feeMode(): string
    {
        return Bosta::FEE_MODE_ESTIMATE === $this->string(Bosta::CONFIG_FEE_MODE) ? Bosta::FEE_MODE_ESTIMATE : Bosta::FEE_MODE_FLAT;
    }

    public function autoCreate(): bool
    {
        return (bool) ($this->config[Bosta::CONFIG_AUTO_CREATE] ?? true);
    }

    public function completeCodOnDelivery(): bool
    {
        return (bool) ($this->config[Bosta::CONFIG_COMPLETE_COD_ON_DELIVERY] ?? true);
    }

    public function referencePrefix(): string
    {
        return $this->string(Bosta::CONFIG_REFERENCE_PREFIX);
    }

    /** @return list<string> */
    public function codPaymentMethods(): array
    {
        $codes = $this->config[Bosta::CONFIG_COD_PAYMENT_METHODS] ?? ['cod'];

        return array_values(array_filter(is_array($codes) ? $codes : [], 'is_string'));
    }

    /** Flat fee for a channel, in the channel's base currency minor units. */
    public function channelAmount(string $channelCode): ?int
    {
        $channels = $this->config[Bosta::CONFIG_CHANNELS] ?? [];
        $amount = is_array($channels) && is_array($channels[$channelCode] ?? null) ? ($channels[$channelCode]['amount'] ?? null) : null;

        return is_numeric($amount) ? (int) $amount : null;
    }

    private function string(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
