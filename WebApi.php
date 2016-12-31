<?php
namespace Vanen\Net;

class HttpResponse
{
    public $object;
    public $statusCode;
    public $message;

    public function __construct($statusCode = 200, $message = 'Ok', $object = null)
    {
        $serverProtocol = filter_input(INPUT_SERVER, 'SERVER_PROTOCOL');

        $this->statusCode = $statusCode;
        $this->message = "$serverProtocol $statusCode $message";
        $this->object = $object;
    }
}

namespace Vanen\Mvc;

abstract class ApiController
{
    /**
     * The HTTP method of this request.
     * @var string
     */
    public $METHOD = null;
    /**
     * The values of the options of the URI pattern.
     * @var array
     */
    public $OPTIONS = [];
    /**
     * The values of the headers that were sent with this request.
     * @var array
     */
    public $HEADERS = [];

    /**
     * The type of the response. Either JSON or XML.
     * @var string
     */
    public $RESPONSE_TYPE = 'JSON';
    /**
     * The options for reponse type JSON.
     *   http://php.net/manual/en/json.constants.php
     * @var integer
     */
    public $JSON_OPTIONS = 0;

    /**
     * This method is called after the controller has been instantiated and
     * the properties of this class were set.
     */
    public function __controller()
    {
    }
}

final class Api
{
    private $mDefaultPattern = '{controller}';
    private $mHttpMethod = null;
    private $mController = null;
    private $mHeaders = [];

    /**
     * The version of the API. This string is appended after the file name.
     *   E.g. www.example.com/api.php/v1/ where 'v1' is the version.
     * @var string
     */
    public $version = null;

    public function __construct($version = null)
    {
        if ($version) {
            $this->version = trim($version, '/\\');
        }
        $this->mHttpMethod = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
    }

    /**
     * Handles the request.
     * @return void
     */
    public function handle()
    {
        $parsedInfo = $this->parseRequest();

        $_httpResponse = "Vanen\\Net\\HttpResponse";
        if ($parsedInfo instanceof $_httpResponse) {
            header($parsedInfo->message, true, $parsedInfo->statusCode);
            return;
        }

        $headers = [];
        $requestHeaders = array_change_key_case(getallheaders(), CASE_LOWER);

        // Extract the values of the allowed headers from the request headers.
        foreach ($this->mHeaders as $header) {
            $headerLower = strtolower($header);
            if (key_exists($headerLower, $requestHeaders)) {
                $headers[$header] = $requestHeaders[$headerLower];
            } else {
                // The header is still added if it doesn't exist.
                $headers[$header] = null;
            }
        }

        if (count($headers)) {
            // Allow the headers that are saved in ApiController::$HEADERS
            header('Access-Control-Allow-Headers: ' . join(', ', array_keys($headers)));
        }

        // Instantiate the controller.
        $this->mController = new $parsedInfo->class();
        // Update the values of ApiController base class.
        $this->mController->METHOD = $this->mHttpMethod;
        $this->mController->OPTIONS = $parsedInfo->options;
        $this->mController->HEADERS = $headers;

        // Call the __controller method that is used as second constructor.
        $__controller = '__controller';
        if (method_exists($this->mController, $__controller)) {
            $this->mController->$__controller();
        }

        // Call the function that was requested.
        $response = call_user_func_array([
            $this->mController,
            $parsedInfo->method
        ], $parsedInfo->parameters);

        // Check if the response is an instance of HttpResponse.
        if ($response instanceof $_httpResponse) {
            // Update the header depending on the response type.
            header($response->message, true, $response->statusCode);
            if ($response->object !== null) {
                $this->respond($response->object);
            }
        } else if ($response !== null) {
            $this->respond($response);
        }
    }

    /**
     * Adds a header to the allowed headers.
     *   The header's value is saved in $HEADERS of the ApiController class.
     * @param  string $name The header's name.
     * @return void
     */
    public function allowHeader($name)
    {
        $this->mHeaders[] = $name;
    }

    //
    // Echos the response object encoded with JSON or XML.
    //
    private function respond($response)
    {
        if (!$this->mController) {
            header('Content-Type: application/json');
            echo json_encode($response);
            return;
        }

        $notXml = strcasecmp($this->mController->RESPONSE_TYPE, 'xml') !== 0;
        if (strcasecmp($this->mController->RESPONSE_TYPE, 'json') !== 0 && $notXml) {
            trigger_error('Unknown response type "' . $this->mController->RESPONSE_TYPE .
                    '". Valid response types are JSON and XML.', E_USER_ERROR);
        }

        header('Content-Type: application/' . $this->mController->RESPONSE_TYPE);

        if ($notXml) {
            echo json_encode($response, $this->mController->JSON_OPTIONS);
        } else {
            echo $this->xml_encode($response);
        }
    }

