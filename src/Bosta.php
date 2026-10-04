<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin;

/**
 * Names and codes shared across the plugin: the calculator type stored on the
 * shipping method, its configuration keys and the Bosta delivery state codes.
 */
final class Bosta
{
    public const CALCULATOR = 'bosta';

    public const API_BASE_URL = 'https://app.bosta.co/api/v2';

    /** Egypt, the only country Bosta serves (GET /cities?countryId=...). */
    public const EGYPT_COUNTRY_ID = '60e4482c7cb7d4bc4849c4d5';

    public const WEBHOOK_SECRET_HEADER = 'X-Bosta-Secret';

    public const CONFIG_API_KEY = 'api_key';

    public const CONFIG_WEBHOOK_SECRET = 'webhook_secret';

    public const CONFIG_PICKUP_CITY = 'pickup_city';

    public const CONFIG_BUSINESS_LOCATION_ID = 'business_location_id';

    public const CONFIG_PACKAGE_SIZE = 'package_size';

    public const CONFIG_FEE_MODE = 'fee_mode';

    public const CONFIG_AUTO_CREATE = 'auto_create';

    public const CONFIG_COD_PAYMENT_METHODS = 'cod_payment_methods';

    public const CONFIG_COMPLETE_COD_ON_DELIVERY = 'complete_cod_on_delivery';

    public const CONFIG_REFERENCE_PREFIX = 'reference_prefix';

    public const CONFIG_CHANNELS = 'channels';

    public const FEE_MODE_FLAT = 'flat';

    public const FEE_MODE_ESTIMATE = 'estimate';

    public const DELIVERY_TYPE_SEND = 10;

    /** Bosta accepts COD up to this amount (EGP). */
    public const MAX_COD = 30000;

    public const STATE_PICKUP_REQUESTED = 10;

    public const STATE_ROUTE_ASSIGNED = 20;

    public const STATE_PICKED_UP_FROM_BUSINESS = 21;

    public const STATE_RECEIVED_AT_WAREHOUSE = 24;

    public const STATE_IN_TRANSIT = 30;

    public const STATE_OUT_FOR_DELIVERY = 41;

    public const STATE_DELIVERED = 45;

    public const STATE_RETURNED_TO_BUSINESS = 46;

    public const STATE_EXCEPTION = 47;

    public const STATE_TERMINATED = 48;

    public const STATE_CANCELED = 49;

    public const STATE_LOST = 100;

    public const STATE_DAMAGED = 101;

    /** The parcel has left the merchant: the Sylius shipment is shipped from here on. */
    public const SHIPPED_STATES = [
        self::STATE_PICKED_UP_FROM_BUSINESS,
        self::STATE_RECEIVED_AT_WAREHOUSE,
        self::STATE_IN_TRANSIT,
        self::STATE_OUT_FOR_DELIVERY,
        self::STATE_DELIVERED,
    ];

    /** The delivery will not reach the customer; a merchant needs to look at it. */
    public const PROBLEM_STATES = [
        self::STATE_RETURNED_TO_BUSINESS,
        self::STATE_TERMINATED,
        self::STATE_CANCELED,
        self::STATE_LOST,
        self::STATE_DAMAGED,
    ];

    /** Bosta delivery states a delivery can still be terminated from (not picked up yet). */
    public const TERMINABLE_STATES = [self::STATE_PICKUP_REQUESTED, self::STATE_ROUTE_ASSIGNED];

    public const STATE_LABELS = [
        10 => 'Pickup requested',
        11 => 'Waiting for route',
        20 => 'Route assigned',
        21 => 'Picked up from business',
        22 => 'Picking up from consignee',
        23 => 'Picked up from consignee',
        24 => 'Received at warehouse',
        25 => 'Fulfilled',
        30 => 'In transit between hubs',
        40 => 'Picking up',
        41 => 'Out for delivery',
        45 => 'Delivered',
        46 => 'Returned to business',
        47 => 'Exception',
        48 => 'Terminated',
        49 => 'Canceled',
        60 => 'Returned to stock',
        100 => 'Lost',
        101 => 'Damaged',
        102 => 'Investigation',
        103 => 'Awaiting your action',
        104 => 'Archived',
        105 => 'On hold',
    ];

    public static function merchantTrackingUrl(string $trackingNumber): string
    {
        return 'https://business.bosta.co/orders/' . rawurlencode($trackingNumber);
    }

    public static function customerTrackingUrl(string $trackingNumber): string
    {
        return 'https://bosta.co/tracking-shipments?shipment-number=' . rawurlencode($trackingNumber);
    }

    private function __construct()
    {
    }
}
