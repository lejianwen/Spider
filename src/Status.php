<?php


namespace Ljw\Spider;


class Status implements \ArrayAccess
{
    protected $type;
    /** @var Redis|\Redis client */
    protected $client;
    protected $value;
    protected $redis_key = 'lwf:spider:status';
    protected $task_id;
    protected $server_id;
    protected $last_time; //上次同步时间

    public function __construct($redis = null, $server_id = 1, $task_id = 0)
    {
        $this->task_id = $task_id;
        $this->server_id = $server_id;
        if ($redis) {
            $this->type = 'redis';
            $this->value = [];
            $this->client = $redis;
        }

    }

    public function offsetExists($offset)
    {
        return $this->value[$offset] ?? false;
    }

    public function offsetGet($offset)
    {
        return $this->value[$offset] ?? null;
    }

    public function offsetSet($offset, $value)
    {
        $this->value[$offset] = $value;
        $this->toSync();
    }

    public function offsetUnset($offset)
    {
        if (isset($this->value[$offset])) {
            unset($this->value[$offset]);
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
            $this->client->del($this->cmdKey());
        } else {
            $this->value = [];
        }
    }

    public function setTaskId($task_id)
    {
        $this->task_id = $task_id;
        return $this;
    }

    /**
     * 每100ms同步到redis
     */
    public function toSync()
    {
        $now = microtime(true) * 1000;
        if ($now - $this->last_time > 100) {
            $this->sync();
            $this->last_time = $now;
        }
    }

    /**
     * 同步到redis
     */
    public function sync()
    {
        if ($this->client) {
            $this->client->hMSet($this->key(), $this->value);
        }
    }

    public function loadFromRemote($offset = null)
    {
        if ($this->client) {
            if ($offset) {
                return $this->client->hGet($this->key(), $offset);
            } else {
                return $this->client->hGetAll($this->key());
            }
        }
        return null;
    }

    public function cmdKey()
    {
        return $this->key() . ':cmd';
    }

    public function getCmd()
    {
        if ($this->client) {
            return $this->client->get($this->cmdKey());
        } else {
            return '';
        }
    }

    public function setCmd($value)
    {
        if ($this->client) {
            $this->client->set($this->cmdKey(), $value);
        } else {
        }
    }
}

