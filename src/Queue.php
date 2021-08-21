<?php


namespace Ljw\Spider;


class Queue
{
    protected $type;
    /** @var \Redis|mixed|\SplQueue $client */
    protected $client;
    protected $config;
    protected $redis_key;

    public function __construct($type = '', $redis_key = 'lwf:spider:wait:queue')
    {
        $this->type = $type;
        if ($type == 'redis') {
            $this->redis_key = $redis_key;
            $this->client = Redis::_instance();
        } else {
            $this->client = new \SplQueue();
        }
    }

    public function dequeue()
    {
        if ($this->type == 'redis') {
            $value = $this->client->rPop($this->redis_key);
            if ($value) {
                return json_decode($value, true);
            }
            return null;
        } else {
            return $this->client->dequeue();
        }
    }

    public function enqueue($value)
    {
        if ($this->type == 'redis') {
            return $this->client->lPush($this->redis_key, json_encode($value, JSON_UNESCAPED_UNICODE));
        } else {
            return $this->client->enqueue($value);
        }
    }

    public function unshift($value)
    {
        if ($this->type == 'redis') {
            return $this->client->rPush($this->redis_key, json_encode($value, JSON_UNESCAPED_UNICODE));
        } else {
            return $this->client->unshift($value);
        }
    }

    public function isEmpty()
    {
        if ($this->type == 'redis') {
            return $this->client->lLen($this->redis_key) <= 0;
        } else {
            return $this->client->isEmpty();
        }
    }

    public function clear()
    {
        if ($this->type == 'redis') {
            $this->client->del($this->redis_key);
        }
    }

}