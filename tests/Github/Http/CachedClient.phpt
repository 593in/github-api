<?php

/**
 * @author  Miloslav Hůla
 *
 * @testCase
 */


require __DIR__ . '/../../bootstrap.php';

use Milo\Github\Http;


class MockClient implements Http\IClient
{
	/** @var callable */
	public $onRequest;

	/** @var int */
	public $requestCount = 0;


	public function request(Http\Request $request)
	{
		$response = call_user_func($this->onRequest, $request);
		$this->requestCount++;
		return $response;
	}

	public function onRequest($foo)
	{
		trigger_error('Inner onRequest called: ' . var_export($foo, TRUE), E_USER_NOTICE);
	}

	public function onResponse($foo)
	{
		trigger_error('Inner onResponse called: ' . var_export($foo, TRUE), E_USER_NOTICE);
	}

}


class MockCache implements Milo\Github\Storages\ICache
{
	private $cache = [];

	public function save($key, $value) {
		return $this->cache[$key] = $value;
	}

	public function load($key)
	{
		return isset($this->cache[$key])
			? $this->cache[$key]
			: NULL;
	}

}


class CachingTestCase extends Tester\TestCase
{
	/** @var Http\CachedClient */
	private $client;

	/** @var MockClient */
	private $innerClient;


	public function setup()
	{
		$cache = new MockCache;
		$this->innerClient = new MockClient;
		$this->client = new Http\CachedClient($cache, $this->innerClient);

		$this->innerClient->onRequest = function (Http\Request $request) {
			return $request->hasHeader('If-None-Match')
				? new Http\Response(304, [], "inner-304-{$request->getContent()}")
				: new Http\Response(200, ['ETag' => '"inner"'], "inner-200-{$request->getContent()}");
		};
	}


	public function testSetOnRequestOnResponseCallbacks()
	{
		Assert::same($this->innerClient, $this->client->getInnerClient());

		Assert::error(function() {
			Assert::same($this->client, $this->client->onRequest('callback-1'));
			Assert::same($this->client, $this->client->onResponse('callback-2'));
		}, [
			[E_USER_NOTICE, "Inner onRequest called: 'callback-1'"],
			[E_USER_NOTICE, 'Inner onResponse called: NULL'],
		]);

		$onResponseCalled = FALSE;
		Assert::error(function() use (& $onResponseCalled) {
			$this->client->onResponse(function() use (& $onResponseCalled) {
				$onResponseCalled = TRUE;
			});
		}, E_USER_NOTICE, 'Inner onResponse called: NULL');

		$this->client->request(new Http\Request('', ''));
		Assert::true($onResponseCalled);

		Assert::same(1, $this->innerClient->requestCount);
	}


	public function testNoCaching()
	{
		$this->innerClient->onRequest = function (Http\Request $request) {
			Assert::false($request->hasHeader('ETag'));
			Assert::false($request->hasHeader('If-Modified-Since'));

			return new Http\Response(200, [], "response-{$request->getContent()}");
		};

		$response = $this->client->request(new Http\Request('', '', [], '1'));
		Assert::same('response-1', $response->getContent());
		Assert::same(1, $this->innerClient->requestCount);

		$response = $this->client->request(new Http\Request('', '', [], '2'));
		Assert::same('response-2', $response->getContent());
		Assert::same(2, $this->innerClient->requestCount);
	}


	public function testETagCaching()
	{
		$this->innerClient->onRequest = function (Http\Request $request) {
			Assert::false($request->hasHeader('If-None-Match'));
			Assert::false($request->hasHeader('If-Modified-Since'));

			return new Http\Response(200, ['ETag' => 'e-tag'], "response-{$request->getContent()}");
		};

		$response = $this->client->request(new Http\Request('', '', [], '1'));
		Assert::same('response-1', $response->getContent());
		Assert::same(1, $this->innerClient->requestCount);


		$this->innerClient->onRequest = function (Http\Request $request) {
			Assert::same('e-tag', $request->getHeader('If-None-Match'));
			Assert::false($request->hasHeader('If-Modified-Since'));

			return new Http\Response(304, [], "response-{$request->getContent()}");
		};
		$response = $this->client->request(new Http\Request('', '', [], '2'));
		Assert::same('response-1', $response->getContent());
		Assert::type('Milo\Github\Http\Response', $response->getPrevious());
		Assert::same(304, $response->getPrevious()->getCode());
		Assert::same(2, $this->innerClient->requestCount);
	}


