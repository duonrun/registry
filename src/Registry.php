<?php

declare(strict_types=1);

namespace Duon\Registry;

use Closure;
use Duon\Registry\Entry;
use Duon\Registry\Exception\NotFoundException;
use Duon\Wire\CallableResolver;
use Duon\Wire\Creator;
use Duon\Wire\Exception\WireException;
use Duon\Wire\WireContainer;
use Psr\Container\ContainerInterface as Container;

/**
 * @psalm-api
 *
 * @psalm-type EntryArray = array<never, never>|array<string, Entry>
 */
class Registry implements WireContainer
{
	protected Creator $creator;
	protected readonly ?Container $wrappedContainer;

	/** @psalm-var EntryArray */
	protected array $entries = [];

	/** @psalm-var array<never, never>|array<non-empty-string, self> */
	protected array $tags = [];

	protected bool $isFrozen = false;

	/** @psalm-var EntryArray */
	protected array $frozenEntries = [];

	public function __construct(
		public readonly bool $autowire = true,
		?Container $container = null,
		protected readonly string $tag = '',
		protected readonly ?Registry $parent = null,
	) {
		if ($container) {
			$this->wrappedContainer = $container;
			$this->add(Container::class, $container);
			$this->add($container::class, $container);
		} else {
			$this->wrappedContainer = null;
			$this->add(Container::class, $this);
		}
		$this->add(Registry::class, $this);
		$this->creator = new Creator($this);
	}

	#[\Override]
	public function has(string $id): bool
	{
		return isset($this->entries[$id]) || $this->parent?->has($id) || $this->wrappedContainer?->has($id);
	}

	/** @psalm-return list<string> */
	public function entries(bool $includeRegistry = false): array
	{
		$keys = array_keys($this->entries);

		if ($includeRegistry) {
			return $keys;
		}

		return array_values(array_filter($keys, function ($item) {
			return $item !== Container::class && !is_subclass_of($item, Container::class);
		}));
	}

	public function entry(string $id): Entry
	{
		return $this->entries[$id];
	}

	#[\Override]
	public function get(string $id): mixed
	{
		$entry = $this->entries[$id] ?? null;

		try {
			if ($entry) {
				return $this->resolveEntry($entry);
			}

			if ($this->wrappedContainer?->has($id)) {
				return $this->wrappedContainer->get($id);
			}

			// We are in a tag. See if the $id can be resolved by the parent
			// be registered on the root.
			if ($this->parent) {
				return $this->parent->get($id);
			}

			// Autowiring: $id does not exists as an entry in the registry
			if ($this->autowire && class_exists($id)) {
				if ($this->isFrozen) {
					// In frozen state, autowired entries are request-scoped by default
					$this->add($id)->requestScoped();
					return $this->get($id);
				}
				return $this->creator->create($id);
			}
		} catch (WireException $e) {
			throw new NotFoundException('Unresolvable id: ' . $id . ' - Details: ' . $e->getMessage());
		}

		throw new NotFoundException('Unresolvable id: ' . $id);
	}

	#[\Override]
	public function definition(string $id): mixed
	{
		$entry = $this->entries[$id] ?? null;

		if ($entry !== null) {
			return $entry->definition();
		}

		throw new NotFoundException('Unresolvable definition - id: ' . $id);
	}

	/**
	 * @psalm-param non-empty-string $id
	 */
	public function add(
		string $id,
		mixed $value = null,
	): Entry {
		$entry = new Entry($id, $value ?? $id);
		$this->entries[$id] = $entry;

		return $entry;
	}

	public function addEntry(
		Entry $entry,
	): Entry {
		$this->entries[$entry->id] = $entry;

		return $entry;
	}

	/** @psalm-param non-empty-string $tag */
	public function tag(string $tag): Registry
	{
		if (!isset($this->tags[$tag])) {
			$this->tags[$tag] = new self(tag: $tag, parent: $this);
		}

		return $this->tags[$tag];
	}

