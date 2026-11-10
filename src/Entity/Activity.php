<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
#[ORM\Entity]
#[ORM\Table(name:'neox_board_activity')]
#[ORM\Index(columns:['board_id','created_at'],name:'idx_neox_board_activity')]
class Activity
{
 #[ORM\Id,ORM\Column(type:'guid')] private string $id;
 #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Board $board;
 #[ORM\Column(length:40)] private string $action;
 #[ORM\Column(length:255)] private string $description;
 #[ORM\Column(type:'datetime_immutable')] private \DateTimeImmutable $createdAt;
 public function __construct(Board $board,string $action,string $description){$this->id=(string)Uuid::v7();$this->board=$board;$this->action=$action;$this->description=$description;$this->createdAt=new \DateTimeImmutable();}
 public function getId():string{return $this->id;}public function getBoard():Board{return $this->board;}public function getAction():string{return $this->action;}public function getDescription():string{return $this->description;}public function getCreatedAt():\DateTimeImmutable{return $this->createdAt;}
}
