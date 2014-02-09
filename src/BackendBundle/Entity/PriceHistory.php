<?php
namespace CloudMarketWatch\BackendBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="price_history")
 *
 */
class PriceHistory
{
	/** @ORM\Id @ORM\Column(type="bigint") @ORM\GeneratedValue */
	private $id;
	/** @ORM\ManyToOne(targetEntity="Product") @ORM\JoinColumn(name="product_id", referencedColumnName="id") */
	private $product;
	/** @ORM\Column(type="datetime") */
	private $date;
	/** @ORM\Column(type="integer") */
	private $price;
	/** @ORM\Column(type="string", length=25) */
	private $availabilityZone;

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set date
     *
     * @param \DateTime $date
     * @return PriceHistory
     */
    public function setDate($date)
    {
        $this->date = $date;
    
        return $this;
    }

    /**
     * Get date
     *
     * @return \DateTime 
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * Set price
     *
     * @param integer $price
     * @return PriceHistory
     */
    public function setPrice($price)
    {
        $this->price = $price;
    
        return $this;
    }

    /**
     * Get price
     *
     * @return integer 
     */
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Set availabilityZone
     *
     * @param string $availabilityZone
     * @return PriceHistory
     */
    public function setAvailabilityZone($availabilityZone)
    {
        $this->availabilityZone = $availabilityZone;
    
        return $this;
    }

    /**
     * Get availabilityZone
     *
     * @return string 
     */
    public function getAvailabilityZone()
    {
        return $this->availabilityZone;
    }

    /**
     * Set product
     *
     * @param \CloudMarketWatch\BackendBundle\Entity\Product $product
     * @return PriceHistory
     */
    public function setProduct(\CloudMarketWatch\BackendBundle\Entity\Product $product = null)
    {
        $this->product = $product;
    
        return $this;
    }

    /**
     * Get product
     *
     * @return \CloudMarketWatch\BackendBundle\Entity\Product 
     */
    public function getProduct()
    {
        return $this->product;
    }
}