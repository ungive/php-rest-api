# Creating a REST API with PHP

This tutorial will cover all features of this library and how to use them. I.a. it has the following features:  
- Each method can have any number of HTTP verbs associated to it in its documentation comment. [#](#http-verbs)
- Methods are distinguished by their parameters if the method name is not specified in the URI.  
- The URI can contain variables[#](#uri-variables) and regex-like alternations[#](#alternations) if desired. E.g. `(json|xml)`.  
A rewrite engine is not needed for that.  
- The `ApiController` base class has several attributes that you can work with inside your controller class. [#](#the-apicontroller-class)
- A response (-object) is encoded by the WebApi class. The supported types are JSON and XML.  
- The HTTP response code can be specified by returning an instance of the `HttpResponse` class.  

--

The `WebApi` class is located in the `Vanen\Mvc` namespace and contains the following attributes and methods:  
>`$version` - The version of the api (optional). Is added directly after the file name and before the URI path: e.g. "v1": `/api.php/v1/products`.
`__construct($version = null)` - Constructor with $version as parameter.  
`handle()` - Handles the request.  
`allowHeader($name)` - Allows a header. Its value will be stored in `ApiController`'s[#](#the-apicontroller-class) `$HEADERS` property.  


--

This is an example of a simple API controller located in `api.php`:  

```php
<?php
require 'WebApi.php';

use Vanen\Mvc\Api;
use Vanen\Mvc\ApiController;

class ProductsController extends ApiController
{
    private $products = [];

    public function __construct()
    {
        $this->products[] = (object)['id' => 1, 'name' => 'Pizza', 'price' => 3.85];
        $this->products[] = (object)['id' => 2, 'name' => 'Pencil', 'price' => 0.49];
        $this->products[] = (object)['id' => 3, 'name' => 'Flashdrive', 'price' => 14.99];
    }

/** :GET */
    public function products()
    {
        return $this->products;
    }

/** :GET */
    public function product($id)
    {
        foreach ($this->products as $product) {
            if (strval($product->id) === $id) {
                return $product;
            }
        }

        return null;
    }
}

$api = new Api();
$api->handle();
```

### Method Attributes

#### HTTP Verbs

This controller has two methods. They can be called like this:  
`/api.php/products` returns all products.  
`/api.php/products?id=1` returns the product with the id 1.  

Both methods only allow `GET` requests as specified in the documentation comment. A method's attribute is identified by a preceding colon to differ from normal comments. Hence `/** :POST :PUT */` will allow `POST` and `PUT` requests.  

#### URI Variables

The controller's name is always put after the script's file name by default.  
You can change this by adding another attribute to the documentation comment like so: `/** :GET :{method} */`.  
`{}` indicates a path variable. An attribute is recognized as path if it contains at least one variable.  
`{method}` is a global variable which refers to the current method. There is also `{controller}` which is the controller's name.  

You can also insert a method's variables into the path by adding an anchor character in front of its name.  
Here is an example for the `product` method: `/** :GET :{controller}/{$id} */`.  
It can now be called like this: `api.php/products/1`.  

#### Alternations

Here is an example of an alternation in the URI path: `:{controller}.(json|xml)`.  
You can now either call `/api.php/products.json` or `/api.php/products.xml`.
The user's decision is saved in `ApiController`'s `$OPTIONS` property.  

--

### The ApiController class

Your controller class has to be derived from this class, otherwise it will not be recognized as ApiController. Its name also has to end with `Controller` (case-insensitive).  
The `ApiController` class is located in the `Vanen\Mvc` namespace and has the following attributes and methods:  

>`$METHOD` - Represents the HTTP method of the current request.  
`$OPTIONS` - Results of alternations[#](#alternations) in the URI path.  
`$HEADERS` - Contains the names and values of the headers that were allowed with `allowHeader()`.  
`$RESPONSE_TYPE` - The response's type. Either JSON or XML (case-insensitive).  
`$JSON_OPTIONS` - An integer value that represents the response's [JSON options](http://php.net/manual/en/json.constants.php).  
`__controller()` - This method is called after the controller has been instantiated and values of the `ApiController` class were set.  

--

### Response Types

The available response types are JSON and XML. It can be set in the controller class as follows:  
`$this->RESPONSE_TYPE = 'JSON'` or `$this->RESPONSE_TYPE = 'XML'`.

In combination with alternations[#](#alternations) you can design the `products` method like this:  
```php
/** :GET :{controller}.(json|xml) */
    public function products()
    {
        $this->RESPONSE_TYPE = $this->OPTIONS[0];
        $isXml = $this->RESPONSE_TYPE === 'xml';
        return $isXml ? [
            'products' => $this->products
        ] : $this->products;
    }
```

`api.php/products.json`:  
![JSON](http://image.prntscr.com/image/05e78d47e87f42cc8ef7e71d8a92b414.png)  

`/api.php/products.xml`:  
![XML](http://image.prntscr.com/image/996e26447c5f4243b766d2546216b1fc.png)  

--

### The HttpResponse class

Instead of returning a normal object, you can also return an instance of the `HttpResponse` class.  
With it you can specify an [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) and a message including a custom object to descibe an error e.g. The default status code is 200.  

The `HttpResponse` class class is located in the `Vanen\Mvc` namespace and has the following attributes and methods:  

>`$object` - The response object.  
`$statusCode` - The [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) of this request.  
`$message` - The message that is associated with the code.  
`__construct($statusCode = 200, $message = 'Ok', $object = null)` - Constructor.  

Here is an example of the `product` method using this class. Remember to `use Vanen\Net\HttpResponse;`.  
```php
/** :GET :{controller}/{$id} */
    public function product($id)
    {
        foreach ($this->products as $product) {
            if (strval($product->id) === $id) {
                return $product;
            }
        }

        return new HttpResponse(404, 'Not Found', (object)[
            'exception' => (object)[
                'type' => 'NotFoundApiException',
                'message' => 'Product not found',
                'code' => 404
            ]
        ]);
    }
```

`/api.php/products/4`:  
![JSON2](http://image.prntscr.com/image/ce3b2754d93d4f8abda5ea993ec1a72d.png)  

---

A full example wrapping all of this up can be found in the `test` folder of this repository.  
The style of this library is inspired by ASP.NET.  

©2016 Jonas Vanen.  
