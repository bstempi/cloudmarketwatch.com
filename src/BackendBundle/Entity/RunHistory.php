<?php
namespace CloudMarketWatch\BackendBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="aws_scrape_history")
 *
 */
class RunHistory
{
	/** @ORM\Id @ORM\Column(type="bigint") @ORM\GeneratedValue */
	private $id;
	/** @ORM\Column(type="datetime") */
	private $date;

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
     * @return RunHistory
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
}