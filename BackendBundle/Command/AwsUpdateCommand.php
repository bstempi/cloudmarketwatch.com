<?php
namespace CloudMarketWatch\BackendBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AwsUpdateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('cws:awsupdate')
            ->setDescription('Grabs the latest market data from AWS');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // TODO implement me
    }
}
?>