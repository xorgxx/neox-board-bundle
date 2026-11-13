<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Controller;
use Doctrine\ORM\EntityManagerInterface;
use Neox\BoardBundle\Entity\{Board,Space,Link,Activity,Section,Widget};
use Neox\BoardBundle\Realtime\DashboardBroadcaster;
use Neox\BoardBundle\Service\FaviconFetcher;
use Neox\BoardBundle\Service\LinkAvailabilityChecker;
use Neox\BoardBundle\Service\WeatherForecastProvider;
use Neox\BoardBundle\Service\CalendarObservancesProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,RedirectResponse,JsonResponse};
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[Route('/neox-board', name:'neox_board_')]
final class DashboardController extends AbstractController
{
 private const MAX_EXTERNAL_DROP_LINKS = 100;
 public function __construct(private EntityManagerInterface $em, private ValidatorInterface $validator, private CsrfTokenManagerInterface $csrf, private DashboardBroadcaster $broadcaster, private FaviconFetcher $favicons, private WeatherForecastProvider $weather, private CalendarObservancesProvider $observances, private KernelInterface $kernel){}
 private function board(): Board { $u=$this->getUser(); if(!$u instanceof UserInterface || !method_exists($u,'getUserIdentifier')) throw $this->createAccessDeniedException(); $key=$u->getUserIdentifier(); $repo=$this->em->getRepository(Board::class); $board=$repo->findOneBy(['ownerKey'=>$key]); if(!$board){$board=new Board($key);$this->em->persist($board);$this->em->flush();} return $board; }
 #[Route('',name:'index',methods:['GET'])]
 #[Route('/spaces',name:'spaces',methods:['GET'])]
 #[Route('/favorites',name:'favorites',methods:['GET'])]
 #[Route('/activity',name:'activity',methods:['GET'])]
 #[Route('/settings',name:'settings',methods:['GET'])]
 #[Route('/widgets',name:'widgets',methods:['GET'])]
 #[Route('/help',name:'help',methods:['GET'])]
 #[Route('/spaces/{spaceId}',name:'space_view',methods:['GET'],requirements:['spaceId'=>'[0-9a-fA-F-]{36}'])]
 public function index(Request $request): Response
 {
  $board = $this->board();
  $savedWeatherLocation = $board->getWeatherLocation();
  $weatherLocation = $savedWeatherLocation ?? $this->weather->getDefaultLocation();
  $spaces = $board->getSpaces()->toArray();
  $query = trim((string) $request->query->get('q', ''));
  $dateFilter = null;
  $searchTerm = $query;
  if (preg_match('/(?:^|\\s)@(days?|d|hours?|h|weeks?|w|months?|mo|m|years?|y|minutes?|mins?|min):(\\d+)(?=\\s|$)/i', $query, $dateMatch) === 1) {
   $unit = match (strtolower($dateMatch[1])) {
    'd', 'day', 'days' => 'days',
    'h', 'hour', 'hours' => 'hours',
    'w', 'week', 'weeks' => 'weeks',
    'm', 'mo', 'month', 'months' => 'months',
    'y', 'year', 'years' => 'years',
    'min', 'mins', 'minute', 'minutes' => 'minutes',
   };
   $amount = min((int) $dateMatch[2], 10000);
   if ($unit === 'months' || $unit === 'years') {
    $dateFilter = (new \DateTimeImmutable())->modify('-'.$amount.' '.$unit);
   } else {
    $unitSeconds = ['days' => 86400, 'hours' => 3600, 'weeks' => 604800, 'minutes' => 60];
    $dateFilter = new \DateTimeImmutable('-'.($amount * $unitSeconds[$unit]).' seconds');
   }
   $searchTerm = trim(str_replace($dateMatch[0], ' ', $query));
  }
  $routePanel = match ((string) $request->attributes->get('_route')) {
   'neox_board_spaces' => 'spaces',
   'neox_board_favorites' => 'favorites',
   'neox_board_activity' => 'activity',
   'neox_board_settings' => 'settings',
   'neox_board_widgets' => 'widgets',
   'neox_board_help' => 'help',
   'neox_board_space_view' => 'space',
   default => null,
  };
  $panel = $query !== '' ? 'search' : ($routePanel ?? (string) $request->query->get('panel', 'overview'));
  if (!in_array($panel, ['overview', 'spaces', 'favorites', 'space', 'activity', 'widgets', 'settings', 'help', 'search'], true)) {
   $panel = 'overview';
  }

  $selectedSpace = null;
  if ($panel === 'space') {
   $spaceId = (string) ($request->attributes->get('spaceId') ?? $request->query->get('space', ''));
   foreach ($spaces as $candidate) {
    if ((string) $candidate->getId() === $spaceId) {
     $selectedSpace = $candidate;
     break;
    }
   }
   if ($selectedSpace === null) {
    $panel = 'overview';
   }
  }

  $searchSpaceIds = [];
  $searchLinkIds = [];
  $searchSectionIds = [];
  $displaySpaces = $panel === 'space' && $selectedSpace !== null ? [$selectedSpace] : $spaces;
  if ($panel === 'search') {
   $displaySpaces = [];
   foreach ($spaces as $space) {
    $spaceMatches = $dateFilter === null && $searchTerm !== '' && (mb_stripos($space->getTitle(), $searchTerm) !== false || mb_stripos($space->getDescription(), $searchTerm) !== false);
    $matchingLinks = [];
    $orderedItems = [];
    foreach ($space->getLinks() as $link) {
     $orderedItems[] = ['type' => 'link', 'position' => $link->getPosition(), 'id' => (string) $link->getId(), 'item' => $link];
    }
    foreach ($space->getSections() as $section) {
     $orderedItems[] = ['type' => 'section', 'position' => $section->getPosition(), 'id' => (string) $section->getId(), 'item' => $section];
    }
    usort($orderedItems, static fn (array $a, array $b): int => ($a['position'] <=> $b['position']) ?: strcmp($a['id'], $b['id']));
    $currentSectionId = null;
    foreach ($orderedItems as $entry) {
     if ($entry['type'] === 'section') {
      $currentSectionId = $entry['id'];
      continue;
     }
     $link = $entry['item'];
     $textMatches = $searchTerm === '' || mb_stripos($link->getTitle(), $searchTerm) !== false || mb_stripos($link->getUrl(), $searchTerm) !== false;
     $dateMatches = $dateFilter === null || ($link->getLastOpenedAt() !== null && $link->getLastOpenedAt() >= $dateFilter);
     if ($textMatches && $dateMatches) {
      $matchingLinks[] = $entry['id'];
      if ($currentSectionId !== null) $searchSectionIds[] = $currentSectionId;
     }
    }
    if ($spaceMatches || $matchingLinks !== []) {
     $displaySpaces[] = $space;
     if ($spaceMatches) {
      $searchSpaceIds[] = (string) $space->getId();
     } else {
      array_push($searchLinkIds, ...$matchingLinks);
     }
    }
   }
  }
  $searchSectionIds = array_values(array_unique($searchSectionIds));

  $menuItems = [];
  foreach ($spaces as $menuSpace) {
   $entries = [];
   foreach ($menuSpace->getLinks() as $menuLink) {
    $entries[] = ['type' => 'link', 'position' => $menuLink->getPosition(), 'item' => $menuLink];
   }
   foreach ($menuSpace->getSections() as $menuSection) {
    $entries[] = ['type' => 'section', 'position' => $menuSection->getPosition(), 'item' => $menuSection];
   }
   usort($entries, static fn (array $a, array $b): int => ($a['position'] <=> $b['position']) ?: strcmp($a['item']->getId(), $b['item']->getId()));
   $menuItems[(string) $menuSpace->getId()] = $entries;
  }

  $links = 0;
  $favorites = 0;
  foreach ($spaces as $space) {
   $links += $space->getLinks()->count();
   if ($space->isFavorite()) {
    ++$favorites;
   }
   foreach ($space->getLinks() as $link) {
    if ($link->isFavorite()) {
     ++$favorites;
    }
   }
  }

  $activityRows = [];
  foreach ($this->em->getRepository(Activity::class)->findBy(['board' => $board], ['createdAt' => 'DESC'], 8) as $activity) {
   $activityRows[] = ['icon' => '↗', 'title' => $activity->getDescription(), 'detail' => '', 'time' => $activity->getCreatedAt()->format('d/m H:i')];
  }

  $now = new \DateTimeImmutable('now', new \DateTimeZone($this->weather->getTimezone($weatherLocation)));
  $weekdays = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
  $months = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
  $dateLabel = $weekdays[(int) $now->format('N')].' '.$now->format('j').' '.$months[(int) $now->format('n')];

  return $this->render('@NeoxBoard/dashboard/index.html.twig', [
   'spaces' => $spaces,
   'display_spaces' => $displaySpaces,
   'search_space_ids' => $searchSpaceIds,
   'search_link_ids' => $searchLinkIds,
   'search_section_ids' => $searchSectionIds,
   'menu_items' => $menuItems,
   'selected_space' => $selectedSpace,
   'user_name' => $this->getUser()->getUserIdentifier(),
   'links_count' => $links,
   'favorites_count' => $favorites,
   'activities' => $activityRows,
   'query' => $query,
   'panel' => $panel,
   'mercure_url' => $this->broadcaster->subscriberUrl($board),
   'weather_forecast' => $this->weather->getForecast($weatherLocation),
   'weather_location' => $weatherLocation,
   'background_settings' => $board->getBackgroundSettings() ?? ['mode' => 'default', 'color' => '#0b1220'],
   'weather_widget_enabled' => $board->isWidgetEnabled('weather'),
   'weather_widget_placement' => $board->getWeatherWidgetPlacement(),
   'date_label' => $dateLabel,
   'calendar_observances' => $this->observances->getForDate(
    $now,
    $savedWeatherLocation === null ? null : (string) ($weatherLocation['school_city'] ?? ''),
    $savedWeatherLocation === null ? null : (string) ($weatherLocation['school_zone'] ?? ''),
    (string) ($weatherLocation['country_code'] ?? 'FR'),
    $this->weather->getTimezone($weatherLocation),
   ),
  ]);
 }

