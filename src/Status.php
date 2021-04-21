<?php


namespace Ljw\Spider;


class Status implements \ArrayAccess
{
    protected $type;
    /** @var Redis|\Redis client */
    protected $client;
    protected $redis_key = 'lwf:spider:status';
    protected $status_keys = ['success_num', 'fail_num', 'request_num'];

    public function __construct($type = '')
    {
        $this->type = $type;
        if ($type == 'redis') {
            $this->client = Redis::_instance();
        } else {
            $this->client = [];
        }
    }

    public function offsetExists($offset)
    {
        if ($this->type == 'redis') {
            return $this->client->exists($this->key($offset));
        } else {
            return $this->client[$this->key($offset)] ?? false;
        }
    }

    public function offsetGet($offset)
    {
        if ($this->type == 'redis') {
            return $this->client->get($this->key($offset));
        } else {
            return $this->client[$this->key($offset)] ?? null;
        }
    }

    public function offsetSet($offset, $value)
    {
        if ($this->type == 'redis') {
            $this->client->set($this->key($offset), $value);
        } else {
            $this->client[$this->key($offset)] = $value;
        }
    }

    public function offsetUnset($offset)
    {
        if ($this->type == 'redis') {
            $this->client->del($this->key($offset));
        } else {
            if ($this[$this->key($offset)]) {
                unset($this->client[$this->key($offset)]);
            }
        }
    }

    public function key($offset)
    {
        return $this->redis_key . ':' . $offset;
    }
}
