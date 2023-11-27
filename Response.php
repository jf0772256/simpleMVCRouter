<?php
	
	namespace Jesse\SimplifiedMVC\Router;
	
	class Response
	{
		private array $errorTitles = [
			"400" => "400: Bad request, The server received malformed data in the last request",
			"403" => "403: You're Not Authorized",
			"404" => "404: Page Not Found"
		];
		function statusCode(?int $code = null) : ?int
		{
			return http_response_code($code ?? 0);
		}
		function customErrorCode(string $message, ?int $code = 0) : void
		{
			header("HTTP/1.1 {$code} {$message}");
		}
		function errorTitle(int $error, ?string $errorTitle = NULL) : ?string
		{
			if (!empty($errorTitle))
			{
				$this->errorTitles[(string)$error] = $errorTitle;
				return null;
			}
			if (!array_key_exists((string)$error, $this->errorTitles)) return "Error";
			return $this->errorTitles[(string)$error];
		}
		function redirect(string $path) : never
		{
			header("Location: $path");
			exit;
		}
	}