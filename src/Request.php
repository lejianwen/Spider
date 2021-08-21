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
            return $response->getBody()->getContents();
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
                $results[$key] = $promise->wait()->getBody()->getContents();
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                Log::debug("get fail {$url['url']} " . $e->getMessage());
                $results[$key] = false;
            }
        }
        return $results;
    }
}