<?php
// Helpers run inside the app container: admin actions without a browser.
require '/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/app/tests/TestApplication/.env');
$kernel = new Sylius\TestApplication\Kernel('test', true);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$em = $c->get('doctrine.orm.entity_manager');

if ('complete-payment' === $argv[1]) {
    $payment = $c->get('sylius.repository.payment')->find((int) $argv[2]);
    $c->get('sylius_abstraction.state_machine')->apply($payment, 'sylius_payment', 'complete');
    $em->flush();
    // What kernel.terminate does after an admin request.
    $c->get('event_dispatcher')->dispatch(new Symfony\Component\Console\Event\ConsoleTerminateEvent(new Symfony\Component\Console\Command\Command('x'), new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\NullOutput(), 0), 'console.terminate');
    echo $payment->getState(), "\n";
}

if ('admin-action' === $argv[1]) {
    $shipment = $c->get('sylius.repository.shipment')->find((int) $argv[3]);
    $manager = $c->get('storkplus_sylius_bosta_shipping.manager');
    try {
        $delivery = 'recreate' === $argv[2] ? $manager->recreate($shipment) : $manager->create($shipment);
        echo $delivery->getStatus(), ' ', $delivery->getTrackingNumber(), ' ', $delivery->getLastError(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

if ('render-checkout-choice' === $argv[1]) {
    // The checkout shipping choice hook, as rendered on the "select shipping" step.
    $method = $c->get('sylius.repository.shipping_method')->findOneBy(['code' => $argv[2]]);
    $twig = $c->get('twig');
    $template = $twig->createTemplate("{% hook 'sylius_shop.checkout.select_shipping.content.form.shipments.shipment.choice.details' with { method } %}");
    $form = $c->get('form.factory')->createNamed('shipping_method', Symfony\Component\Form\Extension\Core\Type\RadioType::class)->createView();
    $template = $twig->createTemplate("{% hook 'sylius_shop.checkout.select_shipping.content.form.shipments.shipment.choice.details' with { method, form } %}");
    echo $twig->render($template, ['method' => $method, 'form' => $form]);
}
