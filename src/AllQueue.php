<?php


namespace Ljw\Spider;


class AllQueue implements \ArrayAccess, \Countable
{
    protected $type;
    /** @var Redis|\Redis|array client */
    protected $client;
    protected $redis_key;

    public function __construct($type = '', $redis_key = 'lwf:spider:all:queue')
    {
        $this->type = $type;
        if ($type == 'redis') {
            $this->redis_key = $redis_key;
            $this->client = Redis::_instance();
        } else {
            $this->client = [];
        }
    }

    public function offsetExists($offset)
    {
        if ($this->type == 'redis') {
            return $this->client->hExists($this->redis_key, $offset);
        } else {
            return $this->client[$offset] ?? false;
        }
    }

    public function offsetGet($offset)
    {
        if ($this->type == 'redis') {
            $value = $this->client->hGet($this->redis_key, $offset);
            return json_decode($value, true);
        } else {
            return $this->client[$offset] ?? null;
        }
    }

    public function offsetSet($offset, $value)
    {
        if ($this->type == 'redis') {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            $this->client->hSet($this->redis_key, $offset, $value);
        } else {
            $this->client[$offset] = $value;
        }
    }

    public function offsetUnset($offset)
    {
        if ($this->type == 'redis') {
            $this->client->del($this->redis_key, $offset);
        } else {
            if ($this[$offset]) {
                unset($this->client[$offset]);
            }
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
            return $this->client->hLen($this->redis_key);
        } else {
            return count($this->client);
        }
    }

    public function count()
    {
        return $this->size();
    }
}