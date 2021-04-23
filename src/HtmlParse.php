<?php


namespace Ljw\Spider;


class HtmlParse
{
    protected $dom_documnt;
    protected $key;
    protected $xpath;

    public function __construct()
    {
        $this->dom_documnt = new \DOMDocument();
    }

    public function select($html, $selector, $type = 'xpath')
    {
        if ($type == 'xpath') {
            return $this->xpathSelect($html, $selector);
        } elseif ($type == 'regex') {
            return $this->regexSelect($html, $selector);
        }
        return false;
    }

    public function xpathSelect($html, $selector, $outer = false)
    {
        $key = md5($html);
        if ($key != $this->key) {
            $this->key = $key;
            @$this->dom_documnt->loadHTML('<?xml encoding="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . $html);
            $this->xpath = new \DOMXPath($this->dom_documnt);
        }
        $xpath = $this->xpath;
        $elements = @$xpath->query($selector);
        if ($elements === false) {
            return false;
        }
        $result = [];
        /** @var \DOMNode $element */
        foreach ($elements as $element) {
            if ($outer) {
                $content = $this->dom_documnt->saveXml($element);
            } else {
                $node_name = $element->nodeName;
                $node_type = $element->nodeType;
                if ($node_type == XML_ELEMENT_NODE && $node_name == 'img') {
                    $content = $element->getAttribute('src');
                } // 如果是标签属性，直接取节点值
                elseif ($node_type == XML_ATTRIBUTE_NODE || $node_type == XML_TEXT_NODE || $node_type == XML_CDATA_SECTION_NODE) {
                    $content = $element->nodeValue;
                } else {
                    // 保留nodeValue里的html符号，给children二次提取
                    $content = $this->dom_documnt->saveXml($element);
                    //$content = trim(self::$dom->saveHtml($element));
                    $content = preg_replace(["#^<{$node_name}.*>#isU", "#</{$node_name}>$#isU"], ['', ''], $content);
                }
            }

            $result[] = $content;
        }
        return $result;
    }

    public function regexSelect($html, $selector, $outer = false)
    {
        if (@preg_match_all($selector, $html, $matched) === false) {
            return false;
        }
        $count = count($matched);
        if ($count == 0) {
            return [];
        } elseif ($count == 2) {
            //只有一个匹配项
            return $outer ? $matched[0] : $matched[1];
        } else {
            //有多个匹配项
            $result = [];
            for ($i = 1; $i < $count; $i++) {
                $result[] = $matched[$i];
            }
            return $result;
        }
    }
}