    //
    // Parses the request and returns an object with the name of
    // the class (controller) that was requested, the method and
    // the parameters with their values.
    //
    private function parseRequest()
    {
        $methodNotAllowed = false;

        // Get information about all controllers.
        foreach ($this->getControllerInfo() ?: [] as $controller => $methods) {
            foreach ($methods as $method) {
                // Skip this method if it doesn't accept this request method.
                if (!in_array($this->mHttpMethod, $method->http_verbs)) {
                    $methodNotAllowed = true;
                    continue;
                }

                // Filter all information from the URI using the pattern of this method.
                $uriInfo = $this->filterUri($method->uri_pattern);
                if (!$uriInfo) {
                    continue;
                }

                // Skip this controller if it was not requested.
                if (property_exists($uriInfo, 'controller') && $uriInfo->controller !== null &&
                        strcasecmp(substr($controller, 0, -10), $uriInfo->controller) !== 0) {
                    break;
                }

                // Skip this method if it was not requested.
                if (property_exists($uriInfo, 'method') && $uriInfo->method !== null &&
                        strcasecmp($method->name, $uriInfo->method) !== 0) {
                    continue;
                }

                // Skip this method if more parameters were passed than it takes.
                if (count($uriInfo->parameters) > count($method->parameters)) {
                    continue;
                }

                $parameters = [];
                foreach ($method->parameters as $param) {
                    // Copy the parameters from the URI into a new array.
                    foreach ($uriInfo->parameters as $name => $value) {
                        if (strcasecmp($param->name, $name) === 0) {
                            $parameters[$param->name] = $value;
                        }
                    }
                    // If the parameter was not passed take the default value.
                    if (!key_exists($param->name, $parameters)) {
                        if ($param->is_optional) {
                            $parameters[$param->name] = $param->default;
                        } else {
                            $parameters = false;
                            break;
                        }
                    }
                }

                // Skip this method if a parameter was not passed.
                if ($parameters === false) {
                    continue;
                }

                return (object)[
                    'class' => $controller,
                    'method' => $method->name,
                    'parameters' => $parameters,
                    'options' => $uriInfo->options
                ];
            }
        }

        if ($methodNotAllowed) {
            // Resource was found but the method is not allowed.
            return new \Vanen\Net\HttpResponse(405, 'Method Not Allowed');
        }
        return new \Vanen\Net\HttpResponse(404, 'Not Found');

    }

    //
    // Gets information about the method(s) of a controller(s).
    //
    private function getControllerInfo($class = null, $method = null)
    {
        // This function is used to filter a controller's method.
        // It returns the class name, the method name and
        // all HTTP verbs and the URI pattern from its doc comment.
        $methodFilter = function ($method) {
            // Skip methods that are not public.
            if (!in_array('public', \Reflection::getModifierNames($method->getModifiers()))) {
                return null;
            }

            // Get the cleaned comment text.
            $comment = $this->filterCommentText($method->getDocComment());
            $result = (object)[
                'name' => $method->name,
                'http_verbs' => [],
                'uri_pattern' => null,
                'parameters' => array_map(function ($p) {
                    $isOptional = $p->isOptional();
                    return (object)[
                        'name' => $p->name,
                        'is_optional' => $isOptional,
                        'default' => $isOptional ? $p->getDefaultValue() : null
                    ];
                }, $method->getParameters())
            ];

            // Get all words from the comment that start with a colon.
            foreach (array_filter(preg_split('/\s+/', $comment), function ($s) {
                return substr($s, 0, 1) === ':';
            }) as $word) {
                if (strpos($word, '{') !== false && strpos($word, '}') !== false) {
                    // If it contains braces it is a URI pattern.
                    $result->uri_pattern = ltrim($word, ':');
                } else {
                    // Add the word to the HTTP verbs array otherwise.
                    if (!in_array($word, $result->http_verbs)) {
                        $result->http_verbs[] = ltrim($word, ':');
                    }
                }
            }

            // Only return the result if it has HTTP verbs.
            // If it doesn't null is returned and it will be filtered out (array_filter).
            return $result->http_verbs ? $result : null;
        };

        // If no class name was passed get all classes that end with 'controller'.
        $reflects = $class === null ? array_map(function ($class) {
            return new \ReflectionClass($class);
        }, array_filter(get_declared_classes(), function ($class) {
            return substr_compare(strtolower($class), 'controller', -10) === 0 &&
                is_subclass_of($class, 'Vanen\Mvc\ApiController');
        })) : [new \ReflectionClass($class)];

        $info = [];
        foreach ($reflects as $reflect) {
            if ($method !== null && !method_exists($reflect->name, $method)) {
                continue;
            }
            $methods = $method === null ? $reflect->getMethods() : [$reflect->getMethod($method)];
            $info[$reflect->name] = array_filter(array_map($methodFilter, $methods));
        }

        return $info ?: false;
    }

    //
    // Gets the text of a documentation comment without asterisks.
    //
    private function filterCommentText($comment)
    {
        preg_match_all('/[^*\s]+|(\s*[^*]+)/', $comment, $matches);
        $text = trim(join('', array_slice($matches[0], 1, -1)));

        $cleaned = '';
        for ($i = 0, $len = strlen($text), $lb = false; $i < $len; ++$i) {
            if ($lb && ctype_space($text[$i])) {
                continue;
            }
            $lb = $text[$i] === "\n";
            $cleaned .= $text[$i];
        }

        return $cleaned;
    }

