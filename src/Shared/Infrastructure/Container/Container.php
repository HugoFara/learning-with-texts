<?php

/**
 * Dependency Injection Container
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Container
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Container;

/**
 * PSR-11 compatible Dependency Injection Container.
 *
 * This container supports:
 * - Service registration (singletons, factories, instances)
 * - Auto-wiring via reflection
 * - Service aliases
 * - Lazy loading
 *
 * Usage:
 * ```php
 * $container = new Container();
 *
 * // Register a singleton (created once, reused)
 * $container->singleton(LanguageFacade::class, function($c) {
 *     return new LanguageFacade($c->get(LanguageRepositoryInterface::class));
 * });
 *
 * // Register a factory (new instance each time)
 * $container->bind(SomeService::class, function($c) {
 *     return new SomeService();
 * });
 *
 * // Register an existing instance
 * $container->instance('config', $configArray);
 *
 * // Auto-wire a class (container resolves dependencies automatically)
 * $service = $container->get(LanguageFacade::class);
 * ```
 *
 * @since 3.0.0
 */
class Container implements ContainerInterface
{
    /**
     * The global container instance (singleton pattern for app-wide access)
     *
     * @var Container|null
     */
    private static ?Container $instance = null;

    /**
     * Registered service bindings
     *
     * @var array<string, array{factory: callable, singleton: bool}>
     */
    private array $bindings = [];

    /**
     * Resolved singleton instances
     *
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Service aliases
     *
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * Services currently being resolved (for circular dependency detection)
     *
     * @var array<string, bool>
     */
    private array $resolving = [];

    /**
     * Get the global container instance.
     *
     * @return Container
     */
    public static function getInstance(): Container
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set the global container instance.
     *
     * @param Container|null $container Container instance or null to reset
     *
     * @return void
     */
    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    /**
     * Register a binding in the container.
     *
     * @param string   $abstract  The abstract type or service name
     * @param callable $factory   Factory function that creates the service
     * @param bool     $singleton Whether to cache the instance
     *
     * @return void
     */
    public function bind(string $abstract, callable $factory, bool $singleton = false): void
    {
        $this->bindings[$abstract] = [
            'factory' => $factory,
            'singleton' => $singleton,
        ];
    }

