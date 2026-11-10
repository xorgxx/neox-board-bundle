<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Realtime;
use Neox\BoardBundle\Entity\Board;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MercureTurboBroadcaster implements DashboardBroadcaster
{
    private HttpClientInterface $client;
    public function __construct(private readonly ?string $hubUrl, private readonly ?string $publicUrl, private readonly ?string $jwtSecret)
    {
        $this->client = HttpClient::create();
    }
    public function publishRefresh(Board $board, ?string $requestId = null): void
    {
        if (!$this->hubUrl || !$this->jwtSecret) return;
        $topic = $this->topic($board);
        $token = $this->jwt(['publish'=>[$topic]]);
        try {
            $this->client->request('POST', $this->hubUrl, [
                'headers'=>['Authorization'=>'Bearer '.$token],
                'body'=>['topic'=>$topic,'data'=>$this->refreshStream($requestId)],
            ])->getStatusCode();
        } catch (\Throwable $exception) {
            error_log('Neox Board Mercure publish failed: '.$exception->getMessage());
        }
    }
    public function subscriberUrl(Board $board): ?string
    {
        if (!$this->publicUrl || !$this->jwtSecret) return null;
        $topic=$this->topic($board);
        return $this->publicUrl.'?'.http_build_query(['topic'=>$topic,'authorization'=>$this->jwt(['subscribe'=>[$topic]])]);
    }
    private function topic(Board $board):string{return 'urn:neox-board:board:'.$board->getId();}
    private function refreshStream(?string $requestId): string { return '<turbo-stream action="refresh"'.($requestId !== null ? ' request-id="'.htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8').'"' : '').'></turbo-stream>'; }
    private function jwt(array $rights):string
    {
        $encode=static fn(string $value):string=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');
        $head=$encode(json_encode(['typ'=>'JWT','alg'=>'HS256'],JSON_THROW_ON_ERROR));
        $claims=$encode(json_encode(['mercure'=>$rights,'exp'=>time()+3600],JSON_THROW_ON_ERROR));
        $signature=$encode(hash_hmac('sha256',$head.'.'.$claims,(string)$this->jwtSecret,true));
        return $head.'.'.$claims.'.'.$signature;
    }
}
