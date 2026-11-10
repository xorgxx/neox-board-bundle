<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Entity;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'neox_board')]
class Board
{
    #[ORM\Id, ORM\Column(type: 'guid')]
    private string $id;
    #[ORM\Column(length: 190)] private string $ownerKey;
    #[ORM\Column(length: 160)] private string $title = 'Mon dashboard';
    #[ORM\Column(type: 'json', nullable: true)] private ?array $weatherLocation = null;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $backgroundSettings = null;
    #[ORM\Column(name: 'widget_settings', type: 'json', nullable: true)] private ?array $widgetSettings = null;
    #[ORM\OneToMany(mappedBy: 'board', targetEntity: Space::class, cascade: ['persist','remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position'=>'ASC'])] private Collection $spaces;
    public function __construct(string $ownerKey) { $this->id=(string)Uuid::v7(); $this->ownerKey=$ownerKey; $this->spaces=new ArrayCollection(); }
    public function getId(): string { return $this->id; } public function getOwnerKey(): string { return $this->ownerKey; }
    public function getTitle(): string { return $this->title; } public function setTitle(string $v): void { $this->title=$v; }
    public function getWeatherLocation(): ?array { return $this->weatherLocation; }
    public function setWeatherLocation(?array $location): void { $this->weatherLocation=$location; }
    public function getBackgroundSettings(): ?array { return $this->backgroundSettings; }
    public function setBackgroundSettings(?array $settings): void { $this->backgroundSettings=$settings; }
    public function isWidgetEnabled(string $name): bool { return ($this->widgetSettings ?? [])[$name] ?? false; }
    public function setWidgetEnabled(string $name, bool $enabled): void { $settings=$this->widgetSettings ?? []; $settings[$name]=$enabled; $this->widgetSettings=$settings; }
    public function getWidgetSettings(): array { return $this->widgetSettings ?? []; }
    public function getWeatherWidgetPlacement(): array { $settings=$this->widgetSettings ?? []; return ['size'=>$settings['weather_size'] ?? 'wide','column'=>$settings['weather_column'] ?? 0,'position'=>$settings['weather_position'] ?? 'top']; }
    public function setWeatherWidgetPlacement(string $size, int $column, string $position): void { $settings=$this->widgetSettings ?? []; $settings['weather_size']=$size; $settings['weather_column']=$column; $settings['weather_position']=$position; $this->widgetSettings=$settings; }
    public function getSpaces(): Collection { return $this->spaces; }
    public function addSpace(Space $space): void { if (!$this->spaces->contains($space)) {$this->spaces->add($space);$space->setBoard($this);} }
}
