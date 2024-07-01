<?php
	
	namespace Jesse\SimplifiedMVC\Router;
	
	use Closure;
	use Exception;
	use Jesse\SimplifiedMVC\Router\Exception\NotFound;
	
	class Router extends IRouter
	{
		private Request $request;
		private Response $response;
		private string|null $MiddlewareClass = null;
		private string|null $applicationClass = null;
		private string $routesPath = "";
		private bool $usingFacade = false;
		private string $routePrefix = "";
		private string $routeControllerClass = "";
		function __construct(Request $request, Response $response, ?string $routesPath = "")
		{
			// Set up the parent class
			parent::__construct();
			$this->request = $request;
			$this->response = $response;
			$this->routesPath = $routesPath;
		}
		protected function __set_facade_classes($application, $middleware = null): void
		{
			$this->applicationClass = $application;
			$this->MiddlewareClass = $middleware;
			$this->usingFacade = true;
		}
		
		/**
		 * Takes an instance of Router and combines it to the router that use is called from. Routes with the same
		 * method=>route in the use() will overwrite the existing route.
		 *
		 * @param Router|string $router router with routes defined, if passing as a string, pass the name of the routes file, less the extension
		 *
		 * @return Router
		 */
		public function use(Router|string $router) : self
		{
			// if just the file name is passed we will need to go pull the file in and get the router from the file... then proceed as normal
			if (!$router instanceof Router) {
				$router = require_once $this->routesPath . "{$router}.php";
			}
			$_incomingRoutes = $router->requestRoutesArray($this);
			foreach ($_incomingRoutes as $method => $routeArray)
			{
				foreach ($routeArray as $route => $action)
				{
					parent::add($method,$route, $action);
				}
			}
			return $this;
		}
		public function get (string $route, array|callable|string $action) : self
		{
			$action = (!empty($this->routeControllerClass) && !is_callable($action)) ? [$this->routeControllerClass, $action] : $action;
			parent::add('get', $this->routePrefix . $route, $action);
			return $this;
		}
		public function post (string $route, array|callable|string $action) : self
		{
			$action = (!empty($this->routeControllerClass) && !is_callable($action)) ? [$this->routeControllerClass, $action] : $action;
			parent::add('post', $this->routePrefix . $route, $action);
			return $this;
		}
		public function put (string $route, array|callable|string $action) : self
		{
			$action = (!empty($this->routeControllerClass) && !is_callable($action)) ? [$this->routeControllerClass, $action] : $action;
			parent::add('put', $this->routePrefix . $route, $action);
			return $this;
		}
		public function patch (string $route, array|callable|string $action) : self
		{
			$action = (!empty($this->routeControllerClass) && !is_callable($action)) ? [$this->routeControllerClass, $action] : $action;
			parent::add('patch', $this->routePrefix . $route, $action);
			return $this;
		}
		public function delete (string $route, array|callable|string $action) : self
		{
			$action = (!empty($this->routeControllerClass) && !is_callable($action)) ? [$this->routeControllerClass, $action] : $action;
			parent::add('delete', $this->routePrefix . $route, $action);
			return $this;
		}
		public function update (string $route, array|callable|string $action) : self
		{
			$action = (!empty($this->routeControllerClass) && !is_callable($action)) ? [$this->routeControllerClass, $action] : $action;
			parent::add('update', $this->routePrefix . $route, $action);
			return $this;
		}
		
		/**
		 * Applies a middleware to the last route added
		 *
		 * @param $key string Middleware key name
		 *
		 * @return $this
		 */
		public function only(string $key) : self
		{
			$this->middleware($key);
			return $this;
		}
		
		public function except(string $key) : self { return $this; }
		
		/**
		 * Set a prefix for the route object. Namely used when including routes files.
		 * All routes in the route object will use the prefix.
		 * ```
		 *     $router = new Router($request, $response);
		 *     $router->prefix('/api/v1');
		 *     $router->get('/user/{id}')->only('api-auth');
		 *     // is the same as:
		 *     $router->get('/api/v1/user/{id}')->only('api-auth');
		 * ```
		 *
		 * @param string|null $prefix string representing all front values of the route path, must have leading '/' char, if no arguments will clear prefix
		 *
		 * @return $this this method is chainable
		 * @example
		 */
		public function prefix(?string $prefix = "") : self
		{
			$this->routePrefix = $prefix;
			return $this;
		}
		
		/**
		 * Allow router to preset controller class, if set then it will append the method to the action callback.
		 * This is instance wide, and can be reset if needed after added by calling it again with empty arguments list.
		 * ```
		 *    $router = new Router($req, $res);
		 *    $router->controller(User::class)->prefix('/user');
		 *    // to clear class and set to the normal way::
		 *    $router->controller()->prefix('/user');
		 * ```
		 *
		 * @param string|null $controller Controller class string, invoke it by calling [CLASSNAME]::class EG User::class see example above
		 *
		 * @return $this returns Router and is chainable
		 */
		public function controller(?string $controller = "") : self
		{
			$this->routeControllerClass = $controller;
			return $this;
		}
		
		/**
		 * Resolves the routes and completes any actions
		 * @return mixed
		 * @throws NotFound
		 * @throws Exception
		 */
		public function resolve() : mixed
		{
			//set up for facade loading
			if ($this->usingFacade)
			{
				$middleware = $this->MiddlewareClass;
				$application = $this->applicationClass;
			}
			else
			{
				$middleware = Middleware::class;
				$application = Application::class;
			}
			$path = $this->request->getPath();
			$method = $this->request->method();
			$path = $this->request->parameterSearch($this->requestRoutesArray($this,$method), $path);
			// route action
			$data = $this->requestRoutesArray($this,$method,$path) ?? false;
			$callback = $data['action']?? null;
			// action cannot be null / not set
			if (!$callback)
			{
				// call back is not set
				$this->response->statusCode(404);
				throw new NotFound();
			}
			
			try
			{
				call_user_func([$middleware, 'resolve'], $data['middleware']);
			}
			catch (Exception $ex)
			{
				die($ex);
			}
			
			
			if (is_string($callback))
			{
				// this is a loaded view
				return $application::$app->view->renderView($callback, ["title" => $callback, "params" => $this->request->params]);
				// not yet implemented
				//throw new Exception("Not Yet Implemented", 404);
			}
			
			if (is_array($callback))
			{
				// this is the controller process
				/**
				 * @var Controller $controller
				 */
				$controller = new $callback[0];
				$application::$app->controller = $controller;
				$controller->action = $callback[1];
				$callback[0] = $controller;
				// not yet implemented
				//throw new Exception("Not Yet Implemented", 404);
			}
			
			// call back is a render function.
			return  call_user_func($callback, $this->request, $this->response);
		}
	}