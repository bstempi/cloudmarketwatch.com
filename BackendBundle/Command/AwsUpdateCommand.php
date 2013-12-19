<?php
namespace CloudMarketWatch\BackendBundle\Command;

use Aws\Ec2\Ec2Client;
use Aws\Common\Enum\Region;

use CloudMarketWatch\BackendBundle\Entity\PriceHistory;
use CloudMarketWatch\BackendBundle\Entity\Product;
use CloudMarketWatch\BackendBundle\Entity\RunHistory;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// TODO Add proper logging
class AwsUpdateCommand extends ContainerAwareCommand {

	/**
	 * Entity manager for db access
	 * @var unknown
	 */
	private $em;

	/**
	 * Logger for logging
	 * @var unknown
	 */
	private $logger;

	/**
	 * Map of product types, keyed by instance type and distribution type
	 * @var unknown
	 */
	private $productTypes;

	/**
	 * Static variable to let us know which platform we're working with
	 * @var unknown
	 */
	private static $platform = "aws";

	/**
	 * Array of regions that we can't query, such as gov't regions
	 * @var array of black-listed regions
	 */
	private static $regionBlacklist = array('us-gov-west-1');

	protected function configure() {
		$this->setName('cloudmarketwatch:awsupdate')
				->setDescription('Grabs the latest market data from AWS');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {

		$this->em = $this->getContainer()->get('doctrine')->getManager();
		$this->logger = $this->getContainer()->get('logger');

		$this->logger->info("AWS spot price update started");

		// Create an array of configuration options for AWS
		// TODO Don't hard-code the region in the future
		$config = array(
				'key' => $this->getContainer()->getParameter('aws_key'),
				'secret' => $this->getContainer()->getParameter('aws_secret'),
				'region' => '');

		// Amazon regions
		// Filters out dups and the blacklist
		$regions = array_diff(array_unique(Region::values()),
				AwsUpdateCommand::$regionBlacklist);

		$this->productTypes = $this->getProductTypes();

		// Get the date bounds for our query
		$startDate = $this->getLastRunDate();
		$endDate = new \DateTime();

		foreach ($regions as $currentRegion) {
			$config['region'] = $currentRegion;
			$this->getPricesUsingOptions($config, $startDate, $endDate);
		}

		// Record the fact that we did this operation
		$this->persistRunHistory($endDate);

		// Flush to db
		$this->logger->info("Flushing data to db");
		$this->em->flush();

		$this->logger->info("Finished");
	}

	private function getPricesUsingOptions($awsConfig, \DateTime $startDate,
			\DateTime $endDate) {
		// Amazon client
		$ec2Client = Ec2Client::factory($awsConfig);

		// Prime our request by setting the next token to an empty string
		$nextToken = "";

		$this->logger
				->info(
						"Getting history for region " . $awsConfig['region']
								. " between "
								. $startDate->format(\DateTime::ISO8601)
								. " and "
								. $endDate->format(\DateTime::ISO8601));

		do {
			// Make a request
			$awsResponse = $ec2Client
					->describeSpotPriceHistory(
							array("StartTime" => $startDate,
									"EndTime" => $endDate,
									"NextToken" => $nextToken));

			$this->persistPrices($awsResponse['SpotPriceHistory']);

			// Are there more?  Then loop again
			$nextToken = $awsResponse['NextToken'];

		} while (!empty($nextToken));

		$this->logger->info("Done");
	}

	// TODO Document me
	private function persistPrices($histories) {
		foreach ($histories as $history) {

			$product = $this->getProduct($history);

			$priceHistory = new PriceHistory();
			$priceHistory
					->setDate(
							\DateTime::createFromFormat("Y-m-d*H:i:s|+",
									$history['Timestamp']));
			$priceHistory->setPrice($history['SpotPrice'] * 1000000);
			$priceHistory->setAvailabilityZone($history['AvailabilityZone']);
			$priceHistory->setProduct($product);

			$this->em->persist($priceHistory);
		}
	}

	// TODO Document me
	private function getProduct($history) {

		$instanceType = $history['InstanceType'];
		$distributionType = $history['ProductDescription'];

		if (array_key_exists($instanceType, $this->productTypes)
				&& array_key_exists($distributionType,
						$this->productTypes[$instanceType])) {
			return $this->productTypes[$instanceType][$distributionType];
		} else {
			$this->logger
					->info(
							"Found a new product type: " . $instanceType
									. " -- " . $distributionType);

			$product = new Product();
			$product->setDistributionType($distributionType);
			$product->setInstanceType($instanceType);
			$product->setPlatform(AwsUpdateCommand::$platform);

			$this->productTypes[$instanceType][$distributionType] = $product;
			$this->em->persist($product);

			return $product;
		}
	}

	// TODO Document me
	private function persistRunHistory($endDate) {
		$runHistory = new RunHistory();
		$runHistory->setDate($endDate);
		$this->em->persist($runHistory);
	}

	// TODO Document me
	private function getLastRunDate() {
		$this->logger->info("Finding the last run date");

		$maxDateQuery = $this->em
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

	// TODO Document me
	private function getProductTypes() {
		$this->logger->info("Producing product map");

		$products = $this->em
				->getRepository('CloudMarketWatch\BackendBundle\Entity\Product')
				->findByPlatform(AwsUpdateCommand::$platform);
		$productMap = array();

		foreach ($products as $product) {
			$productMap[$product->getInstanceType()][$product
					->getDistributionType()] = $product;
		}

		return $productMap;
	}
}
?>