<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Form\Type;

use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaClientInterface;
use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Sylius\Bundle\CoreBundle\Form\Type\Shipping\Calculator\ChannelBasedFlatRateConfigurationType;
use Sylius\Component\Channel\Model\ChannelsAwareInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class BostaCalculatorConfigurationType extends AbstractType
{
    private const GROUPS = ['sylius'];

    public function __construct(
        private readonly BostaClientInterface $client,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RepositoryInterface $paymentMethodRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('webhook_url', TextType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.webhook_url',
                'help' => 'storkplus_sylius_bosta_shipping.form.webhook_url_help',
                'help_translation_parameters' => ['%header%' => Bosta::WEBHOOK_SECRET_HEADER],
                'mapped' => false,
                'required' => false,
                // Disabled: never read from the request, so every (re-)render shows the URL.
                'disabled' => true,
                'data' => $this->urlGenerator->generate('storkplus_sylius_bosta_shipping_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'attr' => ['class' => 'font-monospace user-select-all'],
            ])
            ->add(Bosta::CONFIG_API_KEY, TextType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.api_key',
                'help' => 'storkplus_sylius_bosta_shipping.form.api_key_help',
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [new NotBlank(['message' => 'storkplus_sylius_bosta_shipping.api_key.not_blank', 'groups' => self::GROUPS])],
            ])
            ->add(Bosta::CONFIG_WEBHOOK_SECRET, TextType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.webhook_secret',
                'help' => 'storkplus_sylius_bosta_shipping.form.webhook_secret_help',
                'help_translation_parameters' => ['%header%' => Bosta::WEBHOOK_SECRET_HEADER],
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'class' => 'font-monospace'],
                'constraints' => [new Length(['min' => 16, 'groups' => self::GROUPS])],
            ])
            ->add(Bosta::CONFIG_PICKUP_CITY, ChoiceType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.pickup_city',
                'help' => 'storkplus_sylius_bosta_shipping.form.pickup_city_help',
                'choices' => $this->cityChoices(),
                'required' => false,
                'placeholder' => 'Cairo',
            ])
            ->add(Bosta::CONFIG_BUSINESS_LOCATION_ID, TextType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.business_location_id',
                'help' => 'storkplus_sylius_bosta_shipping.form.business_location_id_help',
                'required' => false,
            ])
            ->add(Bosta::CONFIG_PACKAGE_SIZE, ChoiceType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.package_size',
                'choices' => [
                    'storkplus_sylius_bosta_shipping.form.size.small' => 'SMALL',
                    'storkplus_sylius_bosta_shipping.form.size.medium' => 'MEDIUM',
                    'storkplus_sylius_bosta_shipping.form.size.large' => 'LARGE',
                    'storkplus_sylius_bosta_shipping.form.size.light_bulky' => 'Light Bulky',
                    'storkplus_sylius_bosta_shipping.form.size.heavy_bulky' => 'Heavy Bulky',
                ],
            ])
            ->add(Bosta::CONFIG_FEE_MODE, ChoiceType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.fee_mode',
                'help' => 'storkplus_sylius_bosta_shipping.form.fee_mode_help',
                'choices' => [
                    'storkplus_sylius_bosta_shipping.form.fee_mode_flat' => Bosta::FEE_MODE_FLAT,
                    'storkplus_sylius_bosta_shipping.form.fee_mode_estimate' => Bosta::FEE_MODE_ESTIMATE,
                ],
            ])
            ->add(Bosta::CONFIG_CHANNELS, ChannelBasedFlatRateConfigurationType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.channels',
                // Required in estimate mode too: it is what gets charged when Bosta cannot price the city.
                'constraints' => [new Callback(callback: [self::class, 'validateChannelAmounts'], groups: self::GROUPS)],
            ])
            ->add(Bosta::CONFIG_COD_PAYMENT_METHODS, ChoiceType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.cod_payment_methods',
                'help' => 'storkplus_sylius_bosta_shipping.form.cod_payment_methods_help',
                'choices' => $this->paymentMethodChoices(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add(Bosta::CONFIG_AUTO_CREATE, CheckboxType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.auto_create',
                'help' => 'storkplus_sylius_bosta_shipping.form.auto_create_help',
                'required' => false,
            ])
            ->add(Bosta::CONFIG_COMPLETE_COD_ON_DELIVERY, CheckboxType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.complete_cod_on_delivery',
                'required' => false,
            ])
            ->add(Bosta::CONFIG_REFERENCE_PREFIX, TextType::class, [
                'label' => 'storkplus_sylius_bosta_shipping.form.reference_prefix',
                'help' => 'storkplus_sylius_bosta_shipping.form.reference_prefix_help',
                'required' => false,
            ])
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $data = $event->getData();
                $data = is_array($data) ? $data : [];
                // Defaults for a new method; existing values are kept.
                $data += [
                    Bosta::CONFIG_AUTO_CREATE => true,
                    Bosta::CONFIG_COD_PAYMENT_METHODS => ['cod'],
                    Bosta::CONFIG_COMPLETE_COD_ON_DELIVERY => true,
                    Bosta::CONFIG_FEE_MODE => Bosta::FEE_MODE_FLAT,
                    Bosta::CONFIG_PACKAGE_SIZE => 'SMALL',
                ];
                if ('' === (string) ($data[Bosta::CONFIG_WEBHOOK_SECRET] ?? '')) {
                    $data[Bosta::CONFIG_WEBHOOK_SECRET] = bin2hex(random_bytes(24));
                }
                $event->setData($data);
            })
            ->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                if (is_array($data) && '' === trim((string) ($data[Bosta::CONFIG_WEBHOOK_SECRET] ?? ''))) {
                    $data[Bosta::CONFIG_WEBHOOK_SECRET] = bin2hex(random_bytes(24));
                    $event->setData($data);
                }
            })
        ;
    }

    /**
     * Every channel the method is offered in needs a flat amount. Without one, a Bosta estimate that comes back
     * empty leaves the shipment with no price.
     */
    public static function validateChannelAmounts(mixed $channels, ExecutionContextInterface $context): void
    {
        $channels = is_array($channels) ? $channels : [];

        $root = $context->getRoot();
        $method = $root instanceof FormInterface ? $root->getData() : null;
        $codes = $method instanceof ChannelsAwareInterface
            ? array_filter(array_map(static fn ($channel): ?string => $channel->getCode(), $method->getChannels()->toArray()))
            : array_keys($channels);

        foreach ($codes as $code) {
            $amount = is_array($channels[$code] ?? null) ? ($channels[$code]['amount'] ?? null) : null;
            if (!is_numeric($amount)) {
                $context->buildViolation('storkplus_sylius_bosta_shipping.channel_amount.not_blank')
                    ->setParameter('%channel%', (string) $code)
                    ->atPath(sprintf('[%s][amount]', $code))
                    ->addViolation();
            }
        }
    }

    public function getBlockPrefix(): string
    {
        return 'storkplus_bosta_calculator_configuration';
    }

    /** @return array<string, string> */
    private function cityChoices(): array
    {
        try {
            $cities = $this->client->citiesWithDistricts();
        } catch (BostaApiException) {
            $cities = [];
        }

        $choices = [];
        foreach ($cities as $city) {
            $name = $city['cityName'] ?? null;
            if (is_string($name) && '' !== $name) {
                $other = is_string($city['cityOtherName'] ?? null) ? ' — ' . $city['cityOtherName'] : '';
                $choices[$name . $other] = $name;
            }
        }

        return [] !== $choices ? $choices : ['Cairo' => 'Cairo', 'Giza' => 'Giza', 'Alexandria' => 'Alexandria'];
    }

    /** @return array<string, string> */
    private function paymentMethodChoices(): array
    {
        $choices = [];
        foreach ($this->paymentMethodRepository->findAll() as $method) {
            if ($method instanceof PaymentMethodInterface && null !== $method->getCode()) {
                $choices[sprintf('%s (%s)', (string) $method->getName(), $method->getCode())] = $method->getCode();
            }
        }

        return $choices;
    }
}
