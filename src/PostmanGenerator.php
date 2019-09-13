<?php

/**
 * This file is part of the Schematicon library.
 * @license    MIT
 * @link       https://github.com/schematicon/collection-generator
 */

namespace Schematicon\CollectionGenerator;

use Datetime;
use Nette\Http\IRequest;
use Nette\Http\Url;
use Nette\Utils\Json;
use Nette\Utils\Strings;


class PostmanGenerator
{
	private const SCHEMA_2_1 = 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json';

	private const TYPE_DATE = 'date';
	private const TYPE_DATETIME = 'datetime';
	private const TYPE_LOCALDATETIME = 'localdatetime';

	const SCALAR_TYPES = [
		'string',
		'string|null',
		'float',
		'float|null',
		'int',
		'int|null',
		'bool',
		'email',
		'email|null',
	];

	private const REQUEST_METHODS = [
		IRequest::GET,
		IRequest::POST,
		IRequest::PUT,
		IRequest::DELETE,
	];

	/** @var array */
	private $resources;

	/** @var string */
	private $baseUrl;

	/** @var array */
	private $baseHeaders;


	public function generate(array $apiSpecification)
	{
		$this->baseUrl = Strings::trim($apiSpecification['prefix']['url'], '/');
		$this->baseUrl = $this->replaceInlineParameters($this->baseUrl, $apiSpecification['prefix']['parameters']);
		$this->baseHeaders = $apiSpecification['prefix']['headers'] ?? [];
		$this->resources = $apiSpecification['resources'] ?? [];

		$sections = [];
		foreach ($apiSpecification['sections'] as $section) {
			$sections[] = $this->buildSection($section['title'], $section['endpoints']);
		}

		sort($sections);
		$output = [
			'info' => [
				'_postman_id' => Strings::webalize($apiSpecification['title']),
				'name' => $apiSpecification['title'],
				'schema' => self::SCHEMA_2_1,
			],
			'item' => $sections,
		];

		return Json::encode($output, Json::PRETTY);
	}


	private function buildSection(string $title, array $endpoints): array
	{
		$result = [];
		foreach ($endpoints as $path => $endpoint) {
			$result = array_merge($result, $this->buildEndpoint($path, $endpoint));
		}

		return [
			'name' => $title,
			'item' => $result,
		];
	}


	private function buildEndpoint(string $path, array $endpoint): array
	{
		$url = $this->baseUrl . $path;

		$result = [];
		foreach ($endpoint as $method => $values) {
			if (!in_array(strtoupper($method), self::REQUEST_METHODS, true)) {
				continue;
			}

			$parameters = $values['parameters'] ?? $endpoint['parameters'] ?? [];
			$headers = array_merge($this->baseHeaders, $endpoint['headers'] ?? []);

			$url = $this->replaceInlineParameters($url, $parameters);
			$endpointUrl = new Url($url);
			$result[] = [
				'name' => $values['title'],
				'request' => [
					'method' => strtoupper($method),
					'header' => $this->buildHeaders($headers),
					'url' => [
						'protocol' => $endpointUrl->getScheme(),
						'host' => explode('.', $endpointUrl->getHost()),
						'path' => explode('/', Strings::trim($endpointUrl->getPath(), '/')),
						'query' => $this->buildParameters($parameters, $path),
					],
					'body' => $this->buildBody($values['request']['schema'] ?? []),
				],
			];
		}

		return $result;
	}


	private function replaceInlineParameters(string $url, array $parameters): string
	{
		foreach ($parameters['properties'] ?? $parameters as $parameterName => $parameterType) {
			$pattern = "{{$parameterName}}";
			if (isset($parameterType['sample'])) {
				$url = str_replace($pattern, $parameterType['sample'], $url);
			}
		}
		return $url;
	}


