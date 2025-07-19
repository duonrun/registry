<?php

declare(strict_types=1);

namespace Duon\Registry\Tests;

use Duon\Registry\Registry;
use Duon\Registry\Exception\ContainerException;
use PHPUnit\Framework\TestCase;

class WorkerModeTest extends TestCase
{
	public function testFreezeContainer(): void
	{
		$registry = new Registry();
		$registry->add('config', ['db' => 'mysql://localhost']);
		$registry->add('router', new \stdClass());

		$this->assertFalse($registry->isFrozen());

		$registry->freeze();

		$this->assertTrue($registry->isFrozen());
	}

	public function testRequestScopedEntries(): void
	{
		$registry = new Registry();
		
		// Boot-time entries (shared across requests)
		$registry->add('config', ['db' => 'mysql://localhost']);
		$registry->add('router', new \stdClass());
		
		// Request-scoped entries
		$registry->add('user_session', \Duon\Registry\Tests\Fixtures\UserSession::class)->requestScoped();
		$registry->add('request_id', fn() => uniqid())->requestScoped();

		$entry = $registry->entry('user_session');
		$this->assertTrue($entry->isRequestScoped());

		$configEntry = $registry->entry('config');
		$this->assertFalse($configEntry->isRequestScoped());
	}

	public function testCloneForRequest(): void
	{
		$registry = new Registry();
		
		// Boot-time setup
		$config = ['db' => 'mysql://localhost'];
		$router = new \stdClass();
		$router->routes = ['/' => 'HomeController'];
		
		$registry->add('config', $config);
		$registry->add('router', $router);
		$registry->add('user_session', \Duon\Registry\Tests\Fixtures\UserSession::class)->requestScoped();
		
		$registry->freeze();

		// Clone for request 1
		$request1 = $registry->cloneForRequest();
		$this->assertTrue($request1->isFrozen());
		$this->assertSame($config, $request1->get('config'));
		$this->assertSame($router, $request1->get('router'));

		// Clone for request 2
		$request2 = $registry->cloneForRequest();
		$this->assertNotSame($request1, $request2);
		$this->assertSame($config, $request2->get('config')); // Shared boot-time data
		$this->assertSame($router, $request2->get('router')); // Shared boot-time data
	}

	public function testResetRequestScopedEntries(): void
	{
		$registry = new Registry();
		
		$registry->add('config', ['db' => 'mysql://localhost']);
		$registry->add('counter', fn() => rand(1, 1000))->requestScoped();
		
		$registry->freeze();

		// Get initial value
		$firstValue = $registry->get('counter');
		$secondValue = $registry->get('counter'); // Should be same (reified)
		$this->assertSame($firstValue, $secondValue);

		// Reset request-scoped entries
		$registry->reset();
		
		// Get new value after reset
		$thirdValue = $registry->get('counter');
		$this->assertNotSame($firstValue, $thirdValue);
	}

	public function testAutowiredEntriesInFrozenState(): void
	{
		$registry = new Registry();
		$registry->add('config', ['test' => true]);
		$registry->freeze();

		// Autowiring in frozen state should create request-scoped entries
		$stdClass = $registry->get(\stdClass::class);
		$entry = $registry->entry(\stdClass::class);
		
		$this->assertTrue($entry->isRequestScoped());
		$this->assertInstanceOf(\stdClass::class, $stdClass);
	}

	public function testCannotResetUnfrozenContainer(): void
	{
		$registry = new Registry();
		$registry->add('test', 'value');

		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('Cannot reset unfrozen container');
		
		$registry->reset();
	}

	public function testCannotCloneUnfrozenContainer(): void
	{
		$registry = new Registry();
		$registry->add('test', 'value');

		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('Cannot clone unfrozen container');
		
		$registry->cloneForRequest();
	}

	public function testTaggedRegistriesInWorkerMode(): void
	{
		$registry = new Registry();
		$registry->add('global_config', ['env' => 'prod']);
		
		$httpTag = $registry->tag('http');
		$httpTag->add('request_method', 'GET')->requestScoped();
		
		$apiTag = $registry->tag('api');
		$apiTag->add('api_version', 'v1')->requestScoped();

		$registry->freeze();

		$clone = $registry->cloneForRequest();
		
		$this->assertTrue($clone->tag('http')->isFrozen());
		$this->assertTrue($clone->tag('api')->isFrozen());
		
		$this->assertSame('GET', $clone->tag('http')->get('request_method'));
		$this->assertSame('v1', $clone->tag('api')->get('api_version'));
	}

	public function testWorkerModeWorkflow(): void
	{
		// Simulate application boot
		$registry = new Registry();
		
		// Boot-time setup (shared across all requests)
		$dbPool = new \stdClass();
		$dbPool->connections = ['primary', 'replica'];
		
		$router = new \stdClass();
		$router->routes = [
			'/' => 'HomeController',
			'/api' => 'ApiController'
		];
		
		$registry->add('db_pool', $dbPool);
		$registry->add('router', $router);
		$registry->add('config', ['env' => 'production', 'debug' => false]);
		
		// Freeze after boot
		$registry->freeze();
		
		// Simulate multiple requests
		for ($i = 0; $i < 3; $i++) {
			$requestContainer = $registry->cloneForRequest();
			
			// Add request-specific data
			$requestContainer->add('request_id', "req-{$i}")->requestScoped();
			$requestContainer->add('user_id', $i * 100)->requestScoped();
			
			// Verify shared boot-time data is accessible
			$this->assertSame($dbPool, $requestContainer->get('db_pool'));
			$this->assertSame($router, $requestContainer->get('router'));
			$this->assertSame(['env' => 'production', 'debug' => false], $requestContainer->get('config'));
			
			// Verify request-specific data
			$this->assertSame("req-{$i}", $requestContainer->get('request_id'));
			$this->assertSame($i * 100, $requestContainer->get('user_id'));
		}
		
		// Original container remains unchanged
		$this->assertFalse($registry->has('request_id'));
		$this->assertFalse($registry->has('user_id'));
	}
}