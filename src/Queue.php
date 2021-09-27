<?php


namespace Ljw\Spider;


class Queue implements \Countable
{
    protected $type;
    /** @var \Redis|mixed|\SplQueue $client */
    protected $client;
    protected $redis_key;

    public function __construct($redis = null, $redis_key = 'lwf:spider:wait:queue')
    {
        if ($redis) {
            $this->type = 'redis';
        }

        if ($this->type == 'redis') {
            $this->redis_key = $redis_key;
            $this->client = $redis;
        } else {
            $this->client = new \SplQueue();
        }
    }

    public function dequeue()
    {
        if ($this->type == 'redis') {
            $value = $this->client->rPop($this->redis_key);
            if ($value) {
                return $value;
            }
            return null;
        } else {
            return $this->client->dequeue();
        }
    }

    public function unshift($value)
    {
        if ($this->type == 'redis') {
            return $this->client->rPush($this->redis_key, $value);
        } else {
            return $this->client->unshift($value);
        }
    }

    public function enqueue($value)
    {
        if ($this->type == 'redis') {
            return $this->client->lPush($this->redis_key, $value);
        } else {
            return $this->client->enqueue($value);
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

    public function size()
    {
        if ($this->type == 'redis') {
            return $this->client->lLen($this->redis_key);
        } else {
            return count($this->client);
        }
    }

    public function count()
    {
        return $this->size();
    }

}