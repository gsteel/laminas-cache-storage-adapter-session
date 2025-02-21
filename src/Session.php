<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\IterableInterface;
use Laminas\Session\Container as SessionContainer;
use stdClass;
use Traversable;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function strpos;

final class Session extends AbstractAdapter implements
    ClearByPrefixInterface,
    FlushableInterface,
    IterableInterface
{
    /**
     * Set options.
     *
     * @see    getOptions()
     *
     * @param array|Traversable|SessionOptions $options
     * @return Session
     */
    public function setOptions($options)
    {
        if (! $options instanceof SessionOptions) {
            $options = new SessionOptions($options);
        }

        return parent::setOptions($options);
    }

    /**
     * Get options.
     *
     * @see setOptions()
     *
     * @return SessionOptions
     */
    public function getOptions()
    {
        if (! $this->options) {
            $this->setOptions(new SessionOptions());
        }
        return $this->options;
    }

    /**
     * Get the session container
     *
     * @return SessionContainer
     */
    protected function getSessionContainer()
    {
        $sessionContainer = $this->getOptions()->getSessionContainer();
        if (! $sessionContainer) {
            throw new Exception\RuntimeException("No session container configured");
        }
        return $sessionContainer;
    }

    /* IterableInterface */

    /**
     * Get the storage iterator
     */
    public function getIterator(): KeyListIterator
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if ($cntr->offsetExists($ns)) {
            $keys = array_keys($cntr->offsetGet($ns));
        } else {
            $keys = [];
        }

        return new KeyListIterator($this, $keys);
    }

    /* FlushableInterface */

    /**
     * Flush the whole session container
     *
     * @return bool
     */
    public function flush()
    {
        $this->getSessionContainer()->exchangeArray([]);
        return true;
    }

    /* ClearByPrefixInterface */

    /**
     * Remove items matching given prefix
     *
     * @param string $prefix
     * @return bool
     */
    public function clearByPrefix($prefix)
    {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            throw new Exception\InvalidArgumentException('No prefix given');
        }

        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if (! $cntr->offsetExists($ns)) {
            return true;
        }

        $data = $cntr->offsetGet($ns);
        foreach ($data as $key => &$item) {
            if (strpos($key, $prefix) === 0) {
                unset($data[$key]);
            }
        }
        $cntr->offsetSet($ns, $data);

        return true;
    }

    /* reading */

    /**
     * Internal method to get an item.
     *
     * @param  string  $normalizedKey
     * @param  bool $success
     * @param  mixed   $casToken
     * @return mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetItem(&$normalizedKey, &$success = null, &$casToken = null)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if (! $cntr->offsetExists($ns)) {
            $success = false;
            return;
        }

        $data    = $cntr->offsetGet($ns);
        $success = array_key_exists($normalizedKey, $data);
        if (! $success) {
            return;
        }

        $value    = $data[$normalizedKey];
        $casToken = $value;
        return $value;
    }

    /**
     * Internal method to get multiple items.
     *
     * @param  array $normalizedKeys
     * @return array Associative array of keys and values
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetItems(array &$normalizedKeys)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if (! $cntr->offsetExists($ns)) {
            return [];
        }

        $data   = $cntr->offsetGet($ns);
        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if (array_key_exists($normalizedKey, $data)) {
                $result[$normalizedKey] = $data[$normalizedKey];
            }
        }

        return $result;
    }

    /**
     * Internal method to test if an item exists.
     *
     * @param  string $normalizedKey
     * @return bool
     */
    protected function internalHasItem(&$normalizedKey)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if (! $cntr->offsetExists($ns)) {
            return false;
        }

        $data = $cntr->offsetGet($ns);
        return array_key_exists($normalizedKey, $data);
    }

    /**
     * Internal method to test multiple items.
     *
     * @param array $normalizedKeys
     * @return array Array of found keys
     */
    protected function internalHasItems(array &$normalizedKeys)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if (! $cntr->offsetExists($ns)) {
            return [];
        }

        $data   = $cntr->offsetGet($ns);
        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if (array_key_exists($normalizedKey, $data)) {
                $result[] = $normalizedKey;
            }
        }

        return $result;
    }

    /**
     * Get metadata of an item.
     *
     * @param  string $normalizedKey
     * @return array|bool Metadata on success, false on failure
     * @throws Exception\ExceptionInterface
     * @triggers getMetadata.pre(PreEvent)
     * @triggers getMetadata.post(PostEvent)
     * @triggers getMetadata.exception(ExceptionEvent)
     */
    protected function internalGetMetadata(&$normalizedKey)
    {
        return $this->internalHasItem($normalizedKey) ? [] : false;
    }

    /* writing */

    /**
     * Internal method to store an item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItem(&$normalizedKey, &$value)
    {
        $cntr                 = $this->getSessionContainer();
        $ns                   = $this->getOptions()->getNamespace();
        $data                 = $cntr->offsetExists($ns) ? $cntr->offsetGet($ns) : [];
        $data[$normalizedKey] = $value;
        $cntr->offsetSet($ns, $data);
        return true;
    }

    /**
     * Internal method to store multiple items.
     *
     * @param  array $normalizedKeyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItems(array &$normalizedKeyValuePairs)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if ($cntr->offsetExists($ns)) {
            $data = array_merge($cntr->offsetGet($ns), $normalizedKeyValuePairs);
        } else {
            $data = $normalizedKeyValuePairs;
        }
        $cntr->offsetSet($ns, $data);

        return [];
    }

    /**
     * Add an item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItem(&$normalizedKey, &$value)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if ($cntr->offsetExists($ns)) {
            $data = $cntr->offsetGet($ns);

            if (array_key_exists($normalizedKey, $data)) {
                return false;
            }

            $data[$normalizedKey] = $value;
        } else {
            $data = [$normalizedKey => $value];
        }

        $cntr->offsetSet($ns, $data);
        return true;
    }

    /**
     * Internal method to add multiple items.
     *
     * @param  array $normalizedKeyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItems(array &$normalizedKeyValuePairs)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        $result = [];
        if ($cntr->offsetExists($ns)) {
            $data = $cntr->offsetGet($ns);

            foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
                if (array_key_exists($normalizedKey, $data)) {
                    $result[] = $normalizedKey;
                } else {
                    $data[$normalizedKey] = $value;
                }
            }
        } else {
            $data = $normalizedKeyValuePairs;
        }

        $cntr->offsetSet($ns, $data);
        return $result;
    }

    /**
     * Internal method to replace an existing item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalReplaceItem(&$normalizedKey, &$value)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if (! $cntr->offsetExists($ns)) {
            return false;
        }

        $data = $cntr->offsetGet($ns);
        if (! array_key_exists($normalizedKey, $data)) {
            return false;
        }
        $data[$normalizedKey] = $value;
        $cntr->offsetSet($ns, $data);

        return true;
    }

    /**
     * Internal method to replace multiple existing items.
     *
     * @param  array $normalizedKeyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalReplaceItems(array &$normalizedKeyValuePairs)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();
        if (! $cntr->offsetExists($ns)) {
            return array_keys($normalizedKeyValuePairs);
        }

        $data   = $cntr->offsetGet($ns);
        $result = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (! array_key_exists($normalizedKey, $data)) {
                $result[] = $normalizedKey;
            } else {
                $data[$normalizedKey] = $value;
            }
        }
        $cntr->offsetSet($ns, $data);

        return $result;
    }

    /**
     * Internal method to remove an item.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalRemoveItem(&$normalizedKey)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if (! $cntr->offsetExists($ns)) {
            return false;
        }

        $data = $cntr->offsetGet($ns);
        if (! array_key_exists($normalizedKey, $data)) {
            return false;
        }

        unset($data[$normalizedKey]);

        if (! $data) {
            $cntr->offsetUnset($ns);
        } else {
            $cntr->offsetSet($ns, $data);
        }

        return true;
    }

    /**
     * Internal method to increment an item.
     *
     * @param  string $normalizedKey
     * @param  int    $value
     * @return int|bool The new value on success, false on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalIncrementItem(&$normalizedKey, &$value)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if ($cntr->offsetExists($ns)) {
            $data = $cntr->offsetGet($ns);
        } else {
            $data = [];
        }

        if (array_key_exists($normalizedKey, $data)) {
            $data[$normalizedKey] += $value;
            $newValue              = $data[$normalizedKey];
        } else {
            // initial value
            $newValue             = $value;
            $data[$normalizedKey] = $newValue;
        }

        $cntr->offsetSet($ns, $data);
        return $newValue;
    }

    /**
     * Internal method to decrement an item.
     *
     * @param  string $normalizedKey
     * @param  int    $value
     * @return int|bool The new value on success, false on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalDecrementItem(&$normalizedKey, &$value)
    {
        $cntr = $this->getSessionContainer();
        $ns   = $this->getOptions()->getNamespace();

        if ($cntr->offsetExists($ns)) {
            $data = $cntr->offsetGet($ns);
        } else {
            $data = [];
        }

        if (array_key_exists($normalizedKey, $data)) {
            $data[$normalizedKey] -= $value;
            $newValue              = $data[$normalizedKey];
        } else {
            // initial value
            $newValue             = -$value;
            $data[$normalizedKey] = $newValue;
        }

        $cntr->offsetSet($ns, $data);
        return $newValue;
    }

    /* status */

    /**
     * Internal method to get capabilities of this adapter
     *
     * @return Capabilities
     */
    protected function internalGetCapabilities()
    {
        if ($this->capabilities === null) {
            $this->capabilityMarker = new stdClass();
            $this->capabilities     = new Capabilities(
                $this,
                $this->capabilityMarker,
                [
                    'supportedDatatypes' => [
                        'NULL'     => true,
                        'boolean'  => true,
                        'integer'  => true,
                        'double'   => true,
                        'string'   => true,
                        'array'    => 'array',
                        'object'   => 'object',
                        'resource' => false,
                    ],
                    'supportedMetadata'  => [],
                    'minTtl'             => 0,
                    'maxKeyLength'       => 0,
                    'namespaceIsPrefix'  => false,
                    'namespaceSeparator' => '',
                ]
            );
        }

        return $this->capabilities;
    }
}
