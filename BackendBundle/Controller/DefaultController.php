<?php

namespace CloudMarketWatch\BackendBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use CloudMarketWatch\BackendBundle\Entity\PriceHistory;
use Aws\Ec2\Ec2Client;

class DefaultController extends Controller
{
    public function indexAction()
    {
		// Create an array of configuration options
		$config = array(
			'key'    => $this->container->getParameter('aws_key'),
			'secret' => $this->container->getParameter('aws_secret'),
			"region" => "us-east-1"
		);
		
		$ec2Client = Ec2Client::factory($config);
		$em = $this->getDoctrine()->getManager();
		$productRepository = $this->getDoctrine()->getRepository('CloudMarketWatchBackendBundle:Product');

		$response = $ec2Client->describeSpotPriceHistory(array(
			"StartTime" => 1383264000,
			"EndTime" => 1383274000
		));
		
		foreach($response['SpotPriceHistory'] as $history)
		{
			$priceHistory = new PriceHistory();
			$priceHistory->setDate(\DateTime::createFromFormat("Y-m-d*H:i:s|+", $history['Timestamp']));
			$priceHistory->setPrice($history['SpotPrice'] * 100 * 1000);
			$priceHistory->setAvailabilityZone($history['AvailabilityZone']);
			
			$em->persist($priceHistory);
		}
		
		$em->flush();
		
        return $this->render('CloudMarketWatchBackendBundle:Default:index.html.twig', array('name' => 'Done'));
    }
}
