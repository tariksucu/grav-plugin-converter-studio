<?php

/**
 * The few Grav classes the plugin's own class touches, reduced to what its tests need, so that
 * converter-studio.php can be loaded and its listeners called without a Grav site. Loaded only by
 * PluginTest, and only when Grav itself is not there.
 */

declare(strict_types=1);

namespace Grav\Common {

    if (!class_exists(Grav::class, false)) {
        /** Grav's service container (Pimple), as an array. */
        class Grav implements \ArrayAccess
        {
            /** @var array<string, mixed> */
            private array $services = [];

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->services[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->services[$offset];
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->services[$offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->services[$offset]);
            }
        }

        abstract class Plugin
        {
            /** @var Grav */
            protected $grav;

            /** @var Config\Config */
            protected $config;

            public function __construct(public string $name, Grav $grav, ?Config\Config $config = null)
            {
                $this->grav = $grav;
                $this->config = $config ?? $grav['config'];
            }
        }
    }
}

namespace Grav\Common\Config {

    if (!class_exists(Config::class, false)) {
        /** Grav's configuration: values by dotted path. */
        class Config
        {
            /** @param array<string, mixed> $items */
            public function __construct(public array $items = [])
            {
            }

            public function get(string $path, mixed $default = null): mixed
            {
                $value = $this->items;
                foreach (explode('.', $path) as $key) {
                    if (!is_array($value) || !array_key_exists($key, $value)) {
                        return $default;
                    }
                    $value = $value[$key];
                }
                return $value;
            }

            public function set(string $path, mixed $value): void
            {
                $target = &$this->items;
                foreach (explode('.', $path) as $key) {
                    if (!isset($target[$key]) || !is_array($target[$key])) {
                        $target[$key] = [];
                    }
                    $target = &$target[$key];
                }
                $target = $value;
            }
        }
    }
}

namespace Grav\Common\Page\Interfaces {

    if (!interface_exists(PageInterface::class, false)) {
        interface PageInterface
        {
            public function path($var = null);

            public function rawMarkdown($var = null);

            public function header($var = null);
        }
    }
}

namespace RocketTheme\Toolbox\Event {

    if (!class_exists(Event::class, false)) {
        class Event implements \ArrayAccess
        {
            /** @param array<string, mixed> $items */
            public function __construct(private array $items = [])
            {
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->items[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->items[$offset];
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->items[$offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->items[$offset]);
            }
        }
    }
}
