<?php
	
	namespace Jesse\SimplifiedMVC\Utilities;
	
	class URL
	{
		private string $protocol;
		private string $host;
		private string $requestUri;
		private ?string $queryString;
		private ?string $signature;
		private array $queryArray = [];
		
		function __construct (array &$server)
		{
			// build URL class properties from server
			$this->protocol = $server['REQUEST_SCHEME'];
			$this->host = $server['HTTP_HOST'];
			$this->requestUri = (explode('?',$server['REQUEST_URI']))[0];
			$this->queryString = $server['QUERY_STRING'];
			$this->parseQueryString();
			//rebuild the query string , minus signature if it was passed...
			$this->queryString = count($this->queryArray) !== 0 ? http_build_query($this->queryArray, '', '&') : '';
		}
		function getQueryValuesString(?string $starting = '') : string
		{
			return !empty($this->queryString) ? "{$starting}{$this->queryString}" : '';
		}
		function getSignature () : string|null
		{
			return $this->signature;
		}
		function getRequestUri() : string { return $this->requestUri; }
		function getURLRequest() : string
		{
			return "{$this->protocol}://{$this->host}{$this->requestUri}";
		}
		function getURLRequestQuery() : string
		{
			return "{$this->getURLRequest()}{$this->getQueryValuesString('?')}";
		}
		function parseQueryString() : array
		{
			if (empty($this->queryString)) return $this->queryArray;
			$smallify = explode('&', $this->queryString);
			foreach ($smallify as $KvPair)
			{
				$keyValueArray = explode('=', $KvPair);
				if ($keyValueArray[0] === 'signature')
				{
					$this->signature = $keyValueArray[1];
					continue;
				}
				$this->queryArray[$keyValueArray[0]] = $keyValueArray[1];
			}
			return $this->queryArray;
		}
		function getQueryValuesArray() : array { return $this->queryArray; }
		function getSignedUrl() : string { return $this->getURLRequestQuery() . (!empty($this->queryString) ? "&signature=" : "?signature=") . Signature::sign($this->getURLRequestQuery()); }
	}