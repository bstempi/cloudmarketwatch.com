<?php
namespace CloudMarketWatch\BackendBundle\Command;

use Aws\Ec2\Ec2Client;
use Aws\Common\Enum\Region;

use CloudMarketWatch\BackendBundle\Entity\PriceHistory;
use CloudMarketWatch\BackendBundle\Entity\Product;
use CloudMarketWatch\BackendBundle\Entity\RunHistory;

use Monolog\Logger;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command that handles scraping for new AWS market prices
 * 
 * This command takes no arguments since all that it needs comes from the container.  It will use the database to figure
 * out the last time that it ran, perform a query against AWS, record the results, and update the database to reflect
 * that it ran.
 */
class AwsUpdateCommand extends ContainerAwareCommand {

	/**
	 * Size of the batches to send to the db
	 * @var int
	 */
	private static $batchSize = 100;
	/**
	 * Entity manager for db access
	 * @var unknown
	 */
	private $em;

	/**
	 * Handle to the lock file
	 * @var resource
	 */
	private $lockFile;

	/**
	 * Name of the lock file
	 * @var string
	 */
	private static $lockFileName = "awsupdate.lock";

	/**
	 * Logger for logging
	 * @var Logger
	 */
	private $logger;

	/**
	 * Map of product types, keyed by instance type and distribution type
	 * @var array of ProductType
	 */
	private $productTypes;

	/**
	 * Static variable to let us know which platform we're working with
	 * @var string
	 */
	private static $platform = "aws";

	// TODO Pull this from the container instead
	/**
	 * Array of regions that we can't query, such as gov't regions
	 * @var array of black-listed regions
	 */
	private static $regionBlacklist = array('us-gov-west-1');

	/**
	 * Registers command with Symfony
	 */
	protected function configure() {
		$this->setName('cloudmarketwatch:awsupdate')
				->setDescription('Grabs the latest market data from AWS');
	}

	/**
	 * Main entry point called by Symfony
	 * 
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {

		$this->em = $this->getContainer()->get('doctrine')->getManager();
		$this->logger = $this->getContainer()->get('logger');

		$this->logger->info("AWS spot price update started");

		// Check to see that we're the only AWS update running
		if ($this->isDuplicateProcessRunning()) {
			$this->logger->info("Duplicate process detected -- exiting");
			return;
		}

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

		// Transaction stuff
		$this->em->beginTransaction();

		foreach ($regions as $currentRegion) {
			$config['region'] = $currentRegion;
			$this->getPricesUsingOptions($config, $startDate, $endDate);
		}

		// Record the fact that we did this operation
		$this->persistRunHistory($endDate);

		// Flush to db
		$this->logger->info("Flushing data to db");
		$this->em->flush();
		$this->em->commit();

		$this->logger->info("Finished");
	}

	/**
	 * Uses the supplied configuration to call the EC2 service and query for prices between the specified dates
	 * 
	 * This method handles calling the service multiple times should the result paginate.  As per Amazon spec, the
	 * prices returned might be outside of the date range.
	 * 
	 * @param array $awsConfig Ec2Client config
	 * @param \DateTime $startDate Date to start search, inclusive
	 * @param \DateTime $endDate Date to end search, inclusive
	 */
	private function getPricesUsingOptions(array $awsConfig,
			\DateTime $startDate, \DateTime $endDate) {
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

		$i = 0;

		do {
			// Make a request
			$this->logger->debug("Making request using token " . $nextToken);
			$awsResponse = $ec2Client
					->describeSpotPriceHistory(
							array("StartTime" => $startDate,
									"EndTime" => $endDate,
									"NextToken" => $nextToken));

			$this->logger
					->debug(
							"Persisting last response; size: "
									. count($awsResponse['SpotPriceHistory']));
			$this
					->persistPrices($awsResponse['SpotPriceHistory'],
							$startDate, $endDate);

			// Are there more?  Then loop again
			$nextToken = $awsResponse['NextToken'];
			$i++;

		} while (!empty($nextToken));

		// Final flush and log message
		$this->em->flush();
		$this->logger->info("Done");
	}

	/**
	 * Detects if another AWS update is running
	 * 
	 * This method uses a file lock to detect if an update is already running.  This implementation is cross-platform.
	 * 
	 * @return boolean true if a dup process is running, false otherwise
	 */
	private function isDuplicateProcessRunning() {
		$this->lockFile = fopen("awsupdate.lock", "w");

		return !flock($this->lockFile, LOCK_NB | LOCK_EX);
	}

	/**
	 * Persists the price histories
	 * 
	 * This method iterates through an array of PriceHistory, checks that the date of each one is acceptable, and
	 * persists.
	 * 
	 * @param array $histories array of PriceHistory
	 * @param \DateTime $startDate Start date to filter with, exclusive
	 * @param \DateTime $endDate End date to filter with, inclusive
	 */
	private function persistPrices(array $histories, \DateTime $startDate,
			\DateTime $endDate) {
		$objectsInCurrentBatch = 0;
		$filteredHistoriesCount = 0;

		foreach ($histories as $history) {

			$product = $this->getProductForHistory($history);

			$priceHistory = new PriceHistory();
			$priceHistory
					->setDate(
							\DateTime::createFromFormat("Y-m-d*H:i:s|+",
									$history['Timestamp']));
			$priceHistory->setPrice(floor($history['SpotPrice'] * 1000000));
			$priceHistory->setAvailabilityZone($history['AvailabilityZone']);
			$priceHistory->setProduct($product);

			// Date check
			if ($priceHistory->getDate() > $startDate
					&& $priceHistory->getDate() <= $endDate) {

				$objectsInCurrentBatch++;
				$this->em->persist($priceHistory);

				if ($objectsInCurrentBatch % AwsUpdateCommand::$batchSize == 0) {
					$this->logger->debug("Flushing batch to db transaction");
					$this->em->flush();
					$this->em->clear($priceHistory);
				}
			} else {
				$filteredHistoriesCount++;
			}
		}

		$this->logger
				->info(
						"Filtered out " . $filteredHistoriesCount
								. " histories that were out of range");
	}

	/**
	 * Gets the Product for the current PriceHistory
	 * 
	 * This method will look at the PriceHistory and attempt to find a Product object that matches.  If no such object
	 * exists, then one will be created.
	 * 
	 * @param array $history A history array from the EC2 response
	 * @return \CloudMarketWatch\BackendBundle\Entity\Product An object representing the product that the price is for
	 */
	private function getProductForHistory(array $history) {

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

	/**
	 * Makes an entry in the database to show that a run completed
	 * 
	 * @param DateTime $endDate Date to record
	 */
	private function persistRunHistory(\DateTime $endDate) {
		$runHistory = new RunHistory();
		$runHistory->setDate($endDate);
		$this->em->persist($runHistory);
	}

	/**
	 * Gets the last end date from teh database
	 * 
	 * @return Ambiguous <NULL, \DateTime, multitype:> null if it none exists, DateTime otherwise
	 */
	private function getLastRunDate() {
		$this->logger->info("Finding the last run date");

		$maxDateQuery = $this->em
				->createQuery(
						"SELECT rh FROM CloudMarketWatch\BackendBundle\Entity\RunHistory rh ORDER BY rh.date DESC");
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

	/**
	 * Gets an array of known Products from the database
	 * 
	 * Because the list of products is small, we don't want to hit the database over and over querying for product
	 * types.  Rather, we want to query for them all and to store them in memory for quick mapping.
	 * 
	 * @return Ambiguous <multitype:, array> null if no entries exists, array of Product otherwise
	 */
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