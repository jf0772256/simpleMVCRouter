<?php
	
	namespace Jesse\SimplifiedMVC\Router;
	
	use Exception;
	use Jesse\SimplifiedMVC\Router\Exception\BadRequest;
	use Jesse\SimplifiedMVC\Router\Utilities\URL;
	
	class Request
	{
		/**
		 * @var bool if the _method is not present then an actual request with type was passed, and we will need to process it to get the body worked out
		 */
		private bool $overwrittenMethod = false;
		public array $params = [];
		public string $contentType = "";
		public string $contentTypeExpected = "application/x-www-form-urlencoded";
		private URL $url;
		public function __construct()
		{
			$this->url = new URL($_SERVER);
		}
		public function getPath() : string
		{
			$params = $this->url->getQueryValuesArray();
			foreach ($params as $key => $value) $this->params[$key] = $value;
			return $this->url->getRequestUri();
		}
		public function method() : string {
			$this->overwrittenMethod = $this->isOtherRequestType();
			return \strtolower($_SERVER['REQUEST_METHOD']);
		}
		public function isGet() : bool {
			return $this->method() === 'get';
		}
		public function isPost() : bool {
			return $this->method() === 'post';
		}
		public function isPut() : bool {
			return $this->method() === 'put';
		}
		public function isUpdate() : bool {
			return $this->method() === 'update';
		}
		public function isPatch() : bool {
			return $this->method() === 'patch';
		}
		public function isDelete() : bool {
			return $this->method() === 'delete';
		}
		public function isOtherRequestType() : bool {
			// if input on body has name _method, set that as the method on the $_SERVER global array
			if (array_key_exists('_method', $_GET)) {
				$_SERVER['REQUEST_METHOD'] = strtoupper(filter_input(INPUT_GET, '_method', FILTER_SANITIZE_SPECIAL_CHARS));
				return true;
			}
			if (array_key_exists('_method', $_POST)) {
				$_SERVER['REQUEST_METHOD'] = strtoupper(filter_input(INPUT_POST, '_method', FILTER_SANITIZE_SPECIAL_CHARS));
				return true;
			}
			return false;
		}
		/**
		 * @throws BadRequest
		 */
		public function getRequestBody() : array
		{
			$this->processContentType();
			$body = [];
			if ($this->isGet()) {
				foreach ($_GET as $key => $value) {
					$body[$key] = filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
				}
			} elseif ($this->isPost() && $this->contentType !== "application/json") {
				foreach ($_POST as $key => $value) {
					$body[$key] = filter_input(INPUT_POST, $key, FILTER_SANITIZE_SPECIAL_CHARS);
				}
			} elseif (!$this->isGet() && !$this->isPost() && $this->contentType !== "application/json") {
				$filtered_get = [];
				$filtered_post = [];
				foreach ($_GET as $key => $value) {
					$filtered_get[$key] = filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
				}
				foreach ($_POST as $key => $value) {
					$filtered_post[$key] = filter_input(INPUT_POST, $key, FILTER_SANITIZE_SPECIAL_CHARS);
				}
				$body = array_merge($filtered_get, $filtered_post);
			}
			elseif ($this->contentType === "application/json" && !$this->isGet())
			{
				$body = json_decode(file_get_contents("php://input"), true);
			}
			elseif (!$this->overwrittenMethod && ($this->isDelete() || $this->isPatch() || $this->isPut() || $this->isUpdate()))
			{
				// read the body value.
				$bodyData = file_get_contents("php://input");
				// determine the content type (non-json as that's caught in if block above) then based on the content type process data into usable values
				switch ($this->contentType)
				{
					case 'multipart/form-data': // this will be harder to parse due to the processing done for boundaries
						// process the data
						break;
					case 'application/x-www-form-urlencoded': // most common, it's what gets passed with normal POST and GET requests
						$toKVPairs = explode('&', $bodyData);
						foreach ($toKVPairs as $key => $value) $body[$key] = urldecode($value);
						break;
					default:
						throw new BadRequest("Unacceptable Content Type used OR data was malformed and unable to be processed", 400);
				}
			}
			
			return $body;
		}
		public function requestExpects(string $contentTypeExpected) : void
		{
			$this->contentTypeExpected = $contentTypeExpected;
		}
		/**
		 * Method to fetch parameters from the request URI and attach them to the named parameters in the route
		 * Parameters are defined using {[name]} eg {id}
		 *
		 * usage:
		 * /user/{id} ===> /user/5 the request object will hold a params array its an assoc array of key value pairs. like: ['id' => 5]
		 *
		 * @param array $routesList Path of routes based on the used method must be passed via the router eg [get => [/user/{id} => callback]]
		 * @param string $uriPath URI passed from the server is the /user/5
		 * @return string returns the route path for use within the router, or will return the original uri path passed for it to 404 fail
		 */
		function parameterSearch (array $routesList, string $uriPath) : ?string {
			$newRoutesList = $this->checkRoutes($routesList, $uriPath);
			if (is_string($newRoutesList)) return $newRoutesList;
			
			// 4.a check see if they are a direct match if they are that is the route to use
			// 4.b if not then check route string for '{}' in them
			// 4.c check with match should it work then extract params and run the route.
			foreach ($newRoutesList as $route => $callback) {
				preg_match_all("/(?<={).+?(?=})/", $route, $paramMatches);
				$uri = $this->carveUpURL($route);
				$uriPathArray =  $this->carveUpURL($uriPath);
				$paramNames = $this->populateParamNames($paramMatches);
				$params = [];
				$indexes = [];
				$matchFailed = false;
				$this->buildParamsIndexArray($uri, $uriPathArray, $indexes, $matchFailed);
				// if match wasn't found on that pass and soo moving to the next route in the list
				if ($matchFailed === true) continue;
				// match found so now we are rebuilding the path and returning that route back for the router -- or return false if something went beyond haywire
				if (!$this->buildParamsArray($paramNames, $uriPathArray, $params, $indexes, $matchFailed)) return false;
				//putting found parameters to the request object
				$this->params = $params;
				return $route;
			}
			// possibly not found, maybe it wasnt caught else where and will likely throw a 404 error
			return $uriPath;
		}
		private function checkRoutes (array $inboundRoutes, string $pathToCheck) : array|string
		{
			// container for routes that meet the pathTopCheck value
			$routes = [];
			// steps to complete
			// 1 filter routes to the same number of steps in the uri; loop over
			foreach ($inboundRoutes as $route => $callBack) {
				// 2 test if the path to check is the same as the route If it is immediately exit and return the current path value
				if ($route === $pathToCheck) return $pathToCheck;
				// 3 Replace the leading and terminating / values from the paths (route and $pathToCheck)
				$routePath = preg_replace("/(^\/)|(\/$)/", "", $route);
				$uri =  preg_replace("/(^\/)|(\/$)/", "", $pathToCheck);
				// 4 count the blocks, if teh block count is teh same on the rhs as the lhs, add route to the routes local array
				if (count(explode('/', $routePath)) == count(explode('/', $uri))) $routes[$route] = $inboundRoutes[$route];
			}
			// 5 when completed, if not found in #2 then return the populated routes local array
			return $routes;
		}
		private function carveUpURL(string $url) : array
		{
			return explode('/', preg_replace("/(^\/)|(\/$)/", "", $url));
		}
		private function populateParamNames($matches) : array
		{
			$paramNames = [];
			if (count($matches) > 0) {
				foreach($matches[0] as $key){
					$paramNames[] = $key;
				}
			}
			return $paramNames;
		}
		private function buildParamsIndexArray(array &$uri, array &$uriPathArray, array &$indexes, bool &$matchFailed) : void
		{
			foreach($uri as $index => $param)
			{
				if(preg_match("/{.*}/", $param)) $indexes[] = $index;
				else {
					//see if uri path array has the same value, if not then it's not a match and should be broken out of and to the next...
					//matched so can continue
					if ($param === $uriPathArray[$index]) continue;
					else {
						// didn't match so break out of this and then continue
						$matchFailed = true;
						break;
					}
				}
			}
		}
		private function buildParamsArray(array &$paramNames, array &$uriPathArray, array &$params, array &$indexes, bool &$matchFailed) : ?bool
		{
			foreach ($indexes as $key => $index) {
				// Uh... Oh something went wrong here
				if(empty($uriPathArray[$index])) return false;
				$params[$paramNames[$key]] = urldecode($uriPathArray[$index]);
			}
			return true;
		}
		/**
		 * @throws BadRequest
		 */
		private function processContentType() : void
		{
			$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['X_CONTENT_TYPE'] ?? $_SERVER['X_HTTP_CONTENT_TYPE'] ?? "application/x-www-form-urlencoded";
			if ($ct !== $this->contentTypeExpected) throw new BadRequest("CONTENT.TYPE.MISMATCH", 400);
			$this->contentType = $ct;
		}
		public function setUrl (URL $url) : void
		{
			$this->url = $url;
		}
		public function getUrl() : URL { return $this->url; }
	}