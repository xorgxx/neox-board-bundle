<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
#[ORM\Entity]
#[ORM\Table(name:'neox_board_link')]
class Link
{
 private const BROWSER_INTERNAL_URL_PATTERN = '~\A(?:about:(?:blank|config|preferences|addons|support|profiles|policies|downloads|logins|home|newtab)(?:[?#][^\s]*)?|(?:chrome|edge|brave|opera|vivaldi)://(?:settings|extensions|flags|history|downloads|bookmarks|password-manager|newtab|version|policy)(?:[/?#][^\s]*)?)\z~iD';
 #[ORM\Id,ORM\Column(type:'guid')] private string $id;
 #[ORM\ManyToOne(inversedBy:'links')] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Space $space;
 #[ORM\Column(length:160)] #[Assert\NotBlank] #[Assert\Length(max:160)] private string $title;
 #[ORM\Column(length:2048)] #[Assert\NotBlank] #[Assert\AtLeastOneOf(constraints:[new Assert\Url(protocols:['http','https']),new Assert\Regex(pattern:self::BROWSER_INTERNAL_URL_PATTERN)],message:'Saisissez une URL web HTTP(S) ou une adresse interne de navigateur prise en charge.')] #[Assert\Length(max:2048)] private string $url;
 #[ORM\Column(length:80)] private string $icon='↗'; #[ORM\Column(length:255, nullable:true)] private ?string $faviconFilename=null; #[ORM\Column] private int $position=0; #[ORM\Column] private bool $favorite=false; #[ORM\Column(type:'datetime_immutable', nullable:true)] private ?\DateTimeImmutable $lastOpenedAt=null; #[ORM\Column(type:'boolean', nullable:true)] private ?bool $availabilityStatus=null; #[ORM\Column(type:'datetime_immutable', nullable:true)] private ?\DateTimeImmutable $availabilityCheckedAt=null;
 public function __construct(string $title='',string $url=''){$this->id=(string)Uuid::v7();$this->title=$title;$this->url=$url;}
 public function getId():string{return $this->id;} public function getSpace():Space{return $this->space;} public function setSpace(Space $s):void{$this->space=$s;} public function getTitle():string{return $this->title;} public function setTitle(string $v):void{$this->title=$v;} public function getUrl():string{return $this->url;} public function setUrl(string $v):void{$this->url=$v;} public function getIcon():string{return $this->icon;} public function setIcon(string $v):void{$this->icon=$v;} public function getFaviconFilename():?string{return $this->faviconFilename;} public function setFaviconFilename(?string $filename):void{$this->faviconFilename=$filename;} public function getPosition():int{return $this->position;} public function setPosition(int $v):void{$this->position=$v;} public function isFavorite():bool{return $this->favorite;} public function toggleFavorite():void{$this->favorite=!$this->favorite;} public function getLastOpenedAt():?\DateTimeImmutable{return $this->lastOpenedAt;} public function setLastOpenedAt(?\DateTimeImmutable $at):void{$this->lastOpenedAt=$at;} public function getAvailabilityStatus():?bool{return $this->availabilityStatus;} public function getAvailabilityCheckedAt():?\DateTimeImmutable{return $this->availabilityCheckedAt;} public function setAvailabilityStatus(?bool $status, ?\DateTimeImmutable $checkedAt=null):void{$this->availabilityStatus=$status;$this->availabilityCheckedAt=$status===null?null:($checkedAt??new \DateTimeImmutable());}

 public function getFallbackColor(): string
 {
  $palette = ['#4f46e5', '#047857', '#b45309', '#be123c', '#0369a1', '#7e22ce', '#0f766e', '#c2410c'];
  return $palette[hexdec(substr(hash('sha256', $this->id), 0, 8)) % count($palette)];
 }

 public function isBrowserInternalUrl(): bool
 {
  return self::isSupportedBrowserInternalUrl($this->url);
 }

 public static function isSupportedBrowserInternalUrl(string $url): bool
 {
  return preg_match(self::BROWSER_INTERNAL_URL_PATTERN, trim($url)) === 1;
 }
}
