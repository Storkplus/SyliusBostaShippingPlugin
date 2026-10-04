<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture;

use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaClientInterface;

/** Records calls; failures and existing deliveries are scripted per test. */
final class FakeBostaClient implements BostaClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var list<string> */
    public array $terminated = [];

    /** @var list<BostaApiException> thrown by the next createDelivery() calls, in order */
    public array $createFailures = [];

    /** @var array<string, array<string, mixed>> deliveries Bosta already has, by businessReference */
    public array $existing = [];

    /** When true, the create succeeds at Bosta but the answer is lost (timeout). */
    public bool $loseCreateResponse = false;

    /** @var array<string, array<string, mixed>> */
    public array $deliveries = [];

    /** @var array<array-key, mixed>|BostaApiException */
    public array|BostaApiException $estimate = [];

    private int $nextTracking = 7000001;

    public function createDelivery(string $apiKey, array $payload): array
    {
        if ([] !== $this->createFailures) {
            throw array_shift($this->createFailures);
        }

        $reference = (string) $payload['uniqueBusinessReference'];
        if (isset($this->existing[$reference])) {
            throw new BostaApiException('Duplicate uniqueBusinessReference', 400, 1061);
        }

        $tracking = (string) $this->nextTracking++;
        $delivery = [
            '_id' => 'id_' . $tracking,
            'businessReference' => $payload['businessReference'],
            'state' => ['code' => 10, 'value' => 'Pickup requested'],
            'trackingNumber' => $tracking,
        ];
        $this->created[] = $payload;
        $this->existing[$reference] = $delivery;
        $this->deliveries[$tracking] = $delivery;

        if ($this->loseCreateResponse) {
            $this->loseCreateResponse = false;

            throw new BostaApiException('Could not reach Bosta: timeout', 0, null, true);
        }

        return $delivery;
    }

    public function getDelivery(string $apiKey, string $trackingNumber): array
    {
        return $this->deliveries[$trackingNumber] ?? throw new BostaApiException('Not found', 404);
    }

    public function findDeliveriesByReference(string $apiKey, string $businessReference): array
    {
        return isset($this->existing[$businessReference]) ? [$this->existing[$businessReference]] : [];
    }

    public function terminateDelivery(string $apiKey, string $trackingNumber): void
    {
        $this->terminated[] = $trackingNumber;
    }

    public function estimateShippingFees(string $apiKey, array $query): array
    {
        if ($this->estimate instanceof BostaApiException) {
            throw $this->estimate;
        }

        return $this->estimate;
    }

    public function airwayBill(string $apiKey, string $trackingNumber, string $lang): string
    {
        return '%PDF-1.4 fake';
    }

    public function citiesWithDistricts(): array
    {
        /** @var list<array<string, mixed>> $cities */
        $cities = json_decode((string) file_get_contents(__DIR__ . '/districts.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $cities;
    }
}