    /**
     * Register a singleton binding.
     *
     * The factory will be called once, and the result cached for subsequent calls.
     *
     * @param string   $abstract The abstract type or service name
     * @param callable $factory  Factory function that creates the service
     *
     * @return void
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->bind($abstract, $factory, true);
    }

    /**
     * Register an existing instance in the container.
     *
     * @param string $abstract The abstract type or service name
     * @param mixed  $instance The service instance
     *
     * @return void
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Register an alias for a service.
     *
     * @param string $alias    The alias name
     * @param string $abstract The actual service name
     *
     * @return void
     */
    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): mixed
    {
        return $this->doGet($id);
    }

    /**
     * Get a service with type inference for static analysis.
     *
     * This method is identical to get() but provides better type inference
     * for static analysis tools like Psalm. Use this when retrieving services
     * by their class name.
     *
     * @template T of object
     * @param class-string<T> $id The service class name
     * @return T The resolved service instance
     *
     * @throws NotFoundException  If service cannot be resolved
     * @throws ContainerException If error while retrieving the entry
     */
    public function getTyped(string $id): object
    {
        /** @var mixed $instance */
        $instance = $this->doGet($id);
        /** @var T */
        return $instance;
    }

    /**
     * Internal service resolution logic.
     *
     * @param string $id The service identifier
     * @return mixed The resolved service
     *
     * @throws NotFoundException  If service cannot be resolved
     * @throws ContainerException If circular dependency detected
     */
    private function doGet(string $id): mixed
    {
        // Resolve aliases
        $id = $this->resolveAlias($id);

        // Return cached instance if available
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Check for circular dependencies
        if (isset($this->resolving[$id])) {
            throw new ContainerException(
                "Circular dependency detected while resolving '$id'"
            );
        }

        $this->resolving[$id] = true;

        try {
            /** @var mixed $instance */
            $instance = $this->resolve($id);
        } finally {
            unset($this->resolving[$id]);
        }

        return $instance;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        return isset($this->instances[$id])
            || isset($this->bindings[$id])
            || $this->canAutoWire($id);
    }

    /**
     * Resolve an alias to its actual service name.
     *
     * @param string $id The service identifier
     *
     * @return string The resolved service name
     */
    private function resolveAlias(string $id): string
    {
        while (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }
        return $id;
    }

    /**
     * Resolve a service from the container.
     *
     * @param string $id The service identifier
     *
     * @return mixed The resolved service
     *
     * @throws NotFoundException If service cannot be resolved
     */
    private function resolve(string $id): mixed
    {
        // Use registered binding if available
        if (isset($this->bindings[$id])) {
            $binding = $this->bindings[$id];
            /** @var mixed $instance */
            $instance = ($binding['factory'])($this);

            if ($binding['singleton']) {
                $this->instances[$id] = $instance;
            }

            return $instance;
        }

        // Try auto-wiring
        if ($this->canAutoWire($id)) {
            return $this->autoWire($id);
        }

        throw new NotFoundException("Service '$id' not found in container");
    }

    /**
     * Check if a class can be auto-wired.
     *
     * @param string $class The class name
     *
     * @return bool
     */
    private function canAutoWire(string $class): bool
    {
        return class_exists($class);
    }

    /**
     * Auto-wire a class by resolving its constructor dependencies.
     *
     * @param string $class The class name
     *
     * @return object The instantiated class
     *
     * @throws ContainerException If auto-wiring fails
     */
    private function autoWire(string $class): object
    {
        /** @var class-string $class */
        $reflector = new \ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException(
                "Class '$class' is not instantiable"
            );
        }

        $constructor = $reflector->getConstructor();

        // No constructor = no dependencies
        if ($constructor === null) {
            // @phpstan-ignore-next-line Class existence already checked via ReflectionClass
            return $reflector->newInstance();
        }

        $parameters = $constructor->getParameters();
        $dependencies = $this->resolveDependencies($parameters);

        /** @var list<mixed> $dependencies */
        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor dependencies.
     *
     * @param \ReflectionParameter[] $parameters Constructor parameters
     *
     * @return list<mixed> Resolved dependencies
     *
     * @throws ContainerException If a dependency cannot be resolved
     */
    private function resolveDependencies(array $parameters): array
    {
        return array_values(array_map(
            fn(\ReflectionParameter $param): mixed => $this->resolveDependency($param),
            $parameters
        ));
    }

    /**
     * Resolve parameters with optional overrides.
     *
     * @param \ReflectionParameter[]   $parameters  Parameters to resolve
     * @param array<string, mixed>     $overrides   Override values by parameter name
     *
     * @return list<mixed> Resolved dependencies
     */
    private function resolveParametersWithOverrides(array $parameters, array $overrides): array
    {
        return array_values(array_map(
            function (\ReflectionParameter $param) use ($overrides): mixed {
                $paramName = $param->getName();
                if (array_key_exists($paramName, $overrides)) {
                    return $overrides[$paramName];
                }
                return $this->resolveDependency($param);
            },
            $parameters
        ));
    }

    /**
     * Resolve a single dependency.
     *
     * @param \ReflectionParameter $parameter The parameter to resolve
     *
     * @return mixed The resolved dependency
     *
     * @throws ContainerException If dependency cannot be resolved
     */
    private function resolveDependency(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        // Handle union types and intersection types
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            // Try each type in order
            foreach ($type->getTypes() as $subType) {
                if ($subType instanceof \ReflectionNamedType && !$subType->isBuiltin()) {
                    $typeName = $subType->getName();
                    if ($this->has($typeName)) {
                        return $this->get($typeName);
                    }
                }
            }
        }

        // Handle named types (class/interface)
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();
            return $this->get($typeName);
        }

        // Handle default values
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // Handle nullable types
        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        $paramName = $parameter->getName();
        $className = $parameter->getDeclaringClass()?->getName() ?? 'unknown';

        throw new ContainerException(
            "Cannot resolve parameter '\${$paramName}' in class '$className'"
        );
    }

    /**
     * Create a new instance with method injection.
     *
     * @param string $class  The class name
     * @param string $method The method name
     * @param array<string, mixed>  $params Additional parameters to pass
     *
     * @return mixed The method return value
     */
    public function call(string $class, string $method, array $params = []): mixed
    {
        /** @var object $instance */
        $instance = $this->get($class);
        $reflector = new \ReflectionMethod($instance, $method);

        $parameters = $reflector->getParameters();
        $dependencies = $this->resolveParametersWithOverrides($parameters, $params);

        return $reflector->invokeArgs($instance, $dependencies);
    }

    /**
     * Make a new instance of a class (always fresh, never cached).
     *
     * @param string $class  The class name
     * @param array<string, mixed>  $params Constructor parameters to override
     *
     * @return object The new instance
     */
    public function make(string $class, array $params = []): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException("Class '$class' not found");
        }

        $reflector = new \ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class '$class' is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return $reflector->newInstance();
        }

        $parameters = $constructor->getParameters();
        $dependencies = $this->resolveParametersWithOverrides($parameters, $params);

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Reset the container (primarily for testing).
     *
     * @return void
     */
    public function reset(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->resolving = [];
    }

    /**
     * Get all registered service IDs.
     *
     * @return string[]
     */
    public function getRegisteredServices(): array
    {
        return array_unique(
            array_merge(
                array_keys($this->bindings),
                array_keys($this->instances)
            )
        );
    }
}
