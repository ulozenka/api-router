<?php

declare(strict_types=1);

/**
 * @copyright   Copyright (c) 2016 ublaboo <ublaboo@paveljanda.com>
 * @author      Pavel Janda <me@paveljanda.com>
 * @package     Ublaboo
 */

namespace Ublaboo\ApiRouter;

use Nette;
use Nette\Application\IRouter;
use Nette\Application\Request;
use Nette\SmartObject;
use Nette\Utils\Strings;

/**
 * @method mixed onMatch(static, Nette\Application\Request $request)
 * 
 * @Annotation
 * @Target({"CLASS", "METHOD"})
 */
class ApiRoute extends ApiRouteSpec implements IRouter
{
	use SmartObject;

	/**
	 * @var callable[]
	 */
	public $onMatch;

	/**
	 * @var string|null
	 */
	private $presenter;

	/**
	 * @var array
	 */
	private $actions = [
		'POST' => false,
		'GET' => false,
		'PUT' => false,
		'DELETE' => false,
		'OPTIONS' => false,
		'PATCH' => false,
	];

	/**
	 * @var array
	 */
	private $default_actions = [
		'POST' => 'create',
		'GET' => 'read',
		'PUT' => 'update',
		'DELETE' => 'delete',
		'OPTIONS' => 'options',
		'PATCH' => 'patch',
	];

	/**
	 * @var array
	 */
	private $formats = [
		'json' => 'application/json',
		'xml' => 'application/xml',
	];

	/**
	 * @var array
	 */
	private $placeholder_order = [];


	public function __construct($path, string $presenter = null, array $data = [])
	{
		/**
		 * Interface for setting route via annotation or directly
		 */
		if (!is_array($path)) {
			$data['value'] = $path;
			$data['presenter'] = $presenter;

			if (empty($data['methods'])) {
				$this->actions = $this->default_actions;
			} else {
				foreach ($data['methods'] as $method => $action) {
					if (is_string($method)) {
						$this->setAction($action, $method);
					} else {
						$m = $action;

						if (isset($this->default_actions[$m])) {
							$this->setAction($this->default_actions[$m], $m);
						}
					}
				}

				unset($data['methods']);
			}
		} else {
			$data = $path;
		}

		/**
		 * Set Path
		 */
		$this->setPath($data['value']);
		unset($data['value']);

		parent::__construct($data);
	}


	public function setPresenter(?string $presenter): void
	{
		$this->presenter = $presenter;
	}


	public function getPresenter(): ?string
	{
		return $this->presenter;
	}


	public function setAction(string $action, string $method = null): void
	{
		if ($method === null) {
			$method = array_search($action, $this->default_actions, true);
		}

		if (!isset($this->default_actions[$method])) {
			return;
		}

		$this->actions[$method] = $action;
	}


	private function prepareForMatch(string $string): string
	{
		return sprintf('/%s/', str_replace('/', '\/', $string));
	}


	/**
	 * Get all parameters from url mask
	 */
	public function getPlacehodlerParameters(): array
	{
		if (!empty($this->placeholder_order)) {
			return array_filter($this->placeholder_order);
		}

		$return = [];

		preg_replace_callback('/<(\w+)>/', function ($item) use (&$return) {
			$return[] = end($item);
		}, $this->path);

		return $return;
	}


	/**
	 * Get required parameters from url mask
	 */
	public function getRequiredParams(): array
	{
		$regex = '/\[[^\[]+?\]/';
		$path = $this->getPath();

		while (preg_match($regex, $path)) {
			$path = preg_replace($regex, '', $path);
		}

		$required = [];

		preg_replace_callback('/<(\w+)>/', function ($item) use (&$required) {
			$required[] = end($item);
		}, $path);

		return $required;
	}


	public function resolveFormat(Nette\Http\IRequest $httpRequest): void
	{
		if ($this->getFormat()) {
			return;
		}

		$header = $httpRequest->getHeader('Accept');

		foreach ($this->formats as $format => $format_full) {
			$format_full = Strings::replace($format_full, '/\//', '\/');

			if (Strings::match($header, "/{$format_full}/")) {
				$this->setFormat($format);
			}
		}

		$this->setFormat('json');
	}


	public function getFormatFull(): string
	{
		return $this->formats[$this->getFormat()];
	}


	public function setMethods(array $methods)
	{
		foreach ($methods as $method => $action) {
			if (is_string($method)) {
				$this->setAction($action, $method);
			} else {
				$m = $action;

				if (isset($this->default_actions[$m])) {
					$this->setAction($this->default_actions[$m], $m);
				}
			}
		}
	}


