<?php

/*
 * This file is part of the overtrue/easy-sms.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\EasySms;

use Closure;
use Overtrue\EasySms\Contracts\GatewayInterface;
use Overtrue\EasySms\Contracts\MessageInterface;
use Overtrue\EasySms\Contracts\PhoneNumberInterface;
use Overtrue\EasySms\Contracts\StrategyInterface;
use Overtrue\EasySms\Exceptions\InvalidArgumentException;
use Overtrue\EasySms\Gateways\Gateway;
use Overtrue\EasySms\Strategies\OrderStrategy;
use Overtrue\EasySms\Support\Config;

/**
 * Class EasySms.
 */
class EasySms
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var string
     */
    protected $defaultGateway;

    /**
     * @var array
     */
    protected $customCreators = [];

    /**
     * @var array
     */
    protected $gateways = [];

    /**
     * @var Messenger
     */
    protected $messenger;

    /**
     * @var array
     */
    protected $strategies = [];

    /**
     * Constructor.
     */
    public function __construct(array $config)
    {
        $this->config = new Config($config);
    }

    /**
     * Send a message.
     *
     * @param string|array           $to
     * @param MessageInterface|array $message
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws Exceptions\NoGatewayAvailableException
     */
    public function send($to, $message, array $gateways = [])
    {
        $to = $this->formatPhoneNumber($to);
        $message = $this->formatMessage($message);
        $gateways = empty($gateways) ? $message->getGateways() : $gateways;

        if (empty($gateways)) {
            $gateways = $this->config->get('default.gateways', []);
        }

        return $this->getMessenger()->send($to, $message, $this->formatGateways($gateways));
    }

    /**
     * Create a gateway.
     *
     * @param string|null $name
     *
     * @return GatewayInterface
     *
     * @throws InvalidArgumentException
     */
    public function gateway($name)
    {
        if (!isset($this->gateways[$name])) {
            $this->gateways[$name] = $this->createGateway($name);
        }

        return $this->gateways[$name];
    }

    /**
     * Get a strategy instance.
     *
     * @param string|null $strategy
     *
     * @return StrategyInterface
     *
     * @throws InvalidArgumentException
     */
    public function strategy($strategy = null)
    {
        if (\is_null($strategy)) {
            $strategy = $this->config->get('default.strategy', OrderStrategy::class);
        }

        if (!\class_exists($strategy)) {
            $strategy = __NAMESPACE__.'\Strategies\\'.\ucfirst($strategy);
        }

        if (!\class_exists($strategy)) {
            throw new InvalidArgumentException("Unsupported strategy \"{$strategy}\"");
        }

        if (empty($this->strategies[$strategy]) || !($this->strategies[$strategy] instanceof StrategyInterface)) {
            $this->strategies[$strategy] = new $strategy($this);
        }

        return $this->strategies[$strategy];
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param string $name
     *
     * @return $this
     */
    public function extend($name, \Closure $callback)
    {
        $this->customCreators[$name] = $callback;

        return $this;
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return Messenger
     */
    public function getMessenger()
    {
        return $this->messenger ?: $this->messenger = new Messenger($this);
    }

    /**
     * Create a new driver instance.
     *
     * @param string $name
     *
     * @return GatewayInterface
     *
     * @throws \InvalidArgumentException
     * @throws InvalidArgumentException
     */
    protected function createGateway($name)
    {
        $config = $this->config->get("gateways.{$name}", []);

        if (!isset($config['timeout'])) {
            $config['timeout'] = $this->config->get('timeout', Gateway::DEFAULT_TIMEOUT);
        }

        $config['options'] = $this->config->get('options', []);

        if (isset($this->customCreators[$name])) {
            $gateway = $this->callCustomCreator($name, $config);
        } else {
            $className = $this->formatGatewayClassName($name);
            $gateway = $this->makeGateway($className, $config);
        }

        if (!($gateway instanceof GatewayInterface)) {
            throw new InvalidArgumentException(\sprintf('Gateway "%s" must implement interface %s.', $name, GatewayInterface::class));
        }

        return $gateway;
    }

    /**
     * Make gateway instance.
     *
     * @param string $gateway
     * @param array  $config
     *
     * @return GatewayInterface
     *
     * @throws InvalidArgumentException
     */
    protected function makeGateway($gateway, $config)
    {
        if (!\class_exists($gateway) || !\in_array(GatewayInterface::class, \class_implements($gateway))) {
            throw new InvalidArgumentException(\sprintf('Class "%s" is a invalid easy-sms gateway.', $gateway));
        }

        return new $gateway($config);
    }

    /**
     * Format gateway name.
     *
     * @param string $name
     *
     * @return string
     */
    protected function formatGatewayClassName($name)
    {
        if (\class_exists($name) && \in_array(GatewayInterface::class, \class_implements($name))) {
            return $name;
        }

        $name = \ucfirst(\str_replace(['-', '_', ''], '', $name));

        return __NAMESPACE__."\\Gateways\\{$name}Gateway";
    }

    /**
     * Call a custom gateway creator.
     *
     * @param string $gateway
     * @param array  $config
     */
    protected function callCustomCreator($gateway, $config)
    {
        return \call_user_func($this->customCreators[$gateway], $config);
    }

    /**
     * @param string|PhoneNumberInterface $number
     *
     * @return PhoneNumberInterface|string
     */
    protected function formatPhoneNumber($number)
    {
        if ($number instanceof PhoneNumberInterface) {
            return $number;
        }

        return new PhoneNumber(\trim($number));
    }

    /**
     * @param array|string|MessageInterface $message
     *
     * @return MessageInterface
     */
    protected function formatMessage($message)
    {
        if (!($message instanceof MessageInterface)) {
            if (!\is_array($message)) {
                $message = [
                    'content' => $message,
                    'template' => $message,
                ];
            }

            $message = new Message($message);
        }

        return $message;
    }

    /**
     * @return array
     *
     * @throws InvalidArgumentException
     */
    protected function formatGateways(array $gateways)
    {
        $formatted = [];

        foreach ($gateways as $gateway => $setting) {
            if (\is_int($gateway) && \is_string($setting)) {
                $gateway = $setting;
                $setting = [];
            }

            $formatted[$gateway] = $setting;
            $globalSettings = $this->config->get("gateways.{$gateway}", []);

            if (\is_string($gateway) && !empty($globalSettings) && \is_array($setting)) {
                $formatted[$gateway] = new Config(\array_merge($globalSettings, $setting));
            }
        }

        $result = [];

        foreach ($this->strategy()->apply($formatted) as $name) {
            $result[$name] = $formatted[$name];
        }

        return $result;
    }
}
