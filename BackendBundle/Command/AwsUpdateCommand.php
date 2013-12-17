<?php
namespace CloudMarketWatch\BackendBundle\Command;

use Aws\Ec2\Ec2Client;

use CloudMarketWatch\BackendBundle\Entity\PriceHistory;
use CloudMarketWatch\BackendBundle\Entity\RunHistory;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// TODO Add proper logging
class AwsUpdateCommand extends ContainerAwareCommand {
	protected function configure() {
		$this->setName('cloudmarketwatch:awsupdate')
				->setDescription('Grabs the latest market data from AWS');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$em = $this->getContainer()->get('doctrine')->getManager();

		// Create an array of configuration options for AWS
		// TODO Don't hard-code the region in the future
		$config = array(
				'key' => $this->getContainer()->getParameter('aws_key'),
				'secret' => $this->getContainer()->getParameter('aws_secret'),
				'region' => 'us-east-1');

		// Amazon client
		$ec2Client = Ec2Client::factory($config);

		// Get the max date
		$startDate = $this->getLastRunDate($em);

		// Prime our request by setting the next token to an empty string
		$nextToken = "";
		$endDate = new \DateTime();

		$output
				->writeln(
						"Start date: " . $startDate->format(\DateTime::ISO8601));
		$output->writeln("End date: " . $endDate->format(\DateTime::ISO8601));

		do {
			// Make a request
			$output->writeln('Querying AWS...');
			$awsResponse = $ec2Client
					->describeSpotPriceHistory(
							array("StartTime" => $startDate,
									"EndTime" => $endDate,
									"NextToken" => $nextToken));

			$this->persistPrices($awsResponse['SpotPriceHistory'], $em);

			// Are there more?  Then loop again
			$nextToken = $awsResponse['NextToken'];

		} while (!empty($nextToken));

		// Record the fact that we did this operation
		$output->writeln('Finished querying AWS.  Writing histories.');
		$this->persistRunHistory($endDate, $em);

		// Flush to db
		$em->flush();
	}

	// TODO Document me
	private function persistPrices($histories, $em) {
		foreach ($histories as $history) {

			// TODO Find a way to handle the product type
			$priceHistory = new PriceHistory();
			$priceHistory
					->setDate(
							\DateTime::createFromFormat("Y-m-d*H:i:s|+",
									$history['Timestamp']));
			$priceHistory->setPrice($history['SpotPrice'] * 100 * 1000);
			$priceHistory->setAvailabilityZone($history['AvailabilityZone']);

			$em->persist($priceHistory);
		}
	}

	// TODO Document me
	private function persistRunHistory($endDate, $em) {
		$runHistory = new RunHistory();
		$runHistory->setDate($endDate);
		$em->persist($runHistory);
	}

	// TODO Document me
	private function getLastRunDate($em) {
		$maxDateQuery = $em
				->createQuery(
						"SELECT rh FROM CloudMarketWatch\BackendBundle\Entity\RunHistory rh ORDER BY rh.date, rh.id DESC");
		$maxDateQuery->setMaxResults(1);
		$maxDateQueryResult = $maxDateQuery->getOneOrNullResult();
		$lastRunDate = null;

		// If we don't have a max date in the database, default to a week ago
		if (is_null($maxDateQueryResult)
				|| is_null($maxDateQueryResult->getDate())) {
			$lastRunDate = new \DateTime();
			$lastRunDate->sub(new \DateInterval("P7D"));
		} else {
			$lastRunDate = $maxDateQueryResult->getDate();
		}

		return $lastRunDate;
	}
}
?>