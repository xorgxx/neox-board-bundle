<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Entity;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;
#[ORM\Entity]
#[ORM\Table(name: 'neox_board_space')]
class Space
{
 #[ORM\Id,ORM\Column(type:'guid')] private string $id;
 #[ORM\ManyToOne(inversedBy:'spaces')] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Board $board;
 #[ORM\Column(length:120)] private string $title;
 #[ORM\Column(length:255)] private string $description='';
 #[ORM\Column] private int $position=0;
 #[ORM\Column(name:'column_index', options:['default'=>0])] private int $columnIndex=0;
 #[ORM\Column] private bool $favorite=false;
 #[ORM\OneToMany(mappedBy:'space',targetEntity:Link::class,cascade:['persist','remove'],orphanRemoval:true)] #[ORM\OrderBy(['position'=>'ASC'])] private Collection $links;
 #[ORM\OneToMany(mappedBy:'space',targetEntity:Section::class,cascade:['persist','remove'],orphanRemoval:true)] #[ORM\OrderBy(['position'=>'ASC'])] private Collection $sections;
 #[ORM\OneToMany(mappedBy:'space',targetEntity:Widget::class,cascade:['persist','remove'],orphanRemoval:true)] #[ORM\OrderBy(['position'=>'ASC'])] private Collection $widgets;
 public function __construct(string $title='Nouvel espace'){$this->id=(string)Uuid::v7();$this->title=$title;$this->links=new ArrayCollection();$this->sections=new ArrayCollection();$this->widgets=new ArrayCollection();}
 public function getId():string{return $this->id;} public function getBoard():Board{return $this->board;} public function setBoard(Board $b):void{$this->board=$b;}
 public function getTitle():string{return $this->title;} public function setTitle(string $v):void{$this->title=$v;} public function getDescription():string{return $this->description;} public function setDescription(string $v):void{$this->description=$v;} public function getPosition():int{return $this->position;} public function setPosition(int $v):void{$this->position=$v;} public function getColumnIndex():int{return $this->columnIndex;} public function setColumnIndex(int $v):void{$this->columnIndex=$v;} public function isFavorite():bool{return $this->favorite;} public function toggleFavorite():void{$this->favorite=!$this->favorite;} public function getLinks():Collection{return $this->links;} public function addLink(Link $l):void{$this->links->add($l);$l->setSpace($this);} public function removeLink(Link $l):void{$this->links->removeElement($l);} public function getSections():Collection{return $this->sections;} public function addSection(Section $s):void{$this->sections->add($s);$s->setSpace($this);} public function removeSection(Section $s):void{$this->sections->removeElement($s);} public function getWidgets():Collection{return $this->widgets;} public function addWidget(Widget $w):void{$this->widgets->add($w);}
}
