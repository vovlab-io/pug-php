<?php

namespace Jade\Stream;

/**
 * Creates a wrapper in order to allow the Zend PhpRenderer
 * to include the compiled file.
 * Class Jade\Stream\Template.
 */
class Template
{
    /**
     * @var resource|null set by PHP on every stream wrapper instance
     */
    public $context;

    /**
     * @var int
     */
    private $position = 0;

    /**
     * @var string
     */
    private $data = '';

    /**
     * @var array compiled sources awaiting inclusion, indexed by key
     */
    private static $templates = array();

    /**
     * Store a compiled source and return the key to use in the stream path.
     *
     * @param string $input
     *
     * @return string
     */
    public static function register($input)
    {
        $key = md5($input);
        self::$templates[$key] = $input;

        return $key;
    }

    /**
     * @param $path
     *
     * @return bool
     */
    public function stream_open($path)
    {
        $key = substr(strstr($path, ';'), 1);
        $this->data = isset(self::$templates[$key]) ? self::$templates[$key] : $key;

        return true;
    }

    /**
     * @return null
     */
    public function stream_stat()
    {
    }

    /**
     * @param $option
     * @param $arg1
     * @param $arg2
     *
     * @return bool
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        return true;
    }

    /**
     * @param $count
     *
     * @return string
     */
    public function stream_read($count)
    {
        $ret = substr($this->data, $this->position, $count);
        $this->position += strlen($ret);

        return $ret;
    }

    /**
     * @return int
     */
    public function stream_tell()
    {
        return $this->position;
    }

    /**
     * @return bool
     */
    public function stream_eof()
    {
        return $this->position >= strlen($this->data);
    }

    /**
     * Dummy URL stat method to prevent PHP "undefined method" errors.
     *
     * @return array
     */
    public function url_stat($path, $flags)
    {
        return array(0, 0, 0, 0, 0, 0, 0, strlen($this->data), 0, 0, 0, 0);
    }
}