	public function testIfModifiedCaching()
	{
		$this->innerClient->onRequest = function (Http\Request $request) {
			Assert::false($request->hasHeader('If-None-Match'));
			Assert::false($request->hasHeader('If-Modified-Since'));

			return new Http\Response(200, ['Last-Modified' => 'today'], "response-{$request->getContent()}");
		};

		$response = $this->client->request(new Http\Request('', '', [], '1'));
		Assert::same('response-1', $response->getContent());
		Assert::same(1, $this->innerClient->requestCount);


		$this->innerClient->onRequest = function (Http\Request $request) {
			Assert::false($request->hasHeader('ETag'));
			Assert::same('today', $request->getHeader('If-Modified-Since'));

			return new Http\Response(304, [], "response-{$request->getContent()}");
		};

		$response = $this->client->request(new Http\Request('', '', [], '2'));
		Assert::same('response-1', $response->getContent());
		Assert::type('Milo\Github\Http\Response', $response->getPrevious());
		Assert::same(304, $response->getPrevious()->getCode());
		Assert::same(2, $this->innerClient->requestCount);
	}


	public function testRepeatedRequest()
	{
		$request = new Http\Request('', '', [], 'same');

		# Empty cache
		$response = $this->client->request($request);
		Assert::same('inner-200-same', $response->getContent());
		Assert::null($response->getPrevious());
		Assert::same(1, $this->innerClient->requestCount);

		# From cache
		$response = $this->client->request($request);
		Assert::same('inner-200-same', $response->getContent());
		Assert::type('Milo\Github\Http\Response', $response->getPrevious());
		Assert::same('inner-304-same', $response->getPrevious()->getContent());
		Assert::same(2, $this->innerClient->requestCount);

		# Again
		$response = $this->client->request($request);
		Assert::same('inner-200-same', $response->getContent());
		Assert::type('Milo\Github\Http\Response', $response->getPrevious());
		Assert::same('inner-304-same', $response->getPrevious()->getContent());
		Assert::same(3, $this->innerClient->requestCount);
	}


	public function testForbidRecheckDisabled()
	{
		$request = new Http\Request('', '', [], 'disabled');

		$response = $this->client->request($request);
		Assert::same('inner-200-disabled', $response->getContent());
		Assert::null($response->getPrevious());
		Assert::same(1, $this->innerClient->requestCount);

		$response = $this->client->request($request);
		Assert::same('inner-200-disabled', $response->getContent());
		Assert::type('Milo\Github\Http\Response', $response->getPrevious());
		Assert::same('inner-304-disabled', $response->getPrevious()->getContent());
		Assert::same(2, $this->innerClient->requestCount);

		$response = $this->client->request($request);
		Assert::same('inner-200-disabled', $response->getContent());
		Assert::type('Milo\Github\Http\Response', $response->getPrevious());
		Assert::same('inner-304-disabled', $response->getPrevious()->getContent());
		Assert::same(3, $this->innerClient->requestCount);
	}


	public function testForbidRecheckEnabled()
	{
		$this->client = new Http\CachedClient(new MockCache, $this->innerClient, TRUE);

		$request = new Http\Request('', '', [], 'enabled');

		$response = $this->client->request($request);
		Assert::same('inner-200-enabled', $response->getContent());
		Assert::null($response->getPrevious());
		Assert::same(1, $this->innerClient->requestCount);

		$response = $this->client->request($request);
		Assert::same('inner-200-enabled', $response->getContent());
		Assert::null($response->getPrevious());
		Assert::same(1, $this->innerClient->requestCount);

		$response = $this->client->request($request);
		Assert::same('inner-200-enabled', $response->getContent());
		Assert::null($response->getPrevious());
		Assert::same(1, $this->innerClient->requestCount);
	}

}

(new CachingTestCase)->run();
