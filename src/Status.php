<?php


namespace Ljw\Spider;


class Status implements \ArrayAccess
{
    protected $type;
    /** @var Redis|\Redis client */
    protected $client;
    protected $redis_key = 'lwf:spider:status';
    protected $task_id;
    protected $server_id;

    public function __construct($redis = null, $server_id = 1, $task_id = 0)
    {
        if ($redis) {
            $this->type = 'redis';
        }
        $this->task_id = $task_id;
        $this->server_id = $server_id;
        if ($this->type == 'redis') {
            $this->client = $redis;
        } else {
            $this->client = [];
            $this->client[$this->key()] = [];
        }
    }

    public function offsetExists($offset)
    {
        if ($this->type == 'redis') {
            return $this->client->hExists($this->key(), $offset);
        } else {
            return $this->client[$this->key()][$offset] ?? false;
        }
    }

    public function offsetGet($offset)
    {
        if ($this->type == 'redis') {
            return $this->client->hGet($this->key(), $offset);
        } else {
            return $this->client[$this->key()][$offset] ?? null;
        }
    }

    public function offsetSet($offset, $value)
    {
        if ($this->type == 'redis') {
            $this->client->hSet($this->key(), $offset, $value);
        } else {
            $this->client[$this->key()][$offset] = $value;
        }
    }

    public function offsetUnset($offset)
    {
        if ($this->type == 'redis') {
            $this->client->hDel($this->key(), $offset);
        } else {
            if (isset($this[$this->key()][$offset])) {
                unset($this[$this->key()][$offset]);
            }
        }
    }

    public function key()
    {
        return $this->redis_key . ':' . $this->server_id . ':' . $this->task_id;
    }

    public function clear()
    {
        if ($this->type == 'redis') {
            $this->client->del($this->key());
        } else {
            unset($this[$this->key()]);
        }
    }

    public function setTaskId($task_id)
    {
        $this->task_id = $task_id;
        return $this;
    }
}

