<?php

namespace Jasny\Router\Runner;

use Jasny\Router\Runner;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Description of Callback
 *
 * @author arnold
 */
class Callback extends Runner
{
    /**
     * Route to a file
     * 
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @return ResponseInterface|mixed
     */
    public function run(RequestInterface $request, ResponseInterface $response)
    {

    }
}
