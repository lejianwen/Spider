<?php

namespace Ljw\Spider;


class Spider
{
    protected $wait_queue;
    protected $all_queue;
    protected $config = [];
    protected $html_parse;
    protected $status;
    protected $task_id = 0;

    public function __construct($config)
    {
        $config['task_num'] = $config['task_num'] ?? 1;
        if ($config['task_num'] > 1) {
            if (empty($config['redis'])) {
                echo "task_num > 2 must redis \n";
                exit;
            }
            Redis::_instance($config['redis']);
            $this->wait_queue = new Queue('redis');
            $this->all_queue = new AllQueue('redis');
            // status init in fork
        } else {
            $this->wait_queue = new Queue();
            $this->all_queue = new AllQueue();
            $this->status = new Status();
        }
        $this->html_parse = new HtmlParse();
        $this->config = $config;
        Log::$show = $config['log_show'] ?? false;
        Log::$filename = $config['log_filename'] ?? '';
    }

    public function ready()
    {
        if ($this->config['task_num'] > 1 && (!$this->wait_queue->isEmpty() || count($this->all_queue) > 0)) {
            $msg = "Old data in Redis, continue? no will clean data, default is yes \n";
            $msg .= 'continue? [Y/n]';
            fwrite(STDOUT, $msg);
            $arg = strtolower(trim(fgets(STDIN)));
            if (empty($arg)) {
                $arg = 'y';
            }
            if ($arg == 'n') {
                $this->wait_queue->clear();
                $this->all_queue->clear();
                for ($i = 0; $i < $this->config['task_num']; $i++) {
                    //clear status
                    $status = new Status('redis', 1, $i);
                    $status->clear();
                    unset($status);
                }
                $this->addUrl($this->config['entry']);
            } elseif ($this->wait_queue->isEmpty()) {
                //等待队列为空,入口页面强制加入
                $this->addUrl($this->config['entry'], [], true);
            }
        } else {
            $this->addUrl($this->config['entry']);
        }
    }

    public function start()
    {
        $this->ready();
        if ($this->config['task_num'] > 1) {
            //不关心子进程的状态
            pcntl_signal(SIGCHLD, SIG_IGN);
            for ($i = 0; $i < $this->config['task_num']; $i++) {
                $pid = pcntl_fork();
                if ($pid > 0) {
                    pcntl_wait($status, WNOHANG);
                    //显示
                    if ($i + 1 == $this->config['task_num'] && !empty($this->config['show_task_panel'])) {
                        //最后一个
                        $this->panel();
                    }
                } else {
                    $this->task_id = $i;
                    Log::$task_id = $this->task_id;
                    $this->status = new Status('redis', 1, $this->task_id);
                    $this->task();
                    break;
                }
            }
        } else {
            $this->task();
        }
    }

    public function task()
    {
        Redis::_instance()->disConnect();
        $this->upTaskStatus('start_time', microtime(true));
        $request = new Request($this->config['guzzle'] ?? []);
        if (isset($this->config['multi_num']) && $this->config['multi_num'] > 1) {
            while (1) {
                //空转次数
                $wait_count = 0;
                $wait_urls = [];

                //获取足够的url数 或 最多空转5次
                while (count($wait_urls) < $this->config['multi_num'] && $wait_count < 5) {
                    if (!$this->wait_queue->isEmpty()) {
                        $url = $this->wait_queue->dequeue();
                        if (!$url) {
                            continue;
                        }
                        $wait_urls[] = $url;
                    } else {
                        usleep(100);
                        $wait_count++;
                    }
                }
                if (empty($wait_urls)) {
                    usleep(100);
                    continue;
                }
                $responses = $request->requestAsync($wait_urls);
                foreach ($responses as $url => $response) {
                    $this->response($url, $response);
                }
                $this->upTaskStatus('memory', memory_get_usage(true));
                if (isset($this->config['interval']) && $this->config['interval'] > 0) {
                    usleep($this->config['interval']);
                }
            }
        } else {
            while (1) {
                if ($this->wait_queue->isEmpty()) {
                    sleep(1);
                    continue;
                }
                $url = $this->wait_queue->dequeue();
                //redis队列可能最后一个已经被别的进程获取
                if (!$url) {
                    continue;
                }
                $this->response($url['url'], $request->request($url));
                $this->upTaskStatus('memory', memory_get_usage(true));
                if (isset($this->config['interval']) && $this->config['interval'] > 0) {
                    usleep($this->config['interval']);
                }
            }
        }
    }

    /**
     * 构造url结构
     * @param $url
     * @param array $cur_url
     * @return array
     */
    public function makeUrl($url, $cur_url = [])
    {
        return [
            'url' => $url,
            'method' => 'get',
            'headers' => [
                'Referer' => $cur_url['url'] ?? ''
            ],
            'try_num' => 0,
            'depth' => ($cur_url['depth'] ?? 0) + 1,
            'status' => 0
        ];
    }

