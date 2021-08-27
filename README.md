# A simple spider

## install
~~~
composer require ljw/spider:dev-master
~~~

## example
~~~php

require __DIR__ . '/../vendor/autoload.php';

$host = 'http://www.ibookv.com/';
$config = [
    'entry' => $host,
    'domains' => ['www.ibookv.com'],
    'max_try_num' => 5,
    'max_depth' => 0,
    'task_num' => 1, //
    'log_filename' => 'spider.log',
     'show_task_panel' => 0, //是否显示状态面板
//            'log_show' => 1,
//            'multi_num' => 5, //guzzle 并发请求,开启多任务时不建议开启
     'interval' => [500, 1200], //请求间隔，一个数字 或者 数组指定最小最大间隔， 单位毫秒
    'guzzle' => [
        'verify' => false, //建议false,不校验https
        'headers' => [
            'User-Agent' => 'ljw-spider',
            'Client-Ip' => '127.0.0.1',
            'timeout' => 3
        ]
    ],
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'pwd' => '',
        'database' => 6,
        'prefix' => 'lll:',
        'timeout' => 30,
    ],
    //代理数组 或者 闭包函数
    'proxy' => [], 
    //'proxy' => function($url_info){},
    // pages
    'pages' => [
        [
            'url' => 'http://www.ibookv.com/book/\d+\.html',
            'selector' => '//*[contains(@class,"book-info")]//h1',
            'only_one' => 1,
            'callback' => function ($data, $html) {
//                        var_dump($data);
            }
        ],
        [
            'url' => 'http://www.ibookv.com/book/\d+\.html',
            'selector' => [
                [
                    'name' => 'book_name',
                    'only_one' => 1,
                    'selector' => '//*[contains(@class,"book-info")]//h1',
                ],
                [
                    'name' => 'author',
                    'only_one' => 1,
                    'selector' => '//*[contains(@class,"writer")]',
                ],
                [
                    'name' => 'chapters',
                    'only_one' => 1,
                    'selector' => [
                        [
                            'name' => 'chapter_title',
                            'only_one' => 0,
                            'selector' => '//*[@id="l2"]//ul//li/a'
                        ],
                        [
                            'name' => 'chapter_url',
                            'only_one' => 0,
                            'selector' => '//*[@id="l2"]//ul//li/a/@href'
                        ]
                    ],
                ],
            ],
            'only_one' => 1,
            'callback' => function ($data, $html) {
//                        var_dump($data);
            }
        ],
        [
            'url' => 'http://www.ibookv.com/category/\d+\.html',
            'selector_type' => 'regex',
            'selector' => '%<li ><a href=".*?">(.*?)</a></li>%i',
            'callback' => function ($data, $html) {
//                        var_dump($data);
            }
        ]
    ]

];
$spider = new Ljw\Spider\Spider($config);
$spider->start();
~~~