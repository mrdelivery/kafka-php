<?php
namespace Kafka;

trait SingletonTrait
{
    use \Psr\Log\LoggerAwareTrait;
    use \Kafka\LoggerTrait;

    protected static $instance = null;

    /**
     * set send messages
     *
     * @access public
     * @param $hostList
     * @param null $timeout
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Abandon existing instance and make a fresh instance
     * @return instance
     */
    public static function refreshInstance()
    {
        static::$instance = null;

        return static::getInstance();
    }

    /**
     * __construct
     *
     * @access public
     * @param $hostList
     * @param null $timeout
     */
    private function __construct()
    {
    }
}
