<?php

namespace Anik\Amqp;

use Anik\Amqp\Exceptions\AmqpException;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;

class AmqpManager
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    private $app;

    /**
     * AmqpConnection instance
     * @var \PhpAmqpLib\Connection\AMQPConnection[]
     */
    private $connections;

    /**
     * AmqpChannel instance
     * @var \PhpAmqpLib\Channel\AMQPChannel[]
     */
    private $channels;

    public function __construct (Container $app) {
        $this->app = $app;
    }

    protected function getConfig ($connection) {
        return $this->app['config']["amqp.connections.{$connection}"];
    }

    public function getDefaultConnection () {
        return $this->app['config']['amqp.default'];
    }

    public function setDefaultConnection ($name) {
        $this->app['config']['amqp.default'] = $name;
    }

    protected function getConnection ($name) {
        return $this->connections[$name] ?? ($this->connections[$name] = $this->resolve($name));
    }

    protected function resolveConnectionName ($name) {
        return $name ?: $this->getDefaultConnection();
    }

    protected function guessChannelId ($channelId, $connection) {
        return is_null($channelId) ? $this->getConfig($connection)['channel_id'] ?? null : $channelId;
    }

    protected function resolve ($name) {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Amqp connection [{$name}] is not defined.");
        }

        if (!isset($config['connection'])) {
            throw new AMQPException("[{$name}] connection properties are not defined.");
        }

        return $this->connect($config['connection']);
    }

    private function connect (array $config) : AbstractConnection {
        return new AMQPSSLConnection($config['host'], $config['port'], $config['username'], $config['password'], $config['vhost'], $config['ssl_options'] ?? [], $config['connect_options'] ?? [], $config['ssl_protocol'] ?? 'ssl');
    }

    protected function acquireChannel ($name, AbstractConnection $connection, $channelId) {
        return $this->channels[$name][$channelId] ?? ($this->channels[$name][$channelId] = $connection->channel($channelId));
    }

    /**
     * @param string|\Anik\Amqp\PublishableMessage $message
     * @param array                                $config
     *
     * @return \Anik\Amqp\PublishableMessage
     *
     * @throws \Anik\Amqp\Exceptions\AmqpException
     */
    private function constructMessage ($message, array $config = []) : PublishableMessage {
        $publishable = $message;
        if (is_string($message)) {
            $publishable = new PublishableMessage($message);
        } elseif ($message instanceof PublishableMessage) {
            $config = array_merge($message->getProperties(), $config);
        } else {
            throw new AmqpException('Message can be typeof string or Anik\Amqp\PublishableMessage.');
        }

        if (count($config)) {
            $publishable->setProperties($config);
        }

        return $publishable;
    }

    public function publish ($message, $routingKey, array $config = []) {
        /**
         * Connection and channels are not closed on purpose
         * - Check here "Don’t open and close connections or channels repeatedly" - https://www.cloudamqp.com/blog/2018-01-19-part4-rabbitmq-13-common-errors.html
         * - The connections are closed on destruction by default (w/ channels): https://github.com/php-amqplib/php-amqplib/blob/v2.9.2/PhpAmqpLib/Connection/AbstractConnection.php#L289
         * Close on destruct is default set to `true`
         */
        $name = $this->resolveConnectionName($config['connection'] ?? null);
        $connection = $this->getConnection($name);
        $channelId = $this->guessChannelId($config['channel_id'] ?? null, $name);
        $channel = $this->acquireChannel($name, $connection, $channelId);

        /* No exception raised, configuration available */
        $defaultConfig = $this->getConfig($name);

        $messageDefault = $defaultConfig['message'] ?? [];

        /* Set exchange properties */
        $exchangeConfig = array_merge($defaultConfig['exchange'], $config['exchange'] ?? []);

        $passableMessages = [];
        foreach ( (!is_array($message) ? [ $message ] : $message) as $msg ) {
            $pMsg = $this->constructMessage($msg, array_merge($messageDefault, $config['message'] ?? []));

            if ($exchange = $pMsg->getExchange()) {
                $pMsg->setExchange($exchange->mergeProperties($exchangeConfig));
            } else {
                $pMsg->setExchange(new Exchange($exchangeConfig['name'], $exchangeConfig));
            }

            $passableMessages[] = $pMsg;
        }

        /* @var \Anik\Amqp\Publisher $publisher */
        $publisher = app(Publisher::class);
        $publisher->setChannel($channel)->publishBulk($passableMessages, $routingKey);
    }
}