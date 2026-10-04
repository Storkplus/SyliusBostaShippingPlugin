<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Console;

use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Manager\BostaDeliveryManagerInterface;
use Storkplus\SyliusBostaShippingPlugin\Repository\BostaDeliveryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'storkplus:bosta:sync',
    description: 'Retry failed Bosta deliveries and refresh the state of deliveries in flight (webhook safety net).',
)]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly BostaDeliveryRepository $repository,
        private readonly BostaDeliveryManagerInterface $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-attempts', null, InputOption::VALUE_REQUIRED, 'Give up retrying a delivery after this many attempts', '5')
            ->addOption('no-retry', null, InputOption::VALUE_NONE, 'Only refresh states, do not retry failed creations')
            ->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Only retry failed creations')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $retried = $created = $refreshed = 0;

        if (!$input->getOption('no-retry')) {
            foreach ($this->repository->findFailed(is_numeric($input->getOption('max-attempts')) ? (int) $input->getOption('max-attempts') : 5) as $delivery) {
                ++$retried;
                if ($this->manager->create($delivery->getShipment())->isCreated()) {
                    ++$created;
                }
            }
        }

        if (!$input->getOption('no-refresh')) {
            foreach ($this->repository->findInFlight() as $delivery) {
                try {
                    $this->manager->refresh($delivery);
                    ++$refreshed;
                } catch (BostaApiException $exception) {
                    $io->warning(sprintf('%s: %s', (string) $delivery->getTrackingNumber(), $exception->describe()));
                }
            }
        }

        $io->writeln(sprintf('Bosta sync: %d retried (%d created), %d refreshed.', $retried, $created, $refreshed));

        return Command::SUCCESS;
    }
}