    //
    // Filters the information from the URI that is needed to parse the request.
    //
    private function filterUri($pattern)
    {
        $pattern = ($this->version ? "$this->version/" : '') . (trim($pattern, '/') ?: $this->mDefaultPattern);

        // Get the part after the file name.
        // Also handles the case of .htaccess usage where the file name might be missing.
        $requestUri = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_STRING);
        $parts = explode(dirname(filter_input(INPUT_SERVER, 'SCRIPT_NAME')), $requestUri);
        $temp = end($parts);
        $last = substr($temp, strpos($temp, '/', strpos($temp, '/') + 1));

        // Convert the URI pattern to a regular expression:
        // Match all normal words in the pattern and make it a capturing group with the variable name as key.
        $regex = preg_replace('/{([a-zA-Z]+)}/', '(?<$1>[^?&\/]+)', addcslashes($pattern, '$./+*?[^]=!<>:-'));
        // All variable names that start with '$' are PHP variables and are indexed with an integer.
        $regex = preg_replace('/{\\\?\$?[a-zA-Z]+}/', '([^?&\/]+)', $regex);
        preg_match("/^$regex(?<rest>.*)$/", trim($last, '/'), $matches);

        if (!$matches && !$pattern && !$this->mDefaultPattern) {
            return (object)[
                'parameters' => filter_input_array(INPUT_GET) ?: [],
                'options' => []
            ];
        }

        // This array contains all values that were passed in the URI.
        $values = array_slice($matches, 1);
        $wasString = false;
        // Entries that belong to a capturing group are appearing twice (numeric and string key).
        // This loop removes all the entries that have a numeric key.
        foreach (array_slice($matches, 1) as $key => $value) {
            if ($wasString) {
                unset($values[$key]);
                $wasString = false;
            }
            $wasString = is_string($key);
        }

        // End here if the path contains more characters than it should.
        $firstChar = $values ? substr($values['rest'], 0, 1) : null;
        if (!$values || $values['rest'] && (strlen($values['rest']) > 1 || $values['rest'] !== '/')
                && $firstChar !== '?' && $firstChar !== '&') {
            return false;
        }
        unset($values['rest']);

        // Save the names of all pattern variables in an array.
        // E.g. {controller}/{method} => ['controller', 'method']
        preg_match_all('/({|\()\\\?\$?([a-zA-Z|]+)(}|\))/', $pattern, $keys);
        $keys = count($keys) > 2 ? $keys[2] : [];

        $result = [
            'parameters' => filter_input_array(INPUT_GET) ?: [],
            'options' => []
        ];

        // Iterate over all keys and sort them into the result array.
        for ($i = 0, $len = count($keys); $i < $len; ++$i) {
            if (key_exists($keys[$i], $values)) {
                // This key-value-pair is a global variable like {controller} or {method}.
                $result[$keys[$i]] = $values[$keys[$i]];
            } else if (strpos($keys[$i], '|') !== false) {
                // This value is an option. E.g. (json|xml).
                $result['options'][] = $values[$i];
            } else {
                // This key-value-pair is a parameter and its value.
                $result['parameters'][$keys[$i]] = $values[$i];
            }
        }

        return (object)$result;
    }

    //
    // Extension for conversion to XML.
    // Source: https://www.darklaunch.com/2009/05/23/php-xml-encode-using-domdocument-convert-array-to-xml-json-encode
    //
    private function xml_encode($mixed, $domElement = null, $DOMDocument = null)
    {
        if (is_null($DOMDocument)) {
            $DOMDocument = new \DOMDocument;
            $DOMDocument->formatOutput = true;
            $this->xml_encode($mixed, $DOMDocument, $DOMDocument);
            return $DOMDocument->saveXML();
        } else {
            if (is_object($mixed)) {
                $mixed = get_object_vars($mixed);
            }
            if (is_array($mixed)) {
                foreach ($mixed as $index => $mixedElement) {
                    if (is_int($index)) {
                        if ($index === 0) {
                            $node = $domElement;
                        } else {
                            $node = $DOMDocument->createElement($domElement->tagName);
                            $domElement->parentNode->appendChild($node);
                        }
                    } else {
                        $plural = $DOMDocument->createElement($index);
                        $domElement->appendChild($plural);
                        $node = $plural;
                        // Added filter for properties that end with 's': is_array($mixedElement).
                        // Those are only converted to an array if they contain an array.
                        if (!(rtrim($index, 's') === $index) && is_array($mixedElement)) {
                            $singular = $DOMDocument->createElement(rtrim($index, 's'));
                            $plural->appendChild($singular);
                            $node = $singular;
                        }
                    }
                    $this->xml_encode($mixedElement, $node, $DOMDocument);
                }
            } else {
                $mixed = is_bool($mixed) ? ($mixed ? 'true' : 'false') : $mixed;
                $domElement->appendChild($DOMDocument->createTextNode($mixed));
            }
        }
    }
}