	public function getMethods(): array
	{
		return array_keys(array_filter($this->actions));
	}


	public function resolveMethod(Nette\Http\IRequest $request): string
	{
		if (!empty($request->getHeader('X-HTTP-Method-Override'))) {
			return Strings::upper($request->getHeader('X-HTTP-Method-Override'));
		}

		if ($method = Strings::upper($request->getQuery('__apiRouteMethod'))) {
			if (isset($this->actions[$method])) {
				return $method;
			}
		}

		return Strings::upper($request->getMethod());
	}


	/********************************************************************************
	 *                              Interface IRouter                               *
	 ********************************************************************************/


	/**
	 * Maps HTTP request to a Request object.
	 */
	public function match(Nette\Http\IRequest $httpRequest): ?Request
	{
		/**
		 * ApiRoute can be easily disabled
		 */
		if ($this->disable) {
			return null;
		}

		$url = $httpRequest->getUrl();

		$path = $url->getPath();

		/**
		 * Build path mask
		 */
		$order = &$this->placeholder_order;
		$parameters = $this->parameters;

		$mask = preg_replace_callback('/(<(\w+)>)|\[|\]/', function ($item) use (&$order, $parameters) {
			if ($item[0] == '[' || $item[0] == ']') {
				if ($item[0] == '[') {
					$order[] = null;
				}

				return $item[0];
			}

			[, , $placeholder] = $item;

			$parameter = $parameters[$placeholder] ?? [];

			$regex = $parameter['requirement'] ?? '\w+';
			$has_default = array_key_exists('default', $parameter);
			$regex = preg_replace('~\(~', '(?:', $regex);

			if ($has_default) {
				$order[] = $placeholder;

				return sprintf('(%s)?', $regex);
			}

			$order[] = $placeholder;

			return sprintf('(%s)', $regex);
		}, $this->path);

		$mask = '^' . str_replace(['[', ']'], ['(', ')?'], $mask) . '$';

		/**
		 * Prepare paths for regex match (escape slashes)
		 */
		if (!preg_match_all($this->prepareForMatch($mask), $path, $matches)) {
			return null;
		}

		/**
		 * Did some action to the request method exists?
		 */
		$this->resolveFormat($httpRequest);
		$method = $this->resolveMethod($httpRequest);
		$action = $this->actions[$method];

		if (!$action) {
			return null;
		}

		/**
		 * Basic params
		 */
		$params = $httpRequest->getQuery();
		$params['action'] = $action;
		$required_params = $this->getRequiredParams();

		/**
		 * Route mask parameters
		 */
		array_shift($matches);

		foreach ($this->placeholder_order as $key => $name) {
			if ($name !== null&& isset($matches[$key])) {
				$params[$name] = reset($matches[$key]) ?: null;

				/**
				 * Required parameters
				 */
				if (empty($params[$name]) && in_array($name, $required_params, true)) {
					return null;
				}
			}
		}

		$request = new Request(
			$this->presenter,
			$method,
			$params,
			$httpRequest->getPost(),
			$httpRequest->getFiles(),
			[Request::SECURED => $httpRequest->isSecured()]
		);

		/**
		 * Trigger event - route matches
		 */
		$this->onMatch($this, $request);

		return $request;
	}


	/**
	 * Constructs absolute URL from Request object.
	 */
	public function constructUrl(Request $request, Nette\Http\Url $url): ?string
	{
		if ($this->presenter != $request->getPresenterName()) {
			return null;
		}

		$base_url = $url->getBaseUrl();

		$action = $request->getParameter('action');
		$parameters = $request->getParameters();
		unset($parameters['action']);
		$path = ltrim($this->getPath(), '/');

		if (array_search($action, $this->actions, true) === false) {
			return null;
		}

		foreach ($parameters as $name => $value) {
			if (strpos($path, "<{$name}>") !== false && $value !== null) {
				$path = str_replace("<{$name}>", (string) $value, $path);

				unset($parameters[$name]);
			}
		}

		$path = preg_replace_callback('/\[.+?\]/', function ($item) {
			if (strpos(end($item), '<')) {
				return '';
			}

			return end($item);
		}, $path);

		/**
		 * There are still some required parameters in url mask
		 */
		if (preg_match('/<\w+>/', $path)) {
			return null;
		}

		$path = str_replace(['[', ']'], '', $path);

		$query = http_build_query($parameters);

		return $base_url . $path . ($query ? '?' . $query : '');
	}
}
