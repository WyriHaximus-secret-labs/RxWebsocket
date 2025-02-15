<?php

namespace Rx\Websocket;

use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use React\EventLoop\LoopInterface;
use React\Http\Client\Client as HttpClient;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use Rx\Disposable\CallbackDisposable;
use Rx\DisposableInterface;
use Rx\Observable;
use Rx\Observable\AnonymousObservable;
use Rx\Observer\CallbackObserver;
use Rx\ObserverInterface;

class Client extends Observable
{
    protected $url;
    private $useMessageObject;
    private $subProtocols;
    private $loop;
    private $connector;
    private $keepAlive;
    private $headers;

    public function __construct(string $url, bool $useMessageObject = false, array $subProtocols = [], LoopInterface $loop = null, ConnectorInterface $connector = null, int $keepAlive = 60000, array $headers = [])
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], ['wss', 'ws'])) {
            throw new \InvalidArgumentException('url must use either ws or wss scheme');
        }

        if ($parsedUrl['scheme'] === 'wss') {
            $url = 'https://' . substr($url, 6);
        }

        if ($parsedUrl['scheme'] === 'ws') {
            $url = 'http://' . substr($url, 5);
        }

        $this->url              = $url;
        $this->useMessageObject = $useMessageObject;
        $this->subProtocols     = $subProtocols;
        $this->loop             = $loop ?: \EventLoop\getLoop();
        $this->connector        = $connector;
        $this->keepAlive        = $keepAlive;
        $this->headers          = $headers;
    }

    public function _subscribe(ObserverInterface $clientObserver): DisposableInterface
    {
        $client = new HttpClient($this->loop, $this->connector);

        $cNegotiator = new ClientNegotiator();

        /** @var Psr7Request $nRequest */
        $nRequest = $cNegotiator->generateRequest(new Uri($this->url));

        if (!empty($this->subProtocols)) {
            $nRequest = $nRequest
                ->withoutHeader('Sec-WebSocket-Protocol')
                ->withHeader('Sec-WebSocket-Protocol', $this->subProtocols);
        }

        $headers = $nRequest->getHeaders();

        $flatHeaders = [];
        foreach ($headers as $k => $v) {
            $flatHeaders[$k] = $v[0];
        }

        foreach ($this->headers as $k => $v) {
            $flatHeaders[$k] = $v;
        }

        $request = $client->request('GET', $this->url, $flatHeaders, '1.1');

        $request->on('error', function ($error) use ($clientObserver) {
            $clientObserver->onError($error);
        });

        $request->on('response', function (ResponseInterface $response, ConnectionInterface $request) use ($flatHeaders, $cNegotiator, $nRequest, $clientObserver) {
            if ($response->getStatusCode() !== 101) {
                $clientObserver->onError(new \Exception('Unexpected response code ' . $response->getStatusCode()));
                return;
            }

            $psr7Response = new Psr7Response(
                $response->getStatusCode(),
                $response->getHeaders(),
                null,
                $response->getProtocolVersion()
            );

            $psr7Request = new Psr7Request('GET', $this->url, $flatHeaders);

            if (!$cNegotiator->validateResponse($psr7Request, $psr7Response)) {
                $clientObserver->onError(new \Exception('Invalid response'));
                return;
            }

            $subprotoHeader = $psr7Response->getHeader('Sec-WebSocket-Protocol');

            $clientObserver->onNext(new MessageSubject(
                new AnonymousObservable(function (ObserverInterface $observer) use ($response, $request, $clientObserver) {

                    $request->on('data', function ($data) use ($observer) {
                        $observer->onNext($data);
                    });

                    $request->on('error', function ($e) use ($observer) {
                        $observer->onError($e);
                    });

                    $request->on('close', function () use ($observer, $clientObserver) {
                        $observer->onCompleted();

                        // complete the parent observer - we only do 1 connection
                        $clientObserver->onCompleted();
                    });

                    $request->on('end', function () use ($observer, $clientObserver) {
                        $observer->onCompleted();

                        // complete the parent observer - we only do 1 connection
                        $clientObserver->onCompleted();
                    });

                    return new CallbackDisposable(function () use ($request) {
                        $request->end();
                    });
                }),
                new CallbackObserver(
                    function ($x) use ($request) {
                        $request->write($x);
                    },
                    function ($e) use ($request) {
                        $request->close();
                    },
                    function () use ($request) {
                        $request->end();
                    }
                ),
                true,
                $this->useMessageObject,
                $subprotoHeader,
                $nRequest,
                $psr7Response,
                $this->keepAlive
            ));
        });

        // empty write to force connection and header send
        $request->write('');

        return new CallbackDisposable(function () use ($request) {
            $request->close();
        });
    }
}