	public function new(string $id, mixed ...$args): object
	{
		$entry = $this->entries[$id] ?? null;

		if ($entry) {
			/** @var mixed */
			$value = $entry->definition();

			if (is_string($value)) {
				if (interface_exists($value)) {
					return $this->new($value, ...$args);
				}

				if (class_exists($value)) {
					/** @psalm-suppress MixedMethodCall */
					return new $value(...$args);
				}
			}
		}

		if (class_exists($id)) {
			/** @psalm-suppress MixedMethodCall */
			return new $id(...$args);
		}

		throw new NotFoundException('Cannot instantiate ' . $id);
	}

	protected function callAndReify(Entry $entry, mixed $value): mixed
	{
		foreach ($entry->getCalls() as $call) {
			$methodToResolve = $call->method;

			/** @psalm-var callable */
			$callable = [$value, $methodToResolve];
			$args = (new CallableResolver($this->creator))->resolve($callable, $call->args);
			$callable(...$args);
		}

		if ($entry->shouldReify()) {
			$entry->set($value);
		}

		return $value;
	}

	protected function resolveEntry(Entry $entry): mixed
	{
		if ($entry->shouldReturnAsIs()) {
			return $entry->definition();
		}

		/** @var mixed - the current value, instantiated or definition */
		$value = $entry->get();

		if (is_string($value)) {
			if (class_exists($value)) {
				$constructor = $entry->getConstructor();
				$args = $entry->getArgs();

				if (isset($args)) {
					// Don't autowire if $args are given
					if ($args instanceof Closure) {
						/** @psalm-var array<string, mixed> */
						$args = $args(...(new CallableResolver($this->creator))->resolve($args));

						return $this->callAndReify(
							$entry,
							$this->creator->create($value, $args),
						);
					}

					return $this->callAndReify(
						$entry,
						$this->creator->create(
							$value,
							predefinedArgs: $args,
							constructor: $constructor ?? '',
						),
					);
				}

				return $this->callAndReify(
					$entry,
					$this->creator->create($value, constructor: $constructor ?? ''),
				);
			}

			if ($this->has($value)) {
				return $this->get($value);
			}

			// If it's a string but not a class, return as-is only if asIs is true or it's not a class-like name
			if ($entry->shouldReturnAsIs() || !str_contains($value, '\\')) {
				return $value;
			}
		}

		if ($value instanceof Closure) {
			$args = $entry->getArgs();

			if (is_null($args)) {
				$args = (new CallableResolver($this->creator))->resolve($value);
			} elseif ($args instanceof Closure) {
				/** @var array<string, mixed> */
				$args = $args();
			}

			/** @var mixed */
			$result = $value(...$args);

			return $this->callAndReify($entry, $result);
		}

		if (is_object($value)) {
			return $value;
		}

		// Return scalar values and arrays as-is when asIs is true or for non-string types
		if ($entry->shouldReturnAsIs() || is_array($value) || is_null($value) || (is_scalar($value) && !is_string($value))) {
			return $value;
		}

		throw new NotFoundException('Unresolvable id: ' . (string) $value);
	}

	public function freeze(): void
	{
		if ($this->isFrozen) {
			return;
		}

		$this->frozenEntries = [];
		foreach ($this->entries as $id => $entry) {
			$this->frozenEntries[$id] = clone $entry;
		}

		$this->isFrozen = true;

		foreach ($this->tags as $tag) {
			$tag->freeze();
		}
	}

	public function isFrozen(): bool
	{
		return $this->isFrozen;
	}

	public function reset(): void
	{
		if (!$this->isFrozen) {
			throw new Exception\ContainerException('Cannot reset unfrozen container');
		}

		foreach ($this->entries as $entry) {
			if ($entry->isRequestScoped()) {
				$entry->resetInstance();
			}
		}

		foreach ($this->tags as $tag) {
			$tag->reset();
		}
	}

	public function cloneForRequest(): self
	{
		if (!$this->isFrozen) {
			throw new Exception\ContainerException('Cannot clone unfrozen container');
		}

		$clone = new self(
			$this->autowire,
			$this->wrappedContainer,
			$this->tag,
			$this->parent
		);

		$clone->isFrozen = true;
		$clone->frozenEntries = $this->frozenEntries;

		foreach ($this->frozenEntries as $id => $entry) {
			$clone->entries[$id] = clone $entry;
		}

		foreach ($this->tags as $tagName => $tag) {
			$clone->tags[$tagName] = $tag->cloneForRequest();
		}

		return $clone;
	}
}
