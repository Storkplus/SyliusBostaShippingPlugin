<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Form;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusBostaShippingPlugin\Form\Type\BostaCalculatorConfigurationType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Validation;

final class ChannelAmountValidationTest extends TestCase
{
    public function test_a_channel_without_amount_is_rejected(): void
    {
        $violations = $this->validate(['default' => ['amount' => null]]);

        self::assertCount(1, $violations);
        self::assertSame('[default][amount]', $violations[0]->getPropertyPath());
    }

    public function test_a_zero_amount_is_accepted(): void
    {
        self::assertCount(0, $this->validate(['default' => ['amount' => 0]]));
    }

    public function test_every_channel_needs_an_amount(): void
    {
        self::assertCount(1, $this->validate(['default' => ['amount' => 6000], 'second' => ['amount' => '']]));
    }

    /** @param array<string, mixed> $channels */
    private function validate(array $channels): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        return Validation::createValidator()->validate(
            $channels,
            new Callback([BostaCalculatorConfigurationType::class, 'validateChannelAmounts']),
        );
    }
}
