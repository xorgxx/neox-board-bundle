<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
#[ORM\Entity]
#[ORM\Table(name:'neox_board_widget')]
class Widget
{
 #[ORM\Id,ORM\Column(type:'guid')] private string $id;
 #[ORM\ManyToOne(inversedBy:'widgets')] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Space $space;
 #[ORM\Column(length:100)] #[Assert\NotBlank] #[Assert\Length(max:100)] #[Assert\Regex('/^[a-z][a-z0-9_.-]*$/')] private string $type;
 #[ORM\Column(length:160)] #[Assert\NotBlank] #[Assert\Length(max:160)] private string $title;
 #[ORM\Column(type:'json')] private array $configuration=[];
 #[ORM\Column] private int $position=0;
 #[ORM\Column] private bool $enabled=true;
 public function __construct(Space $space,string $type,string $title){$this->id=(string)Uuid::v7();$this->space=$space;$this->type=$type;$this->title=$title;}
 public function getId():string{return $this->id;}public function getSpace():Space{return $this->space;}public function getType():string{return $this->type;}public function setType(string $v):void{$this->type=$v;}public function getTitle():string{return $this->title;}public function setTitle(string $v):void{$this->title=$v;}public function getConfiguration():array{return $this->configuration;}public function setConfiguration(array $v):void{$this->configuration=$v;}public function isEnabled():bool{return $this->enabled;}public function setEnabled(bool $v):void{$this->enabled=$v;}public function getPosition():int{return $this->position;}public function setPosition(int $v):void{$this->position=$v;}
}
