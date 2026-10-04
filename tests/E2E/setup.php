<?php
// Egypt country + zone on every channel; payment methods are the fixtures' (cash_on_delivery, bank_transfer).
require '/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/app/tests/TestApplication/.env');
$kernel = new Sylius\TestApplication\Kernel('test', true);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$em = $c->get('doctrine.orm.entity_manager');

$country = $c->get('sylius.repository.country')->findOneBy(['code' => 'EG']);
if (null === $country) {
    $country = $c->get('sylius.factory.country')->createNew();
    $country->setCode('EG');
    $country->enable();
    $em->persist($country);
}

$zone = $c->get('sylius.repository.zone')->findOneBy(['code' => 'EGYPT']);
if (null === $zone) {
    $zone = $c->get('sylius.factory.zone')->createWithMembers(['EG']);
    $zone->setCode('EGYPT');
    $zone->setName('Egypt');
    $zone->setType('country');
    $em->persist($zone);
}

foreach ($c->get('sylius.repository.channel')->findAll() as $channel) {
    if (!$channel->hasCountry($country)) {
        $channel->addCountry($country);
    }
    echo 'channel ', $channel->getCode(), ' host=', $channel->getHostname() ?? '-', "\n";
}
foreach (['cash_on_delivery', 'bank_transfer'] as $code) {
    $method = $c->get('sylius.repository.payment_method')->findOneBy(['code' => $code]);
    $method->enable();
    foreach ($c->get('sylius.repository.channel')->findAll() as $channel) {
        $method->hasChannel($channel) || $method->addChannel($channel);
    }
}
$em->flush();
echo "zone EGYPT id=", $zone->getId(), "\n";
