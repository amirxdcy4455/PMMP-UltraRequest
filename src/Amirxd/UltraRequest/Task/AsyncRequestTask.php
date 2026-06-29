<?php
declare(strict_types=1);

namespace Amirxd\UltraRequest\Task;

use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;
use Override;
use pocketmine\scheduler\AsyncTask;

class AsyncRequestTask extends AsyncTask {

    private string $serializedRequest;
    private string $serializedClient;

    public function __construct(
        Request $request,
        Client $client,
        ?\Closure $onSuccess = null,
        ?\Closure $onError = null
    ) {
        $this->serializedRequest = serialize($request);
        $this->serializedClient  = serialize($client);

        $this->storeLocal('onSuccess', $onSuccess);
        $this->storeLocal('onError', $onError);
    }

    #[Override]
    public function onRun(): void {
        try {
            /** @var Request $request */
            $request = unserialize($this->serializedRequest);
            /** @var Client $client */
            $client  = unserialize($this->serializedClient);

            $response = $client->send($request);

            $this->setResult([
                'success' => true,
                'status'  => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body'    => $response->getBody(),
                'json'    => $response->getJson(),
                'info'    => $response->getInfo(),
            ]);
        } catch (\Throwable $e) {
            $this->setResult([
                'success' => false,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
            ]);
        }
    }

    #[Override]
    public function onCompletion(): void {
        $result = $this->getResult();
        $onSuccess = $this->fetchLocal('onSuccess');
        $onError   = $this->fetchLocal('onError');
        if ($result['success']) {
            if(!is_null($onSuccess)){
                ($onSuccess)($result);
            }
        } else {
            if(!is_null($onError)){
                ($onError)(new \RuntimeException(
                    "[{$result['class']}] {$result['error']}"
                ));
            }
        }
    }
}