    public function addUrl($url, $cur_url = [], $repeat = false)
    {
        $url = trim($url);
        if (!$url
            || strpos($url, '#') !== false
            || 'javascript:' == strtolower(substr($url, 0, 11))) {
            //javascript
            return;
        }
        $parse = parse_url($url);
        if (!empty($parse['scheme']) && !in_array($parse['scheme'], ['https', 'http'])) {
            //忽略非http和https
            return;
        }


        if (!empty($parse['host']) && !in_array($parse['host'], $this->config['domains'])) {
            //不在domain中的链接忽略
            return;
        }
        if (empty($parse['host']) && !empty($cur_url)) {
            $cur_parse = parse_url($cur_url['url']);
            if (substr($url, 0, 1) == '/') {
                //根路径
                $url = $cur_parse['scheme'] . '://' . $cur_parse['host'] . $url;
            } else {
                //当前路径
                $url = $cur_url['url'] . $url;
            }
        }
        if (!$repeat) {
            if (!empty($this->all_queue[$url]) && $this->all_queue[$url]['status'] > -1) {
                //已经添加过了
                return;
            }
            if (!empty($this->all_queue[$url]) && isset($this->config['max_try_num']) && $this->all_queue[$url]['try_num'] >= $this->config['max_try_num']) {
                //达到重试最大次数
                return;
            }
        }

        $url_info = $this->makeUrl($url, $cur_url);

        if (!empty($this->config['max_depth']) && $this->config['max_depth'] < $url_info['depth']) {
            return;
        }

        $this->all_queue[$url] = $url_info;
        $this->wait_queue->enqueue($url_info);
        Log::debug("add {$url} \n");
    }

    public function success($url)
    {
        Log::debug("get success {$url}  \n");
        $info = $this->all_queue[$url];
        $info['try_num']++;
        $info['status'] = 1;
        $this->all_queue[$url] = $info;
        $this->upTaskStatus('success_num', 1);
    }

    public function fail($url)
    {
        Log::debug("get fail {$url}  \n");
        $info = $this->all_queue[$url];
        $info['try_num']++;
        $info['status'] = -1;
        $this->all_queue[$url] = $info;
        $this->upTaskStatus('fail_num', 1);
    }

    public function response($url, $response)
    {
        if ($response === false) {
            $this->fail($url);
            $info = $this->all_queue[$url];
            //重试
            if ($info['try_num'] < $this->config['max_try_num']) {
                $this->wait_queue->enqueue($info);
                Log::debug("retry {$url} \n");
            }
        } else {
            $this->success($url);
            if ($response) {
                //to utf-8
                $response_charset = mb_detect_encoding($response, ['UTF-8', 'GBK', 'GB2312', 'LATIN1', 'ASCII', 'BIG5', 'ISO-8859-1']);
                if ($response_charset != 'UTF-8') {
                    $response = mb_convert_encoding($response, 'UTF-8', $response_charset);
                }
                //解析页面所有链接
                $html_a = $this->html_parse->select($response, "//a/@href");
                if (!empty($html_a)) {
                    foreach ($html_a as $a) {
                        $this->addUrl($a, $this->all_queue[$url]);
                    }
                }
                foreach ($this->config['pages'] as $page) {
                    if (preg_match('#^' . $page['url'] . '$#', $url)) {
                        //匹配到page
                        $data = $this->select($response, $page);

                        if (!empty($page['callback'])) {
                            $page['callback']($data, $url, $response);
                        }
                    }
                }
            } else {
                Log::debug("get success {$url} , but response is empty \n");
            }
        }
    }

    public function select($html, $selector)
    {
        $data = [];
        if (is_array($selector['selector'])) {
            foreach ($selector['selector'] as $selector) {
                $data[$selector['name']] = $this->select($html, $selector);
            }
        } else {
            $only_one = $selector['only_one'] ?? false;
            $_data = $this->html_parse->select($html, $selector['selector'], $selector['selector_type'] ?? 'xpath');
            return $only_one ? ($_data[0] ?? '') : $_data;
        }
        return $data;
    }

    public function upTaskStatus($type, $value)
    {
        if ($this->status) {
            if ($type == 'success_num' || $type == 'fail_num') {
                $this->status[$type] += 1;
                $this->status['request_num'] += 1;
            } else {
                $this->status[$type] = $value;
            }
        }
    }

    public function panel()
    {
        if ($this->config['task_num'] > 1) {
            $statuses = [];
            for ($i = 0; $i < $this->config['task_num']; $i++) {
                $statuses[] = new Status('redis', 1, $i);
            }
            echo "\033c";
            while (1) {
                echo "\033[0;0H";
                echo "\033[1A";
                echo "------------------------ TASKS ------------------------\n";
                echo "\033[47;30m";
                echo str_pad('task_index', 15);
                echo str_pad('request_num', 15);
                echo str_pad('success_num', 15);
                echo str_pad('fail_num', 15);
                echo str_pad('mem', 15);
                echo "\033[0m";
                echo PHP_EOL;
                foreach ($statuses as $key => $status) {
                    echo str_pad($key, 15);
                    echo str_pad($status['request_num'], 15);
                    echo str_pad($status['success_num'], 15);
                    echo str_pad($status['fail_num'], 15);
                    echo str_pad(round($status['memory'] / 1024 / 1024, 2) . 'M', 15);
                    echo PHP_EOL;
                }
                echo "\033[0m";
                sleep(1);
            }
        } else {
            echo "only one task, no panel, sorry!";
        }
    }

}