<?php
namespace STS\Backoff;

use Exception;
use InvalidArgumentException;
use STS\Backoff\Strategies\ConstantStrategy;
use STS\Backoff\Strategies\ExponentialStrategy;
use STS\Backoff\Strategies\LinearStrategy;
use STS\Backoff\Strategies\PolynomialStrategy;

/**
 * Class Retry
 * @package STS\Backoff
 */
class Backoff
{
    /**
     * @var string
     */
    public static $defaultStrategy = "polynomial";

    /**
     * @var int
     */
    public static $defaultMaxAttempts = 5;

    /**
     * @var bool
     */
    public static $defaultJitterEnabled = false;

    /**
     * This callable should take an 'attempt' integer, and return a wait time in milliseconds
     *
     * @var callable
     */
    protected $strategy;

    /**
     * @var array
     */
    protected $strategies = [
        'constant'    => ConstantStrategy::class,
        'linear'      => LinearStrategy::class,
        'polynomial'  => PolynomialStrategy::class,
        'exponential' => ExponentialStrategy::class
    ];

    /**
     * @var
     */
    protected $maxAttempts;

    /**
     * The max wait time you want to allow, regardless of what the strategy says
     *
     * @var int     In milliseconds
     */
    protected $waitCap;

    /**
     * @var bool
     */
    protected $useJitter = false;

    /**
     * @var array
     */
    protected $exceptions = [];

    /**
     * @param null $maxAttempts
     * @param null $strategy
     * @param null $waitCap
     * @param null $useJitter
     */
    public function __construct($maxAttempts = null, $strategy = null, $waitCap = null, $useJitter = null)
    {
        $this->setMaxAttempts($maxAttempts ?: self::$defaultMaxAttempts);
        $this->setStrategy($strategy ?: self::$defaultStrategy);
        $this->setJitter($useJitter ?: self::$defaultJitterEnabled);
        $this->setWaitCap($waitCap);
    }

    /**
     * @param integer $attempts
     */
    public function setMaxAttempts($attempts)
    {
        $this->maxAttempts = $attempts;
    }

    /**
     * @return integer
     */
    public function getMaxAttempts()
    {
        return $this->maxAttempts;
    }

    /**
     * @param $cap
     *
     * @return $this
     */
    public function setWaitCap($cap)
    {
        $this->waitCap = $cap;

        return $this;
    }

    /**
     * @return int
     */
    public function getWaitCap()
    {
        return $this->waitCap;
    }

    /**
     * @param $useJitter
     *
     * @return $this
     */
    public function setJitter($useJitter)
    {
        $this->useJitter = $useJitter;

        return $this;
    }

    /**
     *
     */
    public function enableJitter()
    {
        $this->setJitter(true);

        return $this;
    }

    /**
     *
     */
    public function disableJitter()
    {
        $this->setJitter(false);

        return $this;
    }

    public function jitterEnabled()
    {
        return $this->useJitter;
    }

    /**
     * @return callable
     */
    public function getStrategy()
    {
        return $this->strategy;
    }

    /**
     * @param mixed $strategy
     *
     * @return $this
     */
    public function setStrategy($strategy)
    {
        $this->strategy = $this->buildStrategy($strategy);

        return $this;
    }

    /**
     * Builds a callable strategy.
     *
     * @param mixed $strategy   Can be a string that matches a key in $strategies, an instance of AbstractStrategy
     *                          (or any other instance that has an __invoke method), a callback function, or
     *                          an integer (which we interpret to mean you want a ConstantStrategy)
     *
     * @return callable
     */
    protected function buildStrategy($strategy)
    {
        if (is_string($strategy) && array_key_exists($strategy, $this->strategies)) {
            return new $this->strategies[$strategy];
        }

        if (is_callable($strategy)) {
            return $strategy;
        }

        if (is_int($strategy)) {
            return new ConstantStrategy($strategy);
        }

        throw new InvalidArgumentException("Invalid strategy: " . $strategy);
    }

    /**
     * @param $callback
     *
     * @return mixed
     * @throws Exception
     */
    public function run($callback)
    {
        for ($attempt = 0; $attempt < $this->getMaxAttempts(); $attempt++) {

            $this->wait($attempt);

            try {
                return call_user_func($callback);
            } catch (Exception $e) {
                $this->exceptions[] = $e;
            }
        }

        throw $e;
    }

    /**
     * @param $attempt
     */
    public function wait($attempt)
    {
        if ($attempt == 0) {
            return;
        }

        usleep($this->getWaitTime($attempt) * 1000);
    }

    /**
     * @param $attempt
     *
     * @return int
     */
    public function getWaitTime($attempt)
    {
        $waitTime = call_user_func($this->getStrategy(), $attempt);

        return $this->jitter($this->cap($waitTime));
    }

    /**
     * @param $waitTime
     *
     * @return mixed
     */
    protected function cap($waitTime)
    {
        return is_int($this->getWaitCap())
            ? min($this->getWaitCap(), $waitTime)
            : $waitTime;
    }

    /**
     * @param $waitTime
     *
     * @return int
     */
    protected function jitter($waitTime)
    {
        return $this->jitterEnabled()
            ? mt_rand(0, $waitTime)
            : $waitTime;
    }
}