<?php
namespace CloudMarketWatch\BackendBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="product")
 *
 */
class Product
{
	/** @ORM\Id @ORM\Column(type="bigint") @ORM\GeneratedValue */
	private $id;
	/** @ORM\Column(type="string", length=25) */
	private $instanceType;
	/** @ORM\Column(type="string", length=25) */
	private $platform;

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
     * Set instanceType
     *
     * @param string $instanceType
     * @return Product
     */
    public function setInstanceType($instanceType)
    {
        $this->instanceType = $instanceType;
    
        return $this;
    }

    /**
     * Get instanceType
     *
     * @return string 
     */
    public function getInstanceType()
    {
        return $this->instanceType;
    }

    /**
     * Set platform
     *
     * @param string $platform
     * @return Product
     */
    public function setPlatform($platform)
    {
        $this->platform = $platform;
    
        return $this;
    }

    /**
     * Get platform
     *
     * @return string 
     */
    public function getPlatform()
    {
        return $this->platform;
    }
}