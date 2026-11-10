<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
#[ORM\Entity]
#[ORM\Table(name:'neox_board_section')]
class Section
{
 #[ORM\Id,ORM\Column(type:'guid')] private string $id;
 #[ORM\ManyToOne(inversedBy:'sections')] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Space $space;
 #[ORM\Column(length:120)] #[Assert\NotBlank] #[Assert\Length(max:120)] private string $title;
 #[ORM\Column] private int $position=0;
 public function __construct(Space $space,string $title){$this->id=(string)Uuid::v7();$this->space=$space;$this->title=$title;}
 public function getId():string{return $this->id;}public function getSpace():Space{return $this->space;}public function setSpace(Space $space):void{$this->space=$space;}public function getTitle():string{return $this->title;}public function setTitle(string $v):void{$this->title=$v;}public function getPosition():int{return $this->position;}public function setPosition(int $v):void{$this->position=$v;}
}
