<?php

/**
 * @author  Miloslav Hůla
 */


require __DIR__ . '/../../bootstrap.php';


class TestClient extends Milo\Github\Http\Client
{
	/** @var callable */
	public $onFileGetContents;

	protected function fileGetContents($url, array $contextOptions)
	{
		return call_user_func($this->onFileGetContents, $url, $contextOptions);
	}

}


$request = new Milo\Github\Http\Request('METHOD', 'http://example.com', ['custom' => 'header'], '{content}');

$client = new TestClient;
$client->onFileGetContents = function($url, array $contextOptions) {
	Assert::same('http://example.com', $url);
	Assert::same([
		'http' => [
			'method' => 'METHOD',
			'header' => "custom: header\r\nconnection: close\r\n",
			'follow_location' => 0,
			'protocol_version' => 1.1,
			'ignore_errors' => TRUE,
			'content' => '{content}',
		],
	], $contextOptions);

	return [200, ['Content-Type' => 'foo'], '{response}'];
};

$response = $client->request($request);
Assert::same('{response}', $response->getContent());
Assert::same(['content-type' => 'foo'], $response->getHeaders());


# SSL options
$client = new TestClient(NULL, TRUE);
$client->onFileGetContents = function($url, array $contextOptions) {
	Assert::type('array', $contextOptions['ssl']);
	Assert::same([
		'verify_peer' => TRUE,
	], $contextOptions['ssl']);

	return [200, [], ''];
};
$response = $client->request($request);


$client = new TestClient(NULL, __FILE__);
$client->onFileGetContents = function($url, array $contextOptions) {
	Assert::type('array', $contextOptions['ssl']);
	Assert::same([
		'verify_peer' => TRUE,
		'cafile' => __FILE__,
	], $contextOptions['ssl']);

	return [200, [], ''];
};
$response = $client->request($request);


$client = new TestClient(NULL, __DIR__);
$client->onFileGetContents = function($url, array $contextOptions) {
	Assert::type('array', $contextOptions['ssl']);
	Assert::same([
		'verify_peer' => TRUE,
		'capath' => __DIR__,
	], $contextOptions['ssl']);

	return [200, [], ''];
};
$response = $client->request($request);


Assert::same(10, Assert::$counter);
