<?php


namespace Ljw\Spider;


use GuzzleHttp\Client;

class Request
{
    /** @var Client */
    protected $client;
    protected $last_url;
    protected $proxy;

    public function __construct($config = [])
    {
        $this->client = new Client($config);
    }

    public function setProxy($proxy)
    {
        $this->proxy = $proxy;
    }

    /**
     * @param $url
     * @return string
     */
    public function request($url)
    {
        try {
            $response = $this->client->request(
                $url['method'] ?? 'get',
                $url['url'],
                [
                    'headers' => $url['headers'] ?? [],
                    'proxy' => $this->proxy ?: null
                ]);
            $this->last_url = $url;
            $content_type = $response->getHeaderLine('content-type');
            $charset = '';
            if (stristr($content_type, 'charset=')) {
                preg_match('/charset\=(.*)?\;?/', $content_type, $match);
                $charset = $match[1] ?? '';
            }
            return $this->convertResponse($response->getBody()->getContents(), $charset);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            Log::debug("get fail {$url['url']} " . $e->getMessage());
            return false;
        }
    }

    /**
     * @param $urls
     * @return array
     */
    public function requestAsync($urls)
    {
        $promises = [];
        foreach ($urls as $url) {
            $promises[$url['url']] = $this->client->requestAsync(
                $url['method'] ?? 'get',
                $url['url'],
                [
                    'headers' => $url['headers'] ?? [],
                    'proxy' => $this->proxy ?: null
                ]);
        }
        $results = [];
        foreach ($promises as $key => $promise) {
            try {
                $content_type = $promise->wait()->getHeaderLine('content-type');
                $charset = '';
                if (stristr($content_type, 'charset=')) {
                    preg_match('/charset\=(.*)?\;?/', $content_type, $match);
                    $charset = $match[1] ?? '';
                }
                var_dump($charset);
                $results[$key] = $this->convertResponse($promise->wait()->getBody()->getContents(), $charset);
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                $results[$key] = false;
            }
        }
        return $results;
    }

    public function convertResponse($response, $response_charset = '')
    {
        if (!$response_charset) {
            $response_charset = mb_detect_encoding($response, ['UTF-8', 'GBK', 'GB2312', 'LATIN1', 'ASCII', 'BIG5', 'ISO-8859-1']);
        }

        if ($response_charset != 'UTF-8') {
            $response = mb_convert_encoding($response, 'UTF-8', $response_charset);
        }
        $pattern = '/<meta[^>]*?charset=.*?>/i';
        $response = preg_replace($pattern, '', $response, 1);
        return $response;
    }
}