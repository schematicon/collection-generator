<?php

/**
 * This file is part of the Schematicon library.
 * @license    MIT
 * @link       https://github.com/schematicon/collection-generator
 */

namespace Schematicon\CollectionGenerator;

use Datetime;
use DateTimeImmutable;
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


	public function generate(array $apiSpecification): string
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


	private function buildData(array $schema)
	{
		$types = explode('|', $schema['type'] ?? '');

		if (isset($schema['reference'])) {
			return $this->buildData($this->resources[$schema['reference']]);

		} elseif (isset($schema['oneOf'])) {
			return $this->buildData($schema['oneOf']);

		} elseif (isset($schema['allOf']) || isset($schema['anyOf'])) {
			throw new \LogicException('Not implemented yet for data: ' . var_export($schema, true));

		} elseif (isset($schema['enum'])) {
			return reset($schema['enum']);

		} elseif (in_array('date', $types, true)) {
			return $this->buildDateTime($schema, self::TYPE_DATE);

		} elseif (in_array('datetime', $types, true)) {
			return $this->buildDateTime($schema, self::TYPE_DATETIME);

		} elseif (in_array('localdatetime', $types, true)) {
			return $this->buildDateTime($schema, self::TYPE_LOCALDATETIME);

		} elseif (in_array('array', $types, true)) {
			return $this->buildArray($schema);

		} elseif (in_array('map', $types, true)) {
			return $this->buildMap($schema);

		} else {
			return $schema['sample'] ?? $schema['type'] ?? null;
		}
	}


	private function buildMap(array $schema): array
	{
		$result = [];
		foreach ($schema['properties'] as $name => $propertySchema) {
			$result[$name] = $this->buildData($propertySchema);
		}
		return $result;
	}


	private function buildArray(array $schema): array
	{
		$itemSchema = $schema['item'];
		return [$this->buildData($itemSchema)];
	}


	private function buildDateTime(array $datetime, string $type): string
	{
		$sample = $datetime['sample'] ?? 'now';

		if (!$sample instanceof DateTimeImmutable) {
			try {
				$sample = new DateTimeImmutable($sample);
			} catch (\Exception $e) {
				$sample = new DateTimeImmutable();
			}
		}

		if ($type === self::TYPE_DATE) {
			return $sample->format('Y-m-d');
		} elseif ($type === self::TYPE_LOCALDATETIME) {
			return $sample->format('Y-m-d\TH:i:s');
		} elseif ($type === self::TYPE_DATETIME) {
			return $sample->format(DateTime::ISO8601);
		} else {
			throw new \LogicException();
		}
	}
}
