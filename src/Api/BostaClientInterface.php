<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Api;

interface BostaClientInterface
{
    /**
     * POST /deliveries?apiVersion=1
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the "data" object: _id, trackingNumber, state...
     *
     * @throws BostaApiException
     */
    public function createDelivery(string $apiKey, array $payload): array;

    /**
     * GET /deliveries/business/{trackingNumber}
     *
     * @return array<string, mixed>
     *
     * @throws BostaApiException
     */
    public function getDelivery(string $apiKey, string $trackingNumber): array;

    /**
     * POST /deliveries/search by businessReference.
     *
     * @return list<array<string, mixed>>
     *
     * @throws BostaApiException
     */
    public function findDeliveriesByReference(string $apiKey, string $businessReference): array;

    /**
     * DELETE /deliveries/business/{trackingNumber}/terminate
     *
     * @throws BostaApiException
     */
    public function terminateDelivery(string $apiKey, string $trackingNumber): void;

    /**
     * GET /pricing/shipment/calculator
     *
     * @param array<string, scalar> $query
     *
     * @return array<array-key, mixed>
     *
     * @throws BostaApiException
     */
    public function estimateShippingFees(string $apiKey, array $query): array;

    /**
     * POST /deliveries/mass-awb for one delivery; returns the PDF bytes.
     *
     * @throws BostaApiException
     */
    public function airwayBill(string $apiKey, string $trackingNumber, string $lang): string;

    /**
     * GET /cities/getAllDistricts (public): cities with their districts.
     *
     * @return list<array<string, mixed>>
     *
     * @throws BostaApiException
     */
    public function citiesWithDistricts(): array;
}