 #[Route('/widgets/weather/toggle', name:'widgets_weather_toggle', methods:['POST'])]
 public function toggleWeatherWidget(Request $request): Response
 {
  $board = $this->board();
  if (!$this->validToken('widget_weather_toggle', $request)) throw $this->createAccessDeniedException();
  $board->setWidgetEnabled('weather', $request->request->getBoolean('enabled'));
  $this->em->flush();
  $requestId = $this->refreshRequestId($request);
  $this->broadcaster->publishRefresh($board, $requestId);
  if ($request->headers->has('Turbo-Frame') || str_contains((string) $request->headers->get('Accept'), 'text/vnd.turbo-stream.html')) {
   return $this->refreshResponse();
  }
  return $this->redirectToRoute('neox_board_widgets');
 }

 #[Route('/widgets/weather/layout', name:'widgets_weather_layout', methods:['POST'])]
 public function updateWeatherWidgetLayout(Request $request): Response
 {
  $board = $this->board();
  if (!$this->validToken('widget_weather_layout', $request)) throw $this->createAccessDeniedException();
  $size = (string) $request->request->get('size', 'wide');
  $column = (int) $request->request->get('column', 0);
  $position = (string) $request->request->get('position', 'top');
  if (!in_array($size, ['wide', 'column'], true) || !in_array($column, [0, 1], true) || !in_array($position, ['top', 'bottom'], true)) {
   throw new BadRequestHttpException('Disposition de widget invalide.');
  }
  $board->setWeatherWidgetPlacement($size, $column, $position);
  $this->em->flush();
  $requestId = $this->refreshRequestId($request);
  $this->broadcaster->publishRefresh($board, $requestId);
  if ($request->headers->has('Turbo-Frame') || str_contains((string) $request->headers->get('Accept'), 'text/vnd.turbo-stream.html')) {
   return $this->refreshResponse();
  }
  return $this->redirectToRoute('neox_board_widgets');
 }

