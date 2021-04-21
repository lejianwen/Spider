<?php


namespace Ljw\Spider;


use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;

class Request
{
    protected $client;
    protected $last_url;

    public function __construct($config = [])
    {
        $this->client = new Client($config);
    }

    /**
     * @param $url
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request($url)
    {
        try {
            $response = $this->client->request(
                $url['method'] ?? 'get',
                $url['url'],
                [
                    'headers' => $url['headers']
                ]);
            $this->last_url = $url;
            return $response->getBody()->getContents();
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
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
                    'headers' => $url['headers']
                ]);
        }
        $results = [];
        foreach ($promises as $key => $promise) {
            try {
                $results[$key] = $promise->wait()->getBody()->getContents();
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                $results[$key] = false;
            }
        }
        return $results;
    }
}