	private function buildParameters(array $parameters, string $path): array
	{
		if (!isset($parameters['properties'])) {
			return [];
		}

		$result = [];
		foreach ($parameters['properties'] as $parameterName => $parameter) {
			if (strpos($path, "{{$parameterName}}") !== false) {
				continue;
			}
			$result[] = [
				'key' => $parameterName,
				'value' => $parameter['sample'] ?? null,
				'description' => $parameter['description'] ?? null,
				'disabled' => isset($parameter['optional']) ? $parameter['optional'] : false,
			];
		}
		return $result;
	}


	private function buildHeaders(array $headers): array
	{
		$result = [];
		foreach ($headers as $headerName => $header) {
			$result[] = [
				'key' => $headerName,
				'value' => $header['sample'] ?? null,
				'description' => $header['description'] ?? null,
			];
		}
		return $result;
	}


	private function buildBody(array $schema): ?array
	{
		$data = $this->buildData($schema);

		return [
			'mode' => 'raw',
			'raw' => $data ? Json::encode($data, Json::PRETTY) : null,
		];
	}


	private function buildMap(array $map): array
	{
		$result = [];

		foreach ($map['properties'] as $name => $values) {
			if ($values['type'] === 'array') {
				$result[$name] = $this->buildArray($values);
			} elseif (is_array($map) && reset(array_keys($values)) === 'reference') {
				$result[$name] = $this->buildData($this->resources[$values['reference']]);
			} elseif ($values['type'] === 'map' || $values['type'] === 'map|null') {
				$result[$name] = $this->buildMap($values);
			} elseif (reset(array_keys($values)) === 'oneOf') {
				$result[$name] = $this->buildData($values);
			} elseif (in_array($values['type'], self::SCALAR_TYPES, true)) {
				$result[$name] = $values['sample'] ?? null;
			} elseif ($values['type'] === 'date' || $values['type'] === 'date|null') {
				$result[$name] = $this->buildDateTime($values, self::TYPE_DATE);
			} elseif ($values['type'] === 'datetime' || $values['type'] === 'datetime|null') {
				$result[$name] = $this->buildDateTime($values, self::TYPE_DATETIME);
			} elseif ($values['type'] === 'localdatetime' || $values['type'] === 'localdatetime|null') {
				$result[$name] = $this->buildDateTime($values, self::TYPE_LOCALDATETIME);
			} elseif (reset(array_keys($values)) === 'enum') {
				$result[$name] = $values['sample'] ?? reset($values['enum']);
			} elseif (Strings::startsWith($values['type'], 'null')) {
				$result[$name] = null;
			} else {
				throw new \Exception('Not implemented yet for data: ' . var_export($values, true));
			}
		}
		return $result;
	}


	private function buildArray(array $array): array
	{
		$result = [];
		$item = $array['item'];
		if ($item['type'] === 'map' || $item['type'] === 'map|null') {
			$result[] = $this->buildMap($item);
		}
		return $result;
	}


	private function buildData(?array $data): ?array
	{

		if ($data === null) {
			return null;
		} elseif ($data['type'] === 'map') {
			return $this->buildMap($data);
		} elseif ($data['type'] === 'array') {
			return $this->buildArray($data);
		} elseif (is_array($data) && reset(array_keys($data)) === 'reference') {
			return $this->buildData($this->resources[$data['reference']]);
		} elseif (is_array($data) && reset(array_keys($data)) === 'oneOf') {
			return $this->buildData(reset($data['oneOf']));
		}

		throw new \Exception('Not implemented yet for data: ' . var_export($data, true));
	}


	private function buildDateTime(array $datetime, string $type): string
	{
		$sample = $datetime['sample'] ?? null;

		if (!$sample instanceof DateTime) {
			$sample = new DateTime();
		} elseif (is_string($sample)) {
			return $sample;
		}

		if ($type === self::TYPE_DATE) {
			return $sample->format('Y-m-d');
		} elseif ($type === self::TYPE_LOCALDATETIME) {
			return $sample->format('Y-m-d\TH:i:s');
		}

		return $sample->format(DateTime::ISO8601);
	}
}
