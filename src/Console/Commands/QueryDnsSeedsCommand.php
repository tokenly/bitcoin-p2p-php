<?php

namespace BitWasp\Bitcoin\Networking\Console\Commands;

use BitWasp\Bitcoin\Networking\Peer\Locator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueryDnsSeedsCommand extends AbstractCommand
{
    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName('dnsseed.query')
            ->setDescription('Lookup some peers from DNS seeds')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'A provided DNS seed provider - random otherwise', false)
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $loop = \React\EventLoop\Factory::create();
        $seed = $input->getOption('seed') ?: Locator::dnsSeedHosts()[0];

        $factory = new \BitWasp\Bitcoin\Networking\Factory($loop);
        $factory
            ->getDns()
            ->resolve($seed)
            ->then(
                function ($ipArr) use ($seed, $output, $loop) {
                    $output->writeln('  Results from ' . $seed);
                    foreach ($ipArr as $ip) {
                        $output->writeln('    - ' . $ip);
                    }
                }
            );

        $loop->run();
        return 0;
    }
}
