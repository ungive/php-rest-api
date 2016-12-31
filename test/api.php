<?php
require 'WebApi.php';

use Vanen\Mvc\Api;
use Vanen\Mvc\ApiController;
use Vanen\Net\HttpResponse;

class Product
{
    public $id;
    public $name;
    public $price;

    public function __construct($id, $name, $price)
    {
        $this->id = $id;
        $this->name = $name;
        $this->price = $price;
    }
}

class ProductsController extends ApiController
{
    private $products = [];
    private $isXml = false;

    public function __controller()
    {
        $this->products[] = new Product(1, 'Pizza', 4);
        $this->products[] = new Product(2, 'Keyboard', 32.95);
        $this->products[] = new Product(3, 'Water', 1.09);

        $this->JSON_OPTIONS = JSON_PRETTY_PRINT;
        $this->RESPONSE_TYPE = $this->OPTIONS[0];
        $this->isXml = strcasecmp($this->RESPONSE_TYPE, 'xml') === 0;
    }

/** :GET :/{controller}.(json|xml)/ */
    public function products()
    {
        return $this->isXml ? [
            'products' => $this->products
        ] : $this->products;
    }

/** :GET :/{controller}/{$id}.(json|xml)/ */
    public function product($id)
    {
        foreach ($this->products as $product) {
            if (strval($product->id) === $id) {
                return $this->isXml ? [
                    'product' => $product
                ] : $product;
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
}

$api = new Api();
$api->handle();
