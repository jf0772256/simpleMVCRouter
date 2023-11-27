<?php
	
	namespace Jesse\SimplifiedMVC;
	
	use InvalidArgumentException;
	
	class IRouter
	{
		protected array $routes;
		protected array $cachedRoute = ["method"=>null, "route"=>null];
		
		protected function __construct()
		{
			$this->routes = [
				"get" => [],
				"post" => [],
				"put" => [],
				"update"=> [],
				"patch" => [],
				"delete" => []
			];
		}
		
		protected function requestRoutesArray($caller, ?string $method = null, ?string $route = null) : array|callable|bool
		{
			if (!$caller instanceof Router) throw new InvalidArgumentException("Caller type mismatch. must be caller type of Jesse\SimplifiedMVC\Router", 400);
			if (empty($method) && empty($route)) return $this->routes;
			if (!empty($method) && empty($route)) return $this->routes[$method];
			if (!empty($method) && !empty($route) && array_key_exists($route, $this->routes[$method])) return $this->routes[$method][$route];
			if (!empty($method) && !empty($route) && !array_key_exists($route, $this->routes[$method])) return false;
			throw new InvalidArgumentException("Out of order parameters", 400);
		}
		
		protected function add(string $method, string $route, array|callable|string $action) : void
		{
			if (is_array($action) && array_key_exists("action", $action) && array_key_exists("middleware" ,$action))
			{
				$this->routes[$method][$route]["action"] = $action['action'];
				$this->routes[$method][$route]["middleware"] = $action['middleware'];
			}
			else
			{
				$this->routes[$method][$route]["action"] = $action;
				$this->routes[$method][$route]["middleware"] = null;
			}
			$this->cachedRoute = ["method" => $method, "route" => $route];
		}
		
		protected function middleware($key) : void
		{
			// fetch local cache if is empty reject
			$lc = $this->cachedRoute;
			// update the middleware key with the key
			$this->routes[$lc['method']][$lc['route']]['middleware'] = $key;
			// clear local cache ready for the next add
			$this->cachedRoute = ["method" => null, "route" => null];
		}
	}