 #[Route('/spaces/order',name:'spaces_reorder',methods:['POST'])]
 public function reorderSpaces(Request $request): Response
 {
  $board = $this->board();
  if (!$this->validToken('spaces_reorder', $request)) {
   throw $this->createAccessDeniedException();
  }

  $spaces = $board->getSpaces()->toArray();
  $expected = array_map(static fn (Space $space): string => $space->getId(), $spaces);
  $columns = $request->request->all('columns');
  if (array_diff(array_keys($columns), [0, 1]) !== []) throw new BadRequestHttpException('Une colonne inconnue a été fournie.');
  $columns = [0 => $columns[0] ?? [], 1 => $columns[1] ?? []];
  if (!is_array($columns[0]) || !is_array($columns[1])) throw new BadRequestHttpException('Les deux colonnes doivent être des listes.');
  foreach ($columns as $column) {
   if (!array_is_list($column) || count(array_filter($column, static fn ($id): bool => !is_string($id))) > 0) throw new BadRequestHttpException('Une colonne contient un identifiant invalide.');
  }
  $order = [...$columns[0], ...$columns[1]];
  if (!$this->isValidOrder($order, $expected)) throw new BadRequestHttpException('La liste réordonnée ne correspond pas aux espaces de ce tableau.');

  $byId = [];
  foreach ($spaces as $space) {
   $byId[$space->getId()] = $space;
  }
  foreach ([0 => $columns[0], 1 => $columns[1]] as $columnIndex => $columnSpaces) {
   foreach ($columnSpaces as $position => $spaceId) {
    $byId[$spaceId]->setColumnIndex($columnIndex);
    $byId[$spaceId]->setPosition($position);
   }
  }

  $this->em->flush();
  return $this->completeOrderMutation($request);
 }

 #[Route('/settings/weather-location', name:'settings_weather_location', methods:['POST'])]
 public function updateWeatherLocation(Request $request): Response
 {
  $board = $this->board();
  if (!$this->csrf->isTokenValid(new CsrfToken('weather_location', (string) $request->request->get('_token')))) {
   throw $this->createAccessDeniedException();
  }

  $locationName = trim((string) $request->request->get('location', ''));
  $location = $this->weather->geocode($locationName);
  if ($location === null) {
   $this->addFlash('weather_location_error', 'Ville introuvable. Essaie avec une ville et son pays, par exemple « Lyon, France ».');
   return $this->redirectToRoute('neox_board_settings');
  }

  $board->setWeatherLocation($location);
  $this->em->flush();

  return $this->completeMutation($request);
 }

 #[Route('/settings/background', name:'settings_background', methods:['POST'])]
 public function updateBackground(Request $request): Response
 {
  $board = $this->board();
  if (!$this->validToken('dashboard_background', $request)) throw $this->createAccessDeniedException();

  $mode = (string) $request->request->get('background_mode', 'color');
  $color = strtoupper(trim((string) $request->request->get('background_color', '#0B1220')));
  if (!in_array($mode, ['default', 'color', 'image'], true) || preg_match('/^#(?:[0-9A-F]{3}|[0-9A-F]{6})$/', $color) !== 1) {
   $this->addFlash('background_error', 'Choisis une couleur valide et un mode d’arrière-plan reconnu.');
   return $this->redirectToRoute('neox_board_settings');
  }

  $previous = $board->getBackgroundSettings() ?? [];
  $filename = isset($previous['image']) && is_string($previous['image']) ? $previous['image'] : null;
  $upload = $request->files->get('background_image');
  if ($upload !== null && !$upload instanceof UploadedFile) {
   $this->addFlash('background_error', 'Le fichier transmis est invalide.');
   return $this->redirectToRoute('neox_board_settings');
  }
  if ($upload instanceof UploadedFile) {
   $validationError = $this->validateBackgroundUpload($upload);
   if ($validationError !== null) {
    $this->addFlash('background_error', $validationError);
    return $this->redirectToRoute('neox_board_settings');
   }
   $extension = match ($upload->getMimeType()) {'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'};
   $filename = bin2hex(random_bytes(16)).'.'.$extension;
   $directory = $this->backgroundDirectory();
   if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new \RuntimeException('Impossible de créer le stockage des fonds du dashboard.');
   }
   $upload->move($directory, $filename);
  }
  if ($request->request->getBoolean('remove_background_image')) $filename = null;
  if ($mode === 'image' && $filename === null) {
   $this->addFlash('background_error', 'Sélectionne une image avant d’activer le fond image.');
   return $this->redirectToRoute('neox_board_settings');
  }

  $board->setBackgroundSettings(['mode' => $mode, 'color' => $color, 'image' => $filename]);
  $this->em->flush();
  if ($previous['image'] ?? null && $previous['image'] !== $filename) $this->deleteBackgroundFile((string) $previous['image']);
  return $this->completeMutation($request);
 }

 #[Route('/settings/background/{filename}', name:'background_image', methods:['GET'], requirements:['filename' => '[a-f0-9]{32}\\.(?:jpg|png|webp)'])]
 public function backgroundImage(string $filename): Response
 {
  $settings = $this->board()->getBackgroundSettings() ?? [];
  if (($settings['image'] ?? null) !== $filename) throw $this->createNotFoundException();
  $path = $this->backgroundDirectory().DIRECTORY_SEPARATOR.$filename;
  if (!is_file($path)) throw $this->createNotFoundException();
  $response = new BinaryFileResponse($path);
  $response->headers->set('Content-Type', match (pathinfo($filename, PATHINFO_EXTENSION)) {'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'});
  $response->setPrivate();
  $response->setMaxAge(3600);
  $response->headers->set('X-Content-Type-Options', 'nosniff');
  return $response;
 }

 private function validateBackgroundUpload(UploadedFile $file): ?string
 {
  if (!$file->isValid() || $file->getSize() === false || $file->getSize() > 5 * 1024 * 1024) return 'L’image doit être valide et ne pas dépasser 5 Mo.';
  $mime = $file->getMimeType();
  if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return 'Formats acceptés : JPEG, PNG ou WebP. Les fichiers SVG ne sont pas acceptés.';
  $image = @getimagesize($file->getPathname());
  if (!is_array($image) || ($image['mime'] ?? null) !== $mime || $image[0] > 8000 || $image[1] > 8000) return 'Le fichier ne contient pas une image valide ou ses dimensions dépassent 8 000 × 8 000 px.';
  return null;
 }

 private function backgroundDirectory(): string { return $this->kernel->getProjectDir().'/var/neox-board/backgrounds'; }
 private function deleteBackgroundFile(string $filename): void
 {
  if (preg_match('/^[a-f0-9]{32}\\.(?:jpg|png|webp)$/', $filename) !== 1) return;
  $path = $this->backgroundDirectory().DIRECTORY_SEPARATOR.$filename;
  if (is_file($path)) @unlink($path);
 }

 #[Route('/spaces/new',name:'space_new',methods:['GET','POST'])]
 public function newSpace(Request $r):Response { $board=$this->board();$space=new Space();$error=null;if($r->isMethod('POST')){if(!$this->csrf->isTokenValid(new CsrfToken('space_new',(string)$r->request->get('_token'))))throw $this->createAccessDeniedException();$title=trim((string)$r->request->get('title'));$desc=trim((string)$r->request->get('description'));if($title===''||mb_strlen($title)>120)$error='Le nom est requis et limité à 120 caractères.';else{$space->setTitle($title);$space->setDescription(mb_substr($desc,0,255));$counts=[0=>0,1=>0];$positions=[0=>-1,1=>-1];foreach($board->getSpaces() as $existing){$column=$existing->getColumnIndex();$counts[$column]++;$positions[$column]=max($positions[$column],$existing->getPosition());}$column=$counts[0]<=$counts[1]?0:1;$space->setColumnIndex($column);$space->setPosition($positions[$column]+1);$board->addSpace($space);$this->em->flush();return $this->completeMutation($r);}}return $this->render('@NeoxBoard/dashboard/form.html.twig',['kind'=>'Espace','title'=>'Nouvel espace','space'=>$space,'error'=>$error,'token'=>$this->csrf->getToken('space_new')->getValue()]); }
 #[Route('/spaces/{id}/links/drop-token',name:'link_drop_token',methods:['GET'])]
 public function refreshDroppedLinkToken(string $id): JsonResponse
 {
  $this->ownedSpace($id);
  $response = new JsonResponse(['token' => $this->csrf->getToken('link_new_'.$id)->getValue()]);
  $response->headers->set('Cache-Control', 'private, no-store');
  return $response;
 }
 #[Route('/spaces/{id}/edit',name:'space_edit',methods:['GET','POST'])]
 public function editSpace(string $id,Request $r):Response { $space=$this->ownedSpace($id);$error=null;if($r->isMethod('POST')){if(!$this->csrf->isTokenValid(new CsrfToken('space_edit_'.$id,(string)$r->request->get('_token'))))throw $this->createAccessDeniedException();$title=trim((string)$r->request->get('title'));if($title===''||mb_strlen($title)>120)$error='Le nom est requis et limité à 120 caractères.';else{$space->setTitle($title);$space->setDescription(mb_substr(trim((string)$r->request->get('description')),0,255));$this->em->flush();return $this->completeMutation($r);}}return $this->render('@NeoxBoard/dashboard/form.html.twig',['kind'=>'Espace','title'=>'Modifier l’espace','space'=>$space,'error'=>$error,'token'=>$this->csrf->getToken('space_edit_'.$id)->getValue()]); }
 #[Route('/spaces/{id}/delete',name:'space_delete',methods:['POST'])]
 public function deleteSpace(string $id,Request $r):Response { $s=$this->ownedSpace($id);if(!$this->csrf->isTokenValid(new CsrfToken('space_delete_'.$id,(string)$r->request->get('_token'))))throw $this->createAccessDeniedException();$this->em->remove($s);$this->em->flush();return $this->completeMutation($r); }
 #[Route('/spaces/{id}/links/check',name:'link_drop_check',methods:['POST'])]
 public function checkDroppedLink(string $id, Request $r): JsonResponse
 {
  $space = $this->ownedSpace($id);
  if (!$this->validToken('link_new_'.$id, $r)) throw $this->createAccessDeniedException();
  if (!$this->validDroppedSection($space, (string) $r->request->get('section_id', ''))) return new JsonResponse(['valid' => false, 'duplicate' => false, 'message' => 'Cette section est introuvable.'], 422);
  $input = $this->droppedLinkInputs($r);
  if (count($input) > self::MAX_EXTERNAL_DROP_LINKS) return new JsonResponse(['valid' => false, 'duplicate' => false, 'message' => 'Un dépôt est limité à 100 liens.'], 413);
  $inspection = $this->inspectDroppedLinks($space, $input);
  if ($inspection['invalid'] > 0) return new JsonResponse(['valid' => false, 'duplicate' => false, 'message' => $inspection['message'], 'items' => $inspection['items']], 422);
  if ($inspection['ready'] === []) return new JsonResponse(['valid' => false, 'duplicate' => true, 'message' => 'Tous ces liens existent déjà dans votre tableau.', 'items' => $inspection['items']], 409);
  // Cached clients use `message || fallback`; a zero-width success value avoids a false error.
  $message = count($input) === 1
   ? "\u{200B}"
   : sprintf('%d lien%s prêt%s à ajouter%s.', count($inspection['ready']), count($inspection['ready']) > 1 ? 's' : '', count($inspection['ready']) > 1 ? 's' : '', $inspection['duplicates'] > 0 ? sprintf(' · %d doublon%s ignoré%s', $inspection['duplicates'], $inspection['duplicates'] > 1 ? 's' : '', $inspection['duplicates'] > 1 ? 's' : '') : '');
  return new JsonResponse(['valid' => true, 'duplicate' => false, 'message' => $message, 'readyCount' => count($inspection['ready']), 'duplicateCount' => $inspection['duplicates'], 'items' => $inspection['items']]);
 }

 #[Route('/spaces/{id}/links/drop',name:'link_drop',methods:['POST'])]
 public function createDroppedLink(string $id, Request $r, LinkAvailabilityChecker $checker): Response
 {
  $space = $this->ownedSpace($id);
  if (!$this->validToken('link_new_'.$id, $r)) throw $this->createAccessDeniedException();
  $sectionId = (string) $r->request->get('section_id', '');
  if (!$this->validDroppedSection($space, $sectionId)) return new JsonResponse(['valid' => false, 'duplicate' => false, 'message' => 'Cette section est introuvable.'], 422);
  $input = $this->droppedLinkInputs($r);
  if (count($input) > self::MAX_EXTERNAL_DROP_LINKS) return new JsonResponse(['valid' => false, 'duplicate' => false, 'message' => 'Un dépôt est limité à 100 liens.'], 413);
  $inspection = $this->inspectDroppedLinks($space, $input);
  if ($inspection['invalid'] > 0) return new JsonResponse(['valid' => false, 'duplicate' => false, 'message' => $inspection['message'], 'items' => $inspection['items']], 422);
  if ($inspection['ready'] === []) return new JsonResponse(['valid' => false, 'duplicate' => true, 'message' => 'Tous ces liens existent déjà dans votre tableau.', 'items' => $inspection['items']], 409);
  foreach ($inspection['ready'] as $link) {
   $this->positionDroppedLink($space, $link, $sectionId);
   // Check a single dropped link immediately; large drops stay asynchronous by batch.
   if (count($inspection['ready']) === 1) $this->inspectAddedLink($link, $checker);
   $space->addLink($link);
  }
  $this->em->flush();
  return $this->completeMutation($r);
 }

 #[Route('/spaces/{id}/links/new',name:'link_new',methods:['GET','POST'])]
 public function newLink(string $id, Request $request, LinkAvailabilityChecker $checker): Response
 {
  $space = $this->ownedSpace($id);
  $link = new Link();
  $error = null;
  if ($request->isMethod('POST')) {
   if (!$this->csrf->isTokenValid(new CsrfToken('link_new_'.$id, (string) $request->request->get('_token'))) ) {
    throw $this->createAccessDeniedException();
   }
   $link->setTitle(trim((string) $request->request->get('title')));
   $link->setUrl($this->buildLinkUrl((string) $request->request->get('url', ''), (string) $request->request->get('url_scheme', 'https')));
   if (Link::isSupportedBrowserInternalUrl($link->getUrl())) {
    $error = 'Les pages internes du navigateur sont disponibles dans Aide, pas comme raccourcis de lien.';
   } else {
    $errors = $this->validator->validate($link);
    if (count($errors) > 0) {
     $error = (string) $errors[0]->getMessage();
    } elseif ($this->linkUrlExists($space->getBoard(), $link->getUrl())) {
     $error = 'Ce lien existe déjà dans votre tableau.';
    } else {
     $link->setPosition($this->nextMenuPosition($space));
     $this->inspectAddedLink($link, $checker);
     $space->addLink($link);
     $this->em->flush();
     return $this->completeMutation($request);
    }
   }
  }

  return $this->render('@NeoxBoard/dashboard/form.html.twig', [
   'kind' => 'Lien',
   'title' => 'Ajouter un raccourci',
   'space' => $space,
   'link' => $link,
   'error' => $error,
   'token' => $this->csrf->getToken('link_new_'.$id)->getValue(),
  ]);
 }

 #[Route('/links/{id}/favicon',name:'link_favicon',methods:['GET'],requirements:['id'=>'[0-9a-fA-F-]{36}'])]
 public function linkFavicon(string $id): Response
 {
  $link = $this->ownedLink($id);
  $filename = $link->getFaviconFilename();
  $path = $filename === null ? null : $this->favicons->getPath($filename);
  if ($path === null) throw $this->createNotFoundException();
  $response = new BinaryFileResponse($path);
  $response->setPublic();
  $response->setMaxAge(604800);
  $response->headers->set('X-Content-Type-Options', 'nosniff');
  if (str_ends_with(strtolower($filename), '.svg')) {
   $response->headers->set('Content-Type', 'image/svg+xml');
   $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
  }
  return $response;
 }
 #[Route('/links/check-availability', name:'links_check_availability', methods:['POST'])]
 public function checkLinksAvailability(Request $request, LinkAvailabilityChecker $checker): JsonResponse
 {
  if (!$this->validToken('links_check_availability', $request)) throw $this->createAccessDeniedException();
  $board = $this->board();
  $links = [];
  foreach ($board->getSpaces() as $space) {
   foreach ($space->getLinks() as $link) $links[] = $link;
  }

  if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
   $total = count($links);
   $offset = min(max(0, $request->request->getInt('offset')), $total);
   $batch = array_slice($links, $offset, 2);
   $results = $checker->check($batch);
   $checkedAt = new \DateTimeImmutable();
   $faviconsFound = 0;
   foreach ($results as $index => $result) {
    $link = $batch[$index];
    $link->setAvailabilityStatus($result['active'], $checkedAt);
    $hadFavicon = $link->getFaviconFilename() !== null && $this->favicons->getPath($link->getFaviconFilename()) !== null;
    if (!$hadFavicon) $this->favicons->fetchFor($link);
    if (!$hadFavicon && $link->getFaviconFilename() !== null && $this->favicons->getPath($link->getFaviconFilename()) !== null) $faviconsFound++;
   }
   $this->em->flush();
   $processed = $offset + count($batch);
   $done = $processed >= $total;
   if ($done && $total > 0) $this->broadcaster->publishRefresh($board, $this->refreshRequestId($request));
   $active = count(array_filter($results, static fn (array $result): bool => $result['active']));
   $response = new JsonResponse(['checked' => count($results), 'active' => $active, 'results' => $results, 'faviconsFound' => $faviconsFound, 'total' => $total, 'processed' => $processed, 'done' => $done]);
   $response->headers->set('Cache-Control', 'private, no-store');
   return $response;
  }

  $results = $checker->check($links);
  $checkedAt = new \DateTimeImmutable();
  foreach ($results as $index => $result) {
   $link = $links[$index];
   $link->setAvailabilityStatus($result['active'], $checkedAt);
   if ($link->getFaviconFilename() === null || $this->favicons->getPath($link->getFaviconFilename()) === null) $this->favicons->fetchFor($link);
  }
  $this->em->flush();
  $active = count(array_filter($results, static fn (array $result): bool => $result['active']));
  return new JsonResponse(['checked' => count($results), 'active' => $active, 'results' => $results]);
 }
 #[Route('/links/{id}/open',name:'link_open',methods:['GET'],requirements:['id'=>'[0-9a-fA-F-]{36}'])]
 public function openLink(string $id): Response
 {
  $board = $this->board();
  $link = $this->em->getRepository(Link::class)->find($id);
  if (!$link instanceof Link || $link->getSpace()->getBoard()->getOwnerKey() !== $board->getOwnerKey()) {
   throw $this->createNotFoundException();
  }
  $url = $link->getUrl();
  $parts = parse_url($url);
  if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
   throw $this->createNotFoundException();
  }
  $link->setLastOpenedAt(new \DateTimeImmutable());
  $this->em->flush();
  return $this->redirect($url);
 }

 #[Route('/links/{id}/delete',name:'link_delete',methods:['POST'])]
 public function deleteLink(string $id,Request $r):Response { $link=$this->em->getRepository(Link::class)->find($id);if(!$link||$link->getSpace()->getBoard()->getOwnerKey()!==$this->board()->getOwnerKey())throw $this->createNotFoundException();if(!$this->csrf->isTokenValid(new CsrfToken('link_delete_'.$id,(string)$r->request->get('_token'))))throw $this->createAccessDeniedException();$this->em->remove($link);$this->em->flush();return $this->completeMutation($r); }
 #[Route('/links/{id}/edit',name:'link_edit',methods:['GET','POST'])]
 public function editLink(string $id,Request $r):Response{$link=$this->ownedLink($id);$error=null;if($r->isMethod('POST')){if(!$this->csrf->isTokenValid(new CsrfToken('link_edit_'.$id,(string)$r->request->get('_token'))))throw $this->createAccessDeniedException();$previousUrl=$link->getUrl();$link->setTitle(trim((string)$r->request->get('title')));$link->setUrl($this->buildLinkUrl((string)$r->request->get('url', ''), (string)$r->request->get('url_scheme', 'https')));$errors=$this->validator->validate($link);if(count($errors))$error=(string)$errors[0]->getMessage();else{if($previousUrl!==$link->getUrl()){$link->setFaviconFilename(null);$link->setAvailabilityStatus(null);$this->favicons->fetchFor($link);}$this->em->flush();return $this->completeMutation($r);}}return $this->render('@NeoxBoard/dashboard/form.html.twig',['kind'=>'Lien','title'=>'Modifier le raccourci','space'=>$link->getSpace(),'link'=>$link,'error'=>$error,'token'=>$this->csrf->getToken('link_edit_'.$id)->getValue()]);}
 #[Route('/links/{id}/favorite',name:'link_favorite',methods:['POST'])]
 public function toggleLinkFavorite(string $id,Request $r):Response{$l=$this->ownedLink($id);if(!$this->csrf->isTokenValid(new CsrfToken('link_favorite_'.$id,(string)$r->request->get('_token'))))throw $this->createAccessDeniedException();$l->toggleFavorite();$this->em->flush();return $this->completeMutation($r);}
 #[Route('/spaces/{id}/reorder',name:'space_reorder',methods:['POST'])]
 public function reorderLinks(string $id, Request $r): Response
 {
  $space = $this->ownedSpace($id);
  if (!$this->validToken('space_reorder_'.$id, $r)) {
   throw $this->createAccessDeniedException();
  }

  $items = $r->request->all('items');
  $targetMap = $this->menuEntityMap($space);
  $sourceSpaceId = (string) $r->request->get('sourceSpaceId', '');
  $sourceSpace = null;
  $sourceMap = [];
  $sourceItems = [];
  $movedKeys = [];

  if ($sourceSpaceId !== '' && $sourceSpaceId !== $id) {
   $sourceSpace = $this->ownedSpace($sourceSpaceId);
   $sourceMap = $this->menuEntityMap($sourceSpace);
   $sourceItems = $r->request->all('sourceItems');
   if (!$this->isValidSubset($sourceItems, array_keys($sourceMap))) {
    throw new BadRequestHttpException('La liste source ne correspond pas aux éléments de cet espace.');
   }

   $movedKeys = array_values(array_diff(array_keys($sourceMap), $sourceItems));
   if ($movedKeys === [] || !$this->isValidOrder($items, array_merge(array_keys($targetMap), $movedKeys))) {
    throw new BadRequestHttpException('Le transfert ne correspond pas aux éléments déplacés.');
   }
   $this->validateSectionTransfer($sourceMap, $movedKeys);

   foreach ($movedKeys as $key) {
    $entity = $sourceMap[$key];
    if ($entity instanceof Link) {
     $space->addLink($entity);
    } else {
     $space->addSection($entity);
    }
    $targetMap[$key] = $entity;
   }
  } elseif (!$this->isValidOrder($items, array_keys($targetMap))) {
   throw new BadRequestHttpException('La liste réordonnée ne correspond pas aux éléments de cet espace.');
  }

  foreach ($items as $position => $key) {
   $targetMap[$key]->setPosition($position);
  }
  if ($sourceSpace !== null) {
   foreach ($sourceItems as $position => $key) {
    $sourceMap[$key]->setPosition($position);
   }
  }

  $this->em->flush();
  return $this->completeOrderMutation($r);
 }

 private function menuEntityMap(Space $space): array
 {
  $items = [];
  foreach ($space->getLinks() as $link) $items['link:'.$link->getId()] = $link;
  foreach ($space->getSections() as $section) $items['section:'.$section->getId()] = $section;
  return $items;
 }

 private function isValidSubset(array $order, array $expected): bool
 {
  return array_is_list($order)
   && count(array_filter($order, static fn ($item): bool => !is_string($item))) === 0
   && count(array_unique($order)) === count($order)
   && array_diff($order, $expected) === [];
 }

 private function isValidOrder(array $order, array $expected): bool
 {
  return array_is_list($order)
   && count(array_filter($order, static fn ($item): bool => !is_string($item))) === 0
   && count($order) === count($expected)
   && count(array_unique($order)) === count($order)
   && array_diff($order, $expected) === []
   && array_diff($expected, $order) === [];
 }

 private function validateSectionTransfer(array $sourceMap, array $movedKeys): void
 {
  $entries = [];
  foreach ($sourceMap as $key => $entity) {
   $entries[] = ['key' => $key, 'position' => $entity->getPosition(), 'id' => $entity->getId(), 'section' => $entity instanceof Section];
  }
  usort($entries, static fn (array $a, array $b): int => ($a['position'] <=> $b['position']) ?: strcmp($a['id'], $b['id']));
  $moved = array_fill_keys($movedKeys, true);
  foreach ($entries as $index => $entry) {
   if (!$entry['section'] || !isset($moved[$entry['key']])) continue;
   for ($next = $index + 1; $next < count($entries) && !$entries[$next]['section']; ++$next) {
    if (!isset($moved[$entries[$next]['key']])) {
     throw new BadRequestHttpException('Le déplacement d’une section doit conserver tous ses liens.');
    }
   }
  }
 }

 #[Route('/spaces/{id}/favorite',name:'space_favorite',methods:['POST'])]
 public function toggleSpaceFavorite(string $id,Request $r):Response{$s=$this->ownedSpace($id);if(!$this->csrf->isTokenValid(new CsrfToken('space_favorite_'.$id,(string)$r->request->get('_token'))))throw $this->createAccessDeniedException();$s->toggleFavorite();$this->em->flush();return $this->completeMutation($r);}
 #[Route('/spaces/{id}/sections/new',name:'section_new',methods:['GET','POST'])]
 public function newSection(string $id,Request $r):Response{$space=$this->ownedSpace($id);$section=new Section($space,'');$error=null;if($r->isMethod('POST')){if(!$this->validToken('section_new_'.$id,$r))throw $this->createAccessDeniedException();$section->setTitle(trim((string)$r->request->get('title')));$section->setPosition($this->nextMenuPosition($space));$error=$this->firstError($section);if($error===null){$this->em->persist($section);$this->em->flush();return $this->completeMutation($r);}}return $this->render('@NeoxBoard/dashboard/form.html.twig',['kind'=>'Section','title'=>'Nouvelle section','section'=>$section,'space'=>$space,'error'=>$error,'token'=>$this->csrf->getToken('section_new_'.$id)->getValue()]);}
 #[Route('/sections/{id}/edit',name:'section_edit',methods:['GET','POST'])]
 public function editSection(string $id,Request $r):Response{$section=$this->ownedSection($id);$error=null;if($r->isMethod('POST')){if(!$this->validToken('section_edit_'.$id,$r))throw $this->createAccessDeniedException();$section->setTitle(trim((string)$r->request->get('title')));$error=$this->firstError($section);if($error===null){$this->em->flush();return $this->completeMutation($r);}}return $this->render('@NeoxBoard/dashboard/form.html.twig',['kind'=>'Section','title'=>'Modifier la section','section'=>$section,'space'=>$section->getSpace(),'error'=>$error,'token'=>$this->csrf->getToken('section_edit_'.$id)->getValue()]);}
 #[Route('/sections/{id}/delete',name:'section_delete',methods:['POST'])]
 public function deleteSection(string $id,Request $r):Response{$section=$this->ownedSection($id);if(!$this->validToken('section_delete_'.$id,$r))throw $this->createAccessDeniedException();$this->em->remove($section);$this->em->flush();return $this->completeMutation($r);}
 #[Route('/spaces/{id}/widgets/new',name:'widget_new',methods:['GET','POST'])]
 public function newWidget(string $id,Request $r):Response{$space=$this->ownedSpace($id);$widget=new Widget($space,'link_list','');$error=null;if($r->isMethod('POST')){if(!$this->validToken('widget_new_'.$id,$r))throw $this->createAccessDeniedException();$this->fillWidget($widget,$r);$error=$this->widgetError($widget,(string)$r->request->get('configuration','{}'));if($error===null){$widget->setPosition($space->getWidgets()->count());$this->em->persist($widget);$this->em->flush();return $this->completeMutation($r);}}return $this->render('@NeoxBoard/dashboard/form.html.twig',['kind'=>'Widget','title'=>'Nouveau widget','widget'=>$widget,'space'=>$space,'error'=>$error,'token'=>$this->csrf->getToken('widget_new_'.$id)->getValue()]);}
 #[Route('/widgets/{id}/edit',name:'widget_edit',methods:['GET','POST'])]
 public function editWidget(string $id,Request $r):Response{$widget=$this->ownedWidget($id);$error=null;if($r->isMethod('POST')){if(!$this->validToken('widget_edit_'.$id,$r))throw $this->createAccessDeniedException();$this->fillWidget($widget,$r);$error=$this->widgetError($widget,(string)$r->request->get('configuration','{}'));if($error===null){$this->em->flush();return $this->completeMutation($r);}}return $this->render('@NeoxBoard/dashboard/form.html.twig',['kind'=>'Widget','title'=>'Modifier le widget','widget'=>$widget,'space'=>$widget->getSpace(),'error'=>$error,'token'=>$this->csrf->getToken('widget_edit_'.$id)->getValue()]);}
 #[Route('/widgets/{id}/delete',name:'widget_delete',methods:['POST'])]
 public function deleteWidget(string $id,Request $r):Response{$widget=$this->ownedWidget($id);if(!$this->validToken('widget_delete_'.$id,$r))throw $this->createAccessDeniedException();$this->em->remove($widget);$this->em->flush();return $this->completeMutation($r);}
 private function completeMutation(Request $request):Response { $board=$this->board(); $this->em->persist(new Activity($board,'update','Le dashboard a été mis à jour.')); $this->em->flush(); $requestId=$this->refreshRequestId($request); $this->broadcaster->publishRefresh($board,$requestId); if ($request->headers->has('Turbo-Frame') || str_contains((string)$request->headers->get('Accept'), 'text/vnd.turbo-stream.html')) return $this->refreshResponse(); return $this->redirectToRoute('neox_board_index'); }
 private function completeOrderMutation(Request $request):Response { $board=$this->board(); $this->em->persist(new Activity($board,'update','L’ordre du tableau a été mis à jour.')); $this->em->flush(); $requestId=$this->refreshRequestId($request); $this->broadcaster->publishRefresh($board,$requestId); if ($request->headers->has('Turbo-Frame') || str_contains((string)$request->headers->get('Accept'), 'text/vnd.turbo-stream.html')) return new Response('', 204); return $this->redirectToRoute('neox_board_index'); }
 private function refreshRequestId(Request $request): string { $requestId=(string)$request->headers->get('X-Turbo-Request-Id',''); return preg_match('/^[A-Za-z0-9-]{1,100}$/',$requestId)===1 ? $requestId : bin2hex(random_bytes(16)); }
 private function refreshResponse(): Response { return new Response('<turbo-stream action="refresh" method="morph" scroll="preserve"></turbo-stream>', 200, ['Content-Type'=>'text/vnd.turbo-stream.html; charset=utf-8']); }
 private function inspectAddedLink(Link $link, LinkAvailabilityChecker $checker): void
 {
  $this->favicons->fetchFor($link);
  $result = $checker->check([$link])[0] ?? null;
  if ($result !== null) $link->setAvailabilityStatus($result['active'], new \DateTimeImmutable());
 }
 private function ownedSpace(string $id):Space{$s=$this->em->getRepository(Space::class)->find($id);if(!$s||$s->getBoard()->getOwnerKey()!==$this->board()->getOwnerKey())throw $this->createNotFoundException();return $s;}
 private function ownedLink(string $id):Link{$l=$this->em->getRepository(Link::class)->find($id);if(!$l||$l->getSpace()->getBoard()->getOwnerKey()!==$this->board()->getOwnerKey())throw $this->createNotFoundException();return $l;}
 private function ownedSection(string $id):Section{$s=$this->em->getRepository(Section::class)->find($id);if(!$s||$s->getSpace()->getBoard()->getOwnerKey()!==$this->board()->getOwnerKey())throw $this->createNotFoundException();return $s;}
 private function ownedWidget(string $id):Widget{$w=$this->em->getRepository(Widget::class)->find($id);if(!$w||$w->getSpace()->getBoard()->getOwnerKey()!==$this->board()->getOwnerKey())throw $this->createNotFoundException();return $w;}
 private function linkUrlExists(Board $board, string $url): bool
 {
  $normalized = $this->normalizeLinkUrl($url);
  foreach ($board->getSpaces() as $space) foreach ($space->getLinks() as $existing) {
   if ($this->normalizeLinkUrl($existing->getUrl()) === $normalized) return true;
  }
  return false;
 }

 /** @return list<array{title?: mixed, url?: mixed}> */
 private function droppedLinkInputs(Request $request): array
 {
  $links = $request->request->all('links');
  if ($links !== []) return array_values($links);
  return [['title' => $request->request->get('title', ''), 'url' => $request->request->get('url', '')]];
 }

 /** @param list<array{title?: mixed, url?: mixed}> $input
  * @return array{ready: list<Link>, items: list<array{title: string, url: string, status: string, message: string}>, invalid: int, duplicates: int, message: string}
  */
 private function inspectDroppedLinks(Space $space, array $input): array
 {
  $seen = [];
  foreach ($space->getBoard()->getSpaces() as $boardSpace) foreach ($boardSpace->getLinks() as $existing) {
   $seen[$this->normalizeLinkUrl($existing->getUrl())] = true;
  }

  $ready = [];
  $items = [];
  $invalid = 0;
  $duplicates = 0;
  $firstError = '';
  foreach ($input as $row) {
   $title = is_array($row) && is_scalar($row['title'] ?? null) ? trim((string) $row['title']) : '';
   $url = is_array($row) && is_scalar($row['url'] ?? null) ? trim((string) $row['url']) : '';
   $link = new Link($title, $url);
   $errors = $this->validator->validate($link);
   if (count($errors) > 0) {
    $status = 'invalid';
    $message = (string) $errors[0]->getMessage();
    if ($firstError === '') $firstError = $message;
    ++$invalid;
   } else {
    $normalized = $this->normalizeLinkUrl($url);
    if (isset($seen[$normalized])) {
     $status = 'duplicate';
     $message = 'Ce lien existe déjà dans votre tableau ou dans ce lot.';
     ++$duplicates;
    } else {
     $status = 'ready';
     $message = '';
     $ready[] = $link;
    }
    $seen[$normalized] = true;
   }
   $items[] = ['title' => $title, 'url' => $url, 'status' => $status, 'message' => $message];
  }

  return [
   'ready' => $ready,
   'items' => $items,
   'invalid' => $invalid,
   'duplicates' => $duplicates,
   'message' => $firstError !== '' ? 'Le lot contient une URL invalide : '.$firstError : '',
  ];
 }

 private function normalizeLinkUrl(string $url): string
 {
  $parts = parse_url(trim($url));
  if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return strtolower(trim($url));
  $scheme = strtolower($parts['scheme']);
  $host = strtolower($parts['host']);
  $port = $parts['port'] ?? null;
  if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) $port = null;
  $path = rtrim($parts['path'] ?? '', '/');
  $path = $path === '' ? '/' : $path;
  $query = isset($parts['query']) ? '?'.$parts['query'] : '';
  return $scheme.'://'.$host.($port === null ? '' : ':'.$port).$path.$query;
 }

 private function nextMenuPosition(Space $space): int
 {
  $positions = [];
  foreach ($space->getLinks() as $link) $positions[] = $link->getPosition();
  foreach ($space->getSections() as $section) $positions[] = $section->getPosition();
  return $positions === [] ? 0 : max($positions) + 1;
 }
 private function validDroppedSection(Space $space, string $sectionId): bool
 {
  if ($sectionId === '') return true;
  foreach ($space->getSections() as $section) if ($section->getId() === $sectionId) return true;
  return false;
 }
 private function positionDroppedLink(Space $space, Link $link, string $sectionId): void
 {
  if ($sectionId === '') {
   $firstSectionPosition = null;
   foreach ($space->getSections() as $item) $firstSectionPosition = min($firstSectionPosition ?? PHP_INT_MAX, $item->getPosition());
   if ($firstSectionPosition === null) {
    $link->setPosition($this->nextMenuPosition($space));
    return;
   }
   foreach ($space->getLinks() as $item) if ($item->getPosition() >= $firstSectionPosition) $item->setPosition($item->getPosition() + 1);
   foreach ($space->getSections() as $item) if ($item->getPosition() >= $firstSectionPosition) $item->setPosition($item->getPosition() + 1);
   $link->setPosition($firstSectionPosition);
   return;
  }
  $section = $space->getSections()->filter(static fn (Section $item): bool => $item->getId() === $sectionId)->first();
  $nextSectionPosition = null;
  foreach ($space->getSections() as $item) {
   if ($item->getPosition() > $section->getPosition()) $nextSectionPosition = min($nextSectionPosition ?? PHP_INT_MAX, $item->getPosition());
  }
  $position = $nextSectionPosition ?? $this->nextMenuPosition($space);
  foreach ($space->getLinks() as $item) if ($item->getPosition() >= $position) $item->setPosition($item->getPosition() + 1);
  foreach ($space->getSections() as $item) if ($item->getPosition() >= $position) $item->setPosition($item->getPosition() + 1);
  $link->setPosition($position);
 }
 private function buildLinkUrl(string $url, string $scheme): string { $url=trim($url); if (Link::isSupportedBrowserInternalUrl($url) || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) return $url; return (in_array($scheme, ['http','https'], true) ? $scheme : 'https').'://'.$url; }
 private function validToken(string $id,Request $r):bool{return $this->csrf->isTokenValid(new CsrfToken($id,(string)$r->request->get('_token')));}
 private function firstError(object $data):?string{$errors=$this->validator->validate($data);return count($errors)?(string)$errors[0]->getMessage():null;}
 private function fillWidget(Widget $w,Request $r):void{$w->setTitle(trim((string)$r->request->get('title')));$w->setType(trim((string)$r->request->get('type')));$w->setEnabled($r->request->getBoolean('enabled'));}
 private function widgetError(Widget $w,string $json):?string{$err=$this->firstError($w);if($err!==null)return $err;if(strlen($json)>16384)return 'La configuration dépasse 16 Ko.';try{$data=json_decode($json,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){return 'La configuration doit être un objet JSON valide.';}if(!is_array($data)||array_is_list($data))return 'La configuration doit être un objet JSON.';$w->setConfiguration($data);return null;